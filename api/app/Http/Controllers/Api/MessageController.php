<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MessageResource;
use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\Department;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\MessageThread;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\ApplicationVisibility;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Messaging (polling, no websockets).
 *
 * A conversation is between the applicant on a filing and ONE office —
 * `message_threads` is keyed on `(application_id, department_id)`. It used to
 * be keyed on the filing alone, which is what the client's "make sure the
 * business owner can only contact the correct offices" ran into: a message had
 * no addressee, so there was no correct office to check for. Item 111 had
 * already patched the readable half of that by filtering MESSAGES, and said in
 * this file that one thread per office was the fuller fix but a product
 * decision. It has been made; this is it. See migration 2026_08_30_000010.
 *
 * Two different questions are asked in here and they must not be confused:
 *
 *  - may you READ this conversation — ApplicationVisibility::readsThreadOf(),
 *    the same office boundary as clearances, office sheets and inspection
 *    findings, so the offices cannot drift apart again;
 *  - may you ADDRESS this office about this filing — addressableOffices()
 *    below, which is a fact about the filing's ROUTING, not about the reader.
 *
 * Thin controller: rows are created here with an audit trail, status is never
 * touched.
 */
class MessageController extends Controller
{
    public function __construct(private NotificationService $notify) {}

    /**
     * BPLO, resolved once per request.
     *
     * By CODE and never by a hard-coded id, because department ids are seed
     * data and differ between the register and a fresh test database.
     */
    private ?Department $bplo = null;

    private function bplo(): ?Department
    {
        return $this->bplo ??= Department::where('code', 'BPLO')->first();
    }

    // --- who may be talked to, and about what --------------------------------

    /**
     * The offices an applicant on THIS filing may write to.
     *
     * The client's requirement, stated as a set: every department holding an
     * ApplicationAssignment on the filing, PLUS BPLO. Nothing else — an office
     * with no assignment is not handling this permit and has no business
     * receiving mail about it, and asking to write to it is refused with a 403
     * rather than merely left out of a dropdown. A hidden option is a UI
     * decision; this is the rule.
     *
     * Why the assignment and not the permit type: assignments are what
     * WorkflowService::routeToDepartments creates when a paid filing is routed,
     * they are what the officer queue reads, and they survive reassignment.
     * That is exactly the membership test ApplicationVisibility uses for
     * "is this office on this filing", and using a second one here would let
     * an office be messageable while being unable to open the filing.
     *
     * Why BPLO is always in the set, even on a filing it holds no assignment
     * for: BPLO coordinates every filing and issues the mayor's permit off the
     * other offices' clearances, and it is who an applicant writes to when they
     * do not know who else to ask. Without it, an applicant on a filing that
     * has not been routed yet — assignments only exist once the fee clears —
     * would have nobody at all to contact, which is precisely when they most
     * need to ask.
     *
     * @return Collection<int, Department> keyed by department id
     */
    private function addressableOffices(Application $application): Collection
    {
        $application->loadMissing('assignments.department');

        $offices = $application->assignments
            ->map(fn (ApplicationAssignment $a) => $a->department)
            ->filter()
            ->keyBy('id');

        if (($bplo = $this->bplo()) && ! $offices->has($bplo->id)) {
            $offices->put($bplo->id, $bplo);
        }

        return $offices;
    }

    /**
     * The conversations on this filing that this reader may open.
     *
     * @return Collection<int, MessageThread>
     */
    private function readableThreads(Application $application, User $user): Collection
    {
        $application->loadMissing('messageThreads.department');

        return $application->messageThreads
            ->filter(fn (MessageThread $t) => ApplicationVisibility::readsThreadOf($user, $t->department_id))
            ->values();
    }

    /**
     * The offices this reader sees on this filing: addressable ones they may
     * read, plus any office they already have a conversation with.
     *
     * The second half matters for a filing whose routing changed. An office
     * that came off the filing after writing to the applicant is no longer
     * addressable, but the correspondence it already has is still the
     * applicant's own, and dropping it would delete history from the screen
     * without deleting it from the database. `can_message` is what separates
     * "you may read this" from "you may write here".
     *
     * @return Collection<int, Department> keyed by department id
     */
    private function visibleOffices(Application $application, User $user): Collection
    {
        $offices = $this->addressableOffices($application)
            ->filter(fn (Department $d) => ApplicationVisibility::readsThreadOf($user, $d->id));

        foreach ($this->readableThreads($application, $user) as $thread) {
            if ($thread->department && ! $offices->has($thread->department_id)) {
                $offices->put($thread->department_id, $thread->department);
            }
        }

        return $offices;
    }

