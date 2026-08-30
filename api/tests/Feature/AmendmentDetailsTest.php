<?php

use App\Models\Application;
use App\Models\Business;
use App\Models\PermitType;

/*
 * Checklist items 82/84 — "fix the amendment form; refer to the physical/paper
 * application".
 *
 * The manuscript-alignment migration created the paper form's "Amendment from:"
 * block — has_amendments, amendment_ownership, amendment_location,
 * amendment_nature, amendment_other — and nothing ever read or wrote a single
 * one of them. /apply?type=amendment was the new-application wizard with a
 * different heading: it never asked the one question that makes a filing an
 * amendment, so the counter had to ask it afterwards.
 */

/** A draft amendment on Nena's business, with whatever amendment answers. */
function amendmentDraft(array $amendmentFields = []): int
{
    authAs('owner@biztrack.local');
    $business = Business::where('name', 'like', 'Nena%')->firstOrFail();

    return test()->postJson('/api/v1/applications', [
        'business_id' => $business->id,
        'application_type' => 'amendment',
        'permit_type_ids' => PermitType::where('code', 'BUSINESS')->pluck('id')->all(),
        ...$amendmentFields,
    ])->assertCreated()->json('data.id');
}

it('records which kinds of amendment were ticked', function () {
    $appId = amendmentDraft([
        'amendment_ownership' => true,
        'amendment_location' => true,
    ]);

    $app = Application::findOrFail($appId);

    expect($app->amendment_ownership)->toBeTrue()
        ->and($app->amendment_location)->toBeTrue()
        ->and($app->amendment_nature)->toBeFalse()
        ->and($app->amendment_other)->toBeNull()
        // Derived, never sent: it is the OR of the four above.
        ->and($app->has_amendments)->toBeTrue();
});

it('treats the "Others" text as the tick it is on the paper form', function () {
    // Nothing else chosen, so this proves the free text alone is an answer —
    // there is no fifth boolean and the applicant cannot tick Others silently.
    $appId = amendmentDraft(['amendment_other' => '  change of business name  ']);

    $app = Application::findOrFail($appId);

    expect($app->amendment_other)->toBe('change of business name')
        ->and($app->has_amendments)->toBeTrue();
});

it('round-trips the amendment block on the application payload', function () {
    $appId = amendmentDraft([
        'amendment_nature' => true,
        'amendment_other' => 'new co-owner',
    ]);

    $data = $this->getJson("/api/v1/applications/{$appId}")->assertOk()->json('data.amendments');

    expect($data['has_amendments'])->toBeTrue()
        ->and($data['nature'])->toBeTrue()
        ->and($data['ownership'])->toBeFalse()
        ->and($data['other'])->toBe('new co-owner')
        // What the officer sheet renders; built server-side so the wording of
        // "Nature of Business" lives in exactly one place.
        ->and($data['summary'])->toBe(['Nature of Business', 'Others: new co-owner']);
});

it('lets a draft change what it is amending', function () {
    $appId = amendmentDraft(['amendment_location' => true]);

    $this->putJson("/api/v1/applications/{$appId}", [
        'amendment_location' => false,
        'amendment_ownership' => true,
    ])->assertOk();

    $app = Application::findOrFail($appId);
    expect($app->amendment_location)->toBeFalse()
        ->and($app->amendment_ownership)->toBeTrue()
        ->and($app->has_amendments)->toBeTrue();
});

it('does not blank the amendment answers on an unrelated draft save', function () {
    // Autosave writes the fee profile on its own; the answer must survive it.
    $appId = amendmentDraft(['amendment_ownership' => true]);

    $this->putJson("/api/v1/applications/{$appId}", [
        'fee_profile' => ['gross_sales' => 250000],
    ])->assertOk();

    expect(Application::findOrFail($appId)->amendment_ownership)->toBeTrue();
});

it('refuses to submit an amendment that amends nothing', function () {
    $appId = amendmentDraft();

    expect(Application::findOrFail($appId)->has_amendments)->toBeFalse();

    $this->postJson("/api/v1/applications/{$appId}/submit")
        ->assertStatus(422)
        ->assertJsonValidationErrors('has_amendments');

    expect(Application::findOrFail($appId)->status->value)->toBe('draft');
});

it('submits once something is actually being amended', function () {
    /*
     * An amendment names the permit it amends, the same way a renewal names
     * the one it renews — "amend my business" tells the counter no more than
     * "renew my business" does when the shop holds several permits with
     * different expiry dates. Included here rather than in its own test
     * because without it this filing no longer submits at all.
     */
    $permit = Business::where('name', 'like', 'Nena%')->firstOrFail()->permits()->firstOrFail();
    $appId = amendmentDraft(['amendment_nature' => true, 'prior_permit_id' => $permit->id]);

    $this->postJson("/api/v1/applications/{$appId}/submit")->assertOk();

    expect(Application::findOrFail($appId)->status->value)->not->toBe('draft');
});

it('leaves the amendment columns alone on a filing that is not an amendment', function () {
    // A caller can post these keys on a `new` filing; a new business is not
    // amending anything, and the columns must not say otherwise.
    authAs('owner@biztrack.local');
    $business = Business::where('name', 'like', 'Nena%')->firstOrFail();

    $appId = $this->postJson('/api/v1/applications', [
        'business_id' => $business->id,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::where('code', 'BUSINESS')->pluck('id')->all(),
        'amendment_ownership' => true,
        'amendment_other' => 'sneaked in',
    ])->assertCreated()->json('data.id');

    $app = Application::findOrFail($appId);
    expect($app->has_amendments)->toBeFalse()
        ->and($app->amendment_ownership)->toBeFalse()
        ->and($app->amendment_other)->toBeNull();

    // And the payload says "this form never asked", not "asked and told no".
    $this->getJson("/api/v1/applications/{$appId}")
        ->assertOk()
        ->assertJsonPath('data.amendments', null);
});

it('rejects an "Others" longer than the column can hold', function () {
    authAs('owner@biztrack.local');
    $business = Business::where('name', 'like', 'Nena%')->firstOrFail();

    // A silently truncated answer is worse than a rejected one.
    $this->postJson('/api/v1/applications', [
        'business_id' => $business->id,
        'application_type' => 'amendment',
        'permit_type_ids' => PermitType::where('code', 'BUSINESS')->pluck('id')->all(),
        'amendment_other' => str_repeat('x', 256),
    ])->assertStatus(422)->assertJsonValidationErrors('amendment_other');
});
