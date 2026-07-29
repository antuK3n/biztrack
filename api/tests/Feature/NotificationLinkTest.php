<?php

use App\Models\AppNotification;

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
        ->and($source)->not->toContain('"/review/');
});