    /**
     * Which office a new message is addressed to — refused, not silently
     * redirected, when the answer is "an office that is not on this filing".
     *
     * The default when the caller names nobody:
     *
     *  - an officer writes as their own office. That is the only thing they can
     *    honestly be doing, and it means the existing officer clients keep
     *    working untouched;
     *  - anybody else — the applicant, and the super admin, who has no
     *    department — writes to BPLO. Same assumption as the backfill: BPLO
     *    coordinates every filing and is the office you write to when you do
     *    not know which office to ask.
     *
     * Both halves of the check are load-bearing. `readsThreadOf` stops the
     * sanitary officer posting into the fire office's conversation (they may
     * not even read it), and membership in `addressableOffices` stops anybody —
     * applicant included — opening a conversation with an office that was never
     * routed this filing.
     */
    private function resolveAddressee(User $user, Application $application, ?int $requested): Department
    {
        $offices = $this->addressableOffices($application);

        $targetId = $requested
            ?? ($user->department_id !== null && $offices->has($user->department_id)
                ? $user->department_id
                : $this->bplo()?->id);

        $office = $targetId !== null ? $offices->get($targetId) : null;

        abort_unless(
            $office !== null && ApplicationVisibility::readsThreadOf($user, $office->id),
            403,
            'That office is not handling this application, so it cannot be messaged about it.'
        );

        return $office;
    }

    /**
     * Narrow a message query to the conversations this reader may open.
     *
     * This is the item-111 rule, redrawn where it now belongs. It used to be a
     * per-MESSAGE filter — the applicant's turns, plus turns written by someone
     * in my own department — because a filing had one shared thread and the
     * senders were the only thing distinguishing the offices. Two consequences
     * of that shape are gone with it: BPLO's coordinating turns were invisible
     * to the offices they were coordinating (there was nowhere to put them),
     * and the boundary depended on the SENDER's current department, so moving
     * an officer between offices retroactively moved their old messages.
     *
     * The boundary is the thread's office now, which is a fact recorded when
     * the message was sent and does not move afterwards.
     *
     * A reviewer with no department matches nothing — the same fail-closed
     * posture as ApplicationVisibility::scope(). Note this must be an explicit
     * `1 = 0` and not `where(department_id, null)`: Laravel turns a null value
     * into `IS NULL`, which would match exactly the unaddressed threads that
     * must never be handed out.
     */
    private function scopeMessagesToReader($query, User $user): void
    {
        if (ApplicationVisibility::readsEveryOffice($user)) {
            return;
        }
        // The applicant reads their own filing whole; only office seats are scoped.
        if (! $user->hasPermission(ApplicationVisibility::VIEW_ALL)) {
            return;
        }

        $deptId = $user->department_id;
        if ($deptId === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereExists(fn ($sub) => $sub->selectRaw('1')
            ->from('message_threads as vt')
            ->whereColumn('vt.id', 'messages.thread_id')
            ->where('vt.department_id', $deptId));
    }

    /**
     * Inbox for the dedicated Messages page (checklist item 49): one row per
     * FILING, carrying the offices it can be discussed with.
     *
     * Why the filing and not the thread, now that a filing has several: the
     * inbox has to list a filing NOBODY has written on yet, because that row is
     * the applicant's way into starting a conversation, and a row that exists
     * precisely because there is no thread cannot be produced by paging over
     * threads. Paging over filings keeps that entry point and keeps the page
     * meta honest — `total` counts filings, and every one of them is a row.
     * Which office each conversation is with is then said on the row, in
     * `offices`, rather than being smeared across a list the reader has to
     * reassemble.
     *
     * Applicants see their own applications; an officer sees the filings its
     * office may read, and only where its own office has something to read.
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
         *
         * Item 111: the row summarises only the conversations this reader may
         * open. Without the same scoping as the transcript, the preview line
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
                'messageThreads.department',
            ]);

        ApplicationVisibility::scope($query, $user);

        if ($isOfficer) {
            /*
             * An office joins a conversation that exists; it does not open one.
             *
             * "Exists" means "with MY office, and with something in it" — not
             * merely "this filing has a thread". A filing where only the fire
             * office has been written to has nothing in it for the sanitary
             * officer, and listing it would put an inbox row with no messages,
             * no preview line and a zero count in front of them. That is the
             * leak reappearing as a silhouette: you cannot read what the fire
             * office said, but you can see that it said something and when.
             */
            $query->whereHas('messageThreads', function ($t) use ($user) {
                if (! ApplicationVisibility::readsEveryOffice($user)) {
                    // -1 rather than null: an officer with no office matches
                    // nothing, instead of matching unaddressed threads.
                    $t->where('message_threads.department_id', $user->department_id ?? -1);
                }
                $t->whereHas('messages');
            });
        } else {
            /*
             * An applicant who has not said anything yet still needs a way in,
             * so their filed applications appear whether or not a thread exists.
             * A conversation that already exists always appears, draft or not —
             * a draft can be messaged about before it is filed, and dropping the
             * row would lose the thread rather than hide it.
             */
            $query->where(fn ($q) => $q
                ->whereHas('messageThreads')
                ->orWhere('status', '!=', 'draft'));
        }

