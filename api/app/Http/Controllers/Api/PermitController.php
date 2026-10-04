<?php

namespace App\Http\Controllers\Api;

use App\Enums\PermitStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PermitRegisterResource;
use App\Http\Resources\PermitResource;
use App\Models\ApplicationDocument;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\UnbilledPermitFee;
use App\Services\WorkflowService;
use App\Support\ApplicationVisibility;
use App\Support\OfficeFormAnswers;
use App\Support\PdfFile;
use App\Support\PermitFace;
use App\Support\QrCode;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Issued permits. Owners see their own (via business ownership); officers with
 * permit.view_all see all.
 */
class PermitController extends Controller
{
    private array $eager = ['permitType', 'business:id,name', 'application:id,tracking_id'];

    public function __construct(private WorkflowService $workflow) {}

    /**
     * What the register TABLE needs on top of the list payload.
     *
     * Kept apart from `$eager` because it is three joins and a JSON column
     * more, and every other caller of this resource — the owner's Profile, the
     * filing detail screen — is reading one permit or a handful. Loading the
     * office sheet for those would be work nobody asked for.
     *
     * `business` is re-listed (in registerEager(), as a closure) WITHOUT the
     * column restriction that `$eager` puts on it and WITH retired businesses:
     * `$eager` selects id and name only, and loads null for a soft-deleted one.
     *
     * Two entries for one relation do NOT merge, and the FIRST wins — proved
     * by a test that read `ban: null` off a row whose business has one. So
     * `registerEager()` drops the narrow entry before adding this wide one
     * rather than relying on order.
     */
    private array $registerEager = [
        // `business` itself is added by registerEager(), as a closure — it has
        // to reach retired (soft-deleted) businesses, which a string cannot say.
        'issuedBy:id,first_name,middle_name,last_name,suffix',
        'priorPermit:id,permit_number',
        'application.officeForms',
        /*
         * The filing itself, in full rather than the two columns `$eager`
         * takes. OfficeFormAnswers::derive reads the application's type, its
         * submitted_at and its business profile to work out the answers nobody
         * types; handed a two-column stub it would derive them from nulls and
         * the sheet would print blank boxes that the paper prints filled.
         */
        'application',
        'application.business.lines.psicCode',
        'application.business.address.barangay',
        'application.business.owner',
        /*
         * What the applicant uploaded, and which document types each permit
         * type asks for — together they say which uploads belong to THIS
         * permit's office [client, 4 October 2026: every requirement
         * submitted must show on the office's table]. Loaded here, not per
         * row, so a 25-row page is two queries rather than fifty.
         */
        'application.documents.documentType.permitTypes',
        // The office that asked, for a file sent in answer to a requirement.
        'application.documents.requestResponses.officerRequest:id,department_id,title',
        'permitType.documentTypes',
    ];

    /**
     * The columns the register may be ordered by, and the SQL behind each.
     *
     * A whitelist, not a passthrough: `orderBy($request->query('sort'))` would
     * take a column name from the query string straight into SQL. Everything
     * here is either a column on `permits` or a correlated subquery over a
     * relation, because two of the five sortable columns — the business name
     * and the permit type — live on other tables, and a join would multiply
     * rows on a hasMany the eager loads also touch.
     */
    private const SORTS = [
        'permit_number' => 'permits.permit_number',
        'status' => 'permits.status',
        'valid_from' => 'permits.valid_from',
        'valid_until' => 'permits.valid_until',
        'issued_at' => 'permits.issued_at',
        'ban' => '(select ban from businesses where businesses.id = permits.business_id)',
        'business' => '(select name from businesses where businesses.id = permits.business_id)',
        'permit_type' => '(select name from permit_types where permit_types.id = permits.permit_type_id)',
        'tracking_id' => '(select tracking_id from applications where applications.id = permits.application_id)',
    ];

