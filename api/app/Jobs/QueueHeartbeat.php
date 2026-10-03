<?php

namespace App\Jobs;

use App\Support\Heartbeat;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Proof that a queue worker is running: the scheduler puts one of these on
 * the queue every minute (routes/console.php), and only a worker taking it off
 * writes the beat. The Debug page's Health section reads how old that is.
 *
 * Nothing else depends on it. One missed is a minute's staleness, so it is
 * not retried.
 */
class QueueHeartbeat implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        Heartbeat::beat(Heartbeat::QUEUE);
    }
}
