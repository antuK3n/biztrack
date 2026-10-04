<?php

namespace App\Services\Debug;

use App\Enums\ApplicationStatus;
use App\Enums\ApplicationType;
use App\Enums\ClearanceStatus;
use App\Enums\InspectionResult;
use App\Enums\InspectionStatus;
use App\Enums\OfficerRequestStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\IllegalTransitionException;
use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\ApplicationPermitType;
use App\Models\Inspection;
use App\Models\Payment;
use App\Models\Permit;
use App\Models\PermitType;
use App\Services\PaymentGateway;
use App\Services\WorkflowService;
use App\Support\Audit;
use App\Support\DebugPanel;
use App\Support\PermitFees;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The Debug page's "Move a filing along" section [Ken, 2026-10-04].
 *
 * During the thesis defense the team has to set a demo up in seconds: take a
 * filing and push it to the next stage without signing in as five offices
 * and clicking through each one's screen. This is that, and it is exactly as
 * safe as the real flow because it IS the real flow:
 *
 *   - every step calls the WorkflowService method the office's own button
 *     calls (AssignmentController::approve/return → approveAssignment /
 *     returnAssignment; InspectionController::schedule/conduct/reinspect →
 *     scheduleClearanceInspection / recordInspection / scheduleReinspection;
 *     PaymentController::pay's simulated branch → PaymentGateway::charge and
 *     onPaymentCompleted). Nothing here writes a status column;
 *   - when the service refuses, its refusal is what the page shows, and the
 *     step's writes are rolled back with it;
 *   - notifications, history rows and the service's own audit rows go out as
 *     they would from the office's screen.
 *
 * ── Who is recorded as having done it ────────────────────────────────────
 *
 * The super admin, acting for the office. Signing in as the office's real
 * officer would put a person's name on a status change, a permit and an audit
 * row for an act they never performed, which is the one thing a register must
 * not do. So `Auth::id()` stays the super admin: the status history, the
 * permit's `issued_by_user_id` and every audit row name them, and the
 * `debug.filing.*` row adds which office they acted for.
 *
 * Two places a press would normally stamp the presser are deliberately left
 * alone: the queue item's holder (`officer_user_id`, which
 * AssignmentController::recordHolder fills) and the visit's inspector
 * (InspectionController::conduct fills a blank one). Both name who is
 * responsible for the case FROM HERE ON. Writing the super admin there would
 * send the applicant's resubmission notice to the super admin instead of the
 * office, and answer the office's own officers 403 ("This filing is with
 * another officer") on a filing they are meant to pick up after the demo.
 *
 * ── What it cannot do, on purpose ────────────────────────────────────────
 *
 * The applicant's half. Every clearance carries an office form and a
 * documentary checklist (PermitType::OFFICE_FORM_CODES, refuseIncompleteChecklist),
 * so applying for one means answers and documents only the applicant has.
 * Inventing them would put a fabricated application in front of an office,
 * so a permit the applicant has not handed in is reported as what is holding
 * the filing, and the mover stops there. The same goes for submitting a
 * draft and resubmitting a returned filing.
 */
final class FilingMover
{
    /** BPLO presses Approve on its queue item; the service decides what that means. */
    public const BPLO_APPROVE = 'bplo_approve';

    public const BPLO_RETURN = 'bplo_return';

    public const PAYMENT = 'payment';

    /** An office presses Approve on its permit's queue item. */
    public const PERMIT_APPROVE = 'permit_approve';

    public const PERMIT_RETURN = 'permit_return';

    public const INSPECTION_BOOK = 'inspection_book';

    public const INSPECTION_PASS = 'inspection_pass';

    public const INSPECTION_FAIL = 'inspection_fail';

    public const INSPECTION_REBOOK = 'inspection_rebook';

    public const STEPS = [
        self::BPLO_APPROVE, self::BPLO_RETURN, self::PAYMENT,
        self::PERMIT_APPROVE, self::PERMIT_RETURN,
        self::INSPECTION_BOOK, self::INSPECTION_PASS, self::INSPECTION_FAIL, self::INSPECTION_REBOOK,
    ];

    /** Steps that act on one permit, and so name it. */
    public const PERMIT_STEPS = [
        self::PERMIT_APPROVE, self::PERMIT_RETURN,
        self::INSPECTION_BOOK, self::INSPECTION_PASS, self::INSPECTION_FAIL, self::INSPECTION_REBOOK,
    ];