    /**
     * Issued permits. Paginated, newest issuance first.
     *
     * Two things were wrong here. Unpaged it returned 4,122 rows and 1.7 MB —
     * every permit ever issued, on the request that renders "my permits".
     *
     * And it returned all 4,122 to *every office reviewer*, not just BPLO: the
     * gate was the bare `permit.view_all`, which the RBAC seeder grants to
     * sanitary, fire, zoning, OBO, CENRO and the market office alike. Measured
     * on the live register, a market administrator could list 2 applications and
     * 4,122 permits. `permit.view_all` gets the same reading `application.view_all`
     * already got in checklist item 56 — "filings other than my own, in the
     * offices I am routed to" — because the alternative is that the office
     * boundary holds on the filing and falls over on its outcome.
     *
     * ── `q` and `status`, added for the register-wide permit table ───────────
     *
     * Issue #103 asks for "a page listing ALL approved permits as a table". On
     * the live register that is 2,182 active rows inside 5,475 issued ones, and
     * a pager alone does not make that findable: an administrator holding a
     * permit number is 40 pages away from it and has no way to ask.
     *
     * Both filters are applied AFTER `scopeToReader`, never instead of it. A
     * search that reached outside the reader's scope would turn the office
     * boundary into a query string — the same leak §10 of AGENTS.md records
     * ("a sanitary officer saw 115 filings of which 38 were theirs"), reached
     * by typing rather than by a bug.
     *
     * `q` matches the permit number, the business name and the filing's
     * tracking ID, and the browser's label says so in those words. The three
     * identifiers are the three things an administrator is ever handed over a
     * counter (AGENTS.md §11 on renewal identification), and a search box that
     * silently matched only one of them would read as missing data.
     *
     * `status` takes one value, not a comma-separated list the way
     * /applications does. A permit has five states and they are not stages of
     * one flow, so there is no "a stage is more than one status" case here to
     * answer; when one appears, copy that endpoint's parser rather than
     * inventing a second syntax.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            // Validated against the enum rather than a hand-written `in:` list:
            // PermitStatus gained Superseded on 9 September 2026 and a literal
            // list here would have started rejecting a status the register was
            // already writing.
            'status' => ['sometimes', 'nullable', Rule::enum(PermitStatus::class)],
            /*
             * The office, named by its permit type code rather than by a
             * department id. A permit belongs to an office THROUGH the
             * certificate it is - CENRO issues the CEC, BFP the FSIC - and
             * `permits` carries `permit_type_id`, not a department. Filtering
             * by department would mean a join that answers the same question
             * one step further away.
             *
             * `exists` against the register, so a code the City removes stops
             * being offered instead of quietly returning nothing: MARKET was a
             * permit type until 6 September 2026 and a hand-written list here
             * would still accept it.
             */
            'permit_type' => ['sometimes', 'nullable', 'string', 'exists:permit_types,code'],
            /*
             * Every office BUT this one — "other permits in a separate view"
             * (checklist item 18). BPLO's own table is the Mayor's Permit; the
             * five clearances other offices issue are a second view, and
             * asking for them as one set needs a negative filter, not five
             * requests.
             */
            'exclude_permit_type' => ['sometimes', 'nullable', 'string', 'exists:permit_types,code'],
            /*
             * Retired businesses (checklist item 21) — ones removed from the
             * register, whose certificates stay on it. `hide` drops them,
             * `only` lists nothing else, `include` both. Absent means no
             * filtering, which is what this endpoint always answered; the
             * register page sends `hide` by default.
             */
            'retired' => ['sometimes', 'nullable', Rule::in(['hide', 'include', 'only'])],
            'sort' => ['sometimes', 'nullable', Rule::in(array_keys(self::SORTS))],
            'dir' => ['sometimes', 'nullable', Rule::in(['asc', 'desc'])],
            /*
             * "Which of mine lapse soon" — the one question an office asks of
             * its own certificates that no other control answers. A window in
             * DAYS rather than a date, because that is how the question is
             * asked at a counter ("this month", "the next 90 days"), and the
             * answer changes every midnight if it is stored as a date.
             *
             * Capped at a year: beyond that it selects the whole register and
             * reads as a filter that did nothing.
             */
            'expiring_within' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365'],
            /*
             * Issuance window, for the report an office is asked for at the
             * end of a month. Both ends optional — "everything since March" is
             * as ordinary a question as a closed range.
             */
            'issued_from' => ['sometimes', 'nullable', 'date'],
            'issued_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:issued_from'],
            /*
             * The full register row, for the administrator's table. Off by
             * default: every other caller wants the contracted payload, and
             * this one costs four more eager loads and the office sheet.
             */
            'detail' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $detail = $request->boolean('detail');

        $query = Permit::with($detail ? $this->registerEager() : $this->eager);
        $this->scopeToReader($request, $query);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($code = $request->query('permit_type')) {
            $query->whereHas('permitType', fn ($t) => $t->where('code', $code));
        }

        if ($except = $request->query('exclude_permit_type')) {
            $query->whereHas('permitType', fn ($t) => $t->where('code', '!=', $except));
        }

        /*
         * `whereHas('business')` honours the soft-delete scope, so it is
         * exactly "the business is still on the register"; `onlyTrashed` is
         * exactly the retired ones. Applied after scopeToReader like every
         * other filter here, so it can only ever narrow what a reader sees.
         */
        match ($request->query('retired')) {
            'hide' => $query->whereHas('business'),
            'only' => $query->whereHas('business', fn ($b) => $b->onlyTrashed()),
            default => null,
        };

        /*
         * Lapsing inside the window. Two conditions, and the second is the one
         * that makes it an answer rather than a date comparison:
         *
         *  - `valid_until` between today and today + N, so a certificate that
         *    lapsed LAST month is not "expiring in 30 days" — it has expired,
         *    and the status pill beside this is where that is asked; and
         *  - the permit is still live. A superseded certificate inside its own
         *    term is not the one in force, so listing it as about to lapse
         *    would send an office chasing a renewal that has already happened.
         *
         * Dates, not datetimes: `valid_until` is a DATE column, and comparing
         * it against `now()` would drop everything expiring today.
         */
        if ($days = $request->integer('expiring_within')) {
            $query->whereNotNull('valid_until')
                ->whereBetween('valid_until', [
                    now()->startOfDay()->toDateString(),
                    now()->startOfDay()->addDays($days)->toDateString(),
                ])
                ->whereIn('status', [PermitStatus::Active->value, PermitStatus::Suspended->value]);
        }

        /*
         * The issuance window. `issued_at` is a datetime and these are dates,
         * so the upper bound takes the whole of its day — `issued_to=2026-09-24`
         * meaning "up to and including the 24th" is what anybody typing it
         * intends, and a bare comparison would silently exclude everything
         * issued after midnight on the last day of the range.
         */
        if ($from = $request->query('issued_from')) {
            $query->where('issued_at', '>=', Carbon::parse($from)->startOfDay());
        }

        if ($to = $request->query('issued_to')) {
            $query->where('issued_at', '<=', Carbon::parse($to)->endOfDay());
        }

        /*
         * -- What `q` matches, and why it grew --------------------------------
         *
         * It was permit number, business name and tracking ID - the three
         * things an administrator is handed over a counter. The register table
         * now leads with the BAN and prints the owner and the permit type, so
         * all of those are searchable too: a box that shows a value it will
         * not match makes a correct query look like missing data, which is the
         * same reasoning that put the original three in.
         *
         * Still applied AFTER scopeToReader, never instead of it. A search
         * that reached outside the reader's scope would turn the office
         * boundary into a query string - the leak AGENTS.md section 10
         * records, reached by typing rather than by a bug.
         */
        if ($q = $request->query('q')) {
            $query->where(function ($sub) use ($q) {
                $sub->whereLike('permit_number', "%{$q}%")
                    ->orWhereHas('business', fn ($b) => $b
                        ->whereLike('name', "%{$q}%")
                        ->orWhereLike('ban', "%{$q}%")
                        ->orWhereHas('owner', fn ($o) => $o
                            ->whereLike('first_name', "%{$q}%")
                            ->orWhereLike('last_name', "%{$q}%")))
                    ->orWhereHas('application', fn ($a) => $a->whereLike('tracking_id', "%{$q}%"))
                    ->orWhereHas('permitType', fn ($t) => $t
                        ->whereLike('name', "%{$q}%")
                        ->orWhereLike('code', "%{$q}%"));
            });
        }

        /*
         * Ordering. The default is unchanged - newest issuance first - so a
         * request that names no sort gets exactly the order this endpoint has
         * always answered in.
         *
         * `sort` is resolved THROUGH self::SORTS rather than passed to
         * orderBy, and orderByRaw is safe for that same reason: the string is
         * a constant this file owns, picked by a key the validator has already
         * checked against that array. Nothing from the request reaches SQL.
         *
         * The id tiebreak stays on every path. issued_at is nullable on legacy
         * rows, and equal keys without a tiebreak shuffle between pages - a
         * reader paging the register then sees one row twice and another not
         * at all.
         *
         * A blank sorts as the lowest value: first ascending, last descending.
         * That is what SQLite always did with NULL, and it is stated here
         * because PostgreSQL does the reverse by default — a permit from the
         * old register has no tracking ID, and after an import there are
         * thousands of them to land at the wrong end of the table.
         */
        $sort = $request->query('sort');
        $dir = $request->query('dir') === 'asc' ? 'asc' : 'desc';
        $nulls = $dir === 'asc' ? 'nulls first' : 'nulls last';

        if ($sort !== null && isset(self::SORTS[$sort])) {
            $query->orderByRaw(self::SORTS[$sort].' '.$dir.' '.$nulls);
        } else {
            $query->orderByRaw('issued_at desc nulls last');
        }

        $permits = $query->orderByDesc('id')->paginate($this->perPage($request));

        $resource = $detail ? PermitRegisterResource::class : PermitResource::class;

        /*
         * ── What the owner owes but has not been billed for ──────────────
         *
         * A clearance renewed out of season is ISSUED AND NOT BILLED, by
         * rule, and until now the only screen that said so was the admin
         * Owners page. The applicant was handed a certificate, asked for no
         * money — which reads as paid — and met the fee months later on a
         * January bill they had no reason to expect.
         *
         * This is their own permits page, which is where they look at those
         * certificates, so it is where the outstanding amount belongs.
         *
         * Owners only. An officer reading the register is looking at many
         * businesses and "what YOU owe" is meaningless to them;
         * `permit.view_all` is the same test the query above scopes by, so
         * the two cannot disagree about who is being served.
         */
        $meta = $this->pageMeta($permits);
        $reader = $request->user();

        if ($reader !== null && ! $reader->hasPermission('permit.view_all')) {
            $owed = UnbilledPermitFee::query()
                ->whereHas('business', fn ($b) => $b->where('owner_user_id', $reader->id))
                ->outstanding()
                /*
                 * Rows worth nothing are not shown to the applicant.
                 *
                 * `recordAmendmentFee` deliberately writes a ₱0 row until
                 * BPLO names an amendment fee, so the line appears on the
                 * January bill the day a figure exists. That is right for
                 * the BILL and wrong here: the first run of this screen
                 * showed the owner "Mayor's / Business Permit … ₱0.00"
                 * under a heading about fees due, which is a debt that is
                 * not one. Caught by the E2E snapshot, not by reasoning.
                 */
                ->whereRaw('(amount + surcharge + interest) > 0')
                ->with('permitType')
                ->orderBy('incurred_at')
                ->get();

            /*
             * An empty list and a zero, never omitted keys: a screen has to
             * tell "nothing owed" from "not loaded", and a missing key reads
             * as the second.
             */
            $meta['unbilled_fees'] = [
                'total' => round($owed->sum(
                    fn ($f) => (float) $f->amount + (float) $f->surcharge + (float) $f->interest
                ), 2),
                'items' => $owed->map(fn ($f) => [
                    'permit_type' => $f->permitType?->name,
                    'amount' => (float) $f->amount,
                    'surcharge' => round((float) $f->surcharge + (float) $f->interest, 2),
                    'months_late' => (int) $f->months_late,
                    'incurred_at' => optional($f->incurred_at)->toDateString(),
                ])->values(),
            ];
        }

        return response()->json([
            'data' => $resource::collection($permits->items()),
            'meta' => $meta,
        ]);
    }

    /**
     * The clearances this applicant SUBMITTED A COPY OF, across every filing.
     *
     * This endpoint exists because of one client sentence: "when you submit a
     * sub-permit instead of apply, since it is assuming that you have one
     * already, just also display it in the Profile page, along with the other
     * permits." Until now the copy was reachable from exactly one screen — the
     * clearance stage of the filing it was uploaded to — and that stage only
     * unlocks while the filing is a draft, so once the applicant submitted the
     * application their own certificate effectively vanished from the site.
     *
     * READ THE SHAPE BEFORE RENDERING IT. None of this is a permit and the
     * payload is deliberately built so that no caller can mistake it for one:
     *
     *  - `id` is an ApplicationDocument id, not a Permit id. It is NOT a key
     *    into /permits/{id}, and there is nothing at /permits/{id}/pdf for it.
     *  - there is no permit_number, no valid_from/valid_until, no
     *    days_until_expiry and no verify_url, because the City did not issue
     *    this document and has not recorded a validity for it. Inventing any of
     *    those would put a fabricated legal instrument on the applicant's
     *    Profile. The certificate face is already careful about this (see
     *    certificateData below on why signatories are data, never literals);
     *    this is the same rule one step earlier.
     *  - `filename` and `submitted_at` describe the applicant's own upload, and
     *    that is the whole of what the register knows about it.
     *
     * A held copy is an ApplicationDocument carrying `permit_type_id` — see
     * App\Support\HeldPermits for why the ordinary document table is the
     * mechanism. `whereNotNull('permit_type_id')` is therefore exactly the set
     * of held clearances and nothing else; every ordinary documentary
     * requirement leaves that column null.
     *
     * Scoped on `applicant_user_id` alone, and NOT on business ownership the
     * way the issued-permit list above is. That is not an oversight: the file
     * behind each row is served by DocumentController::download, whose gate is
     * ApplicationVisibility::canView, and the only applicant-side branch that
     * grants is `applicant_user_id === user->id`. Listing rows on a wider
     * predicate than the download accepts would put links on Profile that
     * answer 403 — a row the register shows you and then refuses to hand over
     * is worse than a row it never claimed you had.
     */
    public function held(Request $request): JsonResponse
    {
        $documents = ApplicationDocument::query()
            ->whereNotNull('permit_type_id')
            ->whereHas('application', fn ($a) => $a->where('applicant_user_id', $request->user()->id))
            ->with([
                'permitType:id,code,name',
                'application:id,tracking_id,status,business_id',
                // Soft-deleted businesses stay off the eager load by default, so
                // this comes back null on a filing whose business was removed —
                // the same shape the issued-permit list carries, answered the
                // same way by the browser ("Business removed from register").
                'application.business:id,name',
            ])
            // Newest upload first, matching the issued list's newest-first order
            // so the two blocks on Profile do not read in opposite directions.
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $documents->map(fn (ApplicationDocument $doc) => [
                'id' => $doc->id,
                'permit_type' => $doc->permitType ? [
                    'code' => $doc->permitType->code,
                    'name' => $doc->permitType->name,
                ] : null,
                'filename' => $doc->original_filename,
                'size_bytes' => (int) $doc->size_bytes,
                'submitted_at' => optional($doc->created_at)->toISOString(),
                'download_url' => url("/api/v1/documents/{$doc->id}/download"),
                'business' => $doc->application?->business ? [
                    'id' => $doc->application->business->id,
                    'name' => $doc->application->business->name,
                ] : null,
                'application' => $doc->application ? [
                    'id' => $doc->application->id,
                    'tracking_id' => $doc->application->tracking_id,
                    'status' => $doc->application->status?->value,
                ] : null,
            ])->values(),
        ]);
    }

    /**
     * One permit, plus the certificate block.
     *
     * The screen that renders a permit is a picture of the paper certificate,
     * so it needs the same fields the PDF prints — owner, address, line of
     * business, signature block — and PermitResource carries none of them; it
     * is the list row shape, shared with five other screens that want it small.
     * Rather than widen that for one consumer, the extra fields ride alongside
     * it under their own key, the way AuthController::userPayload adds
     * `created_at` to UserResource.
     *
     * Same array feeds `pdf()`, deliberately: the client asked for a download
     * that looks like what was on screen, and two renderers reading two field
     * sets is how those drift apart.
     */
    public function show(Request $request, Permit $permit): JsonResponse
    {
        $this->authorizeView($request, $permit);
        $permit->load($this->eager);

        return response()->json([
            'data' => (new PermitResource($permit))->resolve()
                + ['certificate' => $this->certificateData($permit)],
        ]);
    }

    /** dompdf permit certificate (CITY OF MALABON header, QR data-URI). */
    /**
     * BPLO lifts a suspension on a business permit.
     *
     * ── The discretion half of the LGU's rule ────────────────────────────────
     *
     * *"can be suspended if the other permits applied to were rejected"* — the
     * suspension itself fires automatically the moment an office refuses a
     * permit, so that nothing slips through a queue nobody opened that morning.
     * This is how a person overrules it: an office that refused in error, or a
     * refusal BPLO judges not to bear on the business permit.
     *
     * Behind `permit.issue`, which BPLO and the super admin hold. The same
     * authority that mints a certificate is the one that decides it may trade
     * while a clearance is unsettled; an office reviewer cannot reach it, and
     * neither can the owner.
     *
     * The refusal is NOT cleared — see `WorkflowService::liftOutcomeSuspension`
     * for why BPLO lifting a suspension is not BPLO granting another office's
     * permit.
     */
    public function liftSuspension(Request $request, Permit $permit): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ], [
            'reason.required' => 'Say why the suspension is being lifted. This is audited.',
        ]);

        $this->workflow->liftOutcomeSuspension($permit, $data['reason']);

        return response()->json([
            'data' => new PermitResource($permit->fresh()->load($this->eager)),
        ]);
    }

    /**
     * Revoke a permit (checklist item 23). Behind `permit.revoke` on the
     * route, which BPLO and the five clearance offices hold. See
     * `WorkflowService::revokePermit` for what may be revoked and what the act
     * writes.
     *
     * And only by the office that ISSUED it [client, 4 October 2026: "yung
     * cert na nirerelease ng office na yon sya lang pwede mag revoke, sa side
     * ng bplo mayors permit lang"]. BPLO reads every office's certificates but
     * revokes only the Mayor's Permit; CHO revokes its Sanitary Permits, and so
     * on. The super admin belongs to no office and revokes nothing. Refused
     * here rather than only hidden on screen, so a direct request is refused too.
     *
     * Answers with the register row rather than the contracted payload, so the
     * table that sent the request can redraw the row with its revocation
     * columns filled without a second round trip.
     */
    public function revoke(Request $request, Permit $permit): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ], [
            'reason.required' => 'Say why this permit is being revoked. The owner is told, and it is audited.',
        ]);

        $issuer = $permit->permitType?->issuing_department_id;
        abort_unless(
            $issuer !== null && (int) $issuer === (int) $request->user()->department_id,
            403,
            'Only the office that issued this permit can revoke it.',
        );

        $this->workflow->revokePermit($permit, $data['reason']);

        return response()->json([
            'data' => new PermitRegisterResource($permit->fresh()->load($this->registerEager())),
        ]);
    }

    public function pdf(Request $request, Permit $permit): Response
    {
        $this->authorizeView($request, $permit);

        $cert = $this->certificateData($permit);
        $verifyUrl = $cert['verify_url'];

        $pdf = Pdf::loadView('pdf.permit', $cert + [
            'qr' => QrCode::svgDataUri($verifyUrl),
        ]);

        /*
         * The Mayor's Permit prints on US LETTER, landscape — 11 inches wide
         * by 8.5 tall, which is the pad the City cuts [client, 4 October
         * 2026]. Its fields are set for that width: three boxes across for the
         * date, area and headcount, and the fee line in one row. Portrait
         * wraps them into a column that reads nothing like the paper beside
         * it, and A4 landscape is 20mm longer and 13mm shorter, so a sheet
         * printed on it sits wrong in the same folder.
         *
         * The clearances stay on portrait A4. They are a single column of
         * fields, and landscape would strand them in the left third.
         */
        // CENRO's certificate is landscape too, as its issued sheet is.
        if (($cert['is_business_permit'] ?? false) || ($cert['is_cenro_certificate'] ?? false)) {
            $pdf->setPaper('letter', 'landscape');
        } elseif (($cert['is_fsic'] ?? false) || ($cert['is_zoning'] ?? false)) {
            // The BFP's FSIC and the CPDO's Zoning Clearance are portrait
            // Letter sheets, as issued.
            $pdf->setPaper('letter', 'portrait');
        }

        // Render once: a second ->output() corrupts the font streams (see PdfFile).
        $file = PdfFile::render($pdf);

        $path = "private/permits/{$permit->id}.pdf";
        Storage::disk('local')->put($path, $file->content);
        if ($permit->pdf_path !== $path) {
            $permit->update(['pdf_path' => $path]);
        }

        return $file->download("permit-{$permit->permit_number}.pdf");
    }

    /**
     * Everything the certificate face prints, from the filing behind it.
     *
     * Two things are load-bearing here.
     *
     * `business` is nullable. `Business` soft-deletes and its permits stay on
     * the register, so `$permit->business` comes back null on an issued permit
     * whose business was removed — this is the same shape that took three
     * officer screens down (see RemovedBusinessRenderingTest). Every read below
     * is null-safe, and `business_name` answers null rather than inventing a
     * name, so the caller can say "removed from register" instead of blank.
     *
     * The signature block is data, never a literal. Officeholders rotate; a
     * name compiled into a view keeps printing someone who left the post until
     * somebody redeploys. Two sources feed it, and neither is a view: the
     * Mayor and the issuing officer come frozen off the permit itself
     * (`PermitFace::signatureBlock`), and the office's own names come from
     * office_signatories. A role with no name still prints as a ruled line —
     * an empty line is honest, a guessed name is not.
     *
     * @return array<string, mixed>
     */
    private function certificateData(Permit $permit): array
    {
        /*
         * `load`, not `loadMissing`. `$this->eager` has already put a
         * `business:id,name` on this model by the time `show()` gets here, and
         * loadMissing would see the relation as present and leave it — two
         * selected columns, no owner_user_id, so `business.owner` never loads
         * and the owner's name on the certificate comes back null. Reloading
         * costs one query on a single-row read.
         */
        $permit->load([
            'permitType.department.signatories',
            'business.address.barangay',
            'business.owner',
            'business.lines.psicCode',
            // `payments` for the receipt line the paper forms print, and the
            // assessment for CENRO's share of it — see the sheet blocks below.
            'application.payments',
            'application.feeAssessment',
            // The FSIC prints its own sheet's answers (occupancy, storeys).
            'application.officeForms',
            // For the signatory fallback on permits frozen before the face
            // carried one — see PermitFace::forPrinting.
            'issuedBy',
        ]);

        /*
         * Both locals are gone with the inline face: `PermitFace` reads the
         * business itself for the live fallback, so holding a copy here only
         * invited the next field to be assembled beside the snapshot instead
         * of inside it.
         */
        $face = PermitFace::forPrinting($permit);

        /*
         * The Mayor and the issuing officer first, then whatever the office
         * configured for itself.
         *
         * The two are not alternatives. `office_signatories` holds the extra
         * names a particular office prints — CENRO's Evaluator and Chief, read
         * off their actual form — and those offices still print them. What the
         * client asked for on 1 October 2026 is that every certificate also
         * carry the Mayor and the officer in charge, which no office had
         * configured and which is not an office-level fact anyway: the officer
         * is whoever signed THIS permit.
         *
         * Dedupe on the role, frozen name winning, so an office that configures
         * its own "City Mayor" row does not put the same caption on the sheet
         * twice. The frozen one wins because it names who signed this permit,
         * where the configured one names whoever holds the post today.
         */
        $block = PermitFace::signatureBlock($face);
        $taken = array_map('strtolower', array_column($block, 'role'));

        $office = $permit->permitType?->department?->signatories
            ?->where('is_active', true)
            ->sortBy([['sort_order', 'asc'], ['role', 'asc']])
            ->map(fn ($s) => ['role' => $s->role, 'name' => $s->name])
            ->reject(fn (array $s) => in_array(strtolower($s['role']), $taken, true))
            ->values()
            ->all() ?? [];

        $signatories = [...$block, ...$office];

        /*
         * ── The Mayor's Permit signs twice, not three times ─────────────────
         *
         * The City's form has two ruled lines: the Mayor on the left and the
         * Licensing Officer, captioned OIC-BPLO, on the right. Our block adds
         * a third — the per-filing "Officer-in-Charge" frozen at issue — and
         * on this certificate that is wrong twice over. It is not on the
         * paper, and the Mayor's Permit is released at PAYMENT, so on most
         * filings no officer holds it yet and the line printed empty.
         *
         * Dropped only where OIC-BPLO is there to take its place. An LGU that
         * has not named one keeps the per-filing officer rather than losing a
         * signature altogether.
         */
        if ($permit->permitType?->code === PermitType::OUTCOME_CODE) {
            $hasOic = collect($signatories)
                ->contains(fn (array $s) => strtolower($s['role']) === 'oic-bplo');

            if ($hasOic) {
                $signatories = array_values(array_filter(
                    $signatories,
                    fn (array $s) => strtolower($s['role']) !== 'officer-in-charge',
                ));
            }
        }

        /*
         * ── CENRO's certificate signs once ──────────────────────────────────
         *
         * The Certificate of Environment Clearance the office issues carries
         * one signature: the Chief, CENRO [client, 4 October 2026]. Not the
         * Mayor, not the reviewing officer, not the Evaluator the office also
         * keeps on file for its application form. So this sheet keeps only
         * the Chief's row and drops the rest.
         *
         * Falls back to whatever the block held when no Chief row exists —
         * a certificate with no signature line at all is worse than one
         * signed by the officer who reviewed it.
         */
        if ($permit->permitType?->code === 'CEC') {
            $chief = array_values(array_filter(
                $signatories,
                fn (array $s) => str_contains(strtolower($s['role']), 'chief'),
            ));

            if ($chief !== []) {
                $signatories = $chief;
            }
        }

        /*
         * ── The Mayor's Permit is a different sheet ─────────────────────────
         *
         * The City's own form, photographed at the BPLO counter [client,
         * 4 October 2026], asks for seven things no clearance does: the
         * Business Account Number and the Mayor's Permit Number in their own
         * boxes at the head, the floor AREA and the number of EMPLOYEES beside
         * the date of issue, and the amount paid with its OR number and date
         * along the fee line.
         *
         * They are gathered only for that permit type. A Sanitary Permit has
         * no amount paid of its own — the fee is assessed once, against the
         * filing — so printing "AMOUNT PAID" on a clearance would attach the
         * business permit's receipt to a document it did not buy.
         *
         * Every one is nullable and every one prints blank rather than absent.
         * The paper has a ruled box for each, and a counter clerk fills what
         * the system does not know; a certificate that silently drops a row is
         * harder to read against the paper than one with an empty line.
         */
        $code = $permit->permitType?->code;
        $isBusinessPermit = $code === PermitType::OUTCOME_CODE;
        $isCenroCertificate = $code === 'CEC';
        $isFsic = $code === 'FSIC';
        $isZoning = $code === 'ZONING';

        /*
         * ── The Zoning Clearance signs once, as the CPDO's form does ────────
         *
         * One line: the City Planning & Development Coordinator / Zoning
         * Administrator [client, 5 October 2026, with the issued sheet]. The
         * name is the CPDO's office_signatories row whose role names either
         * post, and a blank ruled line until the office sets one.
         */
        if ($isZoning) {
            $administrator = collect($office)->first(fn (array $s) => str_contains(strtolower($s['role']), 'zoning administrator')
                || str_contains(strtolower($s['role']), 'coordinator'));

            $signatories = [[
                'role' => "City Planning & Dev't Coordinator / Zoning Administrator",
                'name' => $administrator['name'] ?? null,
            ]];
        }

        /*
         * ── The FSIC signs as the BFP's own form does ───────────────────────
         *
         * BFP-QSF-FSED-005 carries two lines and no Mayor: RECOMMEND APPROVAL
         * by the Chief, Fire Safety Enforcement Section, and APPROVED by the
         * City Fire Marshal [client, 4 October 2026, with the issued sheet].
         * Both captions are fixed by the form; the NAMES come from the BFP's
         * office_signatories rows when it has them, and print as a blank ruled
         * line until it does — an empty line is honest, a guessed name is not.
         */
        if ($isFsic) {
            $named = fn (string $needle) => collect($office)
                ->first(fn (array $s) => str_contains(strtolower($s['role']), $needle))['name'] ?? null;

            $signatories = [
                ['role' => 'Chief, Fire Safety Enforcement Section', 'name' => $named('enforcement'), 'action' => 'Recommend Approval'],
                ['role' => 'City Fire Marshal', 'name' => $named('marshal'), 'action' => 'Approved'],
            ];
        }

        /*
         * The settled payment, not the latest. A filing can carry an
         * abandoned online order beside the one that actually paid, and the
         * OR number on a certificate has to be the one the money arrived
         * under. Both office sheets print it, so it is found once.
         */
        $paid = $permit->application?->payments
            ?->whereNotNull('paid_at')
            ->sortBy('paid_at')
            ->last();

        $sheetFields = [];

        if ($isBusinessPermit) {
            $profile = $permit->application?->fee_profile ?? [];

            $sheetFields = [
                'ban' => $permit->business?->ban,
                'area_sqm' => isset($profile['floor_area_sqm']) && $profile['floor_area_sqm'] !== null
                    ? rtrim(rtrim(number_format((float) $profile['floor_area_sqm'], 2), '0'), '.').' sq m'
                    : null,
                'employees' => isset($profile['employees']) && $profile['employees'] !== null
                    ? (string) (int) $profile['employees']
                    : null,
                'amount_paid' => $paid ? '₱'.number_format((float) $paid->amount, 2) : null,
                'or_number' => $paid?->reference_number,
                'date_paid' => optional($paid?->paid_at)->format('F j, Y'),
            ];
        }

        /*
         * ── CENRO's Certificate of Environment Clearance ────────────────────
         *
         * Laid out from the sheet the office issues [client, 4 October 2026].
         * Besides the face it prints a receipt block — Official Receipt,
         * Amount Paid, Date Paid, Application Control No. — and its own
         * letterhead.
         *
         * AMOUNT PAID here is CENRO's share, not the filing's total. The
         * assessment is one bill across every office on the filing, and each
         * line carries the office it is charged for; the certificate for one
         * office should state what that office charged. Summing the CENRO
         * lines does that. It can be ₱0.00 — the environmental fee schedules
         * in A10-2016 bill per trip and per unit, and a filing with none of
         * those owes CENRO nothing — and ₱0.00 is the honest figure, where
         * the filing total would attribute the business permit's fees to a
         * clearance that did not charge them.
         *
         * Application Control No. is the tracking ID: it is the one number
         * the applicant, the office and this system all call the filing by.
         */
        if ($isCenroCertificate) {
            $lines = collect($permit->application?->feeAssessment?->line_items ?? []);
            $cenroShare = $lines
                ->filter(fn ($l) => strtoupper((string) ($l['office'] ?? '')) === 'CENRO')
                ->sum(fn ($l) => (float) ($l['amount'] ?? 0));

            $sheetFields = [
                'office_amount_paid' => $paid ? '₱'.number_format($cenroShare, 2) : null,
                'or_number' => $paid?->reference_number,
                'date_paid' => optional($paid?->paid_at)->format('F j, Y'),
                'letterhead' => config('biztrack.letterheads.CENRO'),
            ];
        }

        /*
         * ── The BFP's Fire Safety Inspection Certificate ────────────────────
         *
         * Laid out from the issued certificate [client, 4 October 2026]. What
         * it needs beyond the face, all from what the system already holds:
         *
         *   - which certificate: always For Business Permit (New/Renewal) —
         *     see the note on `$purpose` below;
         *   - the description line: occupancy, floor area and storeys, from the
         *     sheet and the fee profile, printed only as far as they are known;
         *   - the Fire Code fee: the BFP's own line(s) of the assessment, not
         *     the filing's total, with the OR number and date it was paid under
         *     (the same reasoning as CENRO's share above).
         *
         * The FSIC NO. is the permit number this system issued, and the
         * tracking ID stands where the paper prints its control number.
         */
        if ($isFsic) {
            $application = $permit->application;
            $saved = $application?->officeForms?->firstWhere('permit_type_id', $permit->permit_type_id);
            $sheet = $application
                ? OfficeFormAnswers::derive($application, 'FSIC', is_array($saved?->form_data) ? $saved->form_data : [])
                : [];

            /*
             * Always FOR BUSINESS PERMIT (NEW/RENEWAL) [client, 5 October
             * 2026: "For Business Permit (New/Renewal) dapat"]. This system
             * issues the FSIC as a clearance for the Mayor's Permit, so that
             * is the box it ticks — even on a filing that also carries an
             * Occupancy Permit, whose sheet's "Certificate Applied For" reads
             * "FSIC for Certificate of Occupancy".
             */
            $purpose = 'business';

            $profile = $application?->fee_profile ?? [];
            $area = isset($profile['floor_area_sqm']) && $profile['floor_area_sqm'] !== null
                ? rtrim(rtrim(number_format((float) $profile['floor_area_sqm'], 2), '0'), '.')
                : null;
            $storeys = trim((string) ($sheet['building_storeys'] ?? ''));
            $occupancy = trim((string) ($sheet['occupancy_type'] ?? ''));

            $description = collect([
                $area !== null ? "occupying approx. {$area} sq m floor area" : null,
                $storeys !== '' ? "of a {$storeys}-storey building" : null,
                $occupancy !== '' ? "utilized as {$occupancy}" : null,
            ])->filter()->implode(' ');

            $bfpShare = collect($application?->feeAssessment?->line_items ?? [])
                ->filter(fn ($l) => strtoupper((string) ($l['office'] ?? '')) === 'BFP')
                ->sum(fn ($l) => (float) ($l['amount'] ?? 0));

            $sheetFields = [
                'fsic_purpose' => $purpose,
                'fsic_others' => null,
                'fsic_valid_for' => 'Issuance of FSIC for Business Permit only',
                'fsic_description' => $description !== '' ? $description : null,
                'office_amount_paid' => $paid ? '₱'.number_format($bfpShare, 2) : null,
                'or_number' => $paid?->reference_number,
                'date_paid' => optional($paid?->paid_at)->format('F j, Y'),
                'letterhead' => config('biztrack.letterheads.BFP'),
            ];
        }

        /*
         * ── The CPDO's Zoning Clearance (For Business Permit) ───────────────
         *
         * Laid out from the issued sheet [client, 5 October 2026]: a boxed
         * block naming the business, its type of establishment, address, the
         * ZONING PERMIT NO. and date issued, and the DECISION, then the four
         * standing conditions. The number is this system's permit number and
         * the type of establishment is the line of business on the face.
         * Only the office's letterhead is added here; the rest is the face.
         */
        if ($isZoning) {
            $sheetFields = [
                'letterhead' => config('biztrack.letterheads.ZONING'),
            ];
        }

        return [
            // Which sheet to draw. The views branch on these rather than on the
            // permit type's name, which is a label and may be reworded.
            'is_business_permit' => $isBusinessPermit,
            'is_cenro_certificate' => $isCenroCertificate,
            'is_fsic' => $isFsic,
            'is_zoning' => $isZoning,
            ...$sheetFields,
            'permit_number' => $permit->permit_number,
            'permit_type_name' => $permit->permitType?->name ?? 'Permit',
            'department_name' => $permit->permitType?->department?->name,
            'status_label' => $permit->status?->label(),
            /*
             * ── The face as it was SIGNED, not as the register reads today ───
             *
             * These seven were assembled here from the live business record, so
             * every edit to a business rewrote every certificate it had ever
             * held — including, after an amendment, five clearances describing
             * premises their offices had never seen. See
             * `permits.issued_details` for the full argument.
             *
             * `PermitFace` is the one builder, shared with the issuance that
             * writes the snapshot, so the frozen face and a live fallback
             * cannot drift into two different shapes.
             */
            ...$face,
            'tracking_id' => $permit->application?->tracking_id,
            'valid_from' => optional($permit->valid_from)->format('F j, Y'),
            'valid_until' => optional($permit->valid_until)->format('F j, Y'),
            'signatories' => $signatories,
            'verify_url' => rtrim((string) config('app.frontend_url'), '/').'/verify/'.$permit->permit_number,
        ];
    }

    /**
     * Narrow a permit query to what this reader may see.
     *
     * Owner: permits of businesses they own. BPLO / super admin: the register.
     * Every other office reviewer: permits issued off filings their office was
     * routed to — the same boundary ApplicationVisibility draws, reached through
     * the permit's application.
     */
    private function scopeToReader(Request $request, $query): void
    {
        $user = $request->user();

        if (ApplicationVisibility::readsEveryOffice($user)) {
            return;
        }

        if (! $user->hasPermission('permit.view_all')) {
            $query->whereHas('business', fn ($b) => $b->where('owner_user_id', $user->id));

            return;
        }

        /*
         * Two ways in, and the office branch is narrower than the filing.
         *
         * An office reviewer reaches a permit only if it was issued by THEIR
         * office — see ApplicationVisibility::readsPermitOf. Scoping to the
         * filing alone handed every office on a six-clearance filing all six
         * certificates.
         *
         * The department comparison is inside the same branch as the filing
         * check rather than applied to the whole query, because the owner
         * branch beside it must stay untouched: an applicant reads their own
         * certificates regardless of which office issued them.
         *
         * A reviewer with no department matches nothing — `where(col, null)`
         * is never true in SQL — which is the fail-closed posture scope() takes.
         */
        $query->where(function ($sub) use ($user) {
            $sub->whereHas('business', fn ($b) => $b->where('owner_user_id', $user->id))
                ->orWhere(fn ($office) => $office
                    ->whereHas('application', fn ($a) => ApplicationVisibility::scope($a, $user))
                    ->whereHas('permitType', fn ($t) => $t->where('issuing_department_id', $user->department_id))
                );
        });
    }

    /**
     * Read one permit. Same boundary as the list — a 403 on the list that a
     * direct id read walks around is not a boundary, and `/permits/{id}/pdf`
     * carries the owner's name and street address, which the public verify
     * endpoint deliberately does not.
     */
    private function authorizeView(Request $request, Permit $permit): void
    {
        $user = $request->user();

        if ($permit->business && $permit->business->owner_user_id === $user->id) {
            return;
        }
        if (ApplicationVisibility::readsEveryOffice($user)) {
            return;
        }

        $permit->loadMissing(['application', 'permitType']);
        $ok = $user->hasPermission('permit.view_all')
            && $permit->application
            && ApplicationVisibility::canView($user, $permit->application)
            // ...and issued by this reader's own office. The filing check above
            // is the coarse one — every office routed to a six-clearance filing
            // passes it, which is how a CHO session came to be able to download
            // a BFP certificate. See readsPermitOf.
            && ApplicationVisibility::readsPermitOf($user, $permit->permitType?->issuing_department_id);

        abort_unless($ok, 403, 'This permit is not yours.');
    }

    /**
     * The eager loads for one register row.
     *
     * Not `array_merge($this->eager, $this->registerEager)`. Eloquent does not
     * merge two entries for the same relation and the FIRST one wins, so
     * merging left `business:id,name` in front of `business:id,name,ban` and
     * every row answered `ban: null` — the column was never selected. A test
     * caught it; this drops the narrow entries the wide list replaces.
     *
     * @return array<int, string>
     */
    private function registerEager(): array
    {
        $replaced = ['business', 'application'];

        $base = array_values(array_filter(
            $this->eager,
            fn (string $relation) => ! in_array(explode(':', $relation)[0], $replaced, true),
        ));

        return array_merge($base, $this->registerEager, [
            /*
             * Retired businesses included. A retired business is one removed
             * from the register (soft-deleted); its certificates stay, and the
             * default scope loaded `null` in its place, so the table printed
             * "Business removed from register" and nothing else. The Retired
             * filter (checklist item 21) now decides whether those rows are
             * listed at all — and when they are, they name the business and
             * say it is retired, which is what a reader asking for them wants.
             */
            'business' => fn ($b) => $b->withTrashed()->select('id', 'name', 'ban', 'deleted_at'),
        ]);
    }
}
