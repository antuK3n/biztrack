<?php

namespace App\Support\DefenseAccounts;

use RuntimeException;

/**
 * A step the app answered with something other than success.
 *
 * Carries the status so the generator can tell a rule saying no (422, 403,
 * 409: report it, never force it) from a crash (500: a bug to chase), and
 * the app's own sentence so the report quotes the rule rather than guessing
 * at it.
 */
final class StepRefused extends RuntimeException
{
    public function __construct(
        public readonly string $step,
        public readonly int $status,
        string $message,
    ) {
        parent::__construct("{$step} answered {$status}: {$message}");
    }

    /** A rule refused it, as opposed to the app failing. */
    public function byRule(): bool
    {
        return $this->status >= 400 && $this->status < 500;
    }
}
