<?php

use App\Models\Application;
use App\Models\AppNotification;
use App\Models\User;
use App\Services\NotificationService;

/*
 * A notification is only useful if its link opens the thing it is about.
 * `/track/{id}` and `/review/{id}` were never routes in web/src/App.tsx, so
 * every one of them bounced the reader through the catch-all redirect.
 */

/** Every path the SPA router actually serves, read from the router itself. */
function routerPaths(): array
{
    $app = file_get_contents(base_path('../web/src/App.tsx'));
    preg_match_all('/path="([^"]+)"/', $app, $m);

    return $m[1];
}

function linkIsRoutable(string $link, array $paths): bool
{
    $segments = explode('/', trim($link, '/'));

    foreach ($paths as $path) {
        $pattern = explode('/', trim($path, '/'));
        if ($path === '*' || count($pattern) !== count($segments)) {
            continue;
        }
        $ok = true;
        foreach ($pattern as $i => $part) {
            if (str_starts_with($part, ':')) {
                continue;           // route param, matches anything
            }
            if ($part !== $segments[$i]) {
                $ok = false;
                break;
            }
        }
        if ($ok) {
            return true;
        }
    }

    return false;
}

it('points every stored notification at a route the app serves', function () {
    $paths = routerPaths();
    expect($paths)->not->toBeEmpty();

    $dead = AppNotification::whereNotNull('link')->pluck('link')->unique()
        ->reject(fn (string $link) => linkIsRoutable($link, $paths))
        ->values()->all();

    expect($dead)->toBe([], 'These notification links have no matching route: '.implode(', ', $dead));
});

it('does not emit the retired /track and /review prefixes', function () {
    $source = file_get_contents(app_path('Services/NotificationService.php'));

    expect($source)->not->toContain('"/track/')
        ->and($source)->not->toContain('"/review/')
        // `/pay/{id}` is the same mistake one rename later: PayPage is mounted
        // at `/applications/{id}/pay`. It outlived the fix above only because no
        // fee had been adjusted yet, so no stored row existed to fail the check.
        ->and($source)->not->toContain('"/pay/');
});

/*
 * ── Issue #98: "clicking a notification logs the user out" ──────────────────
 *
 * Routable is not enough. The SPA is two sites on one origin and web/src/lib
 * /api.ts keys the session token by which of the two the address bar is on, so
 * a citizen path handed to an officer lands where the officer's token is never
 * sent: 401, then the citizen sign-in page, which reads as a logout even though
 * the staff session is untouched. `/applications/{id}` was going to officers on
 * every message an applicant sent.
 */
function linkFor(User $reader): string
{
    /*
     * An unsaved Application on purpose. Nothing under test reads anything but
     * the id and the tracking id, and the alternative is filing and paying for a
     * permit to find out which of two strings a notification stores.
     */
    $app = Application::make();
    $app->id = 4242;
    $app->tracking_id = 'BT-2026-004242';
    $app->setRelation('applicant', $reader);

    app(NotificationService::class)->newMessage($app, $reader);

    return AppNotification::where('user_id', $reader->id)->latest('id')->value('link');
}

it('addresses a filing on the site the reader is signed into', function () {
    $officer = User::where('email', 'bplo@biztrack.local')->firstOrFail();
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();

    expect(linkFor($officer))->toBe('/staff/queue/4242')
        ->and(linkFor($owner))->toBe('/applications/4242');

    // And both are addresses the router serves, not just correctly prefixed.
    $paths = routerPaths();
    expect(linkIsRoutable('/staff/queue/4242', $paths))->toBeTrue()
        ->and(linkIsRoutable('/applications/4242', $paths))->toBeTrue();
});

it('sends a fee adjustment to the pay screen that exists', function () {
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();

    $app = Application::make();
    $app->id = 4243;
    $app->tracking_id = 'BT-2026-004243';
    $app->setRelation('applicant', $owner);

    app(NotificationService::class)->feeAdjusted($app);

    $link = AppNotification::where('user_id', $owner->id)->latest('id')->value('link');
    // `/pay/{id}` was never mounted — PayPage lives under the filing.
    expect($link)->toBe('/applications/4243/pay')
        ->and(linkIsRoutable($link, routerPaths()))->toBeTrue();
});
