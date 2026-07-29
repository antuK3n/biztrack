<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MessageResource;
use App\Models\Application;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\MessageThread;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Per-application message thread (polling, no websockets). Participants are the
 * applicant + any officer with application.view_all. Thin controller — all rows
 * created here with an audit trail; status is never touched.
 */
class MessageController extends Controller
{
    public function __construct(private NotificationService $notify) {}

    /**
     * Inbox for the dedicated Messages page (checklist item 49): one row per
     * conversation, newest first, named after whoever the reader is talking to.
     * Applicants see their own applications (including ones with no thread yet,
     * so they can open the conversation); officers who may read every
     * application see every conversation, which matches the thread check below.
     */
    public function threads(Request $request): JsonResponse
    {
        $user = $request->user();
        $isOfficer = $user->hasPermission('application.view_all');

        $threads = MessageThread::query()
            ->when(! $isOfficer, fn ($q) => $q->whereHas(
                'application',
                fn ($a) => $a->where('applicant_user_id', $user->id)
            ))
            ->with([
                'application.business:id,name',
                'application.applicant:id,name',
                'application.assignments.department',
                'application.assignments.officer:id,name',
            ])
            ->withCount('messages')
            ->get();

        // One extra query for each thread's newest message beats loading every
        // message just to render the preview line.
        $latestIds = Message::query()
            ->whereIn('thread_id', $threads->pluck('id'))
            ->selectRaw('MAX(id) as id')
            ->groupBy('thread_id')
            ->pluck('id');
        $latest = Message::with('sender:id,name,department_id')
            ->whereIn('id', $latestIds)
            ->get()
            ->keyBy('thread_id');

        $rows = $threads->map(fn (MessageThread $thread) => $this->threadRow(
            $thread->application,
            $user,
            $isOfficer,
            $latest->get($thread->id),
            (int) $thread->messages_count
        ));

        // An applicant who has not said anything yet still needs a way in.
        if (! $isOfficer) {
            $rows = $rows->concat(
                Application::query()
                    ->where('applicant_user_id', $user->id)
                    ->where('status', '!=', 'draft')
                    ->whereDoesntHave('messageThread')
                    ->with(['business:id,name', 'applicant:id,name', 'assignments.department', 'assignments.officer:id,name'])
                    ->get()
                    ->map(fn (Application $app) => $this->threadRow($app, $user, $isOfficer, null, 0))
            );
        }

        $rows = $rows->filter()->sortByDesc('sort_key')->values()->map(function (array $row) {
            unset($row['sort_key']);

            return $row;
        });

        return response()->json(['data' => $rows]);
    }

    /** One inbox row, named from the reader's side of the conversation. */
    private function threadRow(
        ?Application $app,
        User $user,
        bool $isOfficer,
        ?Message $latest,
        int $count
    ): ?array {
        if (! $app) {
            return null;
        }

        return [
            'application_id' => $app->id,
            'tracking_id' => $app->tracking_id,
            'business_name' => $app->business?->name,
            'status' => $app->status?->value,
            'counterparty' => $isOfficer
                ? [
                    'name' => $app->applicant?->name ?? 'Applicant',
                    'subtitle' => $app->business?->name ?? $app->tracking_id,
                    'is_officer' => false,
                ]
                : $this->officeCounterparty($app, $latest),
            'messages_count' => $count,
            'last_message' => $latest ? [
                'body' => $latest->body,
                'sender_name' => $latest->sender?->name,
                'mine' => $latest->sender_user_id === $user->id,
                'created_at' => optional($latest->created_at)->toISOString(),
            ] : null,
            'updated_at' => optional($latest?->created_at ?? $app->updated_at)->toISOString(),
            'sort_key' => ($latest?->created_at ?? $app->updated_at)?->timestamp ?? 0,
        ];
    }

