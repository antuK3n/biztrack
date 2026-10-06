<?php

namespace App\Support\DefenseAccounts;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * The generator's hands: every write goes through the API, as a person.
 *
 * ── Why requests, and not the services they call ────────────────────────────
 *
 * The client's objection to the earlier demo seeders was that they wrote rows
 * straight through Eloquent and so "bypassed validations" (3 October 2026,
 * quoted in SeedExpiringDemoBusinesses). Calling WorkflowService directly
 * would be the same objection one layer down: the controllers are where
 * ownership, permission, the `unrestricted` bar, the 8-to-5 booking rule and
 * every 422 an applicant can meet are checked. So each step here is the
 * request the screen sends — same route, same middleware, same validator —
 * dispatched through the HTTP kernel in this process, the way the test suite
 * dispatches its own.
 *
 * ── Who is signed in ────────────────────────────────────────────────────────
 *
 * A real Sanctum token per account, minted for the day the clock stands on,
 * carried as a bearer header, and deleted by `forgetTokens()` at the end. A
 * token rather than a guard set by hand because code reading
 * `currentAccessToken()` would find nothing on a hand-set user, and a token
 * per DAY because the clock moves: one minted "in 2025" would read as 720
 * minutes past its expiry by the time the clock stands in 2026.
 *
 * The guard's user is forgotten before each request. The guard keeps the
 * user it resolved, and without this the second account to act would act as
 * the first. Forgotten, not the guard rebuilt (`forgetGuards()`, as the test
 * suite does): each new Sanctum guard registers one more listener on the
 * container's request binding, and over a run's few thousand requests those
 * piled up until the process ran out of memory.
 *
 * ── The URL ─────────────────────────────────────────────────────────────────
 *
 * Requests are built on `app.url`, never a bare path. A bare path makes the
 * request's host "localhost", and anything the app builds from the current
 * request — a link in a notice, a URL on a permit face — would carry it.
 */
final class AppClient
{
    private ?User $actor = null;

    /** @var array<string, string> plain-text tokens by "user id|day" */
    private array $tokens = [];

    /** @var list<int> every token minted, for forgetTokens() */
    private array $minted = [];

    public function __construct(private readonly Clock $clock) {}

    public function as(?User $user): self
    {
        $this->actor = $user;

        return $this;
    }

    public function get(string $step, string $uri, array $query = []): array
    {
        return $this->call($step, 'GET', $uri, $query);
    }

    public function post(string $step, string $uri, array $data = []): array
    {
        return $this->call($step, 'POST', $uri, $data);
    }

    public function put(string $step, string $uri, array $data = []): array
    {
        return $this->call($step, 'PUT', $uri, $data);
    }

    /** A multipart POST carrying one PDF, as a file picker sends it. */
    public function upload(string $step, string $uri, array $data, string $filename, string $pdf, string $field = 'file'): array
    {
        $path = tempnam(sys_get_temp_dir(), 'bt-upload-');
        file_put_contents($path, $pdf);

        try {
            return $this->call($step, 'POST', $uri, $data, [
                $field => new UploadedFile($path, $filename, 'application/pdf', null, true),
            ]);
        } finally {
            @unlink($path);
        }
    }

    /**
     * Follow a link as a browser would — no token, GET, the response kept
     * whole (the e-mail verification link answers with a redirect).
     *
     * From the owner's own address: the link's route allows six a minute per
     * address, and eighty owners confirming from one would be refused after
     * the sixth, which is the limiter working, not anything to get round.
     */
    public function visit(string $url, string $ip): Response
    {
        $this->clock->tick();
        app('auth')->guard('sanctum')->forgetUser();

        return $this->dispatch(Request::create($url, 'GET', [], [], [], [
            'REMOTE_ADDR' => $ip,
        ]));
    }

    /**
     * @param  array<string, UploadedFile>  $files
     * @return array<string, mixed> the decoded body
     */
    public function call(string $step, string $method, string $uri, array $data = [], array $files = []): array
    {
        $this->clock->tick();

        $url = rtrim((string) config('app.url'), '/').'/api/v1/'.ltrim($uri, '/');
        $server = [
            'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => '127.0.0.1',
        ];
        if ($this->actor !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$this->tokenFor($this->actor);
        }

        if ($method === 'GET' || $files !== []) {
            $request = Request::create($url, $method, $data, [], $files, $server);
        } else {
            $server['CONTENT_TYPE'] = 'application/json';
            $request = Request::create($url, $method, [], [], [], $server, (string) json_encode($data));
        }

        app('auth')->guard('sanctum')->forgetUser();
        $response = $this->dispatch($request);
        $status = $response->getStatusCode();
        $body = json_decode((string) $response->getContent(), true) ?? [];

        if ($status >= 300) {
            $message = $body['message'] ?? 'no message';
            if (isset($body['errors']) && is_array($body['errors'])) {
                $message .= ' '.json_encode($body['errors'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            if ($status >= 500 && property_exists($response, 'exception') && $response->exception !== null) {
                $message .= ' ['.$response->exception->getMessage().']';
            }

            throw new StepRefused($step, $status, $message);
        }

        return $body;
    }

    /**
     * Forget tokens that a rolled-back owner took with it. The rows went with
     * the transaction; holding on to their plain text would send a bearer
     * the database no longer knows.
     */
    public function dropCachedTokens(): void
    {
        $this->tokens = [];
    }

    /** Delete every token this run minted. Answers how many went. */
    public function forgetTokens(): int
    {
        $gone = PersonalAccessToken::whereKey($this->minted)->delete();
        $this->minted = [];
        $this->tokens = [];

        return $gone;
    }

    /** A token minted on registration, to be deleted with the rest. */
    public function adopt(string $plainTextToken): void
    {
        $id = (int) strtok($plainTextToken, '|');
        if ($id > 0) {
            $this->minted[] = $id;
        }
    }

    private function tokenFor(User $user): string
    {
        $key = $user->id.'|'.CarbonImmutable::now()->toDateString();

        if (! isset($this->tokens[$key])) {
            // Named as the sign-in screen names a token ("web:<portal>"), so
            // nothing an admin could see of the run's sessions reads as a
            // script; they are deleted at the end in any case.
            $portal = $user->hasRole('admin') ? 'admin' : ($user->hasRole('business_owner') ? 'public' : 'staff');
            $token = $user->createToken("web:{$portal}");
            $this->minted[] = $token->accessToken->getKey();
            $this->tokens[$key] = $token->plainTextToken;
        }

        return $this->tokens[$key];
    }

    private function dispatch(Request $request): Response
    {
        $kernel = app(HttpKernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return $response;
    }
}
