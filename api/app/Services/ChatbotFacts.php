<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Models\Department;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Support\ChatbotReply;
use App\Support\OfficeHours;
use App\Support\PaymentMode;
use App\Support\Ra11032;
use App\Support\RenewalSeason;
use App\Support\RenewalWindow;
use App\Support\Zoning\PinZone;
use Illuminate\Support\Facades\Cache;

/**
 * What Gemini is told before every question: how to answer, and BizTrack's
 * PUBLIC facts to answer from [Ken, 5 October 2026].
 *
 * ── Built from the system, not written about it ─────────────────────────────
 *
 * The permits, their offices, each checklist, what drives each fee, the late
 * charges, the RA 11032 tiers, office hours, the payment switch, the renewal
 * window and the zoning-at-the-pin rule are all read at request time from the
 * tables and constants that decide them, through the same phrasing the
 * rule-based bot uses where it has one (ChatbotResponder). A fact written into
 * this file by hand would drift from the rule the moment the rule moved, and
 * the model would repeat the stale one with confidence. What IS written here is
 * what has no home on the server: the screens an owner sees, and the order a
 * new filing goes through.
 *
 * ── What is never in it ────────────────────────────────────────────────────
 *
 * Anything about a person. No owner, filing, payment or permit row is read
 * here, only reference data, so the same text goes out for every asker.
 * Questions about the asker's own records never reach Gemini at all
 * (ChatbotReply::$keepLocal).
 *
 * ── Cached, and keyed by what changes it ───────────────────────────────────
 *
 * Built once per payment mode per Manila day and kept for CACHE_MINUTES. The
 * payment switch is flipped on a running server, so it is in the key rather
 * than waited out; a requirement edited by an administrator reaches the model
 * within CACHE_MINUTES. GeminiChatbot keys its answer cache on a hash of this
 * text, so a changed fact also retires every answer built on the old one.
 */
class ChatbotFacts
{
    public const CACHE_MINUTES = 10;

    public function __construct(private ChatbotResponder $rules) {}

    public function instruction(): string
    {
        $today = OfficeHours::now();
        $key = 'chatbot:facts:'.PaymentMode::current().':'.$today->toDateString();

        return Cache::remember($key, now()->addMinutes(self::CACHE_MINUTES), fn () => $this->build($today->format('l j F Y')));
    }

    private function build(string $today): string
    {
        $types = PermitType::with('department', 'documentTypes')->orderBy('id')->get();

        $sections = [
            $this->rules(),
            "FACTS\nToday is {$today}, Malabon City (Manila time).",
            $this->screens(),
            $this->newFiling(),
            $this->permits($types),
            $this->requirements($types),
            $this->fees($types),
            $this->processing(),
            $this->hours(),
            $this->paying(),
            $this->renewal(),
            $this->zoning(),
            "FORM FIELDS\n".implode("\n", array_map(fn (string $note) => "• {$note}", $this->rules->fieldNotes())),
        ];

        return implode("\n\n", $sections);
    }

    /** How to answer. The JSON shape itself is enforced by the request's schema. */
    private function rules(): string
    {
        $intents = implode(', ', ChatbotReply::INTENTS);

        return <<<TEXT
            You are the BizTrack assistant, the chat inside BizTrack, Malabon City's online business permit system. Business owners ask you about Malabon City business permits and about using BizTrack.

            How to answer:
            • Use only the FACTS below. If they do not answer the question, or it is not about Malabon City business permits or using BizTrack, set intent to "fallback" and say in one sentence that you do not know.
            • Never invent a fee, an amount of money, a date, a deadline, a requirement, an office or a contact detail. Never quote a peso amount at all: the exact fee is on the owner's Tax Order of Payment.
            • You cannot see anyone's applications, payments or permits. If the question is about the owner's own (where their application is, its status, their permit, their payment), set intent to "status" and leave answer empty: BizTrack answers those from its records.
            • Reply in the language of the question: English, Filipino or Taglish.
            • Plain text only: no markdown, no asterisks, no headings, no links. Start a list line with "•". Keep it short, 120 words at most.
            • Reply as JSON with "answer", "intent" (one of: {$intents}) and "confidence" (0 to 1: how sure you are of the intent and that the facts answer it).

            Intents: requirements = documents needed; fees = how much, what a fee depends on, late charges; payment = how or where to pay; renewal = renewing, expiry, deadlines; status = the owner's own applications, payments or permits; offices = which office does what; hours = processing time or office hours; field = what a box on a form means; permit = a permit in general; greeting = hello or thanks; fallback = anything the facts do not cover.
            TEXT;
    }

