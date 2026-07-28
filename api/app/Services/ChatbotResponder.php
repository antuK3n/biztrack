<?php

namespace App\Services;

use App\Models\Application;
use App\Models\PermitType;
use App\Models\User;

/**
 * Rule-based chatbot brain (no LLM). Keyword intent matching, Taglish-tolerant.
 * Tracking-id lookups are scoped to the asking user's own applications only.
 */
class ChatbotResponder
{
    private const TRACKING_PATTERN = '/BIZ-\d{4}-\d{5}/i';

    public function reply(User $user, string $message): string
    {
        $text = mb_strtolower($message);

        // A tracking id anywhere in the message means "where is this one?"
        if (preg_match(self::TRACKING_PATTERN, $message, $m)) {
            return $this->trackingStatus($user, strtoupper($m[0]));
        }

        return match ($this->intent($text)) {
            'requirements' => $this->requirements(),
            'renewal' => $this->renewal(),
            'payment' => $this->payment(),
            'fees' => $this->fees(),
            'status' => $this->status(),
            'offices' => $this->offices(),
            'hours' => $this->hours(),
            'greeting' => $this->greeting($user),
            default => $this->fallback(),
        };
    }

    // --- intent matching -----------------------------------------------------

    private function intent(string $text): string
    {
        $rules = [
            'requirements' => ['requirement', 'kailangan', 'kelangan', 'dokumento', 'document', 'checklist', 'dala', 'ipasa', 'submit ko'],
            'renewal' => ['renew', 'deadline', 'expire', 'expiry', 'january', 'enero', 'palugit'],
            'payment' => ['how to pay', 'how do i pay', 'paano magbayad', 'pano magbayad', 'paano bayaran', 'magbayad', 'pay online', 'payment', 'pay'],
            'fees' => ['fee', 'fees', 'bayad', 'babayaran', 'magkano', 'how much', 'cost', 'presyo', 'price', 'surcharge', 'penalty', 'multa', 'interest'],
            'status' => ['status', 'asan', 'nasaan', 'saan na', 'track', 'progress', 'update', 'follow up', 'follow-up'],
            'offices' => ['office', 'opisina', 'tanggapan', 'contact', 'department', 'bplo', 'sino', 'who reviews'],
            'hours' => ['how long', 'gaano katagal', 'ilang araw', 'working day', 'release', 'hours', 'kailan', 'tagal', 'matagal'],
            'greeting' => ['hello', 'hi', 'hey', 'kumusta', 'kamusta', 'musta', 'magandang', 'good morning', 'good afternoon', 'good evening'],
        ];

        foreach ($rules as $intent => $keywords) {
            foreach ($keywords as $keyword) {
                // Short keywords ("hi", "pay", "fee") must match a whole word so
                // they do not fire inside unrelated words ("thing", "coffee").
                // Longer ones match as prefixes ("requirement" -> "requirements").
                $pattern = mb_strlen($keyword) <= 3
                    ? '/\b'.preg_quote($keyword, '/').'\b/u'
                    : '/\b'.preg_quote($keyword, '/').'/u';
                if (preg_match($pattern, $text)) {
                    return $intent;
                }
            }
        }

        return 'fallback';
    }

    // --- answers -------------------------------------------------------------

    /** Live from the seeded permit_type_requirements pivot, never hardcoded. */
    private function requirements(): string
    {
        $types = PermitType::with('documentTypes')->orderBy('id')->get();

        $lines = ['Here are the document requirements for each permit type:'];
        foreach ($types as $type) {
            $docs = $type->documentTypes
                ->map(function ($doc) {
                    $suffix = '';
                    if ($doc->pivot->context === 'renewal') {
                        $suffix = ' (renewals only)';
                    } elseif (! $doc->pivot->is_mandatory) {
                        $suffix = ' (optional)';
                    }

                    return $doc->name.$suffix;
                })
                ->implode(', ');
            $lines[] = "• {$type->name}: {$docs}";
        }
        $lines[] = 'You upload these in the Documents step of the application. If an office needs anything extra, they will ask through Other Requirements.';

        return implode("\n", $lines);
    }

    private function fees(): string
    {
        return 'Fees are computed from the Malabon Revenue Code when you submit your application, '
            ."and every line item is shown in your Tax Order of Payment on the fee step, so you can review the breakdown before paying.\n"
            .'Heads up: paying late adds a 25% surcharge plus 2% interest for every month of delay, so settle within the deadline.';
    }

    private function status(): string
    {
        return "You can track every application under My Applications. Open one to see its current step, timeline, and the offices reviewing it.\n"
            .'Tip: send me your tracking number (it looks like BIZ-2026-00123) and I will check its status for you.';
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

    private function payment(): string
    {
        return "Paying in BizTrack is simulated for this prototype, so no real money moves.\n"
            .'Open your application from My Applications; once your Tax Order of Payment is ready, use the Pay online button on the application detail page. '
            .'You get a receipt right away and your application moves to review.';
    }

    private function renewal(): string
    {
        return "Business permits are renewed during the first 20 days of January every year.\n"
            .'Renew within that window to avoid the late-payment penalties (25% surcharge plus 2% interest per month). '
            .'You can start a renewal from your business record and BizTrack will prefill last year\'s details.';
    }

    /** Live from permit types and their issuing departments. */
    private function offices(): string
    {
        $departments = PermitType::with('department')
            ->get()
            ->pluck('department')
            ->filter()
            ->unique('id')
            ->values();

        $lines = ['These LGU offices review your application, each for its own permit:'];
        foreach ($departments as $dept) {
            $lines[] = "• {$dept->name} ({$dept->code}): {$dept->description}";
        }
        $lines[] = 'You can message the office assigned to your application from the application page.';

        return implode("\n", $lines);
    }

    private function hours(): string
    {
        return 'Under RA 11032 (Ease of Doing Business Act), the LGU must act on complex transactions like business permits within 10 working days. '
            .'Each application in BizTrack shows its own deadline, so you always know the target release date.';
    }

    private function greeting(User $user): string
    {
        $first = trim(explode(' ', trim($user->name ?? ''))[0] ?? '');
        $hello = $first !== '' ? "Kumusta, {$first}!" : 'Kumusta!';

        return "{$hello} I can help with:\n"
            ."• Document requirements per permit\n"
            ."• Fees and how to pay\n"
            ."• Application status (send me your tracking number, like BIZ-2026-00123)\n"
            ."• Renewal deadlines\n"
            ."• LGU offices and processing times\n"
            .'What would you like to know?';
    }

    private function fallback(): string
    {
        return "Sorry, I did not quite get that. I can help with:\n"
            ."• Document requirements per permit\n"
            ."• Fees and how to pay\n"
            ."• Application status (send me your tracking number, like BIZ-2026-00123)\n"
            ."• Renewal deadlines\n"
            ."• LGU offices and processing times\n"
            .'For anything specific to your case, you can also message your assigned office from any application page.';
    }
}
