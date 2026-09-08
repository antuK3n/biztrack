<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Department;
use App\Models\FeeRule;
use App\Models\PermitType;
use App\Models\User;
use App\Support\Ra11032;
use Illuminate\Support\Collection;

/**
 * Rule-based chatbot brain (no LLM). Keyword intent matching, Taglish-tolerant.
 *
 * Two things keep the answers useful: the intent is scored (the longest keyword
 * hit wins, so "how much to pay" is a fee question, not a how-to-pay question),
 * and every answer is scoped to the permit type or office the user actually
 * named. The full six-permit rundown only comes out for genuinely broad asks.
 *
 * On top of the permit intents there is a field layer: someone in the middle of
 * the wizard asks what a box on the form is for, not what a permit costs. Those
 * questions are answered from FIELD_RULES before the permit intents get a look,
 * because "in the sanitary permit application, what is the water source for?"
 * names a permit but is not a question about the permit.
 *
 * Tracking-id lookups are scoped to the asking user's own applications only.
 */
class ChatbotResponder
{
    private const TRACKING_PATTERN = '/BIZ-\d{4}-\d{5}/i';

    /** Looks like someone is quoting a tracking id, but it is not a valid one. */
    private const NEAR_MISS_TRACKING_PATTERN = '/\bbiz[\s\-_]?\d/i';

    /**
     * Intent => keywords. Order only breaks ties; the longest keyword that
     * matches decides the intent, so specific phrases beat stray short words.
     */
    private const INTENT_RULES = [
        'requirements' => ['requirement', 'requirements', 'kailangan', 'kelangan', 'dokumento', 'document', 'documents', 'checklist', 'dala', 'ipasa', 'submit ko', 'upload'],
        'renewal' => ['renew', 'renewal', 'renewing', 'magrenew', 'deadline', 'expire', 'expires', 'expiry', 'expiration', 'january', 'enero', 'palugit', 'valid until', 'validity'],
        'payment' => ['how to pay', 'how do i pay', 'paano magbayad', 'pano magbayad', 'paano bayaran', 'magbayad', 'pay online', 'payment', 'payments', 'pay', 'gcash', 'maya', 'over the counter', 'receipt', 'resibo'],
        'fees' => ['fee', 'fees', 'bayad', 'babayaran', 'magkano', 'how much', 'cost', 'presyo', 'price', 'surcharge', 'penalty', 'multa', 'interest'],
        'status' => ['status', 'asan', 'nasaan', 'saan na', 'track', 'tracking', 'progress', 'update', 'follow up', 'follow-up', 'kamusta na', 'approved na'],
        'offices' => ['office', 'opisina', 'tanggapan', 'contact', 'department', 'bplo', 'sino', 'who reviews', 'who handles', 'who issues', 'which office', 'anong opisina', 'in charge'],
        'hours' => ['how long', 'gaano katagal', 'ilang araw', 'working day', 'working days', 'processing time', 'release', 'hours', 'kailan', 'tagal', 'matagal'],
        'greeting' => ['hello', 'hi', 'hey', 'kumusta', 'kamusta', 'musta', 'magandang', 'good morning', 'good afternoon', 'good evening', 'salamat', 'thanks', 'thank you'],
    ];

    /** Permit type code => the words testers really type for it, Taglish included. */
    private const PERMIT_ALIASES = [
        'BUSINESS' => ['business permit', 'mayor', 'mayors', 'mayors permit', 'mayor permit', "mayor's permit", 'bplo', 'business licensing', 'permit to operate', 'negosyo permit'],
        'SANITARY' => ['sanitary', 'sanitary permit', 'sanitation', 'health cert', 'health certificate', 'health permit', 'health office', 'city health', 'cho', 'sanitaryo', 'kalusugan'],
        'FSIC' => ['fsic', 'fire', 'fire safety', 'fire clearance', 'fire inspection', 'bumbero', 'bfp', 'sunog'],
        'OCCUPANCY' => ['occupancy', 'occupancy permit', 'building official', 'obo', 'okupansya'],
        'CEC' => ['environmental', 'environment', 'cenro', 'cec', 'ecc', 'kalikasan'],
        'ZONING' => ['zoning', 'zonal', 'locational', 'location clearance', 'city planning', 'cpdo'],
    ];

    /**
     * Offices to fall back on when the matched permit type is not configured.
     * Zoning is the one that comes and goes with the official zone data.
     */
    private const OFFICE_ALIASES = [
        'CPDO' => ['zoning', 'zonal', 'locational', 'location clearance', 'city planning', 'cpdo'],
    ];

