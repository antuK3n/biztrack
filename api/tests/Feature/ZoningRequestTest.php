<?php

use App\Models\OfficerRequest;
use App\Models\Role;
use App\Models\User;

/*
 * Tester checklist item 75 — zoning officers could not ask an applicant for a
 * missing requirement.
 *
 * Concluded: an oversight, not a policy. The role already approves and returns
 * its own assignment through application.review, so it was never a "view-only
 * review set"; the only office that routinely needs one more sketch or lot plan
 * was the only one whose sole recourse was to return the entire filing.
 */

it('grants the zoning officer request.create like every other reviewing office', function () {
    $zoning = Role::where('name', 'zoning_officer')->firstOrFail();

    expect($zoning->permissions->pluck('name')->all())->toContain('request.create');
});

it('lets the zoning officer ask the applicant for a missing requirement', function () {
    $app = fileRoutedApplication('Zoning Requirement Bakery', ['BUSINESS', 'ZONING']);

    $created = $this->withHeaders(authAs('zoning@biztrack.local'))
        ->postJson("/api/v1/applications/{$app['id']}/requests", [
            'request_type' => 'document',
            'title' => 'Vicinity map and lot plan',
            'description' => 'Please upload a lot plan signed by a geodetic engineer.',
        ])->assertCreated()->json('data');

    $row = OfficerRequest::findOrFail($created['id']);
    $officer = User::where('email', 'zoning@biztrack.local')->firstOrFail();

    // Attributed to CPDO so the applicant can see who is asking.
    expect($row->department_id)->toBe($officer->department_id)
        ->and($row->requested_by_user_id)->toBe($officer->id);
});

it('lets the zoning officer close the request it raised', function () {
    $app = fileRoutedApplication('Zoning Closing Bakery', ['BUSINESS', 'ZONING']);

    $requestId = $this->withHeaders(authAs('zoning@biztrack.local'))
        ->postJson("/api/v1/applications/{$app['id']}/requests", [
            'request_type' => 'document',
            'title' => 'Vicinity map',
        ])->assertCreated()->json('data.id');

    $this->withHeaders(authAs('zoning@biztrack.local'))
        ->postJson("/api/v1/requests/{$requestId}/close", ['outcome' => 'fulfilled'])
        ->assertOk();
});

it('still keeps the zoning officer inside its own office', function () {
    // Granting request.create must not hand CPDO the rest of the register.
    $elsewhere = fileRoutedApplication('Zoning Outsider Bakery', ['BUSINESS', 'SANITARY']);

    $this->withHeaders(authAs('zoning@biztrack.local'))
        ->postJson("/api/v1/applications/{$elsewhere['id']}/requests", [
            'request_type' => 'document',
            'title' => 'Not my filing',
        ])->assertForbidden();

    expect(listedApplicationIds('zoning@biztrack.local'))->not->toContain($elsewhere['id']);
});
