<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Client for the R (plumber) statistics service.
 *
 * R stays a separate program and remains the statistics engine, as the client's
 * paper describes. The division of labour is deliberate and worth stating:
 *
 *  - **Laravel owns all SQL.** R never touches the database. `r/R/db.R`'s
 *    postgres readers stay stubs. That keeps RBAC and office scoping in exactly
 *    one place, and means the SQLite dev database needs no R-side driver.
 *  - **R is a pure compute service.** Row sets in, statistics out. Every
 *    endpoint returns the same answer for the same input.
 *
 * Nothing on a request path calls this class. `analytics:refresh` pushes rows,
 * R computes, Laravel persists the result, and page loads read the persisted
 * result — see AnalyticsResolver. So a slow or dead R service delays the next
 * refresh; it cannot slow down or break a page load.
 *
 * Every failure mode collapses to null. A refused connection, a timeout, a 500,
 * a truncated body and a JSON payload of the wrong shape are all the same fact
 * to the caller — "R did not give us statistics" — and the caller's response to
 * that fact is the same in every case. The reason is logged, and surfaced
 * through lastError() so the command can print it.
 */
class RAnalytics
{
    private ?string $lastError = null;

    /**
     * Whether pushing to R is switched on at all.
     *
     * Off is a legitimate deployment: a demo box with no R installed serves the
     * PHP fallback and labels it. It must not be a way to hide an R outage, so
     * the flag is reported in the snapshot meta rather than silently assumed.
     */
    public function enabled(): bool
    {
        return (bool) config('analytics.r.enabled');
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Liveness plus the R version, for the refresh log and the meta payload.
     *
     * @return array{status: string, r_version?: string}|null
     */
    public function health(): ?array
    {
        return $this->request('get', '/health');
    }

    /**
     * Push a row set to an endpoint and get statistics back.
     *
     * @param  array<string, mixed>  $dataset
     * @return array<string, mixed>|null  null when R could not be reached or answered badly
     */
    public function compute(string $endpoint, array $dataset): ?array
    {
        return $this->request('post', $endpoint, $dataset);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>|null
     */
    private function request(string $method, string $endpoint, ?array $payload = null): ?array
    {
        $this->lastError = null;

        if (! $this->enabled()) {
            $this->lastError = 'R analytics is disabled (R_ANALYTICS_ENABLED=false).';

            return null;
        }

        $url = config('analytics.r.base_url').'/'.ltrim($endpoint, '/');

        try {
            $request = Http::timeout((float) config('analytics.r.timeout'))
                ->connectTimeout((float) config('analytics.r.connect_timeout'))
                ->acceptJson();

            $response = $method === 'post'
                ? $request->asJson()->post($url, $payload ?? [])
                : $request->get($url);

            if ($response->failed()) {
                // plumber puts its own error text in the body, which is usually
                // the actual R condition message and the only useful clue.
                $this->fail(sprintf(
                    'R returned HTTP %d for %s: %s',
                    $response->status(),
                    $endpoint,
                    trim(mb_substr($response->body(), 0, 500)),
                ));

                return null;
            }

            $decoded = $response->json();

            if (! is_array($decoded)) {
                $this->fail("R returned a non-object body for {$endpoint}.");

                return null;
            }

            // plumber serialises an R `stop()` inside a 200 in some setups.
            if (isset($decoded['error']) && is_string($decoded['error'])) {
                $this->fail("R reported an error for {$endpoint}: {$decoded['error']}");

                return null;
            }

            return $decoded;
        } catch (Throwable $e) {
            // Connection refused, DNS, timeout, malformed chunked body. The
            // distinction matters for the log, not for the caller.
            $this->fail("R was unreachable for {$endpoint}: {$e->getMessage()}");

            return null;
        }
    }

    private function fail(string $message): void
    {
        $this->lastError = $message;
        Log::warning('[analytics] '.$message);
    }
}