    /** A return without a note tells the applicant nothing; the office screen requires one too. */
    public const NOTE_REQUIRED = [self::BPLO_RETURN, self::PERMIT_RETURN];

    /**
     * Where "Advance to" can take a filing. Ranked by `rank()` below.
     *
     * "Paid" and not "Business Permit released": on a NEW filing the permit is
     * released by the payment itself (WorkflowService::releaseOutcomePermit),
     * but a renewal or an amendment goes back to BPLO after paying and is
     * issued at the final approval. "Completed" is ApplicationStatus::Approved's
     * own label.
     */
    public const TARGETS = [
        'pending_payment' => 'Ready to pay',
        'paid' => 'Paid',
        'approved' => 'Completed',
    ];

    /*
     * A ceiling, not a budget. Five clearances at three office acts each plus
     * the form and the payment is seventeen; a loop that reaches forty is one
     * whose step stopped changing anything, and stopping beats spinning.
     */
    private const MAX_ADVANCE = 40;

    public function __construct(
        private WorkflowService $workflow,
        private PaymentGateway $gateway,
    ) {}

    // ── reading ─────────────────────────────────────────────────────────────

    /**
     * Everything the section shows about one filing: where it is, each permit,
     * what is holding it, the steps legal from here and where it can advance to.
     *
     * @return array<string, mixed>
     */
    public function describe(Application $app): array
    {
        $app = $this->load($app);
        $fee = PermitFees::balance($app);

        return [
            'id' => $app->id,
            'tracking_id' => $app->tracking_id,
            'type' => $app->application_type?->value,
            'type_label' => $app->application_type?->label(),
            'status' => $app->status?->value,
            'status_label' => $app->status?->label(),
            // Soft-deleted businesses and applicants leak nulls (AGENTS.md §11).
            'business' => $app->business?->name,
            'applicant' => $app->applicant?->fullName(),
            'submitted_at' => $app->submitted_at?->toIso8601String(),
            'fee' => [
                'assessed' => $app->feeAssessment !== null,
                'total_assessed' => number_format($fee['total_assessed'], 2, '.', ''),
                'total_paid' => number_format($fee['total_paid'], 2, '.', ''),
                'balance_due' => number_format($fee['balance_due'], 2, '.', ''),
            ],
            'permits' => $this->permitRows($app),
            'blockers' => $this->blockers($app),
            'steps' => $this->steps($app),
            /*
             * None at all when no forward step exists — a returned filing, or
             * every permit still with the applicant. A button that can only
             * answer "stopped after 0 steps" is one the page should not offer;
             * "What is holding it" already says why.
             */
            'targets' => $this->nextForward($app) === null ? [] : collect(self::TARGETS)
                ->filter(fn ($label, $to) => $this->targetApplies($app, $to))
                ->map(fn ($label, $to) => ['to' => $to, 'label' => $label, 'reached' => $this->reached($app, $to)])
                ->values()
                ->all(),
        ];
    }

    /**
     * The steps legal from where the filing stands, in the order the flow
     * takes them. "Legal" is the state machine's word (ApplicationStatus and
     * ClearanceStatus::allowedNext); the service may still refuse one for a
     * reason the state alone does not show — an open Other Requirement, say —
     * and then its refusal is the answer.
     *
     * @return list<array<string, mixed>>
     */
    public function steps(Application $app): array
    {
        $app = $this->load($app);
        $status = $app->status;
        $steps = [];

        // `isDecided()`, not `status->isTerminal()`: a paid filing gathering
        // its other permits wears `approved`, and the status alone would
        // report no steps for every live clearance stage.
        if ($status === null || $app->isDecided()
            || in_array($status, [ApplicationStatus::Draft, ApplicationStatus::Returned], true)) {
            return [];
        }

        $bplo = $this->assignmentFor($app, $this->workflow->bploDepartmentId());

        if ($status === ApplicationStatus::ForApproval && $bplo !== null) {
            $steps[] = $this->step($app, self::BPLO_APPROVE);
            $steps[] = $this->step($app, self::BPLO_RETURN);
        }

        if ($status === ApplicationStatus::PendingPayment) {
            $steps[] = $this->step($app, self::PAYMENT);
        }

        if ($this->inClearanceStage($app)) {
            foreach ($this->clearanceRows($app) as $row) {
                foreach ($this->rowSteps($app, $row) as $key) {
                    $steps[] = $this->step($app, $key, $row);
                }
            }
        }

        if ($status === ApplicationStatus::ForFinalApproval && $bplo !== null) {
            $steps[] = $this->step($app, self::BPLO_APPROVE);
        }

        return $steps;
    }

