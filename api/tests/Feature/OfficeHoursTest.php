<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Support\OfficeHours;
use Illuminate\Support\Carbon;

/*
 * Out-of-office-hours notice and record [checklist 2026-09-27, Login 6].
 *
 * Times are set in Manila and the app clock runs in UTC, which is the point:
 * the answer must come from Manila's day, not the server's.
 */

function atManila(string $when): void
{
    test()->travelTo(Carbon::parse($when, 'Asia/Manila'));
}

it('is open on a weekday between eight and five, Manila time', function () {
    atManila('2026-09-28 08:00'); // Monday
    expect(OfficeHours::isOpen())->toBeTrue();

    atManila('2026-09-28 16:59');
    expect(OfficeHours::isOpen())->toBeTrue();
});

it('is closed before eight, from five sharp, and at weekends', function (string $when) {
    atManila($when);
    expect(OfficeHours::isOpen())->toBeFalse();
})->with([
    'Monday 07:59' => '2026-09-28 07:59',
    'Monday 17:00' => '2026-09-28 17:00',
    'Saturday noon' => '2026-09-26 12:00',
    'Sunday noon' => '2026-09-27 12:00',
]);

it('reads the day in Manila, not in UTC', function () {
    // 01:00 UTC Monday is 09:00 Monday in Manila: open, though UTC says 1 a.m.
    $this->travelTo(Carbon::parse('2026-09-28 01:00', 'UTC'));
    expect(OfficeHours::isOpen())->toBeTrue();

    // 10:00 UTC Friday is 18:00 Friday in Manila: closed, though UTC says mid-morning.
    $this->travelTo(Carbon::parse('2026-10-02 10:00', 'UTC'));
    expect(OfficeHours::isOpen())->toBeFalse();
});

it('treats a configured holiday as closed', function () {
    config(['office_hours.holidays' => ['2026-11-30']]); // Bonifacio Day, a Monday
    atManila('2026-11-30 10:00');
    expect(OfficeHours::isOpen())->toBeFalse();
});

it('takes its hours from config, not from code', function () {
    config(['office_hours.closes' => '12:00']);
    atManila('2026-09-28 13:00');
    expect(OfficeHours::isOpen())->toBeFalse();
});

it('serves the answer and the server time to anyone', function () {
    atManila('2026-09-26 21:15');

    $this->getJson('/api/v1/office-hours')
        ->assertOk()
        ->assertJsonPath('data.open', false)
        ->assertJsonPath('data.timezone', 'Asia/Manila')
        ->assertJsonPath('data.opens', '08:00')
        ->assertJsonPath('data.closes', '17:00')
        ->assertJsonPath('data.now', '2026-09-26T21:15:00+08:00');
});

it('records an officer signing in outside office hours', function (string $email, string $portal) {
    atManila('2026-09-26 21:15'); // Saturday night

    $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'biztrack1', 'portal' => $portal])->assertOk();

    $row = AuditLog::where('action', 'user.signed_in_outside_hours')->latest('id')->firstOrFail();
    expect($row->user_id)->toBe(User::where('email', $email)->value('id'));
    expect($row->changes['portal'])->toBe($portal);
    // The month in full, as in every date (checklist, Apply for Permit 7).
    expect($row->changes['local_time'])->toBe('Sat, September 26, 2026 21:15');
})->with([
    'officer' => ['bplo@biztrack.local', 'staff'],
    'super admin' => ['admin@biztrack.local', 'admin'],
]);

it('records nothing for an officer signing in during office hours', function () {
    atManila('2026-09-28 10:00');

    $this->postJson('/api/v1/auth/login', ['email' => 'bplo@biztrack.local', 'password' => 'biztrack1', 'portal' => 'staff'])->assertOk();

    expect(AuditLog::where('action', 'user.signed_in_outside_hours')->count())->toBe(0);
});

it('records nothing for a business owner signing in at night', function () {
    atManila('2026-09-26 23:00');

    $this->postJson('/api/v1/auth/login', ['email' => 'owner@biztrack.local', 'password' => 'biztrack1'])->assertOk();

    expect(AuditLog::where('action', 'user.signed_in_outside_hours')->count())->toBe(0);
});

it('names who signed in on the ordinary sign-in row too', function () {
    $this->postJson('/api/v1/auth/login', ['email' => 'owner@biztrack.local', 'password' => 'biztrack1'])->assertOk();

    expect(AuditLog::where('action', 'user.logged_in')->latest('id')->first()->user_id)
        ->toBe(User::where('email', 'owner@biztrack.local')->value('id'));
});

/*
 * hoursBetween() is the clock the office-performance figures run on: only time
 * when City Hall is open counts against an office. Dates are Manila time;
 * 28 September 2026 is a Monday.
 */
it('counts only office hours between two instants', function (string $from, string $to, float $hours) {
    $at = fn (string $s) => Carbon::parse($s, 'Asia/Manila');

    expect(OfficeHours::hoursBetween($at($from), $at($to)))->toEqualWithDelta($hours, 0.001);
})->with([
    'within one office day' => ['2026-09-28 09:00', '2026-09-28 11:30', 2.5],
    'filed at 7 pm, finished 9 am next day' => ['2026-09-28 19:00', '2026-09-29 09:00', 1.0],
    'filed at 7 pm, finished before opening' => ['2026-09-28 19:00', '2026-09-29 07:30', 0.0],
    'filed Friday 7 pm, finished Monday 9 am' => ['2026-10-02 19:00', '2026-10-05 09:00', 1.0],
    'filed Saturday, finished Monday at closing' => ['2026-10-03 10:00', '2026-10-05 17:00', 9.0],
    'a full office week' => ['2026-09-28 08:00', '2026-10-02 17:00', 45.0],
    'a lunchtime-to-next-noon review' => ['2026-09-28 12:00', '2026-09-29 12:00', 9.0],
    'end before start' => ['2026-09-29 10:00', '2026-09-28 10:00', 0.0],
]);

it('reads instants in Manila time whatever zone they arrive in', function () {
    // 11:00 UTC is 19:00 in Manila, after closing; 01:00 UTC next day is 09:00.
    $from = Carbon::parse('2026-09-28 11:00', 'UTC');
    $to = Carbon::parse('2026-09-29 01:00', 'UTC');

    expect(OfficeHours::hoursBetween($from, $to))->toEqualWithDelta(1.0, 0.001);
});

it('makes an office day nine office hours long', function () {
    expect(OfficeHours::hoursPerDay())->toBe(9.0);
});
