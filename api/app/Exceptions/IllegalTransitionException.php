<?php

namespace App\Exceptions;

use App\Enums\ApplicationStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A caller tried to move a filing somewhere `ApplicationStatus::allowedNext()`
 * does not go.
 *
 * This is thrown, not returned, and that is the point. The bug it exists to
 * close (INS-5) was silent: an office approved its review, the API answered
 * 200, and a rejected filing quietly became `for_inspection` with a site visit
 * booked against it. Nobody was told, because nothing refused. A refusal that
 * logs a warning and carries on would be the same defect with better manners.
 *
 * 409 rather than 422: the request is well-formed and the caller is authorised
 * — `AssignmentController::approve` has already checked the department and the
 * route has already checked `application.review`. What is wrong is the state of
 * the filing at the moment the request arrives, which is exactly what Conflict
 * means. A 422 would send the frontend looking for a bad field.
 *
 * `render()` lives here rather than in the service so WorkflowService stays
 * free of HTTP concerns, the same convention the comment on
 * scheduleReinspection() states: "this codebase guards in controllers and keeps
 * services free of HTTP concerns." Laravel calls this automatically.
 */
class IllegalTransitionException extends RuntimeException
{
    public function __construct(
        public readonly ?ApplicationStatus $from,
        public readonly ApplicationStatus $to,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * The message an officer reads.
     *
     * It names the filing's current status, because the officer's screen may
     * have been open since before somebody else rejected it — "you cannot do
     * that" without saying what changed underneath them is how a real bug gets
     * reported as a mystery.
     */
    public static function refuse(?ApplicationStatus $from, ApplicationStatus $to): self
    {
        $fromLabel = $from?->label() ?? 'no status';

        return new self($from, $to, sprintf(
            'This application is %s, so it cannot move to %s. Refresh the filing to see its current state.',
            $fromLabel,
            $to->label(),
        ));
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'from_status' => $this->from?->value,
            'to_status' => $this->to->value,
        ], 409);
    }
}