    /*
     * The owner's screens, from web/src/lib/nav.ts and the dashboard tiles
     * (DashboardPage.tsx). Labels as the screen prints them, because the
     * owner will go looking for exactly those words.
     */
    private function screens(): string
    {
        return <<<'TEXT'
            BIZTRACK SCREENS (for a business owner)
            • Home: tiles for New Business Permit, Renew Business Permit, Amendment Form (to change the details of a permit already held) and Other Requirements (what an office has asked for beyond the application, such as a liquor permit or health certificates for food handlers).
            • Track: every application with its tracking number, status and timeline. Open one to pay, to apply for its other permits, and to see what each office needs.
            • My Permits: the permits issued to the owner's businesses.
            • Messages: write to the office handling an application, or send BPLO a general enquiry.
            • Drafts: applications started and not yet submitted.
            • Profile (from the avatar): account details and Payment History, where receipts are kept.
            Numbers: a tracking number reads like BIZ-2026-00123 (an application in progress), a permit number like MCB-2026-000001, a business account number like BP-2026-0001.
            TEXT;
    }

    /*
     * Payment comes first and the clearances follow (AGENTS.md §11,
     * docs/clearances-after-payment.md): the wizard is the business permit
     * alone, BPLO approves, payment releases the Mayor's Permit, and the other
     * permits are applied for from the filing afterwards. Zoning is on every
     * new filing (WorkflowService, "puts the zoning clearance on every new
     * filing").
     */
    private function newFiling(): string
    {
        return <<<'TEXT'
            HOW A NEW APPLICATION GOES
            1. Home, New Business Permit: the application for the Mayor's / Business Permit, in steps: Data Privacy Consent, Location & Zoning, Business Information & Registration, Business Operation, Documentary Requirements, Review & Submit. It is saved as a draft as you go.
            2. Submit. BizTrack gives the tracking number, and the fees are assessed then into the Tax Order of Payment.
            3. BPLO reviews the application, and may return it for corrections.
            4. Once BPLO approves, pay the Tax Order of Payment. The Mayor's / Business Permit is released when the payment clears.
            5. Then apply for each other permit the business needs, from the application in Track. Each office reviews its own permit, and most inspect the premises. A new business always applies for the Zoning Clearance.
            TEXT;
    }

    /** @param  iterable<PermitType>  $types */
    private function permits(iterable $types): string
    {
        $lines = ['PERMITS AND THE OFFICES THAT ISSUE THEM'];
        foreach ($types as $type) {
            $department = $type->department;
            $office = $department ? "{$department->name} ({$department->code})" : 'its issuing office';
            $visit = $type->requires_inspection ? 'Needs an on-site inspection.' : 'Reviewed at the desk, with no site inspection.';
            $lines[] = "• {$type->name}, issued by the {$office}. {$department?->description} {$visit}";
        }

        return implode("\n", $lines);
    }

    /** @param  iterable<PermitType>  $types */
    private function requirements(iterable $types): string
    {
        $lines = ['DOCUMENT REQUIREMENTS (uploaded in the application; an office may ask for more under Other Requirements)'];
        foreach ($types as $type) {
            $lines[] = "{$type->name}:";
            $lines[] = $this->rules->checklist($type);
        }

        return implode("\n", $lines);
    }

    /** @param  iterable<PermitType>  $types */
    private function fees(iterable $types): string
    {
        $lines = [
            'FEES',
            'Fees are computed from the Malabon Revenue Code when the application is submitted: the line of business, gross sales or capitalization, floor area and the permits applied for all feed into it. BPLO assesses them. Every line item shows on the Tax Order of Payment before paying, and one Tax Order of Payment covers every permit on an application. There is no amount to quote before then.',
        ];
        foreach ($types as $type) {
            $lines[] = '• '.($this->rules->feeBasis($type)
                ?? "The {$type->name} has no fee schedule loaded, so its amount is known only from the Tax Order of Payment.");
        }
        $lines[] = 'Late payment: '.$this->rules->penaltyPhrase();

        return implode("\n", $lines);
    }

    private function processing(): string
    {
        $tiers = collect(Ra11032::TIERS)
            ->map(fn (array $t) => $t['statutory_working_days'].' working days for '.mb_strtolower($t['label']))
            ->implode(', ');

        return "PROCESSING TIME\nUnder RA 11032 (Ease of Doing Business Act) the limit depends on how complex the filing is: {$tiers}. "
            .'Each application in BizTrack shows the deadline for its own tier. Inspections are scheduled by the office that needs one.';
    }