    /**
     * What is holding the filing, in the words the section shows. Not every
     * blocker stops "Advance to" — a refused permit on one office does not
     * stop another office's inspection — but each is something nobody on the
     * Debug page can do for the applicant.
     *
     * @return list<string>
     */
    public function blockers(Application $app): array
    {
        $app = $this->load($app);
        $status = $app->status;

        $filing = match (true) {
            $status === null => ['This filing has no status.'],
            $status === ApplicationStatus::Draft => ['The applicant has not submitted this filing yet.'],
            $status === ApplicationStatus::Returned => ['BPLO returned the form. It moves again when the applicant resubmits it.'],
            /*
             * Asked of the row. `approved` is both a filing the city has
             * finished with and one still gathering its other permits, and
             * only the first is stuck — the second has steps, which is why
             * the bare Approved arm that used to sit here (returning no
             * reason at all) has gone with it.
             */
            $app->isDecided() => ["This filing is {$app->statusLabel()}. Nothing moves it on."],
            default => null,
        };
        if ($filing !== null) {
            return $filing;
        }

        $blockers = [];

        if ($status === ApplicationStatus::ForApproval
            && $this->assignmentFor($app, $this->workflow->bploDepartmentId()) === null
            && ! $this->inClearanceStage($app)) {
            $blockers[] = 'BPLO has no queue item on this filing, so there is nothing for BPLO to press.';
        }

        if ($status === ApplicationStatus::PendingPayment && ($waiting = $this->onlinePaymentWaiting($app)) !== null) {
            $blockers[] = "An online payment ({$waiting->reference_number}) is waiting at KwikPay. "
                .'A simulated payment is refused until it is confirmed or set aside, so the owner cannot pay twice.';
        }

        if ($this->inClearanceStage($app)) {
            foreach ($this->clearanceRows($app) as $row) {
                $name = $row->permitType->name;
                $office = $row->permitType->department?->name ?? 'its office';
                $line = match ($row->status) {
                    ClearanceStatus::NotStarted => $row->mode === ApplicationPermitType::MODE_APPLY
                        ? "{$name}: the applicant opened the office form and has not handed it in."
                        : "{$name}: the applicant has not applied for it yet.",
                    ClearanceStatus::Returned => "{$name}: returned to the applicant. It moves again when they hand the form back.",
                    ClearanceStatus::Rejected => "{$name}: refused by {$office}. The applicant may apply again.",
                    ClearanceStatus::ForApproval => $this->renewalUpload($app, $row)
                        || $this->assignmentFor($app, $row->permitType->issuing_department_id) !== null
                        ? null
                        : "{$name}: {$office} has no queue item for it.",
                    default => null,
                };
                if ($line !== null) {
                    $blockers[] = $line;
                }
            }

            $open = $app->officerRequests()
                ->whereNotNull('requested_by_user_id')
                ->where('status', '!=', OfficerRequestStatus::Fulfilled->value)
                ->with('department')
                ->get();
            foreach ($open as $request) {
                $blockers[] = ($request->department?->name ?? 'An office')
                    ." is waiting on “{$request->title}” under Other Requirements.";
            }
        }

        return $blockers;
    }

    // ── acting ──────────────────────────────────────────────────────────────

