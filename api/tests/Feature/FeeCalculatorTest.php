<?php

use App\Models\Application;
use App\Models\Business;
use App\Models\PermitType;
use App\Models\User;
use App\Services\FeeCalculator;

/**
 * Revenue-code fee engine against hand-computed examples from Ordinance
 * A10-2016 (docs/revenue-code-extract.md). Every expected number below was
 * computed by hand from the cited section, not from the engine.
 */
function feeApp(array $permitTypeCodes, string $type, array $profile): Application
{
    $owner = User::where('email', 'owner@biztrack.local')->first();
    $business = Business::where('owner_user_id', $owner->id)->first();

    $app = Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $owner->id,
        'application_type' => $type,
        'status' => 'draft',
        'fee_profile' => $profile,
    ]);
    $app->permitTypes()->sync(
        PermitType::whereIn('code', $permitTypeCodes)->pluck('id')->all()
    );

    return $app->fresh();
}

function amountOf(array $assessed, string $code): ?float
{
    foreach ($assessed['items'] as $item) {
        if ($item['code'] === $code) {
            return $item['amount'];
        }
    }

    return null;
}

it('taxes a renewing retailer 3% on the first 400k and 1.5% above (Sec. 2J.02(d))', function () {
    $app = feeApp(['BUSINESS'], 'renewal', [
        'lines' => [['category' => 'retailer', 'gross_sales' => 800000]],
        'gross_sales' => 800000,
        'business_structure' => 'sole_proprietorship',
    ]);
    $r = app(FeeCalculator::class)->assess($app);

    // 400,000 * 3% + 400,000 * 1.5% = 12,000 + 6,000
    expect(amountOf($r, 'biztax.retailer'))->toBe(18000.0)
        ->and(amountOf($r, 'ctc.corporation'))->toBeNull(); // sole prop: no corporate CTC
});

it('routes franchise holders to 0.75% instead of the graduated table (Sec. 2F.03)', function () {
    $app = feeApp(['BUSINESS'], 'renewal', [
        'lines' => [['category' => 'franchise_holder', 'gross_sales' => 2000000]],
        'gross_sales' => 2000000,
    ]);
    $r = app(FeeCalculator::class)->assess($app);

    expect(amountOf($r, 'biztax.franchise'))->toBe(15000.0)  // 0.75% of 2M
        ->and(amountOf($r, 'biztax.manufacturer'))->toBeNull()
        ->and(amountOf($r, 'biztax.wholesaler'))->toBeNull();
});

it('computes the manufacturer excess tier at 10M gross (Sec. 2J.02(a))', function () {
    $app = feeApp(['BUSINESS'], 'renewal', [
        'lines' => [['category' => 'manufacturer', 'gross_sales' => 10000000]],
        'gross_sales' => 10000000,
    ]);
    $r = app(FeeCalculator::class)->assess($app);

    // 36,562.50 at 6.5M + 0.375% of the 3.5M excess = 36,562.50 + 13,125
    expect(amountOf($r, 'biztax.manufacturer'))->toBe(49687.5);
});

it('suppresses graduated tax for petroleum businesses (Sec. 2L.01)', function () {
    $app = feeApp(['BUSINESS'], 'renewal', [
        'lines' => [['category' => 'retailer', 'gross_sales' => 800000]],
        'gross_sales' => 800000,
        'flags' => ['is_petroleum'],
    ]);
    $r = app(FeeCalculator::class)->assess($app);

    expect(amountOf($r, 'biztax.retailer'))->toBeNull()
        ->and(amountOf($r, 'exempt.petroleum'))->toBe(0.0);
});

it('prefers the specific mayors-permit line over the item-64 catch-all (Sec. 3A.03)', function () {
    $app = feeApp(['BUSINESS'], 'new', [
        'lines' => [['category' => 'carinderia', 'capitalization' => 300000]],
        'capitalization' => 300000,
        'floor_area_sqm' => 40,
        'business_structure' => 'corporation',
    ]);
    $r = app(FeeCalculator::class)->assess($app);

    expect(amountOf($r, 'permit.carinderia'))->toBe(550.0)
        ->and(amountOf($r, 'permit.catchall_office_area'))->toBeNull()
        ->and(amountOf($r, 'permit.filing_fee'))->toBe(100.0)
        ->and(amountOf($r, 'ctc.corporation_new'))->toBe(500.0);
});

it('charges FSIC at 10% of permit + regulatory lines, computed last (RA 9514)', function () {
    $app = feeApp(['BUSINESS', 'FSIC'], 'new', [
        'lines' => [['category' => 'carinderia', 'capitalization' => 300000]],
        'capitalization' => 300000,
        'floor_area_sqm' => 40,
    ]);
    $r = app(FeeCalculator::class)->assess($app);

    $base = collect($r['items'])
        ->whereIn('group', ['mayors_permit', 'regulatory'])
        ->sum('amount');
    expect(amountOf($r, 'fire.fsic'))->toBe(round($base * 0.10, 2))
        ->and($base)->toBeGreaterThan(0);
});

it('keeps only the highest environmental bracket (Sec. 3W.02)', function () {
    $app = feeApp(['CEC'], 'renewal', [
        'lines' => [
            ['category' => 'gasoline_lpg_station'],       // Bracket I: 2,500
            ['category' => 'water_refilling_station'],    // Bracket II: 1,500
        ],
        'capitalization' => 500000,
    ]);
    $r = app(FeeCalculator::class)->assess($app);

    $envItems = collect($r['items'])->filter(fn ($i) => str_starts_with($i['code'], 'env.') && ! $i['requires_officer']);
    expect($envItems)->toHaveCount(1)
        ->and($envItems->first()['amount'])->toBe(2500.0);
});

it('emits a P0 officer line for PIL when no gross sales are declared (Sec. 2O.01)', function () {
    $app = feeApp(['BUSINESS'], 'renewal', [
        'lines' => [['category' => 'retailer']],
        'flags' => ['no_gross_sales_declared'],
    ]);
    $r = app(FeeCalculator::class)->assess($app);

    $pil = collect($r['items'])->firstWhere('code', 'pil.presumptive_income');
    expect($pil)->not->toBeNull()
        ->and($pil['amount'])->toBe(0.0)
        ->and($pil['requires_officer'])->toBeTrue();
});

it('caps late-payment interest at 36 months (Secs. 8A.04/8A.05)', function () {
    $p = app(FeeCalculator::class)->latePenalty(10000, 40);

    // surcharge 25% = 2,500; interest 2% x 36 months on 12,500 = 9,000
    expect($p['surcharge'])->toBe(2500.0)
        ->and($p['months_counted'])->toBe(36)
        ->and($p['interest'])->toBe(9000.0)
        ->and($p['total'])->toBe(21500.0);
});

it('assessFees persists the itemized tax order with citations', function () {
    $app = feeApp(['BUSINESS'], 'renewal', [
        'lines' => [['category' => 'retailer', 'gross_sales' => 800000]],
        'gross_sales' => 800000,
    ]);
    $fee = app(App\Services\WorkflowService::class)->assessFees($app);

    expect($fee->line_items)->not->toBeEmpty()
        ->and(collect($fee->line_items)->firstWhere('code', 'biztax.retailer')['section'])->toBe('Sec. 2J.02(d)')
        ->and((float) $fee->total_amount)->toBe(round(collect($fee->line_items)->sum('amount'), 2));
});