    /** Words that turn a question into "give me everything". */
    private const BROAD_TERMS = ['all', 'lahat', 'every', 'each', 'complete list', 'buong listahan', 'compare', 'six', '6'];

    /** Fee questions that are really about paying late. */
    private const PENALTY_TERMS = ['penalty', 'penalties', 'surcharge', 'multa', 'interest', 'late', 'huli', 'overdue', 'nahuli'];

    /** Payment questions that are really about the accepted methods. */
    private const METHOD_TERMS = ['gcash', 'maya', 'card', 'cash', 'over the counter', 'bank', 'method', 'methods', 'paraan'];

    /**
     * Plain-language names for the facts the fee engine actually consumes
     * (see database/data/revenue_code/SCHEMA.md). Order is the order they are
     * offered in; 'fixed' has no label because it explains nothing.
     */
    private const FEE_BASIS_LABELS = [
        'gross_sales' => 'your gross sales for the past year',
        'capitalization' => 'your capitalization if the business is new',
        'floor_area_sqm' => 'the floor area you occupy',
        'employees' => 'how many people you employ',
        /*
         * "in the market you operate", not "you hold". The five rules with
         * `basis: stall_count` price a BUSINESS permit for the operator who runs
         * a market — the mayor's-permit market brackets and garbage Schedule J —
         * not the trader renting one stall inside it. That distinction used to
         * be blurred by the Market Clearance, which was the stall holder's and
         * was removed on 6 September 2026; with it gone the only person this
         * driver can reach is the operator, so it should say so.
         */
        'stall_count' => 'how many stalls are in the market you operate',
        'construction_cost' => 'the construction cost of the premises',
        'units' => 'the unit counts you declare (vehicles, signs, machines and the like)',
    ];