    /**
     * Run one step and say what it did, or why it was refused.
     *
     * One transaction per step: a refusal anywhere inside it — the service's,
     * or this class's own when there is nothing for the office to press —
     * rolls back every write the step made, so a refused step leaves the
     * filing exactly as it was. The `debug.filing.<step>` audit row is written
     * after, refused or not, with the filing before and after.
     *
     * @param  'step'|'advance'  $via
     * @return array<string, mixed>
     */
    public function run(
        Application $app,
        string $step,
        ?string $permit,
        ?string $note,
        int $actorId,
        string $via = 'step',
    ): array {
        $app = $this->load($app);
        $before = $this->snapshot($app);
        $label = $this->label($app, $step, $permit === null ? null : $this->workflow->pivotFor($app, $permit));
        $refusal = null;
        $said = null;

        try {
            $said = DB::transaction(fn () => $this->perform($this->load($app), $step, $permit, $note));
        } catch (ValidationException $e) {
            $refusal = collect($e->errors())->flatten()->implode(' ');
        } catch (IllegalTransitionException $e) {
            $refusal = $e->getMessage();
        }

        $app = $this->load($app);
        $after = $this->snapshot($app);

        DebugPanel::audit(
            'filing.'.$step,
            $before,
            $after,
            $app,
            actorId: $actorId,
            extra: array_filter([
                'tracking_id' => $app->tracking_id,
                'acting_for' => $this->actingFor($step, $permit),
                'permit' => $permit,
                'note' => $note,
                'via' => $via,
                'refused' => $refusal,
            ], fn ($value) => $value !== null && $value !== ''),
        );

        return [
            'step' => $step,
            'permit' => $permit,
            'label' => $label,
            'ok' => $refusal === null,
            'refusal' => $refusal,
            'changes' => $refusal === null
                ? array_values(array_filter([...$this->changes($before, $after), $said]))
                : [],
        ];
    }

    /**
     * Run the legal forward steps in order until the filing reaches `$to`,
     * and stop at the first refusal or at the first thing only the applicant
     * can do.
     *
     * Forward only: it never returns a filing or fails an inspection, so
     * "Advance to Completed" cannot take a path the demo did not ask for.
     *
     * @return array{results: list<array<string, mixed>>, stopped: string|null, reached: bool}
     */
    public function advance(Application $app, string $to, int $actorId): array
    {
        $results = [];
        $stopped = null;

        for ($i = 0; $i < self::MAX_ADVANCE; $i++) {
            $app = $this->load($app);
            if ($this->reached($app, $to)) {
                break;
            }

            $next = $this->nextForward($app);
            if ($next === null) {
                $stopped = $this->blockers($app)[0] ?? 'Nothing more can be done from the Debug page.';
                break;
            }

            $result = $this->run($app, $next[0], $next[1], null, $actorId, 'advance');
            $results[] = $result;

            if (! $result['ok']) {
                $stopped = $result['refusal'];
                break;
            }
            if ($result['changes'] === []) {
                // A step the service accepted and that moved nothing would be
                // chosen again forever. Not expected; stopping says so.
                $stopped = $result['label'].' changed nothing, so the advance stopped there.';
                break;
            }
        }

        if ($stopped === null && ! $this->reached($this->load($app), $to)) {
            $stopped = 'Stopped after '.self::MAX_ADVANCE.' steps without reaching '.self::TARGETS[$to].'.';
        }

        return ['results' => $results, 'stopped' => $stopped, 'reached' => $this->reached($this->load($app), $to)];
    }

    /**
     * The service call behind each step. Returns an extra sentence for the
     * result when the step did something the before/after comparison cannot
     * see (a booked visit's date, a payment's reference), else null.
     */
    private function perform(Application $app, string $step, ?string $code, ?string $note): ?string
    {
        if ($step === self::PAYMENT) {
            return $this->pay($app);
        }

        if ($step === self::BPLO_APPROVE || $step === self::BPLO_RETURN) {
            $assignment = $this->assignmentFor($app, $this->workflow->bploDepartmentId())
                ?? $this->refuse('BPLO has no queue item on this filing, so there is nothing for BPLO to press.');

            $step === self::BPLO_APPROVE
                ? $this->workflow->approveAssignment($assignment)
                : $this->workflow->returnAssignment($assignment, (string) $note);

            return null;
        }

        $row = ($code === null ? null : $this->workflow->pivotFor($app, $code))
            ?? $this->refuse('This filing does not carry that permit.');
        $type = $row->permitType;
        $office = $type->department?->name ?? 'Its office';

        switch ($step) {
            case self::PERMIT_APPROVE:
            case self::PERMIT_RETURN:
                $assignment = $this->assignmentFor($app, $type->issuing_department_id)
                    ?? $this->refuse("{$office} has no queue item for the {$type->name} yet, so there is nothing for it to press.");

                $step === self::PERMIT_APPROVE
                    ? $this->workflow->approveAssignment($assignment)
                    : $this->workflow->returnAssignment($assignment, (string) $note);

                return null;

            case self::INSPECTION_BOOK:
                $visit = $this->workflow->scheduleClearanceInspection($row, now());

                return 'Inspection booked for '.$visit->scheduled_at->format('j M Y')
                    .($visit->inspector ? ', with '.$visit->inspector->fullName().' as inspector.' : '.');

            case self::INSPECTION_PASS:
            case self::INSPECTION_FAIL:
                $visit = $this->openVisit($app, $type->issuing_department_id)
                    ?? $this->refuse("{$office} has no inspection booked on this filing. Book one first.");

                $this->workflow->recordInspection(
                    $visit,
                    $step === self::INSPECTION_PASS ? InspectionResult::Passed : InspectionResult::Failed,
                    $note !== null && $note !== '' ? $note : null,
                );

                return null;

            case self::INSPECTION_REBOOK:
                $failed = $this->currentVisit($app, $type->issuing_department_id);
                if ($failed === null || ! $failed->canBeReinspected()) {
                    // InspectionController::reinspect's own refusal, word for word.
                    $this->refuse('A re-inspection can only be scheduled from an office’s most recent failed visit, '
                        .'while the application is still for inspection.');
                }
                $visit = $this->workflow->scheduleReinspection($failed, now());

                return 'Re-inspection booked for '.$visit->scheduled_at->format('j M Y').'.';
        }

        $this->refuse('That is not a step this page knows.');
    }

