<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Services\KwikPay\KwikPayGateway;
use Illuminate\Console\Command;

/**
 * Ask KwikPay about online payments nobody has confirmed yet.
 *
 * Merchant docs FAQ: "Callbacks are best-effort notification, not guaranteed
 * delivery. Every integration needs a reconciliation job that polls
 * /api/query for orders still open, or payments will be lost silently." A
 * callback that never arrives — our server down, the tunnel URL changed, a
 * non-2xx that KwikPay does not retry — would otherwise leave an owner who paid
 * looking at "waiting" forever.
 *
 * Every minute from the scheduler, but each payment is asked about on its own
 * backoff (`next_check_at`: first after 2 minutes, then 2, 4, 8, 16, 32 and
 * every 60), so a payment the owner abandoned is not polled in a tight loop.
 * "5" completes it through the same idempotent path the callback uses, "3"
 * fails it, "1" and "0" leave it. After 24 hours still pending it is flagged
 * for the super admin — never failed automatically, because "no answer is not
 * no payment".
 *
 * Runs whatever the payment mode is: switching to simulated must not strand a
 * payment already made through KwikPay.
 */
class ReconcilePayments extends Command
{
    protected $signature = 'biztrack:reconcile-payments {--limit=50 : Most payments to ask about in one run}';

    protected $description = 'Ask KwikPay about pending online payments and settle the ones it has decided';

    public function handle(KwikPayGateway $gateway): int
    {
        $due = Payment::query()
            ->awaitingKwikPay()
            ->where('created_at', '<=', now()->subMinutes(KwikPayGateway::FIRST_CHECK_AFTER_MINUTES))
            ->where(fn ($q) => $q->whereNull('next_check_at')->orWhere('next_check_at', '<=', now()))
            /*
             * A payment with no check scheduled first, then the longest
             * overdue. Said explicitly because the engines disagree when it is
             * left unsaid: SQLite puts NULL first in an ascending sort and
             * PostgreSQL puts it last, so in production a backlog of overdue
             * re-checks could fill every run's --limit ahead of it.
             */
            ->orderByRaw('next_check_at asc nulls first')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        $settled = 0;
        foreach ($due as $payment) {
            $after = $gateway->check($payment, 'reconcile');
            if (! $after->isPending()) {
                $settled++;
            }
        }

        $this->info("Asked about {$due->count()} payment(s); {$settled} settled.");

        return self::SUCCESS;
    }
}
