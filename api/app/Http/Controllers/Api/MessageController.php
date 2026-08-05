<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MessageResource;
use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\MessageThread;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\ApplicationVisibility;
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
     * Hide other offices' turns in a shared conversation (checklist item 111).
     *
     * `message_threads.application_id` is UNIQUE — one conversation per filing,
     * by construction (see responsibleAssignment() below, which already works
     * around it). WorkflowService::routeToDepartments then puts up to six
     * offices on that one filing, so the sanitary officer, the fire inspector
     * and the market administrator were all reading the same transcript,
     * including each other's turns. That is the client's "messages from other
     * officers in other offices show up", and it is a privacy defect: what the
     * fire office said to this applicant is the fire office's business.
     *
     * The boundary is drawn on the MESSAGE, not the thread, and deliberately so.
     * Splitting the schema into one thread per office is the fuller fix, but it
     * changes the shape of the applicant's inbox — they would go from one
     * conversation per filing to one per office — and that is a product decision
     * about their experience, not a leak to be patched silently. Scoping the
     * rows closes the leak the client reported without pre-empting that call.
     *
     * A scoped office sees exactly two kinds of turn:
     *
     *  - the applicant's own, always. They are the other side of every one of
     *    these conversations, and an office cannot review a filing while being
     *    unable to read what the applicant told it;
     *  - its own officers'. Matched through the sender's department rather than
     *    a column on the message, because that is where the fact lives, and the
     *    sender is already eager-loaded with `department_id` everywhere.
     *
     * Deliberately left OUT, and this is the visible trade: BPLO's and the super
     * admin's turns are hidden from a scoped office too. BPLO is another office
     * by this rule and the client named no exception for it. Officers coordinate
     * through the review sheet and requirement requests, not by reading the
     * applicant's mail, and BPLO itself keeps the whole transcript because it
     * holds view_any_office. If the LGU later wants BPLO's coordination visible
     * in-thread, that is one added clause here, not a redesign.
     */
    private function scopeMessagesToReader($query, User $user): void
    {
        if (ApplicationVisibility::readsEveryOffice($user)) {
            return;
        }
        // The applicant reads their own file whole; only office seats are scoped.
        if (! $user->hasPermission(ApplicationVisibility::VIEW_ALL)) {
            return;
        }

        $deptId = $user->department_id;

        $query->where(function ($q) use ($deptId) {
            /*
             * "Sent by the applicant on this filing." Correlated by column so
             * this works both on a concrete application and inside the inbox's
             * per-application subqueries, where the applicant differs per row.
             */
            $q->whereExists(fn ($sub) => $sub->selectRaw('1')
                ->from('message_threads as vt')
                ->join('applications as va', 'va.id', '=', 'vt.application_id')
                ->whereColumn('vt.id', 'messages.thread_id')
                ->whereColumn('va.applicant_user_id', 'messages.sender_user_id'));

            // "Sent by one of my own office's people." A reviewer with no
            // department adds no clause and so sees only the applicant — the
            // same fail-closed posture ApplicationVisibility::scope() takes.
            if ($deptId) {
                $q->orWhereExists(fn ($sub) => $sub->selectRaw('1')
                    ->from('users as vu')
                    ->whereColumn('vu.id', 'messages.sender_user_id')
                    ->where('vu.department_id', $deptId));
            }
        });
    }

    /**
     * Inbox for the dedicated Messages page (checklist item 49): one row per
     * conversation, newest first, named after whoever the reader is talking to.
     * Applicants see their own applications (including ones with no thread yet,
     * so they can open the conversation); an officer sees the conversations on
     * the filings its office may read, which matches the thread check below.
     */
    public function threads(Request $request): JsonResponse
    {
        $request->validate([
            'per_page' => ['sometimes', 'integer'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $user = $request->user();
        $isOfficer = $user->hasPermission('application.view_all');

        /*
         * One query over applications, not two collections merged in PHP.
         *
         * The old shape read every visible thread, appended every threadless
         * application, sorted the union with sortByDesc and returned the lot:
         * 376 rows and 184 KB for the super admin, and unbounded by
         * construction. You cannot page a list whose order is decided after the
         * rows are loaded, so the ordering has to move into SQL first — and once
         * it has, the applicant's threadless filings and the officer's threads
         * are the same query with a different WHERE.
         */
        /*
         * Item 111: the inbox row summarises only the turns this reader may
         * read. Without the same scoping as the transcript, the preview line
         * would quote another office's message and the counter would count it —
         * the leak would survive in the list even though opening the thread no
         * longer showed it.
         */
        $lastMessageAt = Message::query()
            ->selectRaw('MAX(messages.created_at)')
            ->join('message_threads', 'message_threads.id', '=', 'messages.thread_id')
            ->whereColumn('message_threads.application_id', 'applications.id')
            ->tap(fn ($q) => $this->scopeMessagesToReader($q, $user));

        $messagesCount = Message::query()
            ->selectRaw('COUNT(*)')
            ->join('message_threads', 'message_threads.id', '=', 'messages.thread_id')
            ->whereColumn('message_threads.application_id', 'applications.id')
            ->tap(fn ($q) => $this->scopeMessagesToReader($q, $user));

        $query = Application::query()
            ->select('applications.*')
            ->addSelect(['last_message_at' => $lastMessageAt])
            ->addSelect(['messages_count' => $messagesCount])
            ->with([
                'business:id,name',
                'applicant:id,name',
                'assignments.department',
                'assignments.officer:id,name',
                'messageThread:id,application_id',
            ]);

        ApplicationVisibility::scope($query, $user);

        if ($isOfficer) {
            /*
             * An office joins a conversation that exists; it does not open one.
             *
             * "Exists" means "has something this office may read" (item 111), not
             * merely "has a row". Once another office's turns are hidden, a thread
             * where only that other office has spoken has nothing in it for this
             * reader — and listing it would put an inbox row with no messages, no
             * preview line and a zero count in front of the officer. That is the
             * leak reappearing as a silhouette: you cannot read what the fire
             * office said, but you can see that it said something and when.
             */
            $query->whereHas('messageThread.messages', fn ($m) => $this->scopeMessagesToReader($m, $user));
        } else {
            /*
             * An applicant who has not said anything yet still needs a way in,
             * so their filed applications appear whether or not a thread exists.
             * A conversation that already exists always appears, draft or not —
             * a draft can be messaged about before it is filed, and dropping the
             * row would lose the thread rather than hide it.
             */
            $query->where(fn ($q) => $q
                ->whereHas('messageThread')
                ->orWhere('status', '!=', 'draft'));
        }

        // Newest activity first; a filing nobody has written on yet sorts by
        // when it last changed, which is what the old sort_key did.
        $applications = $query
            ->orderByRaw('COALESCE(last_message_at, applications.updated_at) DESC')
            ->orderByDesc('applications.id')
            ->paginate($this->perPage($request));

        // One extra query for this page's newest messages beats loading every
        // message just to render the preview line.
        $threadIds = collect($applications->items())
            ->map(fn (Application $app) => $app->messageThread?->id)
            ->filter()
            ->values();
        // Newest READABLE message per thread — see scopeMessagesToReader().
        $latestIds = Message::query()
            ->whereIn('thread_id', $threadIds)
            ->tap(fn ($q) => $this->scopeMessagesToReader($q, $user))
            ->selectRaw('MAX(id) as id')
            ->groupBy('thread_id')
            ->pluck('id');
        $latest = Message::with('sender:id,name,department_id')
            ->whereIn('id', $latestIds)
            ->get()
            ->keyBy('thread_id');

        $rows = collect($applications->items())
            ->map(fn (Application $app) => $this->threadRow(
                $app,
                $user,
                $isOfficer,
                $app->messageThread ? $latest->get($app->messageThread->id) : null,
                (int) $app->messages_count,
            ))
            ->filter()
            ->values()
            ->map(function (array $row) {
                unset($row['sort_key']);

                return $row;
            });

        return response()->json([
            'data' => $rows,
            'meta' => $this->pageMeta($applications),
        ]);
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
            /*
             * Which office is answerable for this filing (checklist item 73).
             *
             * The applicant could already read a name off the conversation, but
             * only obliquely — sometimes an officer's name, sometimes an office,
             * depending on who happened to have spoken last — so "who am I
             * actually dealing with about my permit" stayed a guess. This says
             * it outright, and on both sides: the applicant learns which office
             * holds their file, and an officer reading the same row learns which
             * office is on it without opening the review sheet.
             *
             * ONE office, never the list. A filing is routed to every office
             * that issues one of its clearances, and printing all of them
             * answers a question nobody asked. See responsibleAssignment().
             */
            'responsible_office' => $this->responsibleOffice($app, $latest),
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

    /**
     * The assignment this conversation belongs to (checklist item 73).
     *
     * A filing routed to four offices has four assignments and only one thread —
     * `message_threads.application_id` is unique — so "which office" is a
     * resolution, not a lookup. In order:
     *
     *   1. the office of whoever spoke last, if that was an officer. Whoever
     *      just wrote to you is who you are dealing with, and this is the answer
     *      the applicant is actually asking for;
     *   2. failing that, the first office with a named officer, because a
     *      person's queue is a stronger claim than an unopened one;
     *   3. failing that, the first office the filing was routed to.
     *
     * Null when nothing is routed yet — a filing that has not been paid for has
     * no assignments at all (see WorkflowService::routeToDepartments), and
     * naming an office then would be inventing one.
     */
    private function responsibleAssignment(Application $app, ?Message $latest): ?ApplicationAssignment
    {
        if ($latest && $latest->sender_user_id !== $app->applicant_user_id) {
            $bySender = $app->assignments->firstWhere('officer_user_id', $latest->sender_user_id);
            if ($bySender) {
                return $bySender;
            }
        }

        return $app->assignments->first(fn ($a) => $a->officer !== null)
            ?? $app->assignments->first();
    }

    /**
     * The responsible office as the inbox renders it.
     *
     * `officer` is null on purpose when the office has not picked one up: a
     * queue with nobody's name on it is the true state, and filling the slot
     * with the office name again would tell the applicant a person is on it.
     *
     * @return array{code: ?string, name: string, officer: ?array{id: int, name: string}}|null
     */
    private function responsibleOffice(Application $app, ?Message $latest): ?array
    {
        $assignment = $this->responsibleAssignment($app, $latest);
        if (! $assignment?->department) {
            return null;
        }

        return [
            'code' => $assignment->department->code,
            'name' => $assignment->department->name,
            'officer' => $assignment->officer ? [
                'id' => $assignment->officer->id,
                'name' => $assignment->officer->name,
            ] : null,
        ];
    }

    /** How many messages of a conversation one request will return. */
    private const MESSAGE_WINDOW = 200;

    /**
     * One application's conversation, oldest message first.
     *
     * Bounded to the most recent {@see self::MESSAGE_WINDOW} turns rather than
     * page one of an ascending list. A chat paginated from the top opens on the
     * first thing anybody said, which is the same mistake `/inspections` made
     * with `scheduled_at` ascending — technically a page, useless as a view. The
     * window is returned in ascending order so the transcript still reads
     * forwards, and `meta.total` says how many turns exist in all.
     *
     * The longest thread on the register is 8 messages, so this changes nothing
     * anybody can see today; it removes the "load every row" that was one long
     * dispute away from mattering.
     */
    public function index(Request $request, Application $application): JsonResponse
    {
        $this->authorizeParticipant($request, $application);

        $thread = $application->messageThread;
        if (! $thread) {
            return response()->json([
                'data' => [],
                'meta' => ['total' => 0, 'returned' => 0, 'window' => self::MESSAGE_WINDOW],
            ]);
        }

        // Item 111: an office reads the applicant's turns and its own, never
        // another office's. Applied to the count as well as the page, so the
        // "showing N of M" the client sees is a count of what they may read.
        $user = $request->user();
        $total = $thread->messages()
            ->tap(fn ($q) => $this->scopeMessagesToReader($q, $user))
            ->count();
        $messages = $thread->messages()
            ->tap(fn ($q) => $this->scopeMessagesToReader($q, $user))
            ->with(['sender:id,name,department_id', 'attachments'])
            ->reorder()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::MESSAGE_WINDOW)
            ->get()
            ->sortBy([['created_at', 'asc'], ['id', 'asc']])
            ->values();

        return response()->json([
            'data' => MessageResource::collection($messages),
            'meta' => [
                'total' => $total,
                'returned' => $messages->count(),
                'window' => self::MESSAGE_WINDOW,
            ],
        ]);
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
        /*
         * No counterparty is a real state, not an impossible one: User is
         * soft-deletable, so an officer replying on the filing of a since-removed
         * account has nobody to notify. counterparty() is typed ?User now and
         * this skips the ping — sending the message must not 500 because the
         * notification had nowhere to go.
         */
        if ($recipient = $this->counterparty($request, $application)) {
            $this->notify->newMessage($application, $recipient);
        }

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

        /*
         * Item 111: hiding another office's message but still serving the file
         * attached to it would leave the leak open behind a guessable id — the
         * transcript would not show it, and an enumerated attachment id would.
         * The message this file hangs off has to be one this reader may read.
         */
        abort_unless(
            Message::whereKey($attachment->message_id)
                ->tap(fn ($q) => $this->scopeMessagesToReader($q, $request->user()))
                ->exists(),
            403,
            'This attachment belongs to another office’s message.'
        );

        abort_unless(Storage::disk('local')->exists($attachment->stored_path), 404, 'File not found.');

        return Storage::disk('local')->download($attachment->stored_path, $attachment->original_filename);
    }

    // --- helpers -------------------------------------------------------------
    /**
     * The applicant, an office the filing was routed to, or BPLO/admin. An
     * office that was never part of the filing is not in the conversation
     * either (checklist item 56).
     */
    private function authorizeParticipant(Request $request, Application $application): void
    {
        ApplicationVisibility::authorize(
            $request->user(),
            $application,
            'You are not a participant in this conversation.'
        );
    }

    /** The other side of the thread relative to the sender, if there is one. */
    private function counterparty(Request $request, Application $application): ?User
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
