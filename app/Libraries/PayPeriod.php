<?php

namespace App\Libraries;

/**
 * Semi-monthly pay periods: 1–15 ("A") and 16–end of month ("B").
 */
final class PayPeriod
{
    private function __construct(
        public readonly string $id,    // e.g. "2026-11-A"
        public readonly string $start, // Y-m-d
        public readonly string $end,   // Y-m-d
    ) {
    }

    public static function containing(string $date): self
    {
        $d    = new \DateTimeImmutable($date);
        $half = (int) $d->format('j') <= 15 ? 'A' : 'B';

        return self::of((int) $d->format('Y'), (int) $d->format('n'), $half);
    }

    public static function of(int $year, int $month, string $half): self
    {
        if ($month < 1 || $month > 12 || ! in_array($half, ['A', 'B'], true)) {
            throw new \InvalidArgumentException('Invalid pay period.');
        }
        $first = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $start = $half === 'A' ? $first : $first->setDate($year, $month, 16);
        $end   = $half === 'A' ? $first->setDate($year, $month, 15) : $first->modify('last day of this month');

        return new self(sprintf('%04d-%02d-%s', $year, $month, $half), $start->format('Y-m-d'), $end->format('Y-m-d'));
    }

    public static function fromId(string $id): self
    {
        if (! preg_match('/^(\d{4})-(\d{2})-([AB])$/', $id, $m)) {
            throw new \InvalidArgumentException('Invalid pay period id.');
        }

        return self::of((int) $m[1], (int) $m[2], $m[3]);
    }

    public function label(): string
    {
        $s = new \DateTimeImmutable($this->start);
        $e = new \DateTimeImmutable($this->end);

        return $s->format('M j') . '–' . $e->format('j, Y');
    }
}
