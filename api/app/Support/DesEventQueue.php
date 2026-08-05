<?php

namespace App\Support;

final class DesEventQueue
{
    private array $heap = [null];

    private int $size = 0;

    public function push(float $time, int $sequence, string $type, array $payload): void
    {
        if (! is_finite($time)) {
            return;
        }

        $this->heap[++$this->size] = [$time, $sequence, $type, $payload];

        for ($i = $this->size; $i > 1 && $this->before($i, $i >> 1); $i >>= 1) {
            $this->swap($i, $i >> 1);
        }
    }

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
