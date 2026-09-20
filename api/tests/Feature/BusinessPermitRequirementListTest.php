<?php

use App\Models\PermitType;

/*
 * MCG-BPLO-FO-001's DOCUMENTARY REQUIREMENTS, held against the seeded list.
 *
 * This file exists because of a fault that no other test could have caught.
 * `PermitType::documentTypes()` had no ordering clause, so the list came back
 * in insertion order — and a row BizTrack asks for on its own account, the Tax
 * Incentive Certificate from section B item 7, came out SECOND, between the
 * paper's items 1 and 3. Every row was correct. Every count was correct. Only
 * the reading order was wrong, and the applicant and the clerk are the only
 * two who ever read it.
 *
 * A count test cannot see that, which is the lesson from the last sweep here:
 * totals agreeing is not identities agreeing. So this asserts the sequence.
 *
 * The paper, as supplied by the client on 16 September 2026:
 *
 *   1  Proof of Business Registration (DTI for Sole Proprietor / SEC for
 *      Corporation, Partnership and OPC / CDA for Cooperative)
 *   2  Locational Clearance
 *   3  Contract of Lease AND Business Permit of Lessor (if leased), or Tax
 *      Declaration / Transfer Certificate of Title (TCT) (if owned)
 *   4  Occupancy Permit (if required)
 *   5  Sketch and photos of location of business
 *   6  Special power of attorney (SPA) / Authorization to transact for
 *      representative together with photocopies of IDs
 *
 * Items 2 and 4 are deliberately absent: BizTrack ISSUES both, in the LGU
 * Clearances stage, and an applicant who already holds one uploads it there
 * through "Upload an existing copy". Asking for either here demands the output
 * of a stage the filing has not reached. That divergence from the paper is
 * recorded in ReferenceSeeder and in migration 2026_09_16_000050.
 *
 * The DISPLAY order is not this printed order, and that is deliberate too. The
 * paper's sequence interleaves rows every applicant uploads with rows that
 * appear only on a particular answer to section B, and since the screen shows
 * only the rows that apply, the applicant would read the printed list with
 * holes punched through the middle of it. So the screen runs unconditional →
 * filing-driven → answer-driven; migration 2026_09_16_000070 has the reasoning.
 */

it('lists the certain requirements first and the conditional ones last', function () {
    $codes = PermitType::where('code', 'BUSINESS')->firstOrFail()
        ->documentTypes->pluck('code')->all();

    expect($codes)->toBe([
        // Required of everyone, so an applicant can act on these before
        // deciding anything else. Paper items 1 and 5.
        'DTI_SEC_CDA',
        'LOCATION_SKETCH',

        // Required by the filing rather than by an answer: a renewal needs the
        // permit it renews, whatever the applicant types.
        'PRIOR_PERMIT',

        /*
         * Required by the applicant's own answers. Item 3's three rows stay
         * adjacent — one printed requirement, two branches, only ever one of
         * them shown — and the tax incentive certificate comes after them,
         * being answer-driven AND absent from the paper's list.
         */
        'LEASE_CONTRACT',     // item 8 = Yes
        'LESSOR_PERMIT',      // item 8 = Yes
        'LAND_TITLE',         // item 8 = No
        'TAX_INCENTIVE_CERT', // item 7 = Yes

        /*
         * And last of all the only row nobody is obliged to fill. Paper item 6
         * is shown to everyone — the paper states its condition in its own
         * wording rather than asking it — so sorting by certainty alone put it
         * third, with five required rows beneath it: the one box an applicant
         * may leave empty, ahead of five they may not.
         */
        'SPA_AUTHORIZATION',  // optional
    ]);
});

/*
 * The two band boundaries, asserted on their own so each survives a reshuffle
 * within a band. Both are properties the client asked for in so many words, and
 * asserting only the exact sequence above would report either one breaking as
 * an ordinary ordering diff in the middle of eight codes, rather than as the
 * particular rule that was broken.
 */
