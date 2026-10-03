<?php

namespace App\Console\Commands;

use App\Support\DebugPanel;
use Illuminate\Console\Command;

/**
 * Open or close the Debug page, from the server and only from the server.
 *
 *   on --hours=N  open it for N hours (default 6, at most 24); it closes
 *                 itself after that whether or not anybody runs `off`
 *   off           close it now
 *   status        open or closed, and until when
 *
 * There is deliberately no web or API door that does this
 * (App\Support\DebugPanel). Audited as `debug.panel_opened` /
 * `debug.panel_closed` with no user and `via: artisan`.
 */
class DebugPanelSwitch extends Command
{
    protected $signature = 'biztrack:debug-panel {action : on|off|status} {--hours='.DebugPanel::DEFAULT_HOURS.' : How long to keep it open (1 to '.DebugPanel::MAX_HOURS.')}';

    protected $description = 'Open the super admin\'s Debug page for a few hours, close it, or say whether it is open';

    public function handle(): int
    {
        return match ($this->argument('action')) {
            'on' => $this->open(),
            'off' => $this->close(),
            'status' => $this->status(),
            default => $this->unknown(),
        };
    }

    private function open(): int
    {
        $hours = $this->option('hours');
        if (! is_numeric($hours) || (int) $hours != $hours) {
            $this->error('--hours must be a whole number, 1 to '.DebugPanel::MAX_HOURS.'.');

            return self::INVALID;
        }

        try {
            $until = DebugPanel::open((int) $hours);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        }

        $this->info('Debug page open to the super admin until '.$until->toDayDateTimeString().' ('.(int) $hours.' '.((int) $hours === 1 ? 'hour' : 'hours').'). It closes itself then.');

        return self::SUCCESS;
    }

    private function close(): int
    {
        DebugPanel::close();
        $this->info('Debug page closed. Its pages and API answer 404 again.');
        $this->bypassNote();

        return self::SUCCESS;
    }

    private function status(): int
    {
        $until = DebugPanel::openUntil();
        $this->line($until
            ? 'Debug page: <info>open</info> until '.$until->toDayDateTimeString().' ('.$until->diffForHumans().').'
            : 'Debug page: closed.');
        $this->bypassNote();

        return self::SUCCESS;
    }

    private function bypassNote(): void
    {
        if (DebugPanel::bypassed()) {
            $this->warn('APP_ENV is local, so the super admin has the page without the flag.');
        }
    }

    private function unknown(): int
    {
        $this->error('Use one of: on, off, status.');

        return self::INVALID;
    }
}