        // Newest activity first; a filing nobody has written on yet sorts by
        // when it last changed, which is what the old sort_key did.
        $applications = $query
            ->orderByRaw('COALESCE(last_message_at, applications.updated_at) DESC')
            ->orderByDesc('applications.id')
            ->paginate($this->perPage($request));

        /*
         * Two extra queries for the whole page, rather than per row: what each
         * readable conversation contains, and the newest turn in it. Loading
         * every message just to render preview lines is what this replaces.
         */
        $threadIds = collect($applications->items())
            ->flatMap(fn (Application $app) => $this->readableThreads($app, $user)->pluck('id'))
            ->values();

        $stats = Message::query()
            ->whereIn('thread_id', $threadIds)
            ->tap(fn ($q) => $this->scopeMessagesToReader($q, $user))
            ->selectRaw('thread_id, COUNT(*) as messages_total, MAX(id) as last_id, MAX(created_at) as last_at')
            ->groupBy('thread_id')
            ->get()
            ->keyBy('thread_id');

        $latest = Message::with('sender:id,name,department_id')
            ->whereIn('id', $stats->pluck('last_id')->filter()->all())
            ->get()
            ->keyBy('thread_id');

        $rows = collect($applications->items())
            ->map(fn (Application $app) => $this->threadRow($app, $user, $isOfficer, $stats, $latest))
            ->filter()
            ->values();

        /*
         * Enquiries carrying no filing, merged into the first page only.
         *
         * The list pages by FILING — that is what the query above orders and
         * counts — and an enquiry has none, so it cannot be paged by the same
         * key. Page 1 is where it belongs anyway: for an applicant there is at
         * most one, and it is the way in they would otherwise not have.
         *
         * The honest limit: a BPLO officer with more enquiries than fit a page
         * sees the most recent, and the rest are reachable only by opening the
         * person. That needs the list to page over conversations rather than
         * filings, which is a larger change than this one and is not pretended
         * at here.
         */
        if ($applications->currentPage() === 1) {
            $rows = $this->generalRows($user, $isOfficer, $this->perPage($request))
                ->merge($rows)
                ->sortByDesc(fn (array $row) => $row['updated_at'] ?? '')
                ->values();
        }

