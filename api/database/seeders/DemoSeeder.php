<?php

namespace Database\Seeders;

use App\Enums\ApplicationStatus;
use App\Enums\ApplicationType;
use App\Enums\AssignmentStatus;
use App\Enums\OfficerRequestStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PermitStatus;
use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\ApplicationStatusHistory;
use App\Models\Barangay;
use App\Models\Business;
use App\Models\BusinessAddress;
use App\Models\BusinessLine;
use App\Models\BusinessOwner;
use App\Models\Department;
use App\Models\FeeAssessment;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\OfficerRequest;
use App\Models\Payment;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Models\Role;
use App\Models\User;
use App\Support\Numbering;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/** Demo accounts + a defense storyline (master plan §11 DemoSeeder). */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $password = env('DEMO_PASSWORD', 'biztrack1');
        $bplo = Department::where('code', 'BPLO')->first();
        $cho = Department::where('code', 'CHO')->first();
        $bfp = Department::where('code', 'BFP')->first();
        $obo = Department::where('code', 'OBO')->first();
        $cenro = Department::where('code', 'CENRO')->first();
        $market = Department::where('code', 'CMO-MARKET')->first();
        $cpdo = Department::where('code', 'CPDO')->first();

        // --- Demo accounts (one per role) -----------------------------------
        $owner = $this->user('owner@biztrack.local', 'Nena', 'Dela Cruz', 'F', $password, ['business_owner']);
        $this->user('bplo@biztrack.local', 'Liza', 'Reyes', 'F', $password, ['bplo_staff'], $bplo);
        $this->user('sanitary@biztrack.local', 'Carlos', 'Dizon', 'M', $password, ['sanitary_officer'], $cho);
        $this->user('fire@biztrack.local', 'Ferdie', 'Lim', 'M', $password, ['fire_inspector'], $bfp);
        $this->user('obo@biztrack.local', 'Ana', 'Villar', 'F', $password, ['obo_staff'], $obo);
        $this->user('cenro@biztrack.local', 'Ben', 'Cruz', 'M', $password, ['cenro_officer'], $cenro);
        $this->user('market@biztrack.local', 'Dina', 'Flores', 'F', $password, ['market_admin'], $market);
        // CPDO reviews the zoning / locational clearance queue (tester item 53).
        $this->user('zoning@biztrack.local', 'Elena', 'Bautista', 'F', $password, ['zoning_officer'], $cpdo);
        $this->user('admin@biztrack.local', 'Ramon', 'Santos', 'M', $password, ['admin']);
        $this->user('inactive@biztrack.local', 'Mario', 'Santos', 'M', $password, ['business_owner'], null, false);

        // Fresh-seed guard: only build the story once.
        if (Application::exists()) {
            return;
        }

        $bploStaff = User::where('email', 'bplo@biztrack.local')->first();
        $businessPt = PermitType::where('code', 'BUSINESS')->first();
        $sanitaryPt = PermitType::where('code', 'SANITARY')->first();
        $fsicPt = PermitType::where('code', 'FSIC')->first();
        $longos = Barangay::where('name', 'Longos')->first();
        $tinajeros = Barangay::where('name', 'Tinajeros')->first();
        $sariSari = PsicCode::where('code', '47111')->first();
        $carinderia = PsicCode::where('code', '56101')->first();
        $pharmacy = PsicCode::where('code', '47721')->first();

        // === Business 1: Nena's Sari-Sari Store (approved, scannable permit) ==
        $b1 = Business::create([
            'owner_user_id' => $owner->id,
            'name' => "Nena's Sari-Sari Store",
            'trade_name' => "Nena's Store",
            'registration_type' => 'DTI',
            'registration_number' => 'DTI-01923845',
            'tin' => '284-991-233-000',
            'ban' => Numbering::ban(),
        ]);
        BusinessAddress::create([
            'business_id' => $b1->id, 'line1' => '12 Gen. Luna St.', 'barangay_id' => $longos->id,
            'latitude' => 14.6690, 'longitude' => 120.9560,
        ]);
        BusinessLine::create(['business_id' => $b1->id, 'psic_code_id' => $sariSari->id, 'capitalization' => 150000]);
        BusinessLine::create(['business_id' => $b1->id, 'psic_code_id' => $carinderia->id, 'capitalization' => 80000]);
        // Paper Table 41: personal owner details (coexists with owner_user_id).
        BusinessOwner::create([
            'business_id' => $b1->id, 'surname' => 'Dela Cruz', 'given_name' => 'Nena',
            'gender' => 'F', 'is_primary' => true,
        ]);

        /*
         * Nena's story opens on a renewal, and what it renewed was one of the
         * old paper permits — there is nothing earlier in this register for it
         * to point at. Said out loud rather than left as a bare null: a null
         * prior permit no longer stands on its own, and this seeder writing one
         * in silence is where the first of the seven renewals-of-nothing came
         * from. Demo data that could not pass the submit gate teaches the wrong
         * shape to everything read off it.
         */
        $app1 = $this->application($b1, $owner, ApplicationType::Renewal, ApplicationStatus::Approved, [$businessPt], now()->subDays(20));
        $app1->update(['prior_permit_declared_none' => true]);
        $this->history($app1, [
            [null, 'draft', $owner], ['draft', 'submitted', $owner], ['submitted', 'pending_payment', null],
            ['pending_payment', 'under_review', null], ['under_review', 'approved', $bploStaff],
        ], now()->subDays(20));
        $this->paidFee($app1, 1150);
        $permit1 = Permit::create([
            'permit_number' => Numbering::permitNumber('MCB'),
            'application_id' => $app1->id, 'business_id' => $b1->id, 'permit_type_id' => $businessPt->id,
            'status' => PermitStatus::Active, 'valid_from' => now()->subDays(18)->toDateString(),
            'valid_until' => now()->endOfYear()->toDateString(), 'issued_at' => now()->subDays(18),
            'issued_by_user_id' => $bploStaff->id,
        ]);
        $app1->update(['decided_at' => now()->subDays(18)]);

        // === Business 2: RxCare Pharmacy (mid-review, 3 departments) =========
        $owner2 = $this->user('juan@biztrack.local', 'Juan', 'Ramos', 'M', $password, ['business_owner']);
        $b2 = Business::create([
            'owner_user_id' => $owner2->id, 'name' => 'RxCare Pharmacy',
            'registration_type' => 'SEC', 'registration_number' => 'SEC-2026-4471',
            'tin' => '112-887-441-000', 'ban' => Numbering::ban(),
        ]);
        BusinessAddress::create([
            'business_id' => $b2->id, 'line1' => '88 Rizal Ave.', 'barangay_id' => $tinajeros->id,
            'latitude' => 14.6712, 'longitude' => 120.9605,
        ]);
        BusinessLine::create(['business_id' => $b2->id, 'psic_code_id' => $pharmacy->id, 'capitalization' => 500000]);
        BusinessOwner::create([
            'business_id' => $b2->id, 'surname' => 'Ramos', 'given_name' => 'Juan',
            'gender' => 'M', 'is_primary' => true,
        ]);

        $app2 = $this->application($b2, $owner2, ApplicationType::New, ApplicationStatus::UnderReview, [$businessPt, $sanitaryPt, $fsicPt], now()->subDays(3));
        $this->history($app2, [
            [null, 'draft', $owner2], ['draft', 'submitted', $owner2], ['submitted', 'pending_payment', null],
            ['pending_payment', 'under_review', null],
        ], now()->subDays(3));
        $this->paidFee($app2, 2610);
        foreach ([[$bplo, AssignmentStatus::Completed], [$cho, AssignmentStatus::InProgress], [$bfp, AssignmentStatus::Pending]] as [$dept, $st]) {
            ApplicationAssignment::create([
                'application_id' => $app2->id, 'department_id' => $dept->id, 'status' => $st,
                'assigned_at' => now()->subDays(2),
                'completed_at' => $st === AssignmentStatus::Completed ? now()->subDay() : null,
            ]);
        }

        // --- Demo messaging + an officer request on app2 (UI demo content) ---
        $thread = MessageThread::create(['application_id' => $app2->id]);
        Message::create([
            'thread_id' => $thread->id, 'sender_user_id' => $bploStaff->id,
            'body' => 'Hi! We started reviewing your application. Please keep an eye out for any requirement requests.',
            'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2),
        ]);
        Message::create([
            'thread_id' => $thread->id, 'sender_user_id' => $owner2->id,
            'body' => 'Thank you! I will respond right away to anything you need.',
            'created_at' => now()->subDays(2)->addHours(1), 'updated_at' => now()->subDays(2)->addHours(1),
        ]);
        OfficerRequest::create([
            'application_id' => $app2->id, 'requested_by_user_id' => $bploStaff->id,
            'department_id' => $bplo->id,
            'request_type' => 'document', 'title' => 'Updated locational clearance',
            'description' => 'Please upload your latest locational (zoning) clearance so we can complete the BPLO review.',
            'status' => OfficerRequestStatus::Pending,
        ]);

        // === An expiring permit (compliance demo) ===========================
        $app3 = $this->application($b1, $owner, ApplicationType::New, ApplicationStatus::Approved, [$businessPt], now()->subMonths(11));
        $this->paidFee($app3, 1150);
        Permit::create([
            'permit_number' => Numbering::permitNumber('MCB'),
            'application_id' => $app3->id, 'business_id' => $b1->id, 'permit_type_id' => $businessPt->id,
            'status' => PermitStatus::Active, 'valid_from' => now()->subMonths(11)->toDateString(),
            'valid_until' => now()->addDays(7)->toDateString(), 'issued_at' => now()->subMonths(11),
            'issued_by_user_id' => $bploStaff->id,
        ]);
    }

    private function user(string $email, string $first, string $last, string $gender, string $password, array $roles, ?Department $dept = null, bool $active = true): User
    {
        $user = User::updateOrCreate(['email' => $email], [
            'name' => "$first $last", 'first_name' => $first, 'last_name' => $last,
            'gender' => $gender, 'mobile_number' => '09171234567',
            'password' => Hash::make($password), 'department_id' => $dept?->id,
            'is_active' => $active, 'data_privacy_consent_at' => now(),
            'email_verified_at' => now(),
        ]);
        $roleIds = Role::whereIn('name', $roles)->pluck('id');
        $user->roles()->sync($roleIds);

        return $user;
    }

    private function application(Business $b, User $owner, ApplicationType $type, ApplicationStatus $status, array $permitTypes, $submittedAt): Application
    {
        $app = Application::create([
            'tracking_id' => Numbering::trackingId(),
            'business_id' => $b->id, 'applicant_user_id' => $owner->id,
            'application_type' => $type, 'status' => $status,
            'submitted_at' => $submittedAt, 'deadline_at' => (clone $submittedAt)->addDays(10),
        ]);
        $app->permitTypes()->sync(collect($permitTypes)->pluck('id'));

        return $app;
    }

    private function history(Application $app, array $rows, $baseTime): void
    {
        $t = clone $baseTime;
        foreach ($rows as [$from, $to, $by]) {
            ApplicationStatusHistory::create([
                'application_id' => $app->id, 'from_status' => $from, 'to_status' => $to,
                'changed_by_user_id' => $by?->id, 'created_at' => $t, 'updated_at' => $t,
            ]);
            $t = (clone $t)->addHours(6);
        }
    }

    private function paidFee(Application $app, float $amount): void
    {
        $fee = FeeAssessment::create([
            'application_id' => $app->id,
            'line_items' => [['label' => 'Permit fees', 'amount' => $amount]],
            'total_amount' => $amount,
        ]);
        Payment::create([
            'application_id' => $app->id, 'fee_assessment_id' => $fee->id,
            'reference_number' => Numbering::paymentReference(), 'amount' => $amount,
            'method' => PaymentMethod::Gcash, 'status' => PaymentStatus::Completed,
            'paid_at' => $app->submitted_at,
        ]);
    }
}