    /**
     * The boxes on the forms, and what to put in them. 'codes' names the permit
     * forms a field belongs to (empty means it lives in the main wizard, so it
     * applies whichever permits you picked). When two fields answer to the same
     * words, the one on the form the user named wins; otherwise the longest
     * alias does, the same tie-break the permit aliases use.
     *
     * Fields come from web/src/pages/applicant/OfficeFormStep.tsx and the wizard
     * steps. Keep the answers to what the form actually does: say where the box
     * is, what to type, and who reads it.
     *
     * @var array<int, array{label: string, codes: array<int, string>, aliases: array<int, string>, answer: string}>
     */
    private const FIELD_RULES = [
        [
            'label' => 'Water Source',
            'codes' => ['SANITARY'],
            'aliases' => ['water source', 'source of water', 'water supply', 'sources of water', 'tubig'],
            'answer' => 'Water Source is on the Sanitary Permit sheet, under Establishment Sanitation Profile. '
                ."It records where your premises gets the water it uses, because the water supply is part of what the City Health Office inspects.\n"
                .'Pick one: Level III (Waterworks), Deep Well, Bottled / Refill, or Other.',
        ],
        [
            'label' => 'Sanitary Classification',
            'codes' => ['SANITARY'],
            'aliases' => ['sanitary classification', 'classification', 'food establishment', 'non-food establishment'],
            'answer' => 'Sanitary Classification is the one required answer on the Sanitary Permit sheet. '
                ."It says what kind of establishment you run, which decides the sanitation checklist the City Health Office inspects you against.\n"
                .'Pick one: Food Establishment, Non-Food Establishment, Personal / Public Service, or Industrial.',
        ],
        [
            'label' => 'No. of Workers Requiring Health Certificates',
            'codes' => ['SANITARY'],
            'aliases' => [
                'no. of workers requiring health certificates', 'workers requiring health certificates',
                'workers needing health certificates', 'number of workers', 'no. of workers', 'workers',
            ],
            'answer' => 'No. of Workers Requiring Health Certificates is a count on the Sanitary Permit sheet. '
                ."Enter how many of your staff need their own health certificate: the ones who handle food or deal with customers face to face.\n"
                .'Type 0 if none of them do. The field is optional, so you can leave it blank and settle it with the City Health Office at inspection.',
        ],
        [
            'label' => 'Certificate Applied For',
            'codes' => ['FSIC'],
            'aliases' => ['certificate applied for', 'certificate applied'],
            'answer' => 'Certificate Applied For is on the Bureau of Fire Protection sheet, and you do not type it. '
                ."BizTrack fills it in from the permits you ticked in Permit Selection and whether this is a new application or a renewal.\n"
                .'To change it, go back to Permit Selection and change what you are applying for.',
        ],
        [
            'label' => 'Authorized Representative',
            'codes' => ['FSIC'],
            'aliases' => ['authorized representative', 'authorised representative', 'representative'],
            'answer' => 'Authorized Representative is on the Bureau of Fire Protection sheet: the person BFP can deal with at the premises if you are not there yourself. '
                ."Enter their full name.\n"
                .'Leave it blank and BizTrack uses the name on your business permit.',
        ],
        [
            'label' => 'Application Type (occupancy scope)',
            'codes' => ['OCCUPANCY'],
            'aliases' => ['application type', 'full or partial', 'partial occupancy', 'full occupancy', 'occupancy scope'],
            'answer' => 'Application Type on the Occupancy sheet is the occupancy scope, and it is required. '
                ."Choose Full if the whole building will be occupied, Partial if only a part of it will be.\n"
                .'The Office of the Building Official inspects against what you pick, so it has to match what you will actually occupy.',
        ],
        [
            'label' => 'Building Permit No.',
            'codes' => ['OCCUPANCY'],
            'aliases' => ['building permit no', 'building permit number', 'building permit'],
            'answer' => 'Building Permit No. is on the Occupancy sheet. '
                ."Enter the number printed on the building permit the Office of the Building Official issued for the structure.\n"
                .'Give the number only. The date it was issued is filled in by the reviewing office, not by you.',
        ],
        [
            'label' => 'FSEC No.',
            'codes' => ['OCCUPANCY'],
            'aliases' => ['fsec no', 'fsec number', 'fsec', 'fire safety evaluation clearance'],
            'answer' => 'FSEC No. is on the Occupancy sheet: the Fire Safety Evaluation Clearance number the Bureau of Fire Protection issued when your building plans were cleared. '
                ."Enter the number only.\n"
                .'The reviewing office records the date it was issued, so leave that to them.',
        ],
        [
            'label' => 'Birthday of Owner',
            'codes' => ['CEC'],
            'aliases' => ['birthday of owner', 'owner birthday', 'birthday', 'birthdate', 'date of birth', 'kaarawan'],
            'answer' => "Birthday of Owner is on the CENRO sheet. Enter the owner's date of birth.\n"
                .'It has to be a date in the past, so BizTrack will not accept today or later.',
        ],
        [
            'label' => 'Type of Application',
            'codes' => ['SANITARY', 'CEC'],
            'aliases' => ['type of application', 'new or renewal'],
            'answer' => 'Type of Application is read-only on the office sheets. '
                ."It shows whether this is a new application or a renewal, taken from the application you started, so you never retype it.\n"
                .'It follows Permit Selection, which is where you would change it.',
        ],
        [
            'label' => 'Date of Application',
            'codes' => [],
            'aliases' => ['date of application', 'application date', 'date filed', 'filing date'],
            'answer' => 'Date of Application is filled in by BizTrack the moment you submit, so it stays read-only and blank until then. '
                .'It is not the date the permit is issued: the issuing office records that during review.',
        ],
        [
            'label' => 'auto-generated control numbers',
            'codes' => [],
            'aliases' => [
                'sanitary permit no', 'sanitary permit number', 'control no', 'control number',
                'fsic application number', 'fsic number', 'auto-generated',
            ],
            'answer' => 'Sanitary Permit No., Control No. and FSIC Application Number are all auto-generated, which is why they are greyed out. '
                ."Leave them alone: the issuing office mints the real number when your permit is released.\n"
                .'The number you use to follow your application in the meantime is your tracking number, which reads like BIZ-2026-00123.',
        ],
        [
            'label' => 'Capital',
            'codes' => [],
            'aliases' => ['capital', 'capitalization', 'capitalisation', 'puhunan'],
            'answer' => 'Capital sits under each line of business in the Location & Zoning step, and it is required. '
                ."Enter, in pesos, what you have put into that line.\n"
                .'For a new business it is what your business tax is assessed on, because there is no full year of gross sales to go on yet.',
        ],
        [
            'label' => 'Gross Sales, Preceding Year',
            'codes' => [],
            'aliases' => ['gross sales', 'gross receipt', 'gross receipts', 'gross income', 'benta', 'sales'],
            'answer' => 'Gross Sales, Preceding Year is in the Business & Tax Profile step, one figure per line of business. '
                ."Enter your total sales for last year, before you take any expenses off.\n"
                .'A renewal is assessed on this figure. If the line is brand new, leave it and your capitalization is used instead.',
        ],
        [
            'label' => 'Line of Business',
            'codes' => [],
            'aliases' => ['line of business', 'psic', 'psic code', 'nature of business'],
            'answer' => 'Line of Business is what you actually sell or do, chosen from the PSIC list in the Location & Zoning step. '
                ."Search for the closest description and pick it.\n"
                .'It sets the tax rate for that line, so pick the one that matches the activity. You can add more than one line if you do more than one thing.',
        ],
        [
            'label' => 'Tax Identification Number (TIN)',
            'codes' => [],
            'aliases' => ['tin', 'tin number', 'tax identification number', 'tax identification'],
            'answer' => 'Tax Identification Number (TIN) is in the Business Information step and it is required. '
                ."Enter the 9 digits the BIR issued you, plus your 3 to 5 digit branch code if you have one, like 123-456-789-000.\n"
                .'It is the number on your BIR Certificate of Registration, not your business permit number.',
        ],
        [
            'label' => 'Floor Area',
            'codes' => [],
            'aliases' => ['floor area', 'square meter', 'square meters', 'square metre', 'sqm', 'laki ng lugar'],
            'answer' => 'Floor Area is in the Business & Tax Profile step. '
                ."Enter the floor space your business occupies at the premises, in square metres.\n"
                .'Some fees are charged per square metre, so this figure feeds straight into your assessment. Give the space you occupy, not the whole building.',
        ],
        [
            'label' => 'Number of Employees',
            'codes' => [],
            'aliases' => ['number of employees', 'no. of employees', 'employee', 'employees', 'headcount', 'staff', 'manggagawa'],
            'answer' => 'Number of Employees is in the Business & Tax Profile step: your total headcount at this location. '
                ."Employees Residing in Malabon is a subset of that number, so it can never be higher.\n"
                .'Some fees are computed from staff counts, which is why both are asked.',
        ],
    ];