    /** Who the applicant is talking to: the officer on the file, else the office. */
    private function officeCounterparty(Application $app, ?Message $latest): array
    {
        if ($latest && $latest->sender && $latest->sender_user_id !== $app->applicant_user_id) {
            $office = $app->assignments
                ->firstWhere('officer_user_id', $latest->sender_user_id)?->department?->name;

            return [
                'name' => $latest->sender->name,
                'subtitle' => $office ?? $this->leadOffice($app),
                'is_officer' => true,
            ];
        }

        $assigned = $app->assignments->first(fn ($a) => $a->officer !== null);

        return [
            'name' => $assigned?->officer?->name ?? $this->leadOffice($app),
            // With no named officer yet the office is the name, so the second
            // line says which of the applicant's filings this is about.
            'subtitle' => $assigned?->officer
                ? ($assigned->department?->name ?? $app->business?->name)
                : ($app->business?->name ?? $app->tracking_id),
            'is_officer' => true,
        ];
    }

    private function leadOffice(Application $app): string
    {
        return $app->assignments->first()?->department?->name
            ?? 'Business Permits and Licensing Office';
    }

    public function index(Request $request, Application $application): JsonResponse
    {
        $this->authorizeParticipant($request, $application);

        $thread = $application->messageThread;
        $messages = $thread
            ? $thread->messages()->with(['sender:id,name,department_id', 'attachments'])->get()
            : collect();

        return response()->json(['data' => MessageResource::collection($messages)]);
    }

    public function store(Request $request, Application $application): JsonResponse
    {
        $this->authorizeParticipant($request, $application);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ], [
            'attachment.max' => 'The attachment may not be larger than 10MB.',
            'attachment.mimes' => 'Attach a PDF, JPG, or PNG file.',
        ]);

        $message = DB::transaction(function () use ($request, $application, $data) {
            $thread = MessageThread::firstOrCreate(['application_id' => $application->id]);

            $message = Message::create([
                'thread_id' => $thread->id,
                'sender_user_id' => $request->user()->id,
                'body' => $data['body'],
            ]);

            if ($file = $request->file('attachment')) {
                $ext = $file->getClientOriginalExtension() ?: $file->guessExtension();
                $filename = Str::uuid()->toString().'.'.$ext;
                $dir = "private/messages/{$application->id}";
                Storage::disk('local')->putFileAs($dir, $file, $filename);

                MessageAttachment::create([
                    'message_id' => $message->id,
                    'original_filename' => $file->getClientOriginalName(),
                    'stored_path' => "{$dir}/{$filename}",
                    'mime' => $file->getClientMimeType(),
                    'size' => $file->getSize(),
                ]);
            }

            return $message;
        });

        Audit::log('message.sent', $message);
        $this->notify->newMessage($application, $this->counterparty($request, $application));

        return response()->json([
            'data' => new MessageResource($message->load(['sender:id,name,department_id', 'attachments'])),
        ], 201);
    }

    public function downloadAttachment(Request $request, MessageAttachment $attachment): StreamedResponse
    {
        $attachment->loadMissing('message.thread.application');
        $app = $attachment->message?->thread?->application;
        abort_unless($app, 404, 'Attachment not found.');
        $this->authorizeParticipant($request, $app);

        abort_unless(Storage::disk('local')->exists($attachment->stored_path), 404, 'File not found.');

        return Storage::disk('local')->download($attachment->stored_path, $attachment->original_filename);
    }

    // --- helpers -------------------------------------------------------------
    private function authorizeParticipant(Request $request, Application $application): void
    {
        if ($application->applicant_user_id === $request->user()->id) {
            return;
        }
        abort_unless(
            $request->user()->hasPermission('application.view_all'),
            403,
            'You are not a participant in this conversation.'
        );
    }

    /** The other side of the thread relative to the sender. */
    private function counterparty(Request $request, Application $application): User
    {
        $application->loadMissing('applicant');
        // Officer sent → notify applicant. Applicant sent → notify the reviewing officer(s).
        if ($request->user()->id === $application->applicant_user_id) {
            $officer = $this->assignedOfficer($application);
            if ($officer) {
                return $officer;
            }
        }

        return $application->applicant;
    }

    private function assignedOfficer(Application $application): ?User
    {
        $officerId = $application->assignments()
            ->whereNotNull('officer_user_id')
            ->orderByDesc('assigned_at')
            ->value('officer_user_id');

        return $officerId ? User::find($officerId) : null;
    }
}
