<?php

use App\Models\Business;
use App\Models\OfficeSignatory;
use App\Models\Permit;
use Smalot\PdfParser\Parser;

/*
 * Checklist item 79 — the Profile page lists approved permits, View shows the
 * permit as its paper counterpart, and it saves as PDF.
 *
 * The certificate face needs fields PermitResource never carried (owner,
 * address, line of business, signature block), so `GET /permits/{id}` answers
 * them under `certificate` and `pdf` renders the same array. These tests hold
 * the two together: the screen and the download must not be able to disagree
 * about what the certificate says.
 *
 * The signature-block assertions exist because names on LGU forms are
 * admin-edited rows, never literals — see the create_office_signatories_table
 * migration. A test that only checked "a name is printed" would pass just as
 * happily against a hardcoded one, so these check that the printed name is the
 * row's, and that editing the row changes the output.
 */

/** The seeded owner's first permit, with its issuing office. */
function ownersPermit(): Permit
{
    return Permit::whereHas('business', fn ($q) => $q->whereHas(
        'owner', fn ($o) => $o->where('email', 'owner@biztrack.local')
    ))->with('permitType.department')->firstOrFail();
}

it('answers the certificate fields the permit view prints', function () {
    $permit = ownersPermit();
    authAs('owner@biztrack.local');

    $cert = $this->getJson("/api/v1/permits/{$permit->id}")
        ->assertOk()
        // The list-row shape is still there; the certificate rides alongside it.
        ->assertJsonPath('data.permit_number', $permit->permit_number)
        ->json('data.certificate');

    expect($cert['owner_name'])->toBe($permit->business->owner->fullName())
        ->and($cert['business_name'])->toBe($permit->business->name)
        ->and($cert['permit_number'])->toBe($permit->permit_number)
        ->and($cert['verify_url'])->toContain($permit->permit_number)
        ->and($cert)->toHaveKeys([
            'trade_name', 'address', 'barangay', 'line_of_business',
            'valid_from', 'valid_until', 'tracking_id', 'signatories',
        ]);
});

it('prints the office signatory on file rather than a name in the template', function () {
    $permit = ownersPermit();

    $signatory = OfficeSignatory::updateOrCreate(
        ['department_id' => $permit->permitType->issuing_department_id, 'role' => 'Officer-in-Charge'],
        ['name' => 'Aurora S. Bautista', 'sort_order' => 0, 'is_active' => true],
    );

    authAs('owner@biztrack.local');
    $cert = $this->getJson("/api/v1/permits/{$permit->id}")->assertOk()->json('data.certificate');

    expect($cert['signatories'])->toContain(['role' => 'Officer-in-Charge', 'name' => 'Aurora S. Bautista']);

    authAs('owner@biztrack.local');
    $text = (new Parser)
        ->parseContent($this->get("/api/v1/permits/{$permit->id}/pdf")->assertOk()->getContent())
        ->getText();
    expect($text)->toContain('Aurora S. Bautista');

    // The officeholder rotates. Nothing but the row changes, and the next
    // download says the new name — which is the whole point of the table.
    $signatory->update(['name' => 'Ramon T. Villafuerte']);

    authAs('owner@biztrack.local');
    $text = (new Parser)
        ->parseContent($this->get("/api/v1/permits/{$permit->id}/pdf")->assertOk()->getContent())
        ->getText();
    expect($text)->toContain('Ramon T. Villafuerte')
        ->and($text)->not->toContain('Aurora S. Bautista');
});

/*
 * Read as an admin, not the owner. Once the business is soft-deleted the
 * owner's own route to the permit closes with it — `index` scopes through
 * `whereHas('business')` and `authorizeView` matches on `business->owner_user_id`
 * — so the permit simply stops being listed for them, consistently. The reader
 * who still reaches it is the register-wide one, and that is where a null
 * business would otherwise crash the render.
 */
it('renders a certificate whose business was removed from the register', function () {
    $permit = ownersPermit();

    // Business soft-deletes; its issued permits stay on the register. This is
    // the shape that crashed three officer screens (RemovedBusinessRendering).
    Business::findOrFail($permit->business_id)->delete();

    authAs('admin@biztrack.local');
    $cert = $this->getJson("/api/v1/permits/{$permit->id}")
        ->assertOk()
        ->json('data.certificate');

    // Null, not a stand-in name: "removed" has to stay tellable from "named".
    expect($cert['business_name'])->toBeNull()
        ->and($cert['owner_name'])->toBeNull()
        ->and($cert['permit_number'])->toBe($permit->permit_number);

    authAs('admin@biztrack.local');
    $bytes = $this->get("/api/v1/permits/{$permit->id}/pdf")->assertOk()->getContent();
    $text = (new Parser)->parseContent($bytes)->getText();

    expect(substr($bytes, 0, 5))->toBe('%PDF-')
        ->and($text)->toContain('Business removed from register');
});