    /**
     * PaymentController::pay's simulated branch, whatever mode owners pay in.
     *
     * The guards are that endpoint's, in its order and its words, because the
     * shared thing is the rule and not the controller: the two must refuse the
     * same filings. They are copied rather than extracted because the
     * controller is being changed on another branch, and a refactor under it
     * is the merge nobody wants during a defense week.
     *
     * The amount is the balance the assessment says is due — never a number
     * chosen here — and an assessment that does not exist yet is raised the
     * same way the endpoint raises it. Recorded as a CARD payment: the one
     * method that exists only while payments are simulated, so the row reads
     * as simulated in the register as well as in its `gateway` column.
     */
    private function pay(Application $app): string
    {
        // BPLO's counter payment refuses a suspended or blacklisted business; so does this.
        $this->workflow->refuseWhileOnHold($app->business);

        $closed = in_array($app->status, [ApplicationStatus::Rejected, ApplicationStatus::Cancelled], true);

        if (! $closed && ! $app->status?->isBillable()) {
            $this->refuse('BPLO has not approved this application yet, so there is nothing to pay.');
        }

        $fee = $app->feeAssessment ?: $this->workflow->assessFees($app);
        $balanceDue = PermitFees::balance($app->fresh())['balance_due'];
        $awaitingFirstPayment = $app->status === ApplicationStatus::PendingPayment;

        if (! $awaitingFirstPayment && $closed) {
            $this->refuse('This application is closed, so there is nothing left to pay.');
        }
        if (! $awaitingFirstPayment && $balanceDue <= 0) {
            $this->refuse('This application has nothing outstanding.');
        }

        /*
         * The endpoint hands an online payment already waiting back to the
         * owner rather than opening a second. There is no owner here to hand
         * it to, and paying the bill again beside it is how somebody pays
         * twice — so it is refused, and named.
         */
        if (($waiting = $this->onlinePaymentWaiting($app)) !== null) {
            $this->refuse("An online payment ({$waiting->reference_number}) is already waiting at KwikPay for this filing. "
                .'It has to be confirmed or set aside first, so the owner cannot pay twice.');
        }

        $payment = $this->gateway->charge($fee, PaymentMethod::Card, $balanceDue);
        Audit::log('payment.completed', $payment, ['amount' => (string) $payment->amount]);

        $this->workflow->onPaymentCompleted($payment);

        return "Simulated payment {$payment->reference_number} of ₱".number_format((float) $payment->amount, 2).' recorded.';
    }

    // ── the forward path ────────────────────────────────────────────────────

    /**
     * The next step "Advance to" takes, as [step, permit code], or null when
     * only the applicant (or nobody) can move the filing from here.
     *
     * @return array{0: string, 1: string|null}|null
     */
    private function nextForward(Application $app): ?array
    {
        $status = $app->status;
        $bplo = $this->assignmentFor($app, $this->workflow->bploDepartmentId());

        if ($status === ApplicationStatus::ForApproval && $bplo !== null) {
            return [self::BPLO_APPROVE, null];
        }
        if ($status === ApplicationStatus::PendingPayment) {
            return [self::PAYMENT, null];
        }

        if ($this->inClearanceStage($app)) {
            foreach ($this->clearanceRows($app) as $row) {
                $forward = array_values(array_intersect(
                    $this->rowSteps($app, $row),
                    [self::PERMIT_APPROVE, self::INSPECTION_BOOK, self::INSPECTION_PASS, self::INSPECTION_REBOOK],
                ));
                if ($forward !== []) {
                    return [$forward[0], $row->permitType->code];
                }
            }
        }

        if ($status === ApplicationStatus::ForFinalApproval && $bplo !== null) {
            return [self::BPLO_APPROVE, null];
        }

        return null;
    }

