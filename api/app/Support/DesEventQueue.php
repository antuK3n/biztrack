<?php

namespace App\Support;

/**
 * The event calendar behind Des: a binary min-heap ordered by simulated time,
 * with insertion order breaking ties.
 *
 * The tie-break is what makes a scenario reproducible. Two events scheduled for
 * the same instant — a service ending exactly when an arrival lands — would
 * otherwise come out in whatever order the heap happened to sift them into, and
 * the RNG draws that follow would diverge. Sequencing the ties means the same
 * seed replays the same run.
 *
 * SplPriorityQueue would do the ordering but its own tie-handling is documented
 * as unspecified, and SplMinHeap cannot carry a secondary key without a custom
 * comparator, so the twenty lines are cheaper than the workaround.
 */
final class DesEventQueue
{
    /**
     * 1-indexed heap; slot 0 stays empty so a node's children are 2i and 2i+1.
     *
     * @var array<int, array{0: float, 1: int, 2: string, 3: array<string, mixed>}>
     */
    private array $heap = [null];

    private int $size = 0;

    /** @param  array<string, mixed>  $payload */
    public function push(float $time, int $sequence, string $type, array $payload): void
    {
        if (! is_finite($time)) {
            // An arrival stream with a zero rate never fires; keeping INF out of
            // the heap saves comparing against it on every sift.
            return;
        }

        $this->heap[++$this->size] = [$time, $sequence, $type, $payload];

        for ($i = $this->size; $i > 1 && $this->before($i, $i >> 1); $i >>= 1) {
            $this->swap($i, $i >> 1);
        }
    }

    /**
     * @return array{0: float, 1: string, 2: array<string, mixed>}|null
     *                                                                  [time, type, payload], earliest first.
     */
    public function pop(): ?array
    {
        if ($this->size === 0) {
            return null;
        }

        $top = $this->heap[1];
        $this->heap[1] = $this->heap[$this->size];
        unset($this->heap[$this->size--]);

        $i = 1;
        while (true) {
            $left = $i << 1;
            if ($left > $this->size) {
                break;
            }
            $child = ($left + 1 <= $this->size && $this->before($left + 1, $left)) ? $left + 1 : $left;
            if (! $this->before($child, $i)) {
                break;
            }
            $this->swap($i, $child);
            $i = $child;
        }

        return [$top[0], $top[2], $top[3]];
    }

    /** Does slot $a sort before slot $b — earlier time, then earlier sequence? */
    private function before(int $a, int $b): bool
    {
        if ($this->heap[$a][0] !== $this->heap[$b][0]) {
            return $this->heap[$a][0] < $this->heap[$b][0];
        }

        return $this->heap[$a][1] < $this->heap[$b][1];
    }

    private function swap(int $a, int $b): void
    {
        [$this->heap[$a], $this->heap[$b]] = [$this->heap[$b], $this->heap[$a]];
    }
}