    private function hours(): string
    {
        $status = OfficeHours::status();
        $names = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
        $days = array_values(array_map(fn (int $d) => $names[$d] ?? (string) $d, $status['days']));
        $contiguous = $days !== [] && end($status['days']) - reset($status['days']) === count($days) - 1;
        $span = $contiguous && count($days) > 2 ? reset($days).' to '.end($days) : implode(', ', $days);

        $holidays = (array) config('office_hours.holidays', []);

        return "OFFICE HOURS\nCity offices are open {$span}, {$status['opens']} to {$status['closes']}, Manila time"
            .($holidays === [] ? '.' : ', and closed on '.implode(', ', $holidays).'.')
            .' BizTrack takes applications at any hour; offices act on them during office hours.';
    }

    /*
     * The payment switch as it stands (PaymentMode), with the methods the Pay
     * Online screen offers in that mode, and the BPLO counter, which exists in
     * both (PaymentController::counterPayment).
     */
    private function paying(): string
    {
        $methods = collect(PaymentMethod::forMode(PaymentMode::current()))->map(fn (PaymentMethod $m) => $m->label())->all();
        $list = implode(', ', array_slice($methods, 0, -1)).' or '.end($methods);

        $online = PaymentMode::isKwikPay()
            ? "Pay online with {$list}. The receipt is issued once the payment is confirmed."
            : "Pay online with {$list}. The receipt is issued at once.";

        return "PAYING\nPay once BPLO has approved the application and its Tax Order of Payment is ready: open the application in Track and press Pay Online. {$online} "
            .'You can also pay in person at the BPLO counter at Malabon City Hall, and BPLO marks the bill paid in BizTrack. '
            .'Receipts are kept in Payment History on Profile.';
    }

    /*
     * When each permit expires as WorkflowService::issuePermitFor dates it
     * (RenewalSeason for the business permit — 31 December, renewed free to 20
     * January — and a clearance's first issue, a
     * year from the renewal for a renewed clearance), RenewalWindow (when
     * renewal opens and when it is too late), RenewalScope (one permit per
     * renewal) and the late charges from the penalty rule.
     */
    private function renewal(): string
    {
        $mayors = PermitType::where('code', PermitType::OUTCOME_CODE)->value('name') ?? "Mayor's / Business Permit";
        $closes = RenewalSeason::CLOSES_DAY.' January';
        $opensBefore = RenewalWindow::opensDaysBefore();
        $cutoff = RenewalWindow::closesMonthsAfter();

        $lines = [
            'RENEWAL',
            "• The {$mayors} always expires on 31 December of the year it is issued or renewed. Renew it from 1 to {$closes} without penalty; it is late after {$closes}. Renewal opens on 1 January.",
            '• The other permits expire on 31 December of the year they are first issued, and a renewal runs one year from the day it is renewed'
                .($opensBefore !== null ? ". They can be renewed from {$opensBefore} days before they expire." : '.'),
            '• Each permit is renewed on its own application: one permit per renewal.',
            "• Renewing after a permit has expired (for the {$mayors}, after {$closes}) adds the late charges. ".$this->rules->penaltyPhrase(),
        ];
        if ($cutoff !== null) {
            $lines[] = "• A permit that expired more than {$cutoff} months ago cannot be renewed: file a New Application instead.";
        }
        $lines[] = '• Start from Home, Renew Business Permit: pick the business, then the permit. BizTrack fills in last year\'s answers. Changes to the address or registration go on an Amendment Form instead.';

        return implode("\n", $lines);
    }

    /*
     * PinZone: the zone under the owner's pin is read, and a line of business
     * the zone clearly does not allow is stopped before filing; the shops in
     * NEIGHBOURHOOD pass everywhere; anything unclear passes to CPDO.
     */
    private function zoning(): string
    {
        $shops = PsicCode::whereIn('code', PinZone::NEIGHBOURHOOD)->orderBy('code')->pluck('title')->implode('; ');
        $cpdo = Department::where('code', 'CPDO')->first();
        $planning = $cpdo ? "{$cpdo->name} ({$cpdo->code})" : 'the city planning office';

        return "ZONING AT THE MAP PIN\nOn Location & Zoning the owner picks the barangay and the line of business, then drops a pin on the map where the business is. "
            .'BizTrack reads the zone under the pin. If that zone clearly does not allow the line of business, the step says so and the application cannot be submitted for that spot; '
            .'to petition it, visit the Business Permits and Licensing Office (BPLO) at Malabon City Hall. '
            .($shops !== '' ? "These small neighbourhood businesses are allowed in every zone: {$shops}. " : '')
            ."Anything unclear is let through, and the {$planning} reviews the zoning with the Zoning Clearance. A renewal is not judged by the pin.";
    }
}