    /**
     * The office's steps on one permit, given its state.
     *
     * @return list<string>
     */
    private function rowSteps(Application $app, ApplicationPermitType $row): array
    {
        $departmentId = $row->permitType->issuing_department_id;

        if ($row->status === ClearanceStatus::ForApproval) {
            // A renewal's uploaded copy is read by BPLO at final approval and
            // routes no office (WorkflowService::startClearance).
            return $this->assignmentFor($app, $departmentId) !== null
                ? [self::PERMIT_APPROVE, self::PERMIT_RETURN]
                : [];
        }

        if ($row->status === ClearanceStatus::ForInspection && $row->awaitingInspection()) {
            if ($this->openVisit($app, $departmentId) !== null) {
                return [self::INSPECTION_PASS, self::INSPECTION_FAIL];
            }
            $current = $this->currentVisit($app, $departmentId);

            return $current !== null && $current->failed()
                ? ($current->canBeReinspected() ? [self::INSPECTION_REBOOK] : [])
                : [self::INSPECTION_BOOK];
        }

        return [];
    }

    /**
     * Where the filing stands on the road "Advance to" walks. A renewal goes
     * from Pending Payment straight to For Final Approval, so both sit above
     * Paid; Rejected and Cancelled are off the road entirely.
     *
     * ── Why this takes the filing and not just its status ───────────────────
     *
     * Rank 3 was `AwaitingOtherPermits` until that status was removed on
     * 4 October 2026. A paid filing gathering its other permits now wears
     * `approved`, which is also what a FINISHED filing wears — so the status
     * alone would put a filing that has only just been paid at rank 5, and
     * "Advance to" would report every remaining stop as already reached. The
     * row says which of the two it is: see `Application::isDecided()`.
     */
    private function rank(Application $app): int
    {
        if ($app->status === ApplicationStatus::Approved) {
            return $app->isDecided() ? 5 : 3;
        }

        return match ($app->status) {
            ApplicationStatus::ForApproval => 1,
            ApplicationStatus::PendingPayment => 2,
            ApplicationStatus::ForFinalApproval => 4,
            default => 0,
        };
    }

    private function reached(Application $app, string $to): bool
    {
        // Finished, so everything on the road is behind it. Asked of the row
        // for the reason `rank()` gives: `approved` alone no longer means it.
        if ($app->isDecided()) {
            return true;
        }

        return $this->rank($app) >= match ($to) {
            'pending_payment' => 2,
            'paid' => 3,
            default => 5,
        };
    }

    /**
     * A filing that is never billed — a clearance-only renewal, an amendment
     * (Application::defersPayment) — has no Ready to pay or Paid to stop at.
     */
    private function targetApplies(Application $app, string $to): bool
    {
        if ($app->status?->isTerminal() && $app->status !== ApplicationStatus::Approved) {
            return false;
        }

        return $to === 'approved' || ! $app->defersPayment();
    }

    // ── pieces ──────────────────────────────────────────────────────────────

    /**
     * The clearance stage: paid, or a clearance-only renewal that is never
     * billed — the same test `startClearance` and ClearanceService::isUnlocked
     * ask. An amendment defers too, but has no clearances to work.
     */
    private function inClearanceStage(Application $app): bool
    {
        $status = $app->status;
        /*
         * `isDecided()` and not `status->isTerminal()`. A paid filing has
         * stood at `approved` since 4 October 2026 while it gathers its
         * other permits, so the status alone reads every live clearance
         * stage as finished and this returned false for all of them — the
         * Debug page offered no step on any paid filing at all.
         */
        if ($status === null || $app->isDecided()) {
            return false;
        }

        return $status->isPaid()
            || ($app->application_type === ApplicationType::Renewal && $app->defersPayment());
    }

