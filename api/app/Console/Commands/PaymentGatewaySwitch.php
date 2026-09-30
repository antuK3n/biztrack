<?php

namespace App\Console\Commands;

use App\Services\KwikPay\KwikPayGateway;
use App\Support\PaymentMode;
use Illuminate\Console\Command;

/**
 * The payment gateway switch from a terminal (docs/payment-gateway.md).
 *
 *   status  which mode new payments use, and whether KwikPay is configured
 *   on      switch to KwikPay — refused while its credentials are missing
 *   off     switch to simulated (what the presentation runs on)
 *   test    one signed /api/me call to KwikPay
 *
 * The same switch as PUT /admin/payment-gateway and audit-logged the same way,
 * with no user (the audit screen shows it as the system's doing) and
 * `via: artisan`.
 */
class PaymentGatewaySwitch extends Command
{
    protected $signature = 'biztrack:payment-gateway {action : status|on|off|test}';

    protected $description = 'Show, switch or test how owners pay: simulated or KwikPay';

    public function handle(KwikPayGateway $gateway): int
    {
        return match ($this->argument('action')) {
            'status' => $this->status(),
            'on' => $this->switchTo(PaymentMode::KWIKPAY),
            'off' => $this->switchTo(PaymentMode::SIMULATED),
            'test' => $this->test($gateway),
            default => $this->unknown(),
        };
    }

    private function status(): int
    {
        $s = PaymentMode::status();

        $this->line('New payments: <info>'.$s['mode'].'</info>'.($s['mode'] === 'simulated' ? ' (no real money)' : ' (real money through KwikPay)'));
        $this->line('Default from PAYMENT_GATEWAY: '.$s['default_mode']);
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
        $this->error('Use one of: status, on, off, test.');

        return self::INVALID;
    }
}