    /** A message shaped like "what does this box want from me". */
    private const FIELD_QUESTION_TERMS = [
        'what is', 'what are', 'what does', 'what do i', 'what should i', 'what to', 'what for',
        "what's", 'whats', 'what', 'ano', 'anong', 'para saan', 'para san',
        'mean', 'means', 'meaning', 'explain', 'define', 'definition',
        'fill', 'fill in', 'fill out', 'enter', 'put', 'ilagay', 'ilalagay',
        'required', 'optional', 'why', 'bakit',
    ];

    /** Words that say the question is about the form itself, not the permit. */
    private const FORM_CONTEXT_TERMS = [
        'field', 'box', 'blank', 'form', 'sheet', 'section', 'item', 'question', 'kahon', 'sagutan',
    ];

    public function reply(User $user, string $message): string
    {
        $text = mb_strtolower(trim($message));

        // Nothing to work with (blank, emoji-only, "???"): ask for a question.
        if (! preg_match('/[\p{L}\p{N}]/u', $text)) {
            return $this->emptyPrompt();
        }

        // A tracking id anywhere in the message means "where is this one?"
        if (preg_match(self::TRACKING_PATTERN, $message, $m)) {
            return $this->trackingStatus($user, strtoupper($m[0]));
        }

        // A half-typed tracking id gets the format, never a guessed lookup.
        if (preg_match(self::NEAR_MISS_TRACKING_PATTERN, $message)) {
            return $this->trackingFormat();
        }

        $permitType = $this->permitType($text);

        // Zoning is the one office people ask about that issues no permit here.
        $office = $permitType ? null : $this->office($text);
        if ($office) {
            return $this->zoning($office);
        }

        // "What is the water source for?" names a permit but asks about a box on
        // its form, so the field layer gets first refusal on the answer.
        $field = $this->fieldAnswer($text, $permitType);
        if ($field !== null) {
            return $field;
        }

        $broad = ! $permitType && $this->mentionsAny($text, self::BROAD_TERMS);

        return match ($this->intent($text)) {
            'requirements' => $this->requirements($permitType, $broad),
            'renewal' => $this->renewal($permitType),
            'payment' => $this->payment($text, $permitType),
            'fees' => $this->fees($text, $permitType),
            'status' => $this->status($user, $permitType),
            'offices' => $this->offices($permitType, $broad),
            'hours' => $this->hours($permitType),
            'greeting' => $this->greeting($user),
            default => $permitType ? $this->permitMenu($permitType) : $this->fallback(),
        };
    }

    // --- intent + entity matching --------------------------------------------

    /** Highest-scoring intent wins: longest keyword first, then most hits. */
    private function intent(string $text): string
    {
        $best = 'fallback';
        $bestLength = 0;
        $bestHits = 0;

        foreach (self::INTENT_RULES as $intent => $keywords) {
            $length = 0;
            $hits = 0;
            foreach ($keywords as $keyword) {
                if ($this->mentions($text, $keyword)) {
                    $hits++;
                    $length = max($length, mb_strlen($keyword));
                }
            }

            if ($hits === 0) {
                continue;
            }
            if ($length > $bestLength || ($length === $bestLength && $hits > $bestHits)) {
                $best = $intent;
                $bestLength = $length;
                $bestHits = $hits;
            }
        }

        return $best;
    }