    /** @return Collection<int, ApplicationPermitType> every permit but the business permit, in the flow's order */
    private function clearanceRows(Application $app): Collection
    {
        return $this->rows($app)->reject(fn (ApplicationPermitType $row) => $row->permitType->code === PermitType::OUTCOME_CODE)->values();
    }

    /** @return Collection<int, ApplicationPermitType> */
    private function rows(Application $app): Collection
    {
        $order = [PermitType::OUTCOME_CODE, ...PermitType::CLEARANCE_ORDER];

        return ApplicationPermitType::where('application_id', $app->id)
            ->with('permitType.department')
            ->get()
            ->sortBy(function (ApplicationPermitType $row) use ($order) {
                $at = array_search($row->permitType->code, $order, true);

                return $at === false ? 100 + $row->permit_type_id : $at;
            })
            ->values();
    }

    private function renewalUpload(Application $app, ApplicationPermitType $row): bool
    {
        return $app->application_type === ApplicationType::Renewal
            && $row->mode === 'upload';
    }

    /** @return list<array<string, mixed>> */
    private function permitRows(Application $app): array
    {
        $issued = Permit::where('application_id', $app->id)->get()->keyBy('permit_type_id');

        return $this->rows($app)->map(function (ApplicationPermitType $row) use ($app, $issued) {
            $type = $row->permitType;
            $assignment = $this->assignmentFor($app, $type->issuing_department_id);
            $visit = $type->code === PermitType::OUTCOME_CODE ? null : $this->currentVisit($app, $type->issuing_department_id);
            $permit = $issued->get($type->id);

            return [
                'code' => $type->code,
                'name' => $type->name,
                'office' => $type->department?->name,
                'office_code' => $type->department?->code,
                'status' => $row->status?->value,
                'status_label' => $row->status?->label(),
                'mode' => $row->mode,
                'queue' => $assignment?->status?->label(),
                'inspection' => $visit === null ? null : [
                    'status' => $visit->status?->value,
                    'result' => $visit->result?->value,
                    'label' => $visit->result?->label() ?? $visit->status?->label(),
                    'scheduled_at' => $visit->scheduled_at?->toIso8601String(),
                ],
                'permit_number' => $permit?->permit_number,
            ];
        })->all();
    }

    /** @return array<string, mixed> */
    private function step(Application $app, string $key, ?ApplicationPermitType $row = null): array
    {
        return [
            'key' => $key,
            'permit' => $row?->permitType->code,
            'label' => $this->label($app, $key, $row),
            'office' => $row?->permitType->department?->code
                ?? ($key === self::PAYMENT ? null : 'BPLO'),
            'note' => match ($key) {
                self::BPLO_RETURN, self::PERMIT_RETURN => 'required',
                self::INSPECTION_FAIL => 'optional',
                default => null,
            },
            'forward' => ! in_array($key, [self::BPLO_RETURN, self::PERMIT_RETURN, self::INSPECTION_FAIL], true),
        ];
    }

    /**
     * What a step is called, on its button and in the result. Every permit
     * step names its permit, so six "Approve" buttons are six different
     * accessible names (AGENTS.md §6.2).
     */
    private function label(Application $app, string $key, ?ApplicationPermitType $row): string
    {
        $office = $row?->permitType->department?->code ?? 'The office';
        $permit = $row?->permitType->name ?? 'permit';

        return match ($key) {
            self::BPLO_APPROVE => $app->status === ApplicationStatus::ForFinalApproval
                ? 'BPLO gives the final approval'
                : 'BPLO accepts the form',
            self::BPLO_RETURN => 'BPLO returns the form to the applicant',
            self::PAYMENT => 'Pay the bill, simulated',
            self::PERMIT_APPROVE => "{$office} accepts the {$permit} paperwork",
            self::PERMIT_RETURN => "{$office} returns the {$permit} to the applicant",
            self::INSPECTION_BOOK => "{$office} books the {$permit} inspection for today",
            self::INSPECTION_PASS => "{$office} records the {$permit} inspection passed",
            self::INSPECTION_FAIL => "{$office} records the {$permit} inspection failed",
            self::INSPECTION_REBOOK => "{$office} books a {$permit} re-inspection for today",
            default => $key,
        };
    }

    private function actingFor(string $step, ?string $code): ?string
    {
        if ($step === self::PAYMENT) {
            return 'applicant';
        }
        if ($code === null) {
            return 'BPLO';
        }

        return PermitType::where('code', $code)->first()?->department?->code;
    }

