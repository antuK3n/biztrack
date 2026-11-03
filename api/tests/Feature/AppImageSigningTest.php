<?php

use Illuminate\Support\Str;

/*
 * The app image can sign permits (revision matrix item 7).
 *
 * Read from the files, as AppImageDataTest reads them, because the image is
 * built on the server and not here: the list the image installs is the pinned
 * one beside the wrapper, the Python the app is told to run is the venv the
 * image builds, and the build context lets the wrapper in and keeps a
 * developer's own venv out.
 */

function signingDockerfile(): string
{
    return (string) file_get_contents(base_path('../infra/azure/app.Dockerfile'));
}

it('installs the pinned signer list into the venv PERMIT_SIGNING_PYTHON names', function () {
    $dockerfile = signingDockerfile();

    expect(preg_match('#^COPY\s+api/resources/signing/requirements\.txt\s+(\S+)\s*$#m', $dockerfile, $copy))->toBe(1)
        ->and(preg_match('#python3 -m venv (\S+)#', $dockerfile, $venv))->toBe(1)
        ->and(preg_match('#^ENV\s+PERMIT_SIGNING_PYTHON=(\S+)\s*$#m', $dockerfile, $python))->toBe(1);

    expect($dockerfile)->toContain("{$venv[1]}/bin/pip install")
        ->and($dockerfile)->toMatch('#--only-binary=:all:\s*\\\\?\s*-r '.preg_quote($copy[1], '#').'#')
        ->and($python[1])->toBe("{$venv[1]}/bin/python");
});

it('pins every package the signer installs, pyHanko and tzdata among them', function () {
    $pins = collect(file(resource_path('signing/requirements.txt'), FILE_IGNORE_NEW_LINES))
        ->map(fn (string $line) => trim($line))
        ->reject(fn (string $line) => $line === '' || str_starts_with($line, '#'));

    expect($pins->all())->each->toMatch('/^[A-Za-z0-9._-]+==[0-9][A-Za-z0-9.]*$/');
    $names = $pins->map(fn (string $pin) => strtolower(Str::before($pin, '==')));
    expect($names->all())->toContain('pyhanko', 'tzdata');
});

it('lets the signer into the build context and keeps a local venv out', function () {
    $ignored = collect(file(base_path('../.dockerignore'), FILE_IGNORE_NEW_LINES))
        ->map(fn (string $line) => trim($line))
        ->reject(fn (string $line) => $line === '' || str_starts_with($line, '#') || str_starts_with($line, '!'));

    foreach (['api/resources/signing/sign_pdf.py', 'api/resources/signing/requirements.txt'] as $file) {
        foreach ($ignored as $pattern) {
            expect(Str::is($pattern, $file) || Str::startsWith($file, rtrim($pattern, '/').'/'))
                ->toBeFalse(".dockerignore's {$pattern} keeps {$file} out of the image");
        }
    }
    expect($ignored->all())->toContain('api/.venv');
});
