<?php

namespace App\Console\Commands;

use App\Services\KwikPay\KwikPayGateway;
use App\Support\PaymentMode;
use Illuminate\Console\Command;

/**
 * The payment gateway switches from a terminal (docs/payment-gateway.md).
 *
 *   status       which mode new payments use, what KwikPay collects, and
 *                whether KwikPay is configured
 *   on           switch to KwikPay — refused while its credentials are missing
 *   off          switch to simulated
 *   test-charge  KwikPay collects the test amount (₱1.00 unless
 *                KWIKPAY_CHARGE_OVERRIDE names another); the bill, receipt and
 *                records keep the real amount
 *   full-charge  KwikPay collects the full bill
 *   callback-only  only KwikPay's signed callback marks a payment paid (the
 *                default)
 *   trust-query  a "5" or "3" from /api/query also settles a payment
 *   read-message the /api/query answer's message settles a payment
 *                ("Transaction completed successfully" / "Transaction failed"),
 *                the way payment-gateway-kwgu.onrender.com reports it
 *   test         one signed /api/me call to KwikPay
 *
 * The same switches as PUT /admin/payment-gateway and the Online Payments
 * screen, audit-logged the same way, with no user (the audit screen shows it
 * as the system's doing) and `via: artisan`. This is the fallback for when the
 * screen cannot be reached; the screen is the way during a presentation.
 */
class PaymentGatewaySwitch extends Command
{
    protected $signature = 'biztrack:payment-gateway {action : status|on|off|test-charge|full-charge|callback-only|trust-query|read-message|test}';

    protected $description = 'Show, switch or test how owners pay (simulated or KwikPay) what KwikPay collects (test charge or the full bill), and what marks a payment paid';

    public function handle(KwikPayGateway $gateway): int
    {
        return match ($this->argument('action')) {
            'status' => $this->status(),
            'on' => $this->switchTo(PaymentMode::KWIKPAY),
            'off' => $this->switchTo(PaymentMode::SIMULATED),
            'test-charge' => $this->switchCharge(PaymentMode::CHARGE_TEST),
            'full-charge' => $this->switchCharge(PaymentMode::CHARGE_FULL),
            'callback-only' => $this->switchConfirm(PaymentMode::CONFIRM_CALLBACK),
            'trust-query' => $this->switchConfirm(PaymentMode::CONFIRM_QUERY),
            'read-message' => $this->switchConfirm(PaymentMode::CONFIRM_MESSAGE),
            'test' => $this->test($gateway),
            default => $this->unknown(),
        };
    }

    private function status(): int
    {
        $s = PaymentMode::status();

        $this->line('New payments: <info>'.$s['mode'].'</info>'.($s['mode'] === 'simulated' ? ' (no real money)' : ' (real money through KwikPay)'));
        $this->line('Default from PAYMENT_GATEWAY: '.$s['default_mode']);
        $this->line('KwikPay collects: <info>'.self::chargeLine($s['charge'], $s['test_amount']).'</info>'
            .($s['charge'] === PaymentMode::CHARGE_TEST ? ' (the bill, receipt and records keep the real amount)' : ''));
        $this->line('Marked paid by: <info>'.self::confirmLine($s['confirm']).'</info>');
        $this->line('KwikPay configured: '.($s['kwikpay']['configured'] ? 'yes' : 'no — missing '.implode(', ', $s['kwikpay']['missing'])));
        $this->line('KwikPay address: '.$s['kwikpay']['base_url']);
        $this->line('Callback address KwikPay must reach: '.$s['kwikpay']['callback_url']);
        $this->line('Online payments still waiting: '.$s['pending'].' ('.count($s['flagged']).' flagged for staff)');

        return self::SUCCESS;
    }

    private function switchTo(string $mode): int
    {
        try {
            $change = PaymentMode::switchTo($mode, 'artisan');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Payment gateway: {$change['from']} → {$change['to']}.");
        if ($mode === PaymentMode::SIMULATED) {
            $this->line('Online payments already in flight are still confirmed by KwikPay and reconciled.');
        }

        return self::SUCCESS;
    }

    private function switchCharge(string $charge): int
    {
        $change = PaymentMode::switchCharge($charge, 'artisan');
        $amount = PaymentMode::status()['test_amount'];

        $this->info('KwikPay collects: '.self::chargeLine($change['from'], $amount).' → '.self::chargeLine($change['to'], $amount).'.');
        $this->line('Online payments already started keep the amount they were opened with.');

        return self::SUCCESS;
    }

    private function switchConfirm(string $confirm): int
    {
        $change = PaymentMode::switchConfirm($confirm, 'artisan');
        $this->info('Marked paid by: '.self::confirmLine($change['from']).' → '.self::confirmLine($change['to']).'.');

        return self::SUCCESS;
    }

    private static function confirmLine(string $confirm): string
    {
        return match ($confirm) {
            PaymentMode::CONFIRM_QUERY => "KwikPay's signed callback, or its status answer",
            PaymentMode::CONFIRM_MESSAGE => "KwikPay's signed callback, or its answer's message",
            default => "KwikPay's signed callback only",
        };
    }

    private static function chargeLine(string $charge, string $testAmount): string
    {
        return $charge === PaymentMode::CHARGE_TEST ? "a ₱{$testAmount} test charge" : 'the full bill';
    }

    private function test(KwikPayGateway $gateway): int
    {
        $result = $gateway->testConnection();
        $result['ok'] ? $this->info($result['message']) : $this->error($result['message']);

        if ($result['ok'] && ($result['merchant_display_name'] ?? '') !== '') {
            $this->line('Merchant: '.$result['merchant_display_name']);
        }

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function unknown(): int
    {
        $this->error('Use one of: status, on, off, test-charge, full-charge, test.');

        return self::INVALID;
    }
}