    /**
     * Whole-word (plural-tolerant) match, so "fee" does not fire inside
     * "coffee" and "market" does not fire inside "marketing".
     */
    private function mentions(string $text, string $term): bool
    {
        $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($term, '/').'s?(?![\p{L}\p{N}])/u';

        return (bool) preg_match($pattern, $text);
    }

    /** @param  array<int, string>  $terms */
    private function mentionsAny(string $text, array $terms): bool
    {
        foreach ($terms as $term) {
            if ($this->mentions($text, $term)) {
                return true;
            }
        }

        return false;
    }

    /** The permit type the user named, longest alias wins ("fire safety" > "fire"). */
    private function permitType(string $text): ?PermitType
    {
        $code = null;
        $best = 0;

        foreach (self::PERMIT_ALIASES as $permitCode => $aliases) {
            foreach ($aliases as $alias) {
                if ($this->mentions($text, $alias) && mb_strlen($alias) > $best) {
                    $code = $permitCode;
                    $best = mb_strlen($alias);
                }
            }
        }

        return $code ? PermitType::with('department', 'documentTypes')->where('code', $code)->first() : null;
    }

    /**
     * "What is this box for?" Answers a named field on a named form, or admits
     * it does not know the field rather than inventing a definition.
     *
     * Returns null when the message is not about a form field at all, so the
     * permit intents below carry on untouched.
     */
    private function fieldAnswer(string $text, ?PermitType $type): ?string
    {
        $asking = $this->mentionsAny($text, self::FIELD_QUESTION_TERMS);
        $field = $this->field($text, $type?->code);

        if ($field) {
            // A bare "water source" is still a field question: nothing else in
            // the message claims it, so answer the field instead of the menu.
            return $asking || $this->intent($text) === 'fallback' ? $field['answer'] : null;
        }

        // Names no field I know, but is plainly asking about one.
        if ($asking && $this->mentionsAny($text, self::FORM_CONTEXT_TERMS)) {
            return $this->fieldFallback($type);
        }

        return null;
    }

