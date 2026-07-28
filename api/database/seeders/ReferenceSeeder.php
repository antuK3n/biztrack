<?php

namespace Database\Seeders;

use App\Models\Barangay;
use App\Models\Department;
use App\Models\DocumentType;
use App\Models\PermitType;
use App\Models\PsicCode;
use Illuminate\Database\Seeder;

/** Reference & lookup data (master plan §11). Idempotent (updateOrCreate). */
class ReferenceSeeder extends Seeder
{
    public function run(): void
    {
        // --- Departments (paper Table 29 + prototype LGU Section p37) --------
        $departments = [
            ['code' => 'BPLO', 'name' => 'Business Permits and Licensing Office',
                'description' => 'Processes and issues business permits; central intake for applications.'],
            ['code' => 'CHO', 'name' => 'City Health Office',
                'description' => 'Issues sanitary permits and health certificates.'],
            ['code' => 'BFP', 'name' => 'Bureau of Fire Protection',
                'description' => 'Conducts fire safety inspections and issues the FSIC.'],
            ['code' => 'CPDO', 'name' => 'City Planning and Development Office (Zoning)',
                'description' => 'Handles zoning/locational clearance (zoning permit excluded until official zone data arrives).'],
            ['code' => 'OBO', 'name' => 'Office of the Building Official',
                'description' => 'Issues occupancy permits for business premises.'],
            ['code' => 'CENRO', 'name' => 'City Environment and Natural Resources Office',
                'description' => 'Issues the City Environmental Certificate.'],
            ['code' => 'CMO-MARKET', 'name' => 'Office of the City Market Administrator',
                'description' => 'Issues market clearance for market-based businesses.'],
        ];
        foreach ($departments as $d) {
            Department::updateOrCreate(['code' => $d['code']], $d);
        }

        // --- Barangays (21, Malabon; verify vs PSGC before defense) ----------
        $barangays = [
            'Acacia', 'Baritan', 'Bayan-bayanan', 'Catmon', 'Concepcion', 'Dampalit',
            'Flores', 'Hulong Duhat', 'Ibaba', 'Longos', 'Maysilo', 'Muzon', 'Niugan',
            'Panghulo', 'Potrero', 'San Agustin', 'Santulan', 'Tañong', 'Tinajeros',
            'Tonsuya', 'Tugatog',
        ];
        foreach ($barangays as $name) {
            Barangay::updateOrCreate(['name' => $name]);
        }

        // --- PSIC codes (~15 illustrative; verify vs PSA PSIC 2019) ----------
        $psic = [
            ['47111', 'Retail sale in non-specialized stores (sari-sari store)'],
            ['56101', 'Restaurants and carinderia'],
            ['10711', 'Manufacture of bakery products (bakeshop)'],
            ['47721', 'Retail sale of pharmaceutical goods (pharmacy)'],
            ['47521', 'Retail sale of hardware and building materials'],
            ['96200', 'Laundry and dry-cleaning services'],
            ['36000', 'Water collection, treatment and supply (water refilling)'],
            ['18120', 'Printing services'],
            ['96110', 'Barbershop and hairdressing'],
            ['45201', 'Maintenance and repair of motor vehicles (auto repair)'],
            ['47411', 'Retail sale of computers and peripheral equipment'],
            ['56301', 'Beverage serving activities (coffee shop)'],
            ['47912', 'Retail sale via internet (online store)'],
            ['82990', 'Other business support service activities'],
            ['93110', 'Operation of sports and fitness facilities (gym)'],
        ];
        foreach ($psic as [$code, $title]) {
            PsicCode::updateOrCreate(['code' => $code], ['title' => $title]);
        }

        // --- Document types --------------------------------------------------
        $docs = [
            ['DTI_SEC_CDA', 'Business Registration (DTI / SEC / CDA)', 'Your DTI, SEC, or CDA certificate of registration.'],
            ['LEASE_TITLE', 'Lease Contract or Land Title', 'Proof you can operate at this address: a lease contract or land title.'],
            ['BRGY_CLEARANCE', 'Barangay Business Clearance', 'A clearance from the barangay where your business is located.'],
            ['CEDULA', 'Community Tax Certificate (Cedula)', 'Your current cedula.'],
            ['VALID_ID', 'Valid Government ID', 'Any government-issued ID of the owner or authorized representative.'],
            ['PRIOR_PERMIT', 'Previous Mayor\'s Permit', 'Your last Mayor\'s Permit. Needed for renewals only.'],
            ['OCCUPANCY', 'Occupancy Permit', 'Certificate of occupancy for the building, where applicable.'],
            ['SANITARY_REQ', 'Sanitary Requirements', 'Health cards and sanitary documents for food-related businesses.'],
            ['FIRE_REQ', 'Fire Safety Requirements', 'Fire safety documents required for the FSIC.'],
            ['LOCATIONAL', 'Locational / Zoning Clearance', 'Zoning clearance for your business location.'],
            // Repeatable "Other Requirements": applicants may attach several files.
            ['OTHER', 'Other Requirements', 'Any other supporting documents. You can add more than one file.'],
        ];
        foreach ($docs as [$code, $name, $help]) {
            DocumentType::updateOrCreate(['code' => $code], ['name' => $name, 'help_text' => $help]);
        }

        // --- Permit types (6, prototype LGU Section p37; validity 365) -------
        // Manuscript names 3 (BUSINESS/SANITARY/FSIC); the extra 3 are additive
        // per the prototype (most-recent team agreement). Zoning stays excluded.
        $dept = fn (string $c) => Department::where('code', $c)->first()->id;

        $business = PermitType::updateOrCreate(['code' => 'BUSINESS'], [
            'name' => "Mayor's / Business Permit",
            'permit_number_prefix' => 'MCB',
            'issuing_department_id' => $dept('BPLO'),
            'validity_days' => 365, 'description' => "Annual mayor's permit to operate.",
            'requires_inspection' => false,
            'base_fee' => 1000, 'per_line_surcharge' => 150,
        ]);
        $sanitary = PermitType::updateOrCreate(['code' => 'SANITARY'], [
            'name' => 'Sanitary Permit / Health Certificate',
            'permit_number_prefix' => 'MCS',
            'issuing_department_id' => $dept('CHO'),
            'validity_days' => 365, 'description' => 'Health/sanitary clearance to operate.',
            'requires_inspection' => true,
            'base_fee' => 500, 'per_line_surcharge' => 50,
        ]);
        $fsic = PermitType::updateOrCreate(['code' => 'FSIC'], [
            'name' => 'Fire Safety Inspection Certificate',
            'permit_number_prefix' => 'MCF',
            'issuing_department_id' => $dept('BFP'),
            'validity_days' => 365, 'description' => 'Fire safety clearance to operate.',
            'requires_inspection' => true,
            'base_fee' => 700, 'per_line_surcharge' => 80,
        ]);
        $occupancy = PermitType::updateOrCreate(['code' => 'OCCUPANCY'], [
            'name' => 'Occupancy Permit',
            'permit_number_prefix' => 'MCO',
            'issuing_department_id' => $dept('OBO'),
            'validity_days' => 365, 'description' => 'Certificate of occupancy for the business premises.',
            'requires_inspection' => false,
            'base_fee' => 800, 'per_line_surcharge' => 0,
        ]);
        $cec = PermitType::updateOrCreate(['code' => 'CEC'], [
            'name' => 'City Environmental Certificate',
            'permit_number_prefix' => 'MCE',
            'issuing_department_id' => $dept('CENRO'),
            'validity_days' => 365, 'description' => 'Environmental compliance certificate.',
            'requires_inspection' => false,
            'base_fee' => 400, 'per_line_surcharge' => 0,
        ]);
        $market = PermitType::updateOrCreate(['code' => 'MARKET'], [
            'name' => 'Market Clearance',
            'permit_number_prefix' => 'MCM',
            'issuing_department_id' => $dept('CMO-MARKET'),
            'validity_days' => 365, 'description' => 'Clearance for market-based businesses.',
            'requires_inspection' => false,
            'base_fee' => 300, 'per_line_surcharge' => 0,
        ]);

        // --- Requirement checklists (context per paper Table 59 enum) --------
        $byCode = fn (string $c) => DocumentType::where('code', $c)->first()->id;
        $req = function (PermitType $pt, array $rows) use ($byCode) {
            $sync = [];
            foreach ($rows as $code => $meta) {
                $sync[$byCode($code)] = [
                    'context' => $meta['context'] ?? 'all',
                    'is_mandatory' => $meta['is_mandatory'] ?? true,
                    'notes' => $meta['notes'] ?? null,
                ];
            }
            $pt->documentTypes()->sync($sync);
        };
        $req($business, [
            'DTI_SEC_CDA' => [], 'LEASE_TITLE' => [], 'BRGY_CLEARANCE' => [],
            'CEDULA' => [], 'VALID_ID' => [], 'OCCUPANCY' => [],
            'PRIOR_PERMIT' => ['context' => 'renewal', 'notes' => 'Required for renewals only.'],
        ]);
        $req($sanitary, ['SANITARY_REQ' => [], 'VALID_ID' => []]);
        $req($fsic, ['FIRE_REQ' => [], 'VALID_ID' => []]);
        $req($occupancy, [
            'OCCUPANCY' => ['context' => 'occupancy'],
            'VALID_ID' => ['context' => 'occupancy'],
        ]);
        $req($cec, ['LOCATIONAL' => [], 'VALID_ID' => []]);
        $req($market, ['BRGY_CLEARANCE' => [], 'VALID_ID' => []]);
    }
}
