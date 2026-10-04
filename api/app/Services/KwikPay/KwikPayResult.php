<?php

namespace App\Services\KwikPay;

/**
 * What one call to KwikPay came back with. See KwikPayClient.
 *
 * `status` is KwikPay's own `status` field, as a string, because the meaning of
 * "1" depends on which endpoint said it (docs FAQ, "Does status 1 mean my
 * payment succeeded?"): on /api/transfer it means the order was ACCEPTED, on
 * /api/query that it is still WAITING. Callers branch on it with the endpoint
 * in mind; nothing here interprets it.
 */
final class KwikPayResult
{
    /** @param  array<string, mixed>  $body */
    public function __construct(
        public readonly ?int $httpStatus,
        public readonly array $body = [],
        public readonly bool $noAnswer = false,
        public readonly ?string $error = null,
    ) {}

    public static function noAnswer(string $error): self
    {
        return new self(httpStatus: null, noAnswer: true, error: $error);
    }

    /** KwikPay's `status` field, or null when there was none. */
    public function status(): ?string
    {
        $status = $this->body['status'] ?? null;

        return $status === null ? null : (string) $status;
    }

    public function message(): string
    {
        if ($this->noAnswer) {
            return 'No answer from the payment service ('.($this->error ?? 'timeout').').';
        }

        return (string) ($this->body['message'] ?? ('HTTP '.$this->httpStatus));
    }

    /**
     * Did KwikPay CLEARLY refuse the request? Only then may a payment be marked
     * failed without asking again.
     *
     * A 4xx is a refusal (bad signature, bad field, duplicate order, IP not
     * allowlisted — docs §6, §7 and the FAQ). A 5xx or a missing answer is not:
     * the order may exist on their side, so it stays pending and reconciliation
     * asks /api/query about it. A 2xx with status "0" is a refusal by the
     * endpoint's own envelope.
     */
    public function clearlyRejected(): bool
    {
        if ($this->noAnswer || $this->httpStatus === null) {
            return false;
        }

        if ($this->httpStatus >= 400 && $this->httpStatus < 500) {
            return true;
        }

        return $this->httpStatus >= 200 && $this->httpStatus < 300 && $this->status() === '0';
    }
}