    /**
     * The field the message names. A field on the form the user named beats one
     * from another form; after that the longest matched alias wins.
     *
     * @return array{label: string, codes: array<int, string>, aliases: array<int, string>, answer: string}|null
     */
    private function field(string $text, ?string $permitCode): ?array
    {
        $best = null;
        $bestScore = 0;

        foreach (self::FIELD_RULES as $rule) {
            $length = 0;
            foreach ($rule['aliases'] as $alias) {
                if ($this->mentions($text, $alias)) {
                    $length = max($length, mb_strlen($alias));
                }
            }
            if ($length === 0) {
                continue;
            }

            // Scoped fields outrank unscoped ones only when the form matches.
            $onNamedForm = $permitCode !== null && in_array($permitCode, $rule['codes'], true);
            $score = $length + ($onNamedForm ? 1000 : 0);
            if ($score > $bestScore) {
                $best = $rule;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /** Asked about a field I have no note on. Say so; do not make one up. */
    private function fieldFallback(?PermitType $type): string
    {
        $department = $type?->department?->name;
        $office = $department ? "the {$department}" : 'the office reviewing your application';

        return "I do not have a note on that field, and I would rather not guess at what it means.\n"
            ."Give me the form and the exact label and I will try again, like \"what is the water source on the sanitary permit form\".\n"
            ."If it is specific to your application, message {$office} from the application page. They can tell you exactly what they need in that box.";
    }

    private function office(string $text): ?Department
    {
        foreach (self::OFFICE_ALIASES as $code => $aliases) {
            if ($this->mentionsAny($text, $aliases)) {
                return Department::where('code', $code)->first();
            }
        }

        return null;
    }

    // --- answers -------------------------------------------------------------

    /** Live from the seeded permit_type_requirements pivot, never hardcoded. */
    private function requirements(?PermitType $type, bool $broad): string
    {
        if ($type) {
            return "{$type->name}, reviewed by the {$type->department?->name}, needs:\n"
                .$this->checklist($type)."\n"
                .'Upload these in the Documents step of your application. Name another permit and I will pull up its checklist.';
        }

        if ($broad) {
            $lines = ['Here are the document requirements for every permit type:'];
            foreach (PermitType::with('documentTypes')->orderBy('id')->get() as $permitType) {
                $lines[] = "• {$permitType->name}: ".$permitType->documentTypes
                    ->map(fn ($doc) => $doc->name.$this->docSuffix($doc))
                    ->implode(', ');
            }
            $lines[] = 'You upload these in the Documents step of the application.';

            return implode("\n", $lines);
        }

        // No permit named: lead with the one everybody files, then offer the rest.
        $business = PermitType::with('documentTypes')->where('code', 'BUSINESS')->first();
        $others = PermitType::whereKeyNot($business?->id)->orderBy('id')->pluck('name')->implode(', ');

        return "Requirements depend on the permit. For the {$business?->name} you need:\n"
            .$this->checklist($business)."\n"
            ."I also have the checklists for: {$others}. Ask me about any one of them, or say \"all requirements\" for the full rundown.";
    }

    private function checklist(?PermitType $type): string
    {
        if (! $type) {
            return '';
        }

        return $type->documentTypes
            ->map(fn ($doc) => "• {$doc->name}{$this->docSuffix($doc)}")
            ->implode("\n");
    }

    private function docSuffix(object $doc): string
    {
        if ($doc->pivot->context === 'renewal') {
            return ' (renewals only)';
        }

        return $doc->pivot->is_mandatory ? '' : ' (optional)';
    }

    /**
     * Fees are never quoted as a peso figure: the real amount comes out of the
     * revenue-code rules at submission (FeeCalculator), and permit_types.base_fee
     * is only a legacy fallback. So say what drives the fee, from the live rules,
     * and point at the Tax Order of Payment for the number.
     */
    private function fees(string $text, ?PermitType $type): string
    {
        if ($this->mentionsAny($text, self::PENALTY_TERMS)) {
            $late = $this->penaltyPhrase().' The surcharge is charged once; the interest keeps adding up until you settle.';

            return $type
                ? "The {$type->name} carries the same late-payment charges as the rest of your assessment. {$late}"
                : "{$late}\nRenewals fall due in the first 20 days of January, so settling inside that window avoids all of it.";
        }

        if ($type) {
            $rules = $this->feeRulesFor($type);

            if ($rules->isEmpty()) {
                return "I do not have a fee schedule loaded for the {$type->name}, so I will not guess at an amount.\n"
                    .'Your Tax Order of Payment shows the assessed amount before you pay, and the '
                    .($type->department?->name ?? 'issuing office').' can explain any line on it.';
            }

            // FSIC is a percentage of the other fees, not a schedule of its own.
            $percentage = $rules->first(fn (FeeRule $rule) => $rule->basis === 'regulatory_subtotal');
            if ($percentage) {
                $rate = $this->percent((float) ($percentage->computation['rate'] ?? 0));
                $head = "The {$type->name} is not a flat fee: it is {$rate} of your mayor's permit and regulatory fees ({$percentage->section}), "
                    .'so it moves with the rest of your assessment.';
            } else {
                $head = "The {$type->name} has no flat rate. Under the Malabon Revenue Code it is computed from "
                    .$this->feeDrivers($rules).'.';
            }

            return "{$head}\n"
                .'The exact amount is assessed when you submit, and every line item shows in your Tax Order of Payment before you pay.';
        }

        return 'Fees are computed from the Malabon Revenue Code when you submit your application: your line of business, '
            ."your gross sales or capitalization, floor area, and the permits you applied for all feed into it.\n"
            ."Every line item is shown in your Tax Order of Payment on the fee step, so you can review the breakdown before paying.\n"
            .'Name a permit and I will tell you what drives its fee. Heads up: '.lcfirst($this->penaltyPhrase());
    }

    /** Active, non-penalty rules that price this permit type. */
    private function feeRulesFor(PermitType $type): Collection
    {
        return FeeRule::where('active', true)
            ->where('group', '!=', 'penalty')
            ->get()
            ->filter(fn (FeeRule $rule) => in_array($type->code, $rule->permit_types ?? [], true))
            ->values();
    }

    /** @param  Collection<int, FeeRule>  $rules */
    private function feeDrivers(Collection $rules): string
    {
        $bases = $rules->pluck('basis')->filter()->unique()->all();
        $drivers = [];
        foreach (self::FEE_BASIS_LABELS as $basis => $label) {
            if (in_array($basis, $bases, true)) {
                $drivers[] = $label;
            }
        }
        // Trim to the three that matter most; the rest are officer-adjusted.
        $drivers = array_slice($drivers, 0, 3);

        if ($drivers === []) {
            return 'the schedule the Revenue Code sets for it';
        }
        if (count($drivers) === 1) {
            return $drivers[0];
        }

        return implode(', ', array_slice($drivers, 0, -1)).' and '.end($drivers);
    }

    /** Surcharge and interest, read from the seeded penalty rule. */
    private function penaltyPhrase(): string
    {
        $constants = FeeRule::where('code', 'penalty.late_payment')->first()?->constants ?? [];
        $surcharge = $this->percent((float) ($constants['surcharge_rate'] ?? 0.25));
        $interest = $this->percent((float) ($constants['interest_rate_monthly'] ?? 0.02));
        $months = (int) ($constants['interest_max_months'] ?? 36);

        return "Paying late adds a {$surcharge} surcharge on the amount due, plus {$interest} interest for every month of delay, "
            ."counted for at most {$months} months.";
    }

    /** 0.25 => "25%", 0.1 => "10%". */
    private function percent(float $rate): string
    {
        return rtrim(rtrim(number_format($rate * 100, 2, '.', ''), '0'), '.').'%';
    }

    private function status(User $user, ?PermitType $type): string
    {
        // Always scoped to the asker: the bot never reveals anyone else's case.
        $query = Application::with('business')
            ->where('applicant_user_id', $user->id)
            ->orderByDesc('id');

        if ($type) {
            $query->whereHas('permitTypes', fn ($q) => $q->where('permit_types.id', $type->id));
        }

        $applications = $query->limit(3)->get();

        if ($applications->isEmpty()) {
            return $type
                ? "None of your applications include the {$type->name} yet. You can add it when you file a new application under Apply.\n"
                    .'If you think you already filed one, open My Applications and check which permits it covers.'
                : "I do not see any applications under your account yet. Start one from Apply and I can track it for you.\n"
                    .'Once you have a tracking number (it looks like BIZ-2026-00123), send it to me any time.';
        }

        $lines = [$type
            ? "Your applications covering the {$type->name}:"
            : 'Your most recent applications:'];

        foreach ($applications as $application) {
            $label = $application->tracking_id ?: 'Draft (no tracking number yet)';
            $business = $application->business?->name;
            $lines[] = $business
                ? "• {$label} ({$business}): {$application->status->label()}"
                : "• {$label}: {$application->status->label()}";
        }
        $lines[] = 'Open one in My Applications for its timeline, or send me a tracking number for a single application.';

        return implode("\n", $lines);
    }

    private function trackingStatus(User $user, string $trackingId): string
    {
        $application = Application::where('tracking_id', $trackingId)
            ->where('applicant_user_id', $user->id)
            ->first();

        if (! $application) {
            return "I could not find {$trackingId} among your applications. "
                .'Double-check the tracking number under My Applications. I can only look up applications filed under your account.';
        }

        return "Application {$trackingId} is currently: {$application->status->label()}. "
            .'Open it in My Applications for the full timeline and next steps.';
    }

    private function trackingFormat(): string
    {
        return "That is not quite a full tracking number, so I did not look anything up. They read like BIZ-2026-00123: the year, then five digits.\n"
            .'You can copy yours from the application card in My Applications and paste it here.';
    }

    private function payment(string $text, ?PermitType $type): string
    {
        if ($this->mentionsAny($text, self::METHOD_TERMS)) {
            return "BizTrack accepts GCash, Maya, and credit or debit card on the Pay online screen. Payment is simulated in this prototype, so no real money moves.\n"
                .'There is no over-the-counter option in the system yet, so pay from your application page and keep the receipt it issues.';
        }

        if ($type) {
            return "Every permit on one application, including the {$type->name}, is settled in a single Tax Order of Payment, so there is no separate payment for it.\n"
                .'Open the application in My Applications and use Pay online once the Tax Order of Payment is ready.';
        }

        return "Paying in BizTrack is simulated for this prototype, so no real money moves.\n"
            .'Open your application from My Applications; once your Tax Order of Payment is ready, use the Pay online button on the application detail page. '
            .'You get a receipt right away and your application moves to review.';
    }

    private function renewal(?PermitType $type): string
    {
        if ($type) {
            // validity_days is the column BizTrack really issues permits with.
            $years = max(1, (int) round(($type->validity_days ?: 365) / 365));
            $span = $years === 1 ? 'one year' : "{$years} years";

            return "The {$type->name} is valid for {$type->validity_days} days (about {$span}) from the date it is issued.\n"
                .'Renew it with your business permit during the first 20 days of January. '.$this->penaltyPhrase();
        }

        return "Business permits are renewed during the first 20 days of January every year.\n"
            .$this->penaltyPhrase().' '
            .'You can start a renewal from your business record and BizTrack will prefill last year\'s details.';
    }

    /** Live from permit types and their issuing departments. */
    private function offices(?PermitType $type, bool $broad): string
    {
        if ($type) {
            $department = $type->department;

            return "The {$type->name} is handled by the {$department?->name} ({$department?->code}). {$department?->description}\n"
                .'You can message that office from your application page once it reaches them.';
        }

        $types = PermitType::with('department')->orderBy('id')->get();

        if ($broad) {
            $lines = ['These LGU offices review your application, each for its own permit:'];
            foreach ($types->pluck('department')->filter()->unique('id') as $department) {
                $lines[] = "• {$department->name} ({$department->code}): {$department->description}";
            }
            $lines[] = 'You can message the office assigned to your application from the application page.';

            return implode("\n", $lines);
        }

        $lines = ['Each permit is reviewed by its own office:'];
        foreach ($types as $permitType) {
            $lines[] = "• {$permitType->name}: {$permitType->department?->name} ({$permitType->department?->code})";
        }
        $lines[] = 'Name one and I will tell you what that office does.';

        return implode("\n", $lines);
    }

    private function hours(?PermitType $type): string
    {
        /*
         * The limit is per complexity tier, not one flat figure: RA 11032 sets
         * 3 working days for simple transactions, 7 for complex and 20 for
         * highly technical. That is what Ra11032::TIERS holds and what every
         * deadline in the system is measured against.
         *
         * This sentence used to say "within 10 working days". That number is in
         * neither the statute nor this codebase, and it is a legal deadline
         * being quoted to an applicant — the one kind of sentence a chatbot has
         * no business improvising. Read from the constant so the answer cannot
         * drift from the rule the deadlines are actually computed with.
         */
        $limits = collect(Ra11032::TIERS)
            ->map(fn (array $t): string => $t['statutory_working_days'].' working days for '.mb_strtolower($t['label']))
            ->implode(', ');

        $law = "Under RA 11032 (Ease of Doing Business Act) the limit depends on how complex the filing is: {$limits}. "
            .'Each application in BizTrack shows the deadline for its own tier.';

        if ($type) {
            $inspection = $type->requires_inspection
                ? "It needs an on-site inspection, so expect a scheduled visit from the {$type->department?->code} before it clears."
                : 'It is a desk review, so no site visit is scheduled for it.';

            return "{$type->name}: reviewed by the {$type->department?->name}. {$inspection}\n{$law}";
        }

        return $law.' Name a permit and I will tell you whether it needs an inspection visit.';
    }

    private function zoning(Department $office): string
    {
        return "Zoning and locational clearance is handled by the {$office->name} ({$office->code}). BizTrack does not process a standalone zoning permit yet, that is on hold until the official zone data is loaded.\n"
            .'What you do need is the Locational / Zoning Clearance document: you upload it with your City Environmental Certificate application.';
    }

    /** The user named a permit but not a question. Offer what I know about it. */
    private function permitMenu(PermitType $type): string
    {
        return "About the {$type->name}, issued by the {$type->department?->name}, I can give you:\n"
            ."• Its document requirements\n"
            ."• Its fee\n"
            ."• Its processing time and whether it needs an inspection\n"
            ."• What any field on its application form is for\n"
            .'Which one would you like?';
    }

    private function greeting(User $user): string
    {
        $first = trim(explode(' ', trim($user->name ?? ''))[0] ?? '');
        $hello = $first !== '' ? "Kumusta, {$first}!" : 'Kumusta!';

        return "{$hello} Ask me about one permit at a time and I will keep it short:\n"
            ."• \"Requirements for a sanitary permit\"\n"
            ."• \"How much is the fire safety fee\"\n"
            ."• \"When is the renewal deadline\"\n"
            ."• \"What is the water source on the sanitary permit form\"\n"
            ."• A tracking number like BIZ-2026-00123 for a status check\n"
            .'What would you like to know?';
    }

    private function emptyPrompt(): string
    {
        return "I did not catch a question there. Try something like \"requirements for a sanitary permit\" or \"how much is the fire safety fee\".\n"
            .'You can also send a tracking number like BIZ-2026-00123 and I will check that application for you.';
    }

    private function fallback(): string
    {
        return "Sorry, I did not quite get that. Things I can answer:\n"
            ."• \"What documents do I need for a sanitary permit\"\n"
            ."• \"How much is the occupancy permit\"\n"
            ."• \"Who reviews the fire safety certificate\"\n"
            ."• \"When is the renewal deadline\"\n"
            ."• \"What do I put in the water source field\"\n"
            ."• A tracking number like BIZ-2026-00123 for a status check\n"
            .'For anything specific to your case, you can also message your assigned office from any application page.';
    }
}