        return response()->json([
            'data' => $rows,
            'meta' => $this->pageMeta($applications),
        ]);
    }

    /**
     * Inbox rows for general enquiries — the applicant's own, or the ones
     * addressed to this officer's office.
     *
     * An applicant's row is SYNTHESISED when they have never written: it
     * carries a null `thread_id` and no messages. Creating the thread here
     * instead would mean a GET that writes, and would leave a row in the table
     * for every account that ever opened the Messages page.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function generalRows(User $user, bool $isOfficer, int $limit): Collection
    {
        $bplo = $this->bplo();
        if ($bplo === null) {
            return collect();
        }

        if (! $isOfficer) {
            $thread = MessageThread::with('department')
                ->whereNull('application_id')
                ->where('user_id', $user->id)
                ->first();

            return collect([$this->generalRow($thread, $user, $bplo, false)]);
        }

        // An office sees the enquiries addressed to it, and only once somebody
        // has actually written — the same rule the filing list uses, for the
        // same reason: a row with nothing in it is a silhouette of a message.
        if (! ApplicationVisibility::readsThreadOf($user, $bplo->id)) {
            return collect();
        }

        $threads = MessageThread::with(['department', 'user:id,name'])
            ->whereNull('application_id')
            ->whereNotNull('user_id')
            ->where('department_id', $bplo->id)
            ->whereHas('messages')
            ->get();

        /*
         * `toBase()` and not just `map()`. An Eloquent collection that maps to
         * arrays downgrades itself to a base collection only if it has
         * something in it to inspect — an EMPTY one stays Eloquent, and the
         * merge below then tries to read a model key off an array and 500s.
         * The empty case is the common one: most offices have no enquiries.
         */
        return $threads->toBase()
            ->map(fn (MessageThread $t) => $this->generalRow($t, $user, $bplo, true))
            ->sortByDesc(fn (array $row) => $row['updated_at'] ?? '')
            ->take($limit)
            ->values();
    }

    /**
     * One inbox row for an enquiry, in the same shape a filing's row has.
     *
     * `application_id` is null and `kind` says so, because every reader of this
     * payload has to branch somewhere and a null id alone would be read as a
     * bug. Everything else — counterparty, offices, preview — keeps its meaning
     * so the inbox does not need a second renderer.
     *
     * @return array<string, mixed>
     */
    private function generalRow(?MessageThread $thread, User $user, Department $bplo, bool $isOfficer): array
    {
        $count = $thread ? Message::where('thread_id', $thread->id)->count() : 0;
        $last = $thread
            ? Message::with('sender:id,name')->where('thread_id', $thread->id)->latest('id')->first()
            : null;

        $office = $thread?->department ?? $bplo;

        return [
            'kind' => 'general',
            'application_id' => null,
            'thread_id' => $thread?->id,
            // The person, when an officer is reading; nobody, when it is your
            // own enquiry and the counterparty is the office.
            'user_id' => $thread?->user_id,
            'tracking_id' => null,
            'business_name' => null,
            'status' => null,
            'counterparty' => $isOfficer
                ? [
                    'name' => $thread?->user?->name ?? 'Applicant',
                    'subtitle' => 'General enquiry',
                    'is_officer' => false,
                ]
                : [
                    'name' => $office->name,
                    'subtitle' => 'General enquiry',
                    'is_officer' => true,
                ],
            'responsible_office' => [
                'code' => $office->code,
                'name' => $office->name,
                'officer' => null,
            ],
            'offices' => [[
                'department_id' => $office->id,
                'code' => $office->code,
                'name' => $office->name,
                'thread_id' => $thread?->id,
                'messages_count' => $count,
                'last_message_at' => optional($last?->created_at)->toISOString(),
                'can_message' => true,
            ]],
            'messages_count' => $count,
            'last_message' => $last ? [
                'body' => $last->body,
                'sender_name' => $last->sender?->name,
                'mine' => $last->sender_user_id === $user->id,
                'created_at' => optional($last->created_at)->toISOString(),
            ] : null,
            'updated_at' => optional($last?->created_at ?? $thread?->updated_at)->toISOString(),
        ];
    }

    /** One inbox row, named from the reader's side of the conversation. */
    private function threadRow(
        ?Application $app,
        User $user,
        bool $isOfficer,
        Collection $stats,
        Collection $latest
    ): ?array {
        if (! $app) {
            return null;
        }

        $offices = $this->officeRows($app, $user, $stats, $latest);

        /*
         * The row's preview and counter are the whole filing's readable
         * correspondence, which for an office IS its own single conversation
         * and for an applicant is all of theirs. The newest turn wins; which
         * office it came from is on `offices` beside it.
         */
        $newest = collect($offices)
            ->filter(fn (array $o) => $o['last_message_at'] !== null)
            ->sortByDesc('last_message_at')
            ->first();
        $last = $newest && $newest['thread_id'] ? $latest->get($newest['thread_id']) : null;
        $count = (int) collect($offices)->sum('messages_count');

        return [
            // Says which of the two shapes this row is, so a reader branches on
            // a stated kind rather than inferring one from a null id.
            'kind' => 'application',
            'thread_id' => null,
            'user_id' => null,
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
                : $this->officeCounterparty($app, $last),
            /*
             * Which office is answerable for this filing (checklist item 73).
             *
             * Distinct from `offices` below and both are needed. `offices` is
             * "who you may talk to about this, and what you have said to each";
             * this is "who is answerable for the permit itself", which is a
             * question about the REVIEW and is answered from the assignments
             * whether or not anybody has ever written a word.
             *
             * ONE office, never the list. See responsibleAssignment().
             */
            'responsible_office' => $this->responsibleOffice($app, $last),
            /*
             * The addressees, and the point of the whole change: the applicant
             * picks the office they are writing to from the offices actually on
             * their filing, and every row says which office it belongs to.
             */
            'offices' => $offices,
            'messages_count' => $count,
            'last_message' => $last ? [
                'body' => $last->body,
                'sender_name' => $last->sender?->name,
                'mine' => $last->sender_user_id === $user->id,
                'created_at' => optional($last->created_at)->toISOString(),
            ] : null,
            'updated_at' => optional($last?->created_at ?? $app->updated_at)->toISOString(),
        ];
    }

    /**
     * One row per office this reader may talk to (or has talked to) about this
     * filing, busiest conversation first.
     *
     * The order is deliberate: an applicant chasing a reply wants the office
     * that just wrote to them, not an alphabetical roster of the city. Offices
     * with nothing said yet sort last, by name, so the list is stable — and
     * they are still IN the list, because an office you have never written to
     * is exactly the one you are about to.
     *
     * @return list<array{department_id:int, code:?string, name:string, thread_id:?int, messages_count:int, last_message_at:?string, can_message:bool}>
     */
    private function officeRows(
        Application $app,
        User $user,
        Collection $stats,
        Collection $latest
    ): array {
        $addressable = $this->addressableOffices($app);
        $threads = $this->readableThreads($app, $user)->keyBy('department_id');

        $rows = $this->visibleOffices($app, $user)
            ->map(function (Department $d) use ($addressable, $threads, $stats, $latest) {
                $thread = $threads->get($d->id);
                $stat = $thread ? $stats->get($thread->id) : null;

                return [
                    'department_id' => $d->id,
                    'code' => $d->code,
                    'name' => $d->name,
                    'thread_id' => $thread?->id,
                    'messages_count' => (int) ($stat->messages_total ?? 0),
                    'last_message_at' => $stat
                        ? optional($latest->get($thread->id)?->created_at)->toISOString()
                        : null,
                    'can_message' => $addressable->has($d->id),
                ];
            })
            ->values()
            ->all();

        // Busiest first, silent offices last by name. Written out rather than
        // chained because the key is two-part and one of its halves is null for
        // every office nobody has written to yet.
        usort($rows, fn (array $a, array $b) => [$b['last_message_at'] ?? '', $a['name']]
            <=> [$a['last_message_at'] ?? '', $b['name']]);

        return $rows;
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
     * The assignment answerable for this filing (checklist item 73).
     *
     * A filing routed to four offices has four assignments, so "which office is
     * handling my permit" is a resolution, not a lookup. In order:
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
     * naming an office then would be inventing one. Deliberately NOT "BPLO,
     * because BPLO is always addressable": being reachable and being
     * answerable are different claims, and printing BPLO here would tell an
     * applicant their unrouted filing is being worked on.
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
     * One conversation, oldest message first.
     *
     * `?department_id=` picks the office. Without it the reader gets every
     * conversation on the filing they may open, merged in time order — which
     * for an office is its own single conversation and so leaves the officer
     * clients working exactly as before, and for an applicant is a readable
     * whole-filing view. Each message names its office (MessageResource), so a
     * merged transcript is never ambiguous about who said what to whom.
     *
     * A department that is neither readable nor on the filing is refused with a
     * 403 rather than answered with an empty list: "there is nothing here" and
     * "that is not yours to read" are different facts, and an empty list would
     * still confirm the filing exists to an office guessing at ids.
     *
     * Bounded to the most recent {@see self::MESSAGE_WINDOW} turns rather than
     * page one of an ascending list. A chat paginated from the top opens on the
     * first thing anybody said, which is the same mistake `/inspections` made
     * with `scheduled_at` ascending — technically a page, useless as a view. The
     * window is returned in ascending order so the transcript still reads
     * forwards, and `meta.total` says how many turns exist in all.
     */
    public function index(Request $request, Application $application): JsonResponse
    {
        $data = $request->validate([
            'department_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $this->authorizeParticipant($request, $application);
        $user = $request->user();

        $offices = $this->officeRowsForFiling($application, $user);
        $threads = $this->readableThreads($application, $user);

        $requested = $data['department_id'] ?? null;
        if ($requested !== null) {
            abort_unless(
                collect($offices)->contains(fn (array $o) => $o['department_id'] === (int) $requested),
                403,
                'That office is not handling this application, so it cannot be messaged about it.'
            );
            $threads = $threads->where('department_id', (int) $requested)->values();
        }

        $meta = fn (int $total, int $returned) => [
            'total' => $total,
            'returned' => $returned,
            'window' => self::MESSAGE_WINDOW,
            'department_id' => $requested !== null ? (int) $requested : null,
            'offices' => $offices,
        ];

        if ($threads->isEmpty()) {
            return response()->json(['data' => [], 'meta' => $meta(0, 0)]);
        }

        $threadIds = $threads->pluck('id')->all();
        $total = Message::whereIn('thread_id', $threadIds)->count();
        $messages = Message::query()
            ->whereIn('thread_id', $threadIds)
            ->with(['sender:id,name,department_id', 'attachments', 'thread:id,department_id', 'thread.department:id,code,name'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::MESSAGE_WINDOW)
            ->get()
            ->sortBy([['created_at', 'asc'], ['id', 'asc']])
            ->values();

        return response()->json([
            'data' => MessageResource::collection($messages),
            'meta' => $meta($total, $messages->count()),
        ]);
    }

    /**
     * The office rows for a single filing, counted on the spot.
     *
     * Same shape as the inbox's, built from one grouped query instead of the
     * page-wide batch — the transcript screen needs the counts to label its
     * office picker, and duplicating the shape would let the two screens
     * disagree about what a conversation is.
     *
     * @return list<array<string, mixed>>
     */
    private function officeRowsForFiling(Application $application, User $user): array
    {
        $threadIds = $this->readableThreads($application, $user)->pluck('id')->all();

        $stats = Message::query()
            ->whereIn('thread_id', $threadIds)
            ->selectRaw('thread_id, COUNT(*) as messages_total, MAX(id) as last_id, MAX(created_at) as last_at')
            ->groupBy('thread_id')
            ->get()
            ->keyBy('thread_id');

        $latest = Message::whereIn('id', $stats->pluck('last_id')->filter()->all())
            ->get()
            ->keyBy('thread_id');

        return $this->officeRows($application, $user, $stats, $latest);
    }

    public function store(Request $request, Application $application): JsonResponse
    {
        $this->authorizeParticipant($request, $application);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            /*
             * Optional, and its absence is not an error: an officer writing
             * from the review sheet is writing as their own office, and the
             * applicant who has not chosen is writing to BPLO. What it is NOT
             * is advisory — resolveAddressee refuses an office that is not on
             * this filing, so the boundary is enforced here and not in the
             * dropdown that offers it.
             */
            'department_id' => ['sometimes', 'nullable', 'integer'],
        ], [
            'attachment.max' => 'The attachment may not be larger than 10MB.',
            'attachment.mimes' => 'Attach a PDF, JPG, or PNG file.',
        ]);

        $office = $this->resolveAddressee(
            $request->user(),
            $application,
            isset($data['department_id']) ? (int) $data['department_id'] : null
        );

        $message = DB::transaction(function () use ($request, $application, $data, $office) {
            // Unique on (application_id, department_id), so this is one row per
            // office per filing and stays race-safe.
            $thread = MessageThread::firstOrCreate([
                'application_id' => $application->id,
                'department_id' => $office->id,
            ]);

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
        if ($recipient = $this->counterparty($request, $application, $office)) {
            $this->notify->newMessage($application, $recipient);
        }

        return response()->json([
            'data' => new MessageResource($message->load([
                'sender:id,name,department_id',
                'attachments',
                'thread:id,department_id',
                'thread.department:id,code,name',
            ])),
        ], 201);
    }

    // --- general enquiries: a question with no filing behind it --------------

    /**
     * The BPLO conversation belonging to one person, created on first sight.
     *
     * BPLO and nothing else. Without a filing there are no assignments, so
     * `addressableOffices` has nothing to reason about and no other office has
     * any business receiving the mail — the same reasoning that already makes
     * BPLO the default addressee on a filing nobody has routed yet.
     *
     * `firstOrCreate` on the unique `(user_id, department_id)` pair, so two
     * requests racing to open the same conversation still produce one row.
     */
    private function generalThreadFor(User $owner): MessageThread
    {
        $bplo = $this->bplo();
        abort_unless($bplo !== null, 503, 'The BPLO office is not set up on this system.');

        return MessageThread::firstOrCreate([
            'user_id' => $owner->id,
            'department_id' => $bplo->id,
        ]);
    }

    /**
     * Who may read and write a general thread: its owner, or BPLO.
     *
     * The ownership test is explicit and cannot be replaced by
     * `readsThreadOf` alone. That predicate answers "true" for ANY reader
     * without `application.view_all`, because on a filing the applicant is the
     * author of every sheet and ownership has already been established by
     * authorizeParticipant. There is no filing here to establish it, so
     * leaning on it would let one business owner read another's enquiry.
     */
    private function authorizeGeneralParticipant(User $reader, MessageThread $thread): void
    {
        $isOwner = $thread->user_id !== null && $thread->user_id === $reader->id;

        $isOffice = $reader->hasPermission('application.view_all')
            && ApplicationVisibility::readsThreadOf($reader, $thread->department_id);

        abort_unless($isOwner || $isOffice, 403, 'This conversation is not yours to read.');
    }

    /**
     * Resolve whose general thread is being asked for.
     *
     * No `{user}` in the path means "mine", which is what an applicant always
     * sends. Naming somebody else is an office action and is refused unless the
     * reader actually holds the office — checked in
     * authorizeGeneralParticipant, not here.
     */
    private function generalOwner(Request $request, ?User $owner): User
    {
        return $owner ?? $request->user();
    }

    public function generalIndex(Request $request, ?User $user = null): JsonResponse
    {
        $reader = $request->user();
        $thread = $this->generalThreadFor($this->generalOwner($request, $user));
        $this->authorizeGeneralParticipant($reader, $thread);

        $total = Message::where('thread_id', $thread->id)->count();
        $messages = Message::query()
            ->where('thread_id', $thread->id)
            ->with(['sender:id,name,department_id', 'attachments', 'thread:id,department_id', 'thread.department:id,code,name'])
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
                'department_id' => $thread->department_id,
                // One office, always, so the transcript screen's picker has the
                // same shape it has on a filing and needs no second branch.
                'offices' => [$this->generalOfficeRow($thread, $total)],
            ],
        ]);
    }

    public function generalStore(Request $request, ?User $user = null): JsonResponse
    {
        $reader = $request->user();
        $owner = $this->generalOwner($request, $user);
        $thread = $this->generalThreadFor($owner);
        $this->authorizeGeneralParticipant($reader, $thread);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ], [
            'attachment.max' => 'The attachment may not be larger than 10MB.',
            'attachment.mimes' => 'Attach a PDF, JPG, or PNG file.',
        ]);

        $message = DB::transaction(function () use ($request, $reader, $thread, $data, $owner) {
            $message = Message::create([
                'thread_id' => $thread->id,
                'sender_user_id' => $reader->id,
                'body' => $data['body'],
            ]);

            if ($file = $request->file('attachment')) {
                $ext = $file->getClientOriginalExtension() ?: $file->guessExtension();
                $filename = Str::uuid()->toString().'.'.$ext;
                // Keyed by the person, not by a filing that does not exist.
                $dir = "private/messages/general/{$owner->id}";
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

        return response()->json([
            'data' => new MessageResource($message->load([
                'sender:id,name,department_id',
                'attachments',
                'thread:id,department_id',
                'thread.department:id,code,name',
            ])),
        ], 201);
    }

    /**
     * The single office row a general conversation carries.
     *
     * @return array<string, mixed>
     */
    private function generalOfficeRow(MessageThread $thread, int $count): array
    {
        $office = $thread->department ?? $this->bplo();

        return [
            'department_id' => $thread->department_id,
            'code' => $office?->code,
            'name' => $office?->name ?? 'Business Permits and Licensing Office',
            'thread_id' => $thread->id,
            'messages_count' => $count,
            'last_message_at' => null,
            'can_message' => true,
        ];
    }

    public function downloadAttachment(Request $request, MessageAttachment $attachment): StreamedResponse
    {
        $attachment->loadMissing('message.thread.application', 'message.thread.user');
        $thread = $attachment->message?->thread;
        abort_unless($thread !== null, 404, 'Attachment not found.');

        /*
         * A general thread has no filing to authorise against, so it is checked
         * on its own terms. Without this branch the `abort_unless($app)` below
         * answered 404 for every attachment on an enquiry — the file was
         * unreachable rather than protected, which reads as a broken feature.
         */
        if ($thread->isGeneral()) {
            $this->authorizeGeneralParticipant($request->user(), $thread);

            abort_unless(Storage::disk('local')->exists($attachment->stored_path), 404, 'File not found.');

            return Storage::disk('local')->download($attachment->stored_path, $attachment->original_filename);
        }

        $app = $thread->application;
        abort_unless($app, 404, 'Attachment not found.');
        $this->authorizeParticipant($request, $app);

        /*
         * Item 111: hiding another office's conversation but still serving the
         * files attached to it would leave the leak open behind a guessable id —
         * the transcript would not show it, and an enumerated attachment id
         * would. The message this file hangs off has to be one this reader may
         * read.
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
     *
     * This is the coarse door — may you touch this filing's mail at all. Which
     * of its conversations is a separate, finer question; see readsThreadOf().
     */
    private function authorizeParticipant(Request $request, Application $application): void
    {
        ApplicationVisibility::authorize(
            $request->user(),
            $application,
            'You are not a participant in this conversation.'
        );
    }

    /**
     * The other side of the thread relative to the sender, if there is one.
     *
     * Now that a message is addressed, the applicant's reply pings the office
     * they actually wrote to rather than whichever officer happened to have
     * been assigned most recently — sending a question to the fire office and
     * notifying the sanitary officer is the same defect as the shared thread,
     * one layer down.
     */
    private function counterparty(Request $request, Application $application, Department $office): ?User
    {
        $application->loadMissing('applicant');
        // Officer sent → notify applicant. Applicant sent → notify the office they wrote to.
        if ($request->user()->id === $application->applicant_user_id) {
            $officer = $this->assignedOfficer($application, $office->id);
            if ($officer) {
                return $officer;
            }
        }

        return $application->applicant;
    }

    /**
     * Whoever in $departmentId holds this filing, else anybody who does.
     *
     * The fallback is deliberate and narrow: a message to an office nobody in
     * it has picked up yet still has to reach a person, and the applicant is
     * owed a reply more than the routing is owed purity. BPLO, which is always
     * addressable, frequently has no named officer on an unrouted filing.
     */
    private function assignedOfficer(Application $application, ?int $departmentId = null): ?User
    {
        $forOffice = $departmentId === null ? null : $application->assignments()
            ->where('department_id', $departmentId)
            ->whereNotNull('officer_user_id')
            ->orderByDesc('assigned_at')
            ->value('officer_user_id');

        $officerId = $forOffice ?? $application->assignments()
            ->whereNotNull('officer_user_id')
            ->orderByDesc('assigned_at')
            ->value('officer_user_id');

        return $officerId ? User::find($officerId) : null;
    }
}
