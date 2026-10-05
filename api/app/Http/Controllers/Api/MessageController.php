<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MessageResource;
use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\Business;
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
use Illuminate\Support\Carbon;
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
 *  - may you READ this conversation — readsThread() below, which scopes an
 *    office seat to its OWN conversations and nothing else;
 *  - may you ADDRESS this office about this filing — addressableOffices(),
 *    which is now every configured office, because the owner decides who they
 *    need to talk to.
 *
 * Reading is deliberately NOT ApplicationVisibility::readsThreadOf any more.
 * That predicate lets `application.view_any_office` through, which is right for
 * a clearance or an office sheet — BPLO issues the mayor's permit off the other
 * offices' work and has to read it — and wrong for correspondence. A message to
 * the health office is not part of the register BPLO coordinates; it is a
 * conversation between two parties, and the client's rule is that an office
 * sees a conversation only if it was the one contacted.
 *
 * The consequence is stated plainly because it is a real loss: BPLO no longer
 * reads other offices' mail on a filing, and a seat with no department — the
 * super admin — reads none at all. Both are fail-closed and both are tested.
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
     * May this reader open a conversation with THIS office?
     *
     * An office seat sees its own conversations and no others. There is no
     * `readsEveryOffice` escape here on purpose: the client's requirement is
     * that a conversation reaches the office it was addressed to and no other,
     * and BPLO holding `application.view_any_office` would otherwise read every
     * office's mail on every filing.
     *
     * A reader WITHOUT `application.view_all` is an applicant, and gets true —
     * they are a party to their own conversations. Which conversations are
     * theirs is settled elsewhere and must be: authorizeParticipant for a
     * filing, authorizeGeneralParticipant for an enquiry. This predicate answers
     * "which office", never "whose".
     *
     * Fail-closed on a null department: a seat that reviews for no office
     * matches nothing rather than matching the unaddressed.
     */
    private function readsThread(User $user, ?int $threadDepartmentId): bool
    {
        if (! $user->hasPermission(ApplicationVisibility::VIEW_ALL)) {
            return true;
        }

        return $user->department_id !== null
            && $user->department_id === $threadDepartmentId;
    }

    /**
     * The offices an applicant on THIS filing may write to: all of them.
     *
     * ── This reverses an earlier rule, deliberately ──────────────────────────
     *
     * It used to be "every department holding an ApplicationAssignment on the
     * filing, PLUS BPLO. Nothing else", refused with a 403 rather than merely
     * left out of a dropdown, and it read the client's "make sure the business
     * owner can only contact the correct offices" as "only the offices already
     * routed to". That reading had a cost nobody wanted: assignments do not
     * exist until the fee clears, so for the whole of the period an applicant
     * has the most questions, the only office they could write to was BPLO —
     * and a question for the health office had to be asked of BPLO and
     * forwarded by hand.
     *
     * The client has since asked for the opposite in as many words: the owner
     * picks from the offices the system has, starts a conversation with any of
     * them, and may come back later and start another with a different one.
     * "The correct office" turns out to mean the office the OWNER judges
     * correct, which is a different question from which offices are reviewing
     * the permit.
     *
     * What this does NOT relax is who can READ. Widening the addressee list
     * would be a leak if an office could see mail it was never sent; it cannot.
     * See readsThread(), which scopes every office seat to its own
     * conversations and is the half of this that has to stay shut.
     *
     * @return Collection<int, Department> keyed by department id
     */
    private function addressableOffices(Application $application): Collection
    {
        // Every configured office. There is no active/inactive flag on
        // departments — a row exists because the LGU has that office — so the
        // table IS the list, and an office added later becomes messageable
        // without a deploy.
        return Department::orderBy('name')->get()->keyBy('id');
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
            ->filter(fn (MessageThread $t) => $this->readsThread($user, $t->department_id))
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
        // The filter below reads the routing; load it rather than lazy-loading
        // once per office.
        $application->loadMissing('assignments');

        $offices = $this->addressableOffices($application)
            ->filter(fn (Department $d) => $this->readsThread($user, $d->id))
            /*
             * ---- And only the offices that will actually SEE it -----------
             *
             * An office's Messages page is its caseload now: "sa admin offices
             * ang maaccess lang nila once na naka assign na sa kanila yung
             * application" [client, 28 September 2026]. Offering an applicant
             * an office that is not on this filing therefore offers them a
             * message nobody will ever be shown - accepted with a 201, stored,
             * and invisible to the only people it was for.
             *
             * This narrows the PICKER, not the gate. addressableOffices() is
             * still every office, so nothing already sent becomes unreadable,
             * and the loop below puts back any office that already holds a
             * conversation here. What changes is what an applicant is invited
             * to start.
             *
             * It reads as a reversal of "the owner picks from the offices the
             * system has" and it is not the same question. That instruction was
             * about reaching an office one has no filing with, and the answer to
             * it now is the general enquiry, which every office has as of the
             * same day. This is about a PARTICULAR permit, and the offices that
             * can act on a permit are the ones it was routed to.
             *
             * An officer reading is unaffected: readsThread() has already cut
             * the list to their own office above.
             */
            ->filter(fn (Department $d) => $user->hasPermission(ApplicationVisibility::VIEW_ALL)
                || $application->assignments->contains('department_id', $d->id));

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
     * The read check is what is load-bearing now: readsThread() stops the
     * sanitary officer posting into the fire office's conversation, which they
     * may not even read. Membership in `addressableOffices` no longer narrows
     * anything for an applicant — every configured office is addressable — but
     * it stays as the guard that a named department actually exists.
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
            $office !== null && $this->readsThread($user, $office->id),
            403,
            'That office is not handling this application, so it cannot be messaged about it.'
        );

        /*
         * ---- And the officer has to still be holding the case ------------
         *
         * "Once na in-unassign na, magsstay pa rin ang convo pero di nya na
         * ma-cha-chat" [client, 30 September 2026]. The screen closes its
         * composer from the same predicate, and this is the half that is a
         * rule rather than an appearance: a browser is the reader's own, and a
         * disabled box stops nothing that types the request by hand or keeps a
         * tab open through a reassignment.
         *
         * Said in the officer's own terms. "That office is not handling this"
         * above is about the wrong office; this is about the right office and
         * the wrong person, and telling them the first would send them looking
         * for a routing problem that is not there.
         */
        abort_unless(
            $this->writesTo($user, $application, $office->id),
            403,
            'This filing is no longer assigned to you, so its conversation is read-only. '
            .'Whoever holds it now can answer.'
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
        // The applicant reads their own filing whole; only office seats are scoped.
        // No readsEveryOffice escape: see readsThread(). BPLO reading every
        // office's clearances does not extend to reading their mail.
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

        /*
         * ---- And only this officer's own stretch of it ------------------
         *
         * "Sa end naman ng bagong officer in charge, wala yung dating convo -
         * bagong convo na dapat nila" [client, 30 September 2026].
         *
         * One office has ONE conversation with the applicant, which runs the
         * whole length of the filing and which the applicant reads whole. An
         * officer reads the part that was theirs: the messages written while
         * they held the case. Somebody taking a case over opens on an empty
         * screen and starts again, and the officer who handed it on keeps the
         * conversation they had - still legible, no longer writable.
         *
         * Messages with NO holder are read by whoever is in the office. They
         * were written to the office before anybody claimed the case, so
         * nobody's stretch owns them and hiding them would lose the applicant's
         * first question.
         *
         * A general enquiry and the administrator's line are unaffected. They
         * have no filing and so no officer in charge; `handled_by_user_id` is
         * null on both, and the clause above has already confined this to
         * threads addressed to the reader's own office.
         */
        $query->where(fn ($who) => $who
            ->whereNull('messages.handled_by_user_id')
            ->orWhere('messages.handled_by_user_id', $user->id));
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
            /*
             * The inbox's Filter, answered in SQL.
             *
             * It has to be here rather than in the browser for the reason the
             * queue's search had to move: this list is PAGED at fifty, so a
             * narrowing applied to what was downloaded would tell a clerk with
             * ninety conversations that nothing is unread while the unread ones
             * sit on page two. The same mistake, one screen over.
             *
             *  unread   — somebody else wrote and the reader has not opened it
             *  awaiting — the reader's own turn was the last: they are waiting
             *  quiet    — nothing has been said on the filing at all
             */
            'narrow' => ['sometimes', 'in:unread,awaiting,quiet'],
            'per_page' => ['sometimes', 'integer'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $user = $request->user();
        $isOfficer = $user->hasPermission('application.view_all');
        $narrow = $request->query('narrow');

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
                // `status` and `blacklisted_at` ride along for the standing
                // note on an officer's row - see counterpartyStanding().
                'business:id,name,status',
                'applicant:id,name,blacklisted_at',
                'assignments.department',
                'assignments.officer:id,name',
                'messageThreads.department',
            ]);

        /*
         * Visible for review.
         *
         * ApplicationVisibility::scope answers "may you review this permit",
         * which is about routing - and routing is now the only thing that
         * puts a filing on an office's Messages page, so the two agree and
         * nothing has to be widened past it.
         *
         * There WAS a widening here, an `orWhereHas` on the office's own
         * threads, added because an office could be written to about a filing
         * it was never routed and that filing had to appear somewhere. It has
         * gone with the clause it existed for [client, 28 September 2026].
         * Which conversation on a row may be opened is still readsThread().
         */
        ApplicationVisibility::scope($query, $user);

        if ($isOfficer) {
            /*
             * ---- What an office's Messages page is FOR -------------------
             *
             * "Ang andon lang sa messages page nila ay kung ano ang mga naka
             * assign na business permit sa kanila" [client, 28 September
             * 2026]. The screen is the correspondence on the permits this
             * office is HANDLING, not every permit somebody happened to write
             * to it about.
             *
             * That distinction has teeth because an owner may address any
             * office about any filing - see addressableOffices(), which the
             * client asked for in as many words. So the fire office could sit
             * on a conversation about a permit it was never routed, with
             * nothing to tell it apart from its actual work. The general
             * enquiry per office, added in the same pass, is where a question
             * like that belongs now.
             *
             * ---- One clause, and the one that was taken out -------------
             *
             * ASSIGNED, and nothing else: an `application_assignments` row
             * for my office, and where the routing names a PERSON it has to
             * be me. 32 of the 34 assignments in the register name one, so a
             * seat that ignored `officer_user_id` would show a colleague's
             * caseload as its own; a routing with nobody named belongs to
             * whoever holds the office.
             *
             * "Sa admin offices ang maaccess lang nila once na naka assign na
             * sa kanila yung application" [client, 28 September 2026].
             *
             * A second clause used to sit here, keeping a filing visible
             * where the office had been WRITTEN to about it even though it
             * was never routed one. The reasoning was that hiding mail
             * somebody had actually sent is worse than a slightly wider list,
             * and the client has answered it: the page is the caseload. An
             * owner who wants to ask an office something that is not about a
             * permit of theirs has the general enquiry to ask it in, which
             * every office now has, and that is where such a question
             * belongs.
             *
             * Nothing is deleted by this. The conversations are in the
             * register and come back on the screen the moment the filing is
             * routed to the office they are addressed to - which is the state
             * in which somebody there can actually act on them.
             */
            $query->where(function ($mine) use ($user) {
                $mine->whereHas('assignments', function ($a) use ($user) {
                    /*
                     * -1 rather than null: a seat with no office matches
                     * nothing, where `where(col, null)` becomes `IS NULL` and
                     * would match every unrouted assignment instead.
                     */
                    $a->where('application_assignments.department_id', $user->department_id ?? -1);
                    $a->where(fn ($who) => $who
                        ->whereNull('application_assignments.officer_user_id')
                        ->orWhere('application_assignments.officer_user_id', $user->id));
                });

                /*
                 * ---- And a case they used to hold, for reading -------------
                 *
                 * "Once na in-unassign na, magsstay pa rin ang convo pero di
                 * nya na ma-cha-chat, like for viewing na lang" [client, 30
                 * September 2026].
                 *
                 * Keyed on their own stretch of the conversation rather than
                 * on a record of the handover, because there is none: an
                 * assignment's `officer_user_id` is overwritten in place, so
                 * by the time somebody is unassigned nothing says they ever
                 * were. `messages.handled_by_user_id` does say, on every
                 * message they were answerable for, and it does not move.
                 *
                 * An officer who held a case and never wrote on it has no
                 * stretch and no row. That is right: there is no conversation
                 * to keep for viewing, which is what this clause preserves.
                 */
                $mine->orWhereHas('messageThreads.messages', fn ($m) => $m
                    ->where('messages.handled_by_user_id', $user->id));
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

        $this->applyNarrow($query, $user, $narrow);

        /*
         * Newest activity first; a filing nobody has written on yet sorts by
         * when it last changed, which is what the old sort_key did.
         *
         * The sort key is its own correlated subquery, not the
         * `last_message_at` alias above wrapped in COALESCE. SQLite lets ORDER
         * BY reach into a select alias inside an expression; PostgreSQL allows
         * an alias only on its own, so the inbox answered 500 there ("column
         * last_message_at does not exist"). MAX over no rows is one NULL row,
         * so the COALESCE still falls back to the filing's own timestamp.
         */
        $activityAt = Message::query()
            ->selectRaw('COALESCE(MAX(messages.created_at), applications.updated_at)')
            ->join('message_threads', 'message_threads.id', '=', 'messages.thread_id')
            ->whereColumn('message_threads.application_id', 'applications.id')
            ->tap(fn ($q) => $this->scopeMessagesToReader($q, $user));

        $applications = $query
            ->orderByDesc($activityAt)
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
            /*
             * `unread_total` rides along in the same aggregate rather than
             * being a second pass over the table.
             *
             * Same definition the nav badge uses in unreadSummary(): a turn
             * somebody ELSE sent that this reader has not opened. Your own turn
             * is never unread to you, which is why the sender is excluded here
             * rather than the count being taken from the thread. Without this
             * the inbox could say when a conversation last moved but not
             * whether the reader had seen it, so "Unread" was a filter the list
             * held no data to answer.
             */
            ->selectRaw(
                'thread_id, COUNT(*) as messages_total, MAX(id) as last_id, MAX(created_at) as last_at, '
                .'SUM(CASE WHEN messages.read_at IS NULL AND messages.sender_user_id <> ? THEN 1 ELSE 0 END) as unread_total',
                [$user->id]
            )
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
        $enquiries = $this->generalRows($user, $isOfficer, $this->perPage($request))
            ->filter(fn (array $row) => $this->rowMatchesNarrow($row, $narrow))
            ->values();

        if ($applications->currentPage() === 1) {
            $rows = $enquiries
                ->merge($rows)
                ->sortByDesc(fn (array $row) => $row['updated_at'] ?? '')
                ->values();

            /*
             * The administrator's line, PINNED to the top rather than sorted
             * in with the rest.
             *
             * Sorting it by date would bury it: an officer who has never
             * needed to ask would find it below thirty filings, and the reason
             * it exists is that they cannot change their own details and have
             * to be able to find where to ask [client, 28 September 2026].
             * Nothing else on this screen is pinned, so the one thing that is
             * reads as deliberate.
             */
            $admin = $this->adminRow($user);
            /*
             * Pinned, but not exempt. A reader who has asked for "Unread" has
             * asked to see less, and a filter that returns a read row is a
             * filter that lies — the enquiry rows above obey the same test for
             * the same reason.
             */
            if ($admin && $this->rowMatchesNarrow($admin, $narrow)) {
                $rows = collect([$admin])->merge($rows)->values();
            }
        }

        /*
         * The enquiries count towards the total, or the screen contradicts
         * itself.
         *
         * `pageMeta` counts what the paginator counted, which is FILINGS —
         * enquiries are merged in afterwards because they have no filing to be
         * paged by. The Messages page states "Showing N of M", and BPLO's inbox
         * read "Showing 3 of 2": three rows on screen, a total that had never
         * heard of one of them. Counted on every page and not only where they
         * are merged, so the total does not shrink when the reader pages on.
         */
        $meta = $this->pageMeta($applications);
        $meta['total'] += $enquiries->count();

        /*
         * The pinned row counts too, on every page, or "Showing 4 of 3" — the
         * same contradiction the enquiries fixed one line above. Counted only
         * when it would actually be shown, so a narrowed inbox does not report
         * a row the narrowing has removed.
         */
        $pinned = $this->adminRow($user);
        if ($pinned && $this->rowMatchesNarrow($pinned, $narrow)) {
            $meta['total'] += 1;
        }

        return response()->json([
            'data' => $rows,
            'meta' => $meta,
        ]);
    }

    /**
     * Narrow the inbox to what is waiting on somebody.
     *
     * Every clause is scoped with scopeMessagesToReader, so an office asking
     * "what is unread" is asking about ITS OWN conversation and cannot be told
     * a filing is unread because another office has mail on it. Without that,
     * the filter would leak the one fact readsThread() exists to hide: that
     * some other office said something.
     *
     * A null or unknown value narrows nothing. The rule list is validated in
     * threads(), so an unknown value cannot arrive here from the API — this is
     * the fail-open default for the one caller that passes null.
     */
    private function applyNarrow($query, User $user, ?string $narrow): void
    {
        if ($narrow === 'unread') {
            $query->whereExists(fn ($sub) => $sub->selectRaw('1')
                ->from('messages')
                ->join('message_threads', 'message_threads.id', '=', 'messages.thread_id')
                ->whereColumn('message_threads.application_id', 'applications.id')
                ->whereNull('messages.read_at')
                ->where('messages.sender_user_id', '!=', $user->id)
                ->tap(fn ($q) => $this->scopeMessagesToReader($q, $user)));

            return;
        }

        if ($narrow === 'awaiting') {
            /*
             * The NEWEST readable turn is the reader's own.
             *
             * Not "the reader has written at all", which is a different and much
             * larger set — every conversation you have ever taken part in. The
             * question this answers is "who owes me an answer", so it is the
             * last word that decides, and the subquery takes exactly one row.
             */
            $lastSender = Message::query()
                ->select('messages.sender_user_id')
                ->join('message_threads', 'message_threads.id', '=', 'messages.thread_id')
                ->whereColumn('message_threads.application_id', 'applications.id')
                ->tap(fn ($q) => $this->scopeMessagesToReader($q, $user))
                ->orderByDesc('messages.id')
                ->limit(1);

            $query->where($lastSender, '=', $user->id);

            return;
        }

        if ($narrow === 'quiet') {
            // Nothing said, by anybody the reader can hear. An office never
            // matches this — its inbox lists a filing only once its own
            // conversation has something in it — which is why the screen offers
            // the option to applicants only.
            $query->whereNotExists(fn ($sub) => $sub->selectRaw('1')
                ->from('messages')
                ->join('message_threads', 'message_threads.id', '=', 'messages.thread_id')
                ->whereColumn('message_threads.application_id', 'applications.id')
                ->tap(fn ($q) => $this->scopeMessagesToReader($q, $user)));
        }
    }

    /**
     * Does this enquiry row answer the same narrowing question?
     *
     * Enquiries are merged into page one in PHP rather than being part of the
     * query above — they have no filing to page by — so the filter has to be
     * applied to them separately, and from the row itself. The row already
     * carries every number the predicate needs, so this reads the same facts
     * the SQL does rather than a second definition of them.
     *
     * @param  array<string, mixed>  $row
     */
    private function rowMatchesNarrow(array $row, ?string $narrow): bool
    {
        return match ($narrow) {
            'unread' => ($row['unread_count'] ?? 0) > 0,
            'awaiting' => ($row['last_message']['mine'] ?? false) === true,
            'quiet' => ($row['messages_count'] ?? 0) === 0,
            default => true,
        };
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
            return $this->ownerEnquiryRows($user);
        }

        /*
         * ---- One row per owner, whether or not anybody has written -------
         *
         * "Lahat dapat ng officer matatanggap ang general inquiry, so that
         * ma-me-message pa rin nila yung business owner sa messages page.
         * Matic na pag gumagawa ng account may magrereflect na sa general
         * inquiry ng BPLO. Ganon naman din sa other offices once na nag-apply
         * na sila ng other permit sa office na yon" [client, 1 October 2026].
         *
         * This listed threads, and only threads with something in them - the
         * same rule the filing list uses, on the reasoning that a row with
         * nothing in it is a silhouette of a message. That reasoning holds for
         * a FILING, where the applicant has already been given a way in, and
         * fails here: the row IS the way in. An office that can only answer
         * what it has been asked cannot start the conversation, and starting
         * it is the whole point of this screen.
         *
         * Which owners an office may hear from:
         *
         *  - BPLO, everybody. It coordinates every filing and is the office
         *    you write to when you do not know which office to ask, so an
         *    account exists in its list from the day it is registered.
         *
         *  - Every other office, the owners it has been routed work for. An
         *    office with no filing of yours has no business opening a
         *    conversation with you, and an inbox listing every citizen in the
         *    city would bury the ones it does.
         *
         * Anybody holding the seat reads them. A general enquiry has no filing
         * and so no officer in charge - `handled_by_user_id` is null on every
         * message in one - which is what "lahat ng officer" comes to in the
         * code: there is no tenure to scope by, and none is applied.
         */
        if ($user->department_id === null) {
            return collect();
        }

        return $this->officeEnquiryRows($user, $bplo, $limit);
    }

    /**
     * The owners this office may hear from, and what has been said to each.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function officeEnquiryRows(User $officer, Department $bplo, int $limit): Collection
    {
        $isBplo = $officer->department_id === $bplo->id;

        /*
         * The row's office is the READER'S, not BPLO's.
         *
         * Passing `$bplo` here labelled every office's list with BPLO's name
         * and BPLO's department id, so the fire office's own enquiries came
         * back addressed to somebody else - and a test that checks one office
         * cannot see another's caught it by finding a BPLO row in the fire
         * office's inbox.
         */
        $office = $officer->department ?? $bplo;

        $owners = User::query()
            ->select(['id', 'name', 'blacklisted_at'])
            ->whereHas('roles.permissions', fn ($q) => $q->where('name', 'business.manage_own'))
            ->where('is_active', true);

        if (! $isBplo) {
            $owners->where(function ($eligible) use ($officer) {
                /*
                 * Routed work, not merely a filing that names this office's
                 * permit. An assignment is the moment the office is actually
                 * given something to do - before that there is nothing for
                 * them to open a conversation about.
                 */
                $eligible->whereHas('businesses.applications.assignments', fn ($a) => $a
                    ->where('application_assignments.department_id', $officer->department_id));

                /*
                 * ---- Or somebody who has simply written to them -----------
                 *
                 * An owner may address ANY office through its general enquiry,
                 * routed work or not - that is what the front door per office
                 * is for [client, 28 September 2026]. Without this clause an
                 * office would be handed a question it could not see: the
                 * thread accepted, stored, and absent from the one screen that
                 * lists enquiries.
                 *
                 * Found by a test that wrote to the fire office from an owner
                 * it had never been routed anything for, and then could not
                 * find the message in the fire office's inbox.
                 */
                $eligible->orWhereHas('messageThreads', fn ($t) => $t
                    ->whereNull('message_threads.application_id')
                    ->where('message_threads.department_id', $officer->department_id));
            });
        }

        $owners = $owners->orderBy('name')->get();

        if ($owners->isEmpty()) {
            return collect();
        }

        /*
         * The threads in one query rather than one per owner. Most of these
         * rows have no thread at all - that is the point of them - so this is
         * a single read that usually returns very little.
         */
        $threads = MessageThread::with(['department', 'user:id,name,blacklisted_at'])
            ->whereNull('application_id')
            ->where('department_id', $officer->department_id)
            ->whereIn('user_id', $owners->pluck('id'))
            ->get()
            ->keyBy('user_id');

        return $owners
            ->toBase()
            ->map(fn (User $owner) => $this->generalRow(
                $threads->get($owner->id),
                $officer,
                $office,
                true,
                $owner,
            ))
            /*
             * Whoever spoke most recently first, then the silent ones by name.
             * An office scanning for what is waiting should not have to read
             * past a hundred accounts that have never written; an office
             * looking for a particular person finds them in alphabetical
             * order below.
             */
            ->sortBy(fn (array $row) => [
                // Silent accounts last, whatever they are called.
                $row['updated_at'] === null ? 1 : 0,
                // Among the rest, newest first - a negated epoch, because one
                // `sortBy` over a tuple is the only way to mix a descending
                // key with an ascending one. A second `sortByDesc` pass would
                // simply undo this one.
                $row['updated_at'] === null ? 0 : -strtotime($row['updated_at']),
                // And alphabetically, which is how an office looks somebody up.
                $row['counterparty']['name'],
            ])
            ->take($limit)
            ->values();
    }

    /**
     * The owner's general enquiry: ONE row, with every office on it.
     *
     * ---- Six rows, then one, and why it came back --------------------
     *
     * The enquiry began as a single conversation with BPLO. When every office
     * got a front door it became a row each, and on the inbox that read as six
     * conversations - six titles, six dates, six previews - for what an owner
     * thinks of as one thing: asking the City a question.
     *
     * "Nasa iisang convo na lang uli ang mga general inquiry sa ibat ibang
     * offices, tas may choices na lang ulit don kung anong office" [client, 30
     * September 2026]. So the row is one again and the office is a choice
     * INSIDE it - the same shape a permit's conversation has, where the pane
     * carries a picker and the inbox carries one line.
     *
     * Nothing changes underneath. `(user_id, department_id)` is still unique,
     * so these are still separate threads and an office still reads only its
     * own; what is joined is the summary, not the correspondence.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function ownerEnquiryRows(User $owner): Collection
    {
        $offices = $this->enquiryOffices($owner);

        /*
         * The row is summarised from the office that spoke LAST, because that
         * is the conversation the reader would open it to see. Its date is what
         * sorts the row among the filings, and its office is what the card
         * names underneath the title.
         */
        $latest = $offices
            ->filter(fn (array $o) => $o['last_message_at'] !== null)
            ->sortByDesc('last_message_at')
            ->first();

        $thread = $latest && $latest['thread_id']
            ? MessageThread::with('department')->find($latest['thread_id'])
            : null;

        $last = $thread
            ? Message::with('sender:id,name')->where('thread_id', $thread->id)->latest('id')->first()
            : null;

        $office = $thread?->department;

        return collect([[
            'kind' => 'general',
            'application_id' => null,
            /*
             * The office the row OPENS on, not the only one it holds. Null
             * before anybody has written, and the pane then picks its own
             * default - see generalIndex().
             */
            'department_id' => $office?->id,
            'thread_id' => $thread?->id,
            'user_id' => $owner->id,
            'tracking_id' => null,
            'business_name' => null,
            'status' => null,
            'counterparty' => [
                // Named for what it IS. Every other row on an applicant's inbox
                // is titled after a business, so the one that has none needs a
                // title of its own rather than whichever office answered last.
                'name' => 'General enquiry',
                'subtitle' => 'Ask any office',
                'is_officer' => true,
            ],
            // Who spoke last, printed under the title. Null until somebody has.
            'responsible_office' => $office ? [
                'code' => $office->code,
                'name' => $office->name,
                'officer' => null,
            ] : null,
            'offices' => $offices->values()->all(),
            'messages_count' => $offices->sum('messages_count'),
            'unread_count' => $offices->sum('unread_count'),
            'last_message' => $last ? [
                'body' => $last->body,
                'sender_name' => $last->sender?->name,
                'mine' => $last->sender_user_id === $owner->id,
                'created_at' => optional($last->created_at)->toISOString(),
            ] : null,
            'updated_at' => $latest['last_message_at'] ?? null,
        ]]);
    }

    /**
     * Every office an owner may ask, with what has been said to each.
     *
     * The shape the office PICKER reads, and the same shape a filing's offices
     * have, so the pane needs no second branch: one row per department whether
     * or not a thread exists for it, because the row IS how a conversation
     * gets started.
     *
     * Counted in two queries rather than per office. Six offices asked one at a
     * time is twelve round trips on a screen an owner already waits for.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function enquiryOffices(User $owner): Collection
    {
        $threads = MessageThread::query()
            ->whereNull('application_id')
            ->where('user_id', $owner->id)
            ->get()
            ->keyBy('department_id');

        $ids = $threads->pluck('id');

        $stats = Message::query()
            ->selectRaw('thread_id')
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('MAX(created_at) AS last_at')
            // Somebody else's turn that the reader has not opened. Your own is
            // never unread to you, which is why the sender is excluded here
            // rather than the count being taken off the thread.
            ->selectRaw('SUM(CASE WHEN read_at IS NULL AND sender_user_id != ? THEN 1 ELSE 0 END) AS unread', [$owner->id])
            ->whereIn('thread_id', $ids)
            ->groupBy('thread_id')
            ->get()
            ->keyBy('thread_id');

        return Department::orderBy('name')->get()->toBase()->map(function (Department $office) use ($threads, $stats) {
            $thread = $threads->get($office->id);
            $stat = $thread ? $stats->get($thread->id) : null;

            return [
                'department_id' => $office->id,
                'code' => $office->code,
                'name' => $office->name,
                'thread_id' => $thread?->id,
                'messages_count' => (int) ($stat->total ?? 0),
                'unread_count' => (int) ($stat->unread ?? 0),
                'last_message_at' => $stat?->last_at
                    ? Carbon::parse($stat->last_at)->toISOString()
                    : null,
                'can_message' => true,
            ];
        })->values();
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
    private function generalRow(
        ?MessageThread $thread,
        User $user,
        Department $for,
        bool $isOfficer,
        ?User $owner = null,
    ): array {
        $count = $thread ? Message::where('thread_id', $thread->id)->count() : 0;
        // Counted here rather than reused from the filing query's aggregate:
        // an enquiry has no application, so it is not in that query at all.
        // Same rule — somebody else's turn, not yet opened.
        $unread = $thread
            ? Message::where('thread_id', $thread->id)
                ->whereNull('read_at')
                ->where('sender_user_id', '!=', $user->id)
                ->count()
            : 0;
        $last = $thread
            ? Message::with('sender:id,name')->where('thread_id', $thread->id)->latest('id')->first()
            : null;

        /*
         * The office is passed IN now rather than read off the thread, because
         * the row has to exist before the thread does: an owner who has never
         * written to the fire office still needs a row to write the first
         * message in. A thread's own office still wins where there is one, so
         * a row built from a thread cannot be mislabelled.
         */
        $office = $thread?->department ?? $for;

        return [
            'kind' => 'general',
            'application_id' => null,
            /*
             * WHICH office the enquiry is with. A general row used to be the
             * one BPLO row, so a client could tell them apart by `kind`
             * alone; there are up to eight now and this is what separates
             * them - in the React key, in the URL, and in what gets sent.
             */
            'department_id' => $office->id,
            'thread_id' => $thread?->id,
            // The person, when an officer is reading; nobody, when it is your
            // own enquiry and the counterparty is the office.
            /*
             * Whose enquiry it is. `$owner` carries it where no thread exists
             * yet - an office's list holds a row for every owner it may hear
             * from, and that row is how the office OPENS the conversation, so
             * it has to name somebody before anybody has written.
             */
            'user_id' => $thread?->user_id ?? $owner?->id,
            'tracking_id' => null,
            'business_name' => null,
            'status' => null,
            'counterparty' => $isOfficer
                ? [
                    'name' => $thread?->user?->name ?? $owner?->name ?? 'Applicant',
                    'subtitle' => 'General enquiry',
                    'is_officer' => false,
                    /*
                     * An enquiry has no filing, so there is no business whose
                     * standing to report - only the person's own. Somebody
                     * whose shop is suspended but who is not blacklisted shows
                     * nothing here, and that is right: the finding is against
                     * the shop, and this row is not about a shop.
                     */
                    /*
                     * The person's own standing, not one business's: an
                     * enquiry is about no business at all, so the note reports
                     * the worst they hold and says how far it reaches
                     * [client, 1 October 2026].
                     */
                    'standing' => $this->ownerStanding($thread?->user ?? $owner),
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
                'unread_count' => $unread,
                'last_message_at' => optional($last?->created_at)->toISOString(),
                'can_message' => true,
            ]],
            'messages_count' => $count,
            'unread_count' => $unread,
            'last_message' => $last ? [
                'body' => $last->body,
                'sender_name' => $last->sender?->name,
                'mine' => $last->sender_user_id === $user->id,
                'created_at' => optional($last->created_at)->toISOString(),
            ] : null,
            'updated_at' => optional($last?->created_at ?? $thread?->updated_at)->toISOString(),
        ];
    }

    /**
     * Where the person writing to this office currently stands.
     *
     * ---- Why an officer is told at all --------------------------------
     *
     * "Paki lagyan din ng note sa other admin offices sa messages page kung
     * ang kumokontak sa kanya ay currently suspended, flagged, blacklisted"
     * [client, 30 September 2026].
     *
     * An office reading its mail cannot otherwise tell. A blacklisted owner
     * and a good-standing one write identical rows, and the reply an officer
     * would give differs: somebody barred from filing should not be told to
     * file, and somebody whose shop is flagged is a conversation worth
     * reading with the finding in mind. The note is the finding, not a
     * judgement about the person - so it says what is recorded and nothing
     * else.
     *
     * ---- Which finding wins ------------------------------------------
     *
     * BLACKLISTED against the PERSON first. It reaches every business they
     * hold and every business they register afterwards, so reporting one
     * shop's lesser standing over it would understate what the officer is
     * looking at.
     *
     * Then the BUSINESS this filing is for - blacklisted, suspended, flagged
     * - because the row is about that filing. Another of the owner's shops
     * being suspended is not this conversation's business, and saying so here
     * would have an officer raising a matter the applicant did not come about.
     *
     * `flagged` is included and is the gentlest of the three: it is a watch,
     * not a bar, and the client named it alongside the other two.
     *
     * @return array{kind: string, label: string}|null
     */
    private function counterpartyStanding(?User $person, ?Business $business): ?array
    {
        if ($person?->isBlacklisted() === true) {
            return [
                'kind' => 'blacklisted',
                'label' => 'Account blacklisted',
                // Moot on a blacklisting: the cascade sets every business the
                // person holds to `blacklisted`, so none of them is suspended
                // and a count here would read as zero and mean nothing.
                'suspended_count' => 0,
            ];
        }

        $found = match ($business?->status) {
            Business::STATUS_BLACKLISTED => ['kind' => 'blacklisted', 'label' => 'Business blacklisted'],
            'suspended' => ['kind' => 'suspended', 'label' => 'Business suspended'],
            'flagged' => ['kind' => 'flagged', 'label' => 'Business flagged'],
            default => null,
        };

        if ($found === null) {
            return null;
        }

        /*
         * How many of this person's businesses are suspended in all.
         *
         * "Pwede rin i-note doon na may isa, dalawa, ... syang business na
         * suspended" [client, 1 October 2026]. The note stays SPECIFIC to the
         * business this conversation is about - which is the same instruction,
         * first half - and this is the scale of it: an officer answering about
         * one suspended shopfront is better for knowing whether it is the only
         * one or the third.
         *
         * It does not change which note is shown. A row whose own business is
         * in good standing says nothing, however many of the owner's others
         * are suspended: that is not this conversation's business, and raising
         * it would have an officer answering a matter the applicant did not
         * come about.
         */
        return $found + ['suspended_count' => $this->businessStandings($person)['suspended'] ?? 0];
    }

    /**
     * Where this person stands with no one business in question.
     *
     * ---- Why a general enquiry needs its own answer ---------------------
     *
     * "Pag yung business is flagged, suspended, ipa-reflect din sa business
     * owner general enquiry, para alam ng other admin officer" [client, 1
     * October 2026].
     *
     * A filing's row names the business the conversation is about, so its note
     * is that business's standing. An enquiry is about no business at all, and
     * reported nothing but a blacklisting - so an office answering a question
     * from somebody with two suspended shopfronts had no sign of it, which is
     * exactly the reader this note was added for.
     *
     * With no business in question the answer is the WORST one they hold, and
     * the counts say how far it reaches. Blacklisting first, for the same
     * reason as anywhere else: it is against the person and covers everything.
     *
     * @return array{kind: string, label: string, suspended_count: int}|null
     */
    private function ownerStanding(?User $person): ?array
    {
        if ($person?->isBlacklisted() === true) {
            return ['kind' => 'blacklisted', 'label' => 'Account blacklisted', 'suspended_count' => 0];
        }

        $held = $this->businessStandings($person);

        $suspended = $held['suspended'] ?? 0;

        if (($held[Business::STATUS_BLACKLISTED] ?? 0) > 0) {
            return ['kind' => 'blacklisted', 'label' => 'Business blacklisted', 'suspended_count' => $suspended];
        }

        if ($suspended > 0) {
            return ['kind' => 'suspended', 'label' => 'Business suspended', 'suspended_count' => $suspended];
        }

        if (($held['flagged'] ?? 0) > 0) {
            return ['kind' => 'flagged', 'label' => 'Business flagged', 'suspended_count' => 0];
        }

        return null;
    }

    /**
     * How many businesses this person holds in each standing.
     *
     * ---- This was a per-status count, memoised, and both were wrong ------
     *
     * It counted suspensions alone, with a cache keyed by user id held in a
     * property on the controller - on the reasoning that an inbox is many rows
     * and few applicants, so the question is asked nine times and answered
     * once. The reasoning was right and the assumption under it was not: the
     * controller is NOT built fresh for every request the way that supposed.
     *
     * A test caught it by suspending a second business between two reads of
     * the same inbox and still being told there was one. The same staleness
     * reaches a long-lived worker - Octane, Swoole - where one instance serves
     * many requests and a count cached on it outlives the facts it counted. On
     * PHP-FPM it would have hidden until the day somebody changed the runtime.
     *
     * So it asks every time, and asks once for every standing rather than once
     * per standing: one grouped COUNT against a table of a few hundred is not
     * worth a cache that can lie. If this page ever does need the saving, the
     * honest place is a single query in threads() building the map for the
     * whole page - not a bag on the controller.
     *
     * @return array<string, int> keyed by status
     */
    private function businessStandings(?User $person): array
    {
        if ($person === null) {
            return [];
        }

        return $person->businesses()
            ->selectRaw('status, COUNT(*) AS n')
            ->groupBy('status')
            ->pluck('n', 'status')
            ->map(fn ($n) => (int) $n)
            ->all();
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
                    /*
                     * Where the person writing to this office currently stands
                     * [client, 30 September 2026]. Null for anybody in good
                     * standing, which is almost everybody.
                     */
                    'standing' => $this->counterpartyStanding($app->applicant, $app->business),
                ]
                // The office of the newest readable turn — taken from the row's
                // own office list rather than resolved again, so the title and
                // the picker cannot name two different offices.
                : $this->officeCounterparty($app, $newest['name'] ?? null),
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
            // Across every conversation on this filing the reader may open —
            // for an office that is its own single one. Summed from the office
            // rows so the row total and the per-office numbers can never
            // disagree.
            'unread_count' => (int) collect($offices)->sum('unread_count'),
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
     * @return list<array{department_id:int, code:?string, name:string, thread_id:?int, messages_count:int, unread_count:int, last_message_at:?string, can_message:bool}>
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
            ->map(function (Department $d) use ($app, $user, $addressable, $threads, $stats, $latest) {
                $thread = $threads->get($d->id);
                $stat = $thread ? $stats->get($thread->id) : null;

                return [
                    'department_id' => $d->id,
                    'code' => $d->code,
                    'name' => $d->name,
                    'thread_id' => $thread?->id,
                    'messages_count' => (int) ($stat->messages_total ?? 0),
                    'unread_count' => (int) ($stat->unread_total ?? 0),
                    'last_message_at' => $stat
                        ? optional($latest->get($thread->id)?->created_at)->toISOString()
                        : null,
                    /*
                     * Addressable, AND still this officer's case to answer.
                     *
                     * "Once na in-unassign na, magsstay pa rin ang convo pero
                     * di nya na ma-cha-chat, like for viewing na lang"
                     * [client, 30 September 2026]. The conversation is left
                     * where it was and the composer closes - writesTo() is the
                     * whole of that rule, and the same predicate refuses the
                     * POST, because a closed box in a browser is a suggestion.
                     *
                     * An applicant is never closed out of their own filing:
                     * writesTo() answers true for anybody without
                     * `application.view_all`.
                     */
                    'can_message' => $addressable->has($d->id)
                        && $this->writesTo($user, $app, $d->id),
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

    /**
     * Who the applicant is talking to: the OFFICE. Never a person.
     *
     * ── This reverses the old rule, on the client's instruction ─────────────
     *
     * It used to be the officer who wrote last, falling back to the office when
     * nobody had. "Sa Messages list, office name lang ang ipakita, while the
     * specific officer's name will only appear in the actual chat messages once
     * that officer responds." Two things were wrong with naming the person:
     *
     *  - it DRIFTS. The same conversation was called "Elena Bautista" on Monday
     *    and "Liza Reyes" on Thursday, because the name was whoever replied
     *    last. An applicant looking for the conversation they had yesterday had
     *    nothing stable to look for.
     *  - it promises a correspondent nobody has. An office is answerable for a
     *    filing, not a person; whoever happens to pick up the next reply is not
     *    who the applicant wrote to, and naming them invited "why is somebody
     *    else answering me".
     *
     * Nothing is hidden by this: every turn in the transcript still carries the
     * name of whoever wrote it and the office they answered for — see
     * MessageResource and the Bubble that renders it. The name appears where it
     * is a fact about a message rather than a claim about the conversation.
     *
     * `$activeOffice` is the office of the newest readable turn, resolved by
     * the caller from the same office rows the row itself carries, so the title
     * and the office list can never disagree.
     */
    private function officeCounterparty(Application $app, ?string $activeOffice): array
    {
        return [
            'name' => $activeOffice ?? $this->leadOffice($app),
            // Which of the applicant's filings this is about. The office is
            // already the name above, so repeating it here would print one fact
            // twice, two lines apart, looking like two.
            'subtitle' => $app->business?->name ?? $app->tracking_id,
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
        /*
         * Opening a conversation is reading it — there is no separate gesture.
         *
         * But only the one that is opened. With no office named this answers
         * every readable thread, and marking them all read cleared a CHO
         * reply the owner never saw: the panel's first request names no
         * office, then it settles on BPLO and shows BPLO's thread alone. So
         * an unnamed read marks only the thread of the office a message would
         * go to unnamed: the reader's own office, else BPLO — the default
         * resolveAddressee() writes to.
         */
        $shown = $requested !== null
            ? $threadIds
            : $threads->where('department_id', $user->department_id ?? $this->bplo()?->id)->pluck('id')->all();
        $this->markThreadsRead($shown, $user);
        /*
         * Scoped to the READER as well as to the thread.
         *
         * The thread was enough while an office had one conversation its whole
         * staff shared. An officer now reads the stretch that was theirs - the
         * messages written while they held the case [client, 30 September
         * 2026] - so the same filter the inbox counts through has to run here,
         * or the transcript would hand a successor the predecessor's
         * conversation that the row beside it says is empty.
         */
        $total = Message::whereIn('thread_id', $threadIds)
            ->tap(fn ($q) => $this->scopeMessagesToReader($q, $user))
            ->count();
        $messages = Message::query()
            ->whereIn('thread_id', $threadIds)
            ->tap(fn ($q) => $this->scopeMessagesToReader($q, $user))
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
                /*
                 * WHO HELD the filing for this office when the message was
                 * written - not who wrote it. An applicant's question carries
                 * the officer it was addressed to, which is what lets that
                 * officer keep reading it after the case moves on, and what
                 * keeps it out of their successor's screen.
                 *
                 * Null when the office holds the filing but nobody in it has
                 * picked the case up. Those messages belong to the office
                 * rather than to a person, and whoever claims it reads them -
                 * see scopeMessagesToReader().
                 */
                'handled_by_user_id' => $this->holderOf($application, $office->id),
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
         * Nobody to tell is a real state, not an impossible one: User is
         * soft-deletable, so an officer replying on the filing of a since-removed
         * account has nobody to notify, and an office can have no active
         * account at all. An empty list skips the ping — sending the message
         * must not 500 because the notification had nowhere to go.
         */
        foreach ($this->recipients($request, $application, $office) as $recipient) {
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

    // --- unread counts for the nav badge -------------------------------------

    /**
     * Restrict a `messages` query to the conversations this reader may open.
     *
     * scopeMessagesToReader answers the office half and deliberately returns
     * early for an applicant, because everywhere else it is called the filing
     * has already been established as theirs. Here nothing has: this counts
     * across the whole table, so the applicant's own half has to be spelled out
     * or the badge would count the city's mail.
     */
    private function scopeUnreadToReader($query, User $user): void
    {
        if ($user->hasPermission(ApplicationVisibility::VIEW_ALL)) {
            // -1 for a seat with no office: matches nothing, rather than
            // matching threads with a null department.
            $deptId = $user->department_id ?? -1;

            $query->whereExists(fn ($sub) => $sub->selectRaw('1')
                ->from('message_threads as ut')
                ->whereColumn('ut.id', 'messages.thread_id')
                ->where('ut.department_id', $deptId));

            /*
             * And only their own stretch of it. A badge counting messages the
             * reader cannot open is a badge that never clears: the successor
             * would be told the predecessor's conversation is waiting on them,
             * and find nothing on the screen it points at.
             */
            $query->where(fn ($who) => $who
                ->whereNull('messages.handled_by_user_id')
                ->orWhere('messages.handled_by_user_id', $user->id));

            return;
        }

        $query->whereExists(fn ($sub) => $sub->selectRaw('1')
            ->from('message_threads as ut')
            ->whereColumn('ut.id', 'messages.thread_id')
            ->where(function ($w) use ($user) {
                // Their own enquiry, or a conversation on a filing of theirs.
                $w->where('ut.user_id', $user->id)
                    ->orWhereExists(fn ($app) => $app->selectRaw('1')
                        ->from('applications')
                        ->whereColumn('applications.id', 'ut.application_id')
                        ->where('applications.applicant_user_id', $user->id));
            }));
    }

    /**
     * How many messages are waiting, and how many notifications.
     *
     * One call, because the nav draws both badges at once and two polls for two
     * numbers is two round trips on every screen.
     *
     * "Unread" is a message somebody ELSE sent that this reader has not opened.
     * Your own turn is never unread to you, which is why the sender is excluded
     * rather than the count being taken from the thread.
     */
    public function unreadSummary(Request $request): JsonResponse
    {
        $user = $request->user();

        $messages = Message::query()
            ->whereNull('messages.read_at')
            ->where('messages.sender_user_id', '!=', $user->id)
            ->tap(fn ($q) => $this->scopeUnreadToReader($q, $user))
            ->count();

        return response()->json([
            'data' => [
                'messages' => $messages,
                'notifications' => $user->notifications()->whereNull('read_at')->count(),
            ],
        ]);
    }

    /**
     * Mark everything the reader just saw as read.
     *
     * Called from the two transcript endpoints, because opening a conversation
     * IS reading it — there is no separate "mark read" gesture in the UI and a
     * badge that only ever counts up is worse than no badge.
     *
     * Only the other side's turns: marking your own read is meaningless, and
     * doing it would hide the fact that the office has not opened yours.
     */
    private function markThreadsRead(array $threadIds, User $user): void
    {
        if ($threadIds === []) {
            return;
        }

        Message::whereIn('thread_id', $threadIds)
            ->whereNull('read_at')
            ->where('sender_user_id', '!=', $user->id)
            ->update(['read_at' => now()]);
    }

    // --- the office's line to the System Administrator -----------------------

    /*
     * ── A third kind of thread ─────────────────────────────────────────────
     *
     * Office accounts cannot edit their own details any more: no Settings, no
     * "Edit your details". Their name, mobile number, office and role are the
     * super admin's to set, which is the shape an LGU actually runs on — the
     * officer directory is the record, and a record people can quietly edit
     * about themselves is not one [client, 28 September 2026].
     *
     * That only works if there is somewhere to ask. This is it: one standing
     * conversation between each office account and whoever holds `user.manage`.
     *
     * ── Why it needed no migration ─────────────────────────────────────────
     *
     * `message_threads` already carries the three columns that tell the kinds
     * apart, and this one is simply the combination nothing used yet:
     *
     *   application_id set              a conversation about a filing
     *   user_id + department_id set     a general enquiry to BPLO
     *   user_id set, department null    THIS: an officer and the super admin
     *
     * The super admin belongs to no office, which is exactly why a null
     * department is the honest way to address them rather than a sentinel id.
     */

    /**
     * Whose line to the administrator this is.
     *
     * Office accounts only, and `department_id` is the whole test: a business
     * owner has none (they message BPLO, which is what general enquiries are
     * for) and neither does the super admin, who would otherwise be opening a
     * conversation with themselves.
     */
    private function assertOfficeAccount(User $user): void
    {
        abort_if(
            $user->department_id === null,
            403,
            'This conversation is for office accounts. Business owners message the City BPLO instead.',
        );
    }

    /**
     * The administrator conversation belonging to one office account, created
     * on first sight.
     *
     * `firstOrCreate` on the triple, so two requests racing to open the same
     * conversation still produce one row — and so that a null department is
     * matched as `is null` rather than skipped, which is what Eloquent does
     * with a null in a where clause.
     */
    private function adminThreadFor(User $officer): MessageThread
    {
        return MessageThread::firstOrCreate(
            ['user_id' => $officer->id, 'kind' => MessageThread::KIND_ADMIN],
            ['department_id' => null, 'application_id' => null],
        );
    }

    /**
     * Who may read and write one: the officer it belongs to, or the super
     * admin.
     *
     * `user.manage` rather than a role name, because the seat is defined by
     * what it can do — and it is the one permission that already means "this
     * account administers the others".
     */
    private function authorizeAdminParticipant(User $reader, MessageThread $thread): void
    {
        $isOwner = $thread->user_id !== null && $thread->user_id === $reader->id;

        abort_unless(
            $isOwner || $reader->hasPermission('user.manage'),
            403,
            'This conversation is not yours to read.',
        );
    }

    /** No `{user}` means "mine"; naming somebody else is the super admin's act. */
    private function adminOwner(Request $request, ?User $owner): User
    {
        $officer = $owner ?? $request->user();
        $this->assertOfficeAccount($officer);

        return $officer;
    }

    public function adminIndex(Request $request, ?User $user = null): JsonResponse
    {
        $reader = $request->user();
        $thread = $this->adminThreadFor($this->adminOwner($request, $user));
        $this->authorizeAdminParticipant($reader, $thread);

        $this->markThreadsRead([$thread->id], $reader);

        $total = Message::where('thread_id', $thread->id)->count();
        $messages = Message::query()
            ->where('thread_id', $thread->id)
            ->with(['sender:id,name,department_id', 'attachments'])
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
                // No office on either side of this one. Said as null rather
                // than faked, so the transcript screen does not print an
                // office badge on a conversation that has none.
                'department_id' => null,
            ],
        ]);
    }

    public function adminStore(Request $request, ?User $user = null): JsonResponse
    {
        $reader = $request->user();
        $officer = $this->adminOwner($request, $user);
        $thread = $this->adminThreadFor($officer);
        $this->authorizeAdminParticipant($reader, $thread);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ], [
            'attachment.max' => 'The attachment may not be larger than 10MB.',
            'attachment.mimes' => 'Attach a PDF, JPG, or PNG file.',
        ]);

        $message = DB::transaction(function () use ($request, $reader, $thread, $data, $officer) {
            $message = Message::create([
                'thread_id' => $thread->id,
                'sender_user_id' => $reader->id,
                'body' => $data['body'],
            ]);

            if ($file = $request->file('attachment')) {
                $ext = $file->getClientOriginalExtension() ?: $file->guessExtension();
                $filename = Str::uuid()->toString().'.'.$ext;
                // Keyed by the officer, like the general thread is keyed by the
                // applicant: there is no filing to file it under.
                $dir = "private/messages/admin/{$officer->id}";
                Storage::disk('local')->putFileAs($dir, $file, $filename);

                $message->attachments()->create([
                    'path' => "{$dir}/{$filename}",
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getClientMimeType(),
                    'size_bytes' => $file->getSize(),
                ]);
            }

            $thread->touch();

            return $message->load(['sender:id,name,department_id', 'attachments']);
        });

        /*
         * Tell the other side.
         *
         * The officer's own turn notifies every super admin, because the seat
         * is what is being written to rather than a person; the super admin's
         * reply notifies the one officer. Without this an officer asking for a
         * name change would be waiting on a screen nobody had been told to
         * open.
         */
        $this->notifyAdminThread($reader, $officer, $message);

        return response()->json(['data' => new MessageResource($message)], 201);
    }

    /** The other side of an administrator conversation, told there is a turn. */
    private function notifyAdminThread(User $sender, User $officer, Message $message): void
    {
        $preview = Str::limit($message->body, 120);

        if ($sender->id === $officer->id) {
            $admins = User::whereHas('roles.permissions', fn ($q) => $q->where('name', 'user.manage'))
                ->where('is_active', true)
                ->get();

            foreach ($admins as $admin) {
                $this->notify->push(
                    $admin,
                    'message',
                    "{$officer->name} sent you a message",
                    $preview,
                    // The super admin's own prefix, and the page's real path:
                    // `/admin/messages` is the ordinary inbox.
                    '/admin/office-messages',
                );
            }

            return;
        }

        $this->notify->push(
            $officer,
            'message',
            'The System Administrator replied',
            $preview,
            '/messages',
        );
    }

    /**
     * Every office account's line to the administrator, for the super admin's
     * inbox.
     *
     * ── Why accounts and not threads ───────────────────────────────────────
     *
     * An officer who has never written has no thread, and that row IS the way
     * in — the super admin has to be able to start the conversation too, which
     * is how "your details have been updated" reaches the person who asked.
     * Listing threads alone would hide every officer who has not spoken yet,
     * which is most of them on the day this ships.
     */
    public function adminThreads(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            /*
             * `unread` only. There was a `talking` narrowing too - "officers
             * who have written" - and it earned its place on neither screen:
             * the list already sorts anybody who has written above everybody
             * who has not, so the filter hid rows without answering a question
             * the order had not already answered [client, 28 September 2026].
             */
            'narrow' => ['sometimes', 'nullable', 'in:unread'],
        ]);

        $reader = $request->user();

        $officers = User::whereNotNull('department_id')
            ->where('is_active', true)
            ->with('department:id,code,name')
            ->when($request->query('q'), fn ($q, $term) => $q->where(fn ($sub) => $sub
                ->where('name', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")))
            ->orderBy('name')
            ->get();

        // One query for every thread, not one per officer.
        $threads = MessageThread::where('kind', MessageThread::KIND_ADMIN)
            ->whereIn('user_id', $officers->pluck('id'))
            ->get()
            ->keyBy('user_id');

        $stats = Message::query()
            ->whereIn('thread_id', $threads->pluck('id'))
            ->selectRaw('thread_id, COUNT(*) as total, MAX(created_at) as last_at')
            ->selectRaw(
                'SUM(CASE WHEN read_at IS NULL AND sender_user_id != ? THEN 1 ELSE 0 END) as unread',
                [$reader->id],
            )
            ->groupBy('thread_id')
            ->get()
            ->keyBy('thread_id');

        $last = Message::query()
            ->whereIn('thread_id', $threads->pluck('id'))
            ->with('sender:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->unique('thread_id')
            ->keyBy('thread_id');

        $rows = $officers->map(function (User $officer) use ($threads, $stats, $last) {
            $thread = $threads->get($officer->id);
            $stat = $thread ? $stats->get($thread->id) : null;
            $newest = $thread ? $last->get($thread->id) : null;

            return [
                'user' => [
                    'id' => $officer->id,
                    'name' => $officer->name,
                    'email' => $officer->email,
                ],
                'office' => $officer->department ? [
                    'id' => $officer->department->id,
                    'code' => $officer->department->code,
                    'name' => $officer->department->name,
                ] : null,
                'thread_id' => $thread?->id,
                'messages_count' => (int) ($stat->total ?? 0),
                'unread_count' => (int) ($stat->unread ?? 0),
                'last_message_at' => optional($newest?->created_at)->toISOString(),
                'preview' => $newest
                    ? Str::limit($newest->body, 140)
                    : null,
                'last_sender' => $newest?->sender?->name,
            ];
        });

        if ($request->query('narrow') === 'unread') {
            $rows = $rows->filter(fn (array $row) => $row['unread_count'] > 0);
        }

        /*
         * Anybody who has written sorts above anybody who has not, newest
         * first. An alphabetical list would bury a question under thirty
         * officers who have never said anything.
         */
        $rows = $rows
            ->sortByDesc(fn (array $row) => $row['last_message_at'] ?? '')
            ->values();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'total' => $rows->count(),
                'unread_total' => $rows->sum('unread_count'),
                // Kept: the header line counts what is waiting, and a caller
                // may still want to know how many conversations exist at all.
                'talking' => $rows->filter(fn (array $r) => $r['messages_count'] > 0)->count(),
            ],
        ]);
    }

    /**
     * The pinned administrator row for an office account's own inbox.
     *
     * Returned even with nothing in it, because the row is the way in — the
     * whole point is that an officer who needs their name corrected can find
     * where to ask without being told where to look.
     *
     * @return array<string, mixed>|null
     */
    private function adminRow(User $user): ?array
    {
        if ($user->department_id === null) {
            return null;
        }

        $thread = MessageThread::where('kind', MessageThread::KIND_ADMIN)
            ->where('user_id', $user->id)
            ->first();

        $count = $thread ? Message::where('thread_id', $thread->id)->count() : 0;
        $unread = $thread
            ? Message::where('thread_id', $thread->id)
                ->whereNull('read_at')
                ->where('sender_user_id', '!=', $user->id)
                ->count()
            : 0;
        $newest = $thread
            ? Message::where('thread_id', $thread->id)->latest('id')->first()
            : null;

        return [
            'kind' => 'admin',
            'application_id' => null,
            'thread_id' => $thread?->id,
            'user_id' => $user->id,
            'tracking_id' => null,
            'business_name' => null,
            'status' => null,
            'counterparty' => [
                'name' => 'System Administrator',
                'subtitle' => 'Your account and details',
                'is_officer' => true,
            ],
            'responsible_office' => null,
            'offices' => [],
            'messages_count' => $count,
            'unread_count' => $unread,
            'preview' => $newest ? Str::limit($newest->body, 140) : null,
            'updated_at' => optional($newest?->created_at ?? $thread?->updated_at)->toISOString(),
        ];
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
    private function generalThreadFor(User $owner, ?int $departmentId = null): MessageThread
    {
        /*
         * BPLO when nobody names an office - which covers every client written
         * before an enquiry could have more than one addressee, and every
         * owner who simply does not know which office to ask.
         */
        $office = $departmentId !== null
            ? Department::find($departmentId)
            : $this->bplo();

        abort_unless(
            $office !== null,
            $departmentId !== null ? 404 : 503,
            $departmentId !== null
                ? 'There is no such office.'
                : 'The BPLO office is not set up on this system.'
        );

        /*
         * `(user_id, department_id)` is unique in the schema, so this is one
         * conversation per person per office - the same shape a filing's
         * thread has, and the reason an owner can hold several enquiries at
         * once without them running together.
         */
        return MessageThread::firstOrCreate([
            'user_id' => $owner->id,
            'department_id' => $office->id,
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
            && $this->readsThread($reader, $thread->department_id);

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

    /**
     * The office named on an enquiry request, from the query or the body.
     *
     * Both, because the same value arrives two ways: a GET carries it in the
     * query string and a POST in the form - and the POST is multipart when
     * there is an attachment, where a query string would be the odd one out.
     * `input()` reads either.
     *
     * An empty string is not an office id: it is a caller that sent the field
     * and left it blank, and `(int) ''` is 0, which would 404 on an office
     * nobody asked for instead of falling back to BPLO.
     */
    private function requestedOffice(Request $request): ?int
    {
        $value = $request->input('department_id');

        return ($value === null || $value === '') ? null : (int) $value;
    }

    public function generalIndex(Request $request, ?User $user = null): JsonResponse
    {
        $request->validate([
            // Which office's enquiry. Absent means BPLO, so a caller written
            // before offices had front doors keeps working untouched.
            'department_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $reader = $request->user();
        $owner = $this->generalOwner($request, $user);

        /*
         * Which office to open on, when the caller names none: BPLO.
         *
         * "Make it on messages page naka default lagi sa BPLO" [client, 1
         * October 2026]. It coordinates every filing and is the office you
         * write to when you do not know which office to ask.
         *
         * It used to answer with whichever office had spoken last, on the
         * reasoning that an applicant is coming back to the conversation they
         * were having. The screen now opens on BPLO regardless, and a server
         * that disagreed would be fetched twice for every visit — once for the
         * office that spoke last, once more when the picker settled — showing
         * one conversation and replacing it with another as the reader looked
         * at it.
         *
         * Nothing is hidden by it. Every office keeps its pill, and the pills
         * carry how much has been said to each.
         *
         * Only for the OWNER's own enquiry. An office opening somebody's
         * enquiry is opening its own conversation with them and may not read
         * another's, so the default there is the office itself.
         */
        $chosen = $this->requestedOffice($request);

        if ($chosen === null) {
            $chosen = $reader->id === $owner->id
                ? $this->bplo()?->id
                : $reader->department_id;
        }

        $thread = $this->generalThreadFor($owner, $chosen);
        $this->authorizeGeneralParticipant($reader, $thread);

        $this->markThreadsRead([$thread->id], $reader);
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
                /*
                 * Every office the reader may ask, so the pane draws the same
                 * picker a filing has [client, 30 September 2026]. An OFFICE
                 * reading somebody's enquiry gets its own and nothing else -
                 * the picker is a choice for the person who has one, and an
                 * office seat may only ever read its own conversation.
                 */
                'offices' => $reader->id === $owner->id
                    ? $this->enquiryOffices($owner)->all()
                    : [$this->generalOfficeRow($thread, $total)],
            ],
        ]);
    }

    public function generalStore(Request $request, ?User $user = null): JsonResponse
    {
        $reader = $request->user();
        $owner = $this->generalOwner($request, $user);
        $thread = $this->generalThreadFor($owner, $this->requestedOffice($request));
        $this->authorizeGeneralParticipant($reader, $thread);

        $data = $request->validate([
            'department_id' => ['sometimes', 'nullable', 'integer'],
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

        $this->notifyEnquiry($reader, $owner, $thread, $message);

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
     * Tell the other side an enquiry has moved.
     *
     * ---- Why this did not exist before, and has to now -------------------
     *
     * A general enquiry was BPLO's alone, and BPLO lives in that inbox: the
     * enquiry sat at the top of the one screen they read all day, so nothing
     * being sent was survivable. Every office has a front door now [client,
     * 28 September 2026], and an enquiry to CENRO lands in an inbox whose
     * whole purpose is that office's CASELOAD. Without a notification it
     * waits for somebody to notice it, which is another way of saying it
     * waits.
     *
     * A filing's message has notified since it existed (see store), and so
     * does the administrator's line. This is the third conversation in the
     * product and it was the one left silent.
     *
     * Both directions, because both are somebody waiting: the office wants to
     * know a question has arrived, and the owner wants to know it was
     * answered - and the owner has no inbox they are paid to watch.
     */
    private function notifyEnquiry(User $sender, User $owner, MessageThread $thread, Message $message): void
    {
        $preview = Str::limit($message->body, 120);
        $office = $thread->department;

        if ($sender->id !== $owner->id) {
            // The office replied. One person is waiting for that.
            $this->notify->push(
                $owner,
                'message',
                $office ? "{$office->name} replied" : 'Your enquiry was answered',
                $preview,
                '/messages',
            );

            return;
        }

        if ($office === null) {
            return;
        }

        /*
         * Everybody holding that office's seat, and only the active ones.
         *
         * Not "the officer in charge" - an enquiry has no filing and so nobody
         * is in charge of it, which is the whole reason it exists. The office
         * is the addressee, so the office is who is told.
         */
        $seats = User::where('department_id', $office->id)
            ->where('is_active', true)
            ->get();

        foreach ($seats as $seat) {
            $this->notify->push(
                $seat,
                'message',
                "{$owner->name} sent your office a question",
                $preview,
                // Their own prefix. An office account signs in at /staff.
                '/staff/messages',
            );
        }
    }

    /**
     * The single office row a general conversation carries.
     *
     * @return array<string, mixed>
     */
    private function generalOfficeRow(MessageThread $thread, int $count): array
    {
        // `loadMissing` rather than a bare read: the thread arrives from
        // firstOrCreate, which does not eager-load the office it just set.
        $thread->loadMissing('department');
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
        $user = $request->user();

        /*
         * An office that has been WRITTEN TO may open that filing's messages,
         * even though the filing was never routed to it.
         *
         * ApplicationVisibility::authorize asks "may you review this permit",
         * which is a question about routing, and it is the right question for
         * the review screens. It is the wrong one here now that an applicant
         * may write to any office: a message to the health office on a filing
         * routed only to BPLO was accepted, stored, and then refused to the
         * health office with "You are not a participant in this conversation."
         * — the applicant saw it sent and nobody could ever read it.
         *
         * Narrow on purpose. This opens the MESSAGES endpoint for an office
         * that holds a conversation on the filing, and nothing else: which
         * conversation it may then read is still readsThread(), so the health
         * office sees its own and not the fire office's, and the review sheet,
         * clearances and inspections are untouched.
         */
        if ($this->readsThread($user, $user->department_id)
            && $user->department_id !== null
            && $application->messageThreads()
                ->where('department_id', $user->department_id)
                ->exists()
        ) {
            return;
        }

        ApplicationVisibility::authorize(
            $user,
            $application,
            'You are not a participant in this conversation.'
        );
    }

    /**
     * Who is told that this message has arrived.
     *
     * Now that a message is addressed, the applicant's reply pings the office
     * they actually wrote to rather than whichever officer happened to have
     * been assigned most recently — sending a question to the fire office and
     * notifying the sanitary officer is the same defect as the shared thread,
     * one layer down.
     *
     * ---- When nobody in that office is holding the case ------------------
     *
     * This used to fall back to ANY officer on the filing, and failing that to
     * the applicant. Both were wrong in a way a tester could see: a question to
     * CHO pinged the BPLO officer, who is refused the CHO conversation, and an
     * owner writing before anyone had claimed the case was told "You have a
     * new message" about the message they had just sent. A deactivated holder
     * was told too, and nobody who could answer was.
     *
     * The rule now [Ken, 5 October 2026]: the officer holding the case for
     * THAT office, while their account is active; otherwise every active
     * account of that office, the same seats a general enquiry tells (see
     * notifyEnquiry()). Never another office, never the sender.
     *
     * @return Collection<int, User>
     */
    private function recipients(Request $request, Application $application, Department $office): Collection
    {
        $sender = $request->user();
        $application->loadMissing('applicant');

        // Officer sent → the applicant, while the account still exists.
        if ($sender->id !== $application->applicant_user_id) {
            return collect([$application->applicant])->filter()->values();
        }

        $holderId = $this->holderOf($application, $office->id);
        $holder = $holderId !== null ? User::find($holderId) : null;
        if ($holder !== null && $holder->is_active && $holder->id !== $sender->id) {
            return collect([$holder]);
        }

        return User::where('department_id', $office->id)
            ->where('is_active', true)
            ->whereKeyNot($sender->id)
            ->get()
            ->toBase();
    }

    /**
     * Who is holding this filing for this office right now, if anybody.
     *
     * The id alone, and one query: this is on the write path of every message
     * and wants neither the model nor its relations.
     */
    private function holderOf(Application $application, int $departmentId): ?int
    {
        return ApplicationAssignment::query()
            ->where('application_id', $application->id)
            ->where('department_id', $departmentId)
            ->value('officer_user_id');
    }

    /**
     * May this reader still WRITE in this office's conversation?
     *
     * "Kung ano lang ang naka assign sa kanya, yun lang ang pwede nyang
     * ma-chat. Once na in-unassign na, magsstay pa rin ang convo pero di nya
     * na ma-cha-chat, like for viewing na lang" [client, 30 September 2026].
     *
     * So holding the case is what grants the composer, and losing it leaves
     * the conversation legible and closed. An applicant is not an officer and
     * is never closed out of their own filing; an office with NOBODY holding
     * the case is open to whoever is in it, because somebody has to be able to
     * answer before anyone has claimed it.
     */
    private function writesTo(User $user, Application $application, int $departmentId): bool
    {
        if (! $user->hasPermission(ApplicationVisibility::VIEW_ALL)) {
            return true;
        }

        if ($user->department_id !== $departmentId) {
            return false;
        }

        $holder = $this->holderOf($application, $departmentId);

        return $holder === null || $holder === $user->id;
    }
}
