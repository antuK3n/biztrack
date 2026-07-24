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