    private function assignmentFor(Application $app, ?int $departmentId): ?ApplicationAssignment
    {
        if ($departmentId === null) {
            return null;
        }

        return ApplicationAssignment::where('application_id', $app->id)
            ->where('department_id', $departmentId)
            ->first();
    }

    /** The office's latest visit on this filing — a failed one stays on the record. */
    private function currentVisit(Application $app, ?int $departmentId): ?Inspection
    {
        return $app->inspections()
            ->currentPerDepartment()
            ->where('department_id', $departmentId)
            ->with('inspector')
            ->first();
    }

    /*
     * Rescheduled counts as open: InspectionController::reschedule moves the
     * date on the same visit, and the office still conducts that visit.
     */
    private function openVisit(Application $app, ?int $departmentId): ?Inspection
    {
        $visit = $this->currentVisit($app, $departmentId);
        $open = [InspectionStatus::Scheduled, InspectionStatus::Rescheduled, InspectionStatus::InProgress];

        return $visit !== null && in_array($visit->status, $open, true)
            ? $visit
            : null;
    }

    /** PaymentController::inFlight: an online payment with somewhere to pay it, not set aside. */
    private function onlinePaymentWaiting(Application $app): ?Payment
    {
        return Payment::query()
            ->awaitingKwikPay()
            ->where('application_id', $app->id)
            ->whereNotNull('pay_url')
            ->whereNull('abandoned_at')
            ->latest('id')
            ->first();
    }

    /**
     * The filing as the audit row and the result compare it: its status, each
     * permit's, what has been paid, which certificates exist, each office's
     * latest visit.
     *
     * @return array<string, mixed>
     */
    private function snapshot(Application $app): array
    {
        $paid = (float) $app->payments()->where('status', PaymentStatus::Completed->value)->sum('amount');

        return [
            'status' => $app->status?->value,
            'permits' => $this->rows($app)
                ->mapWithKeys(fn (ApplicationPermitType $row) => [$row->permitType->code => $row->status?->value])
                ->all(),
            'paid' => number_format($paid, 2, '.', ''),
            'issued' => Permit::where('application_id', $app->id)->orderBy('id')->pluck('permit_number')->all(),
            'visits' => $app->inspections()
                ->currentPerDepartment()
                ->with('department')
                ->get()
                ->mapWithKeys(fn (Inspection $visit) => [
                    (string) ($visit->department?->code ?? $visit->department_id) => $visit->result?->value ?? $visit->status?->value,
                ])
                ->all(),
        ];
    }

    /**
     * What changed between two snapshots, as sentences.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<string>
     */
    private function changes(array $before, array $after): array
    {
        $lines = [];
        $statusLabel = fn (?string $value) => $value === null ? 'none' : (ApplicationStatus::tryFrom($value)?->label() ?? $value);
        $permitLabel = fn (?string $value) => $value === null ? 'not on the filing' : (ClearanceStatus::tryFrom($value)?->label() ?? $value);

        if ($before['status'] !== $after['status']) {
            $lines[] = 'Filing: '.$statusLabel($before['status']).' → '.$statusLabel($after['status']);
        }

        $names = PermitType::pluck('name', 'code');
        foreach ($after['permits'] as $code => $status) {
            $was = $before['permits'][$code] ?? null;
            if ($was !== $status) {
                $lines[] = ($names[$code] ?? $code).': '.$permitLabel($was).' → '.$permitLabel($status);
            }
        }

        if ($before['paid'] !== $after['paid']) {
            $lines[] = 'Paid: ₱'.number_format((float) $before['paid'], 2).' → ₱'.number_format((float) $after['paid'], 2);
        }

        foreach (array_diff($after['issued'], $before['issued']) as $number) {
            $lines[] = "Issued {$number}";
        }

        foreach ($after['visits'] as $office => $state) {
            if (($before['visits'][$office] ?? null) !== $state) {
                $lines[] = "{$office} inspection: ".(InspectionResult::tryFrom((string) $state)?->label()
                    ?? InspectionStatus::tryFrom((string) $state)?->label()
                    ?? $state);
            }
        }

        return $lines;
    }

    private function load(Application $app): Application
    {
        return $app->fresh(['business', 'applicant', 'feeAssessment']) ?? $app;
    }

    /**
     * Refuse in the service's own currency, so a refusal from here and one
     * from WorkflowService reach the page by the same road.
     */
    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['step' => [$message]]);
    }
}
