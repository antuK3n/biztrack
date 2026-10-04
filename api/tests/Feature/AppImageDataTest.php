<?php

use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;

/*
 * Every file the API reads from outside api/ is in the app image, at the path
 * the code reads it from (zoning-check row 42).
 *
 * Both Dockerfiles copied api/ alone, so `base_path('../docs/zoning-ordinance/
 * zone-uses.json')` pointed at nothing on biztrack.page: the readers return an
 * empty list for a missing file, and every trade came back "not on the list"
 * with no error anywhere. Checked on the server on 5 October 2026 — no
 * /var/www/docs at all.
 *
 * So this reads the code for every `'../…'` path, and holds each Dockerfile to
 * a COPY that puts that folder beside WORKDIR, and .dockerignore to letting it
 * into the build context. A new file read from outside api/ fails here until
 * the image carries it.
 */

/** The folders, relative to the repository root, that app code reads from outside api/. */
function aidFolders(): array
{
    $folders = [];
    foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
        preg_match_all("/'\\.\\.\\/([A-Za-z0-9_\\/.-]+)'/", $file->getContents(), $m);
        foreach ($m[1] as $path) {
            $folders[] = preg_match('/\.[a-z]+$/', $path) === 1 ? dirname($path) : rtrim($path, '/');
        }
    }

    return array_values(array_unique($folders));
}

it('finds the files the zoning code reads from outside api/', function () {
    expect(aidFolders())->toEqualCanonicalizing(['docs/zoning-ordinance', 'web/public/zoning']);
});

it('copies every folder the API reads from outside api/ into both app images, where the code looks', function (string $dockerfile) {
    $text = (string) file_get_contents(base_path("../{$dockerfile}"));
    preg_match('/^WORKDIR\s+(\S+)/m', $text, $workdir);
    preg_match_all('/^COPY\s+(?!--)(\S+)\s+(\S+)\s*$/m', $text, $copies, PREG_SET_ORDER);
    $root = dirname($workdir[1]);

    foreach (aidFolders() as $folder) {
        $copied = collect($copies)->contains(fn ($c) => rtrim($c[1], '/') === $folder
            && rtrim($c[2], '/') === "{$root}/{$folder}");
        expect($copied)->toBeTrue("{$dockerfile} does not COPY {$folder}/ to {$root}/{$folder}/");
    }
})->with(['infra/azure/app.Dockerfile', 'infra/php/Dockerfile']);

it('lets those folders into the build context', function () {
    $ignored = collect(file(base_path('../.dockerignore'), FILE_IGNORE_NEW_LINES))
        ->map(fn ($line) => trim($line))
        ->reject(fn ($line) => $line === '' || str_starts_with($line, '#') || str_starts_with($line, '!'));

    foreach (aidFolders() as $folder) {
        foreach ($ignored as $pattern) {
            expect(Str::is($pattern, $folder) || Str::startsWith($folder, rtrim($pattern, '/').'/'))
                ->toBeFalse(".dockerignore's {$pattern} keeps {$folder} out of the image");
        }
    }
});