it('puts every answer-driven requirement below every certain one', function () {
    $rows = PermitType::where('code', 'BUSINESS')->firstOrFail()->documentTypes;

    /*
     * *"Those that are 'Yes' dependent should be at the bottom of the list."*
     *
     * Measured against the rows that are REQUIRED and not answer-driven. The
     * optional row is excluded on purpose: it now sits below the answer-driven
     * block by a later decision of the client's, and including it here would
     * make this assertion contradict the next one.
     */
    $answerDriven = ['rented', 'owned', 'tax_incentives'];
    $isConditional = fn ($doc) => in_array($doc->pivot->context, $answerDriven, true);

    $certain = $rows->reject($isConditional)->filter(fn ($doc) => (bool) $doc->pivot->is_mandatory);
    $conditional = $rows->filter($isConditional);

    // Neither band is empty, or the comparison below would be vacuous.
    expect($certain)->not->toBeEmpty()->and($conditional)->not->toBeEmpty();

    expect($conditional->min(fn ($doc) => $doc->pivot->display_order))
        ->toBeGreaterThan($certain->max(fn ($doc) => $doc->pivot->display_order));
});

it('puts nothing optional above anything required', function () {
    $rows = PermitType::where('code', 'BUSINESS')->firstOrFail()->documentTypes;

    /*
     * The step's whole job is telling an applicant what they still owe, so the
     * box they may leave empty comes after the boxes they may not. Sorting by
     * how CERTAIN a row is — which is what the bands above do — is not the same
     * as sorting by whether it is OWED, and the SPA is the one row where those
     * two come apart: shown to everyone, required of almost nobody.
     */
    $required = $rows->filter(fn ($doc) => (bool) $doc->pivot->is_mandatory);
    $optional = $rows->reject(fn ($doc) => (bool) $doc->pivot->is_mandatory);

    expect($required)->not->toBeEmpty()->and($optional)->not->toBeEmpty();

    expect($optional->min(fn ($doc) => $doc->pivot->display_order))
        ->toBeGreaterThan($required->max(fn ($doc) => $doc->pivot->display_order));
});

it('does not ask for the two clearances it issues itself', function () {
    $codes = PermitType::where('code', 'BUSINESS')->firstOrFail()
        ->documentTypes->pluck('code')->all();

    expect($codes)->not->toContain('LOCATIONAL')   // paper item 2
        ->not->toContain('OCCUPANCY')              // paper item 4
        /*
         * And not the ID as a row of its own. Item 6 is ONE requirement —
         * the authorisation together with photocopies of IDs — and seeding it
         * as two asked twice for one thing, then showed every owner filing in
         * person an ID requirement the paper never puts to them.
         */
        ->not->toContain('VALID_ID');
});

/*
 * The names are asserted, not just the codes, because they are what is read.
 * Each one is the paper's own wording near enough word for word, so that the
 * screen and the printed checklist can be reconciled line by line; drifting
 * off it is a silent regression of exactly the kind this file is here for.
 */
it('names each requirement the way the paper names it', function () {
    $names = PermitType::where('code', 'BUSINESS')->firstOrFail()
        ->documentTypes->pluck('name', 'code');

    expect($names['DTI_SEC_CDA'])->toBe('Proof of Business Registration (DTI / SEC / CDA)')
        // Quoted in full, so quoted exactly — the paper has no spaces round
        // that slash. Item 1 above abbreviates a longer printed phrase, so its
        // spacing is ours; this one's is not.
        ->and($names['LAND_TITLE'])->toBe('Tax Declaration/Transfer Certificate of Title (TCT)')
        ->and($names['LOCATION_SKETCH'])->toBe('Sketch and photos of location of business');
});

/*
 * Help text is rendered as text, never as markup. An `&rsquo;` written into a
 * PHP string reached the applicant as the five characters "&rsquo;" — React
 * escapes what it is handed — and it shipped because nothing reads these
 * strings but a person looking at the screen.
 */
it('writes apostrophes rather than HTML entities in the help text', function () {
    $help = PermitType::where('code', 'BUSINESS')->firstOrFail()
        ->documentTypes->pluck('help_text', 'code');

    foreach ($help as $text) {
        expect($text)->not->toBeEmpty()
            ->and($text)->not->toMatch('/&[a-z]+;/i');
    }

    expect($help['LESSOR_PERMIT'])->toContain("lessor's own business permit");
});
