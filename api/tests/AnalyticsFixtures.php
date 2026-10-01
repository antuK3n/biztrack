<?php

/*
 * Register rows for the analytics tests, written straight to the tables with
 * the timestamps a test needs.
 *
 * The analytics read the register, not the workflow, and most of what they
 * get wrong is about WHEN: a filing at 7 am Manila on the 1st, a permit that
 * lapsed yesterday, two months in the applicant's hands. Driving the workflow
 * to produce those rows would mean travelling the clock between every step;
 * writing them is one line each and says exactly which instant is under test.
 *
 * Every timestamp passed in is a UTC instant string ("2026-09-30 17:00:00"),
 * which is what the database holds. Comments in the tests say what it is in
 * Manila.
 *
 * Nothing here deletes seeded rows. Tests either measure a change (the figure
 * with these rows minus the figure without) or work inside an office of their
 * own (anaOffice), so the demo register underneath does not have to be known.
 */

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function anaOwnerId(): int
{
    return (int) User::where('email', 'owner@biztrack.local')->value('id');
}

function anaDepartmentId(string $code): int
{
    return (int) DB::table('departments')->where('code', $code)->value('id');
}

function anaPermitTypeId(string $code): int
{
    return (int) DB::table('permit_types')->where('code', $code)->value('id');
}

/**
 * An office of the test's own, issuing one permit type of its own, so a scoped
 * figure counts only what the test wrote.
 *
 * @return array{code: string, department_id: int, permit_type_id: int, permit_code: string}
 */
function anaOffice(string $code = 'TST', bool $inspects = false): array
{
    $now = now();
    $departmentId = DB::table('departments')->insertGetId([
        'code' => $code, 'name' => "Test Office {$code}", 'created_at' => $now, 'updated_at' => $now,
    ]);
    $permitCode = "{$code}PT";
    $typeId = DB::table('permit_types')->insertGetId([
        'code' => $permitCode,
        'name' => "Test Permit {$code}",
        'permit_number_prefix' => "{$code}-",
        'issuing_department_id' => $departmentId,
        'validity_days' => 365,
        'requires_inspection' => $inspects,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return ['code' => $code, 'department_id' => $departmentId, 'permit_type_id' => $typeId, 'permit_code' => $permitCode];
}

/** @param  array<string, mixed>  $attrs */
function anaBusiness(array $attrs = []): int
{
    $at = $attrs['created_at'] ?? now()->toDateTimeString();

    return DB::table('businesses')->insertGetId($attrs + [
        'owner_user_id' => anaOwnerId(),
        'name' => 'Analytics Fixture '.Str::random(8),
        'ban' => 'BP-TEST-'.Str::upper(Str::random(10)),
        'status' => 'active',
        'is_active' => true,
        'created_at' => $at,
        'updated_at' => $at,
    ]);
}

/** @param  array<string, mixed>  $attrs */
function anaFiling(int $businessId, array $attrs = []): int
{
    $at = $attrs['created_at'] ?? $attrs['submitted_at'] ?? now()->toDateTimeString();

    return DB::table('applications')->insertGetId($attrs + [
        'tracking_id' => 'BIZ-TEST-'.Str::upper(Str::random(12)),
        'business_id' => $businessId,
        'applicant_user_id' => anaOwnerId(),
        'application_type' => 'new',
        'status' => 'for_approval',
        'created_at' => $at,
        'updated_at' => $at,
    ]);
}

/** @param  array<string, mixed>  $attrs */
function anaPermit(int $businessId, string $typeCode, array $attrs = []): int
{
    $issued = $attrs['issued_at'] ?? now()->toDateTimeString();

    return DB::table('permits')->insertGetId($attrs + [
        'permit_number' => 'TEST-'.Str::upper(Str::random(14)),
        'business_id' => $businessId,
        'permit_type_id' => anaPermitTypeId($typeCode),
        'status' => 'active',
        'valid_from' => substr((string) $issued, 0, 10),
        'valid_until' => now()->addYear()->toDateString(),
        'issued_at' => $issued,
        'created_at' => $issued,
        'updated_at' => $issued,
    ]);
}

/** @param  array<string, mixed>  $attrs */
function anaAssignment(int $applicationId, string $departmentCode, array $attrs = []): int
{
    $at = $attrs['assigned_at'] ?? now()->toDateTimeString();

    return DB::table('application_assignments')->insertGetId($attrs + [
        'application_id' => $applicationId,
        'department_id' => anaDepartmentId($departmentCode),
        'status' => array_key_exists('completed_at', $attrs) && $attrs['completed_at'] !== null ? 'completed' : 'pending',
        'assigned_at' => $at,
        'created_at' => $at,
        'updated_at' => $at,
    ]);
}

/**
 * The filing carries this permit type (an application_permit_types row).
 *
 * @param  array<string, mixed>  $attrs
 */
function anaCarries(int $applicationId, string $typeCode, array $attrs = []): int
{
    return DB::table('application_permit_types')->insertGetId($attrs + [
        'application_id' => $applicationId,
        'permit_type_id' => anaPermitTypeId($typeCode),
        'status' => 'for_approval',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * A filing's status history, one [to_status, at] pair per move, in order.
 *
 * @param  list<array{0: string, 1: string}>  $moves
 */
function anaHistory(int $applicationId, array $moves): void
{
    $from = null;
    foreach ($moves as [$to, $at]) {
        DB::table('application_status_history')->insert([
            'application_id' => $applicationId,
            'from_status' => $from,
            'to_status' => $to,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
        $from = $to;
    }
}
