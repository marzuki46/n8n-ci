<?php

namespace App\Services;

/**
 * Parser ekspresi cron sederhana (5 field).
 * Mendukung: bintang, langkah (/n), rentang (a-b), daftar (a,b), dan nilai tunggal.
 * Contoh: "* * * * *", "SLASH5 * * * *", "0 9 * * 1-5", "0,30 * * * *".
 */
class CronService
{
    public function validate(string $expression): bool
    {
        $parts = explode(' ', trim($expression));
        if (count($parts) !== 5) {
            return false;
        }

        $fields = [
            [$parts[0], 0, 59],
            [$parts[1], 0, 23],
            [$parts[2], 1, 31],
            [$parts[3], 1, 12],
            [$parts[4], 0, 7],
        ];

        foreach ($fields as [$field, $min, $max]) {
            foreach (explode(',', $field) as $token) {
                if (! $this->validToken($token, $min, $max)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Hitung waktu (menit) berikutnya yang cocok dengan ekspresi, setelah $after.
     */
    public function nextRun(string $expression, string $after, string $timezone = 'UTC'): string
    {
        $tz = $this->tz($timezone);

        try {
            $now = new \DateTimeImmutable($after, $tz);
        } catch (\Throwable $e) {
            $now = new \DateTimeImmutable($after, new \DateTimeZone('UTC'));
        }

        // Mulai dari menit berikutnya agar tidak langsung eksekusi ulang
        $candidate = $now->modify('+1 minute');
        $candidate = $candidate->setTime((int) $candidate->format('H'), (int) $candidate->format('i'), 0);

        for ($i = 0; $i < 60 * 24 * 5; $i++) {
            if ($this->matches($expression, $candidate)) {
                return $candidate->format('Y-m-d H:i:s');
            }
            $candidate = $candidate->modify('+1 minute');
        }

        // Tidak ditemukan (ekspresi mungkin terlalu ketat) -> 1 tahun lagi
        return $candidate->modify('+1 year')->format('Y-m-d H:i:s');
    }

    public function matches(string $expression, \DateTimeInterface $time): bool
    {
        $parts = explode(' ', trim($expression));
        if (count($parts) !== 5) {
            return false;
        }

        $minute = (int) $time->format('i');
        $hour   = (int) $time->format('G');
        $day    = (int) $time->format('j');
        $month  = (int) $time->format('n');
        $week   = (int) $time->format('w'); // 0 = Minggu

        $weekField = $parts[4];
        // Dalam cron, 0 dan 7 = Minggu
        $weekMatches = $this->fieldMatches($weekField, $week) || $this->fieldMatches($weekField, $week === 0 ? 7 : -1);

        return $this->fieldMatches($parts[0], $minute)
            && $this->fieldMatches($parts[1], $hour)
            && $this->fieldMatches($parts[2], $day)
            && $this->fieldMatches($parts[3], $month)
            && $weekMatches;
    }

    protected function fieldMatches(string $field, int $value): bool
    {
        foreach (explode(',', $field) as $token) {
            if ($this->tokenMatches($token, $value)) {
                return true;
            }
        }

        return false;
    }

    protected function tokenMatches(string $token, int $value): bool
    {
        $token = trim($token);
        if ($token === '*') {
            return true;
        }

        if (strpos($token, '/') !== false) {
            [$base, $step] = explode('/', $token, 2);
            $step = (int) $step;
            if ($step <= 0) {
                return false;
            }
            if ($base === '*') {
                return $value % $step === 0;
            }
            [$start, $end] = $this->rangeBounds($base);
            return $value >= $start && $value <= $end && ($value - $start) % $step === 0;
        }

        if (strpos($token, '-') !== false) {
            [$start, $end] = $this->rangeBounds($token);
            return $value >= $start && $value <= $end;
        }

        return (int) $token === $value;
    }

    protected function rangeBounds(string $token): array
    {
        $parts = explode('-', $token);
        return [(int) $parts[0], (int) ($parts[1] ?? $parts[0])];
    }

    protected function validToken(string $token, int $min, int $max): bool
    {
        $token = trim($token);
        if ($token === '') {
            return false;
        }

        if ($token === '*') {
            return true;
        }

        if (strpos($token, '/') !== false) {
            [$base, $step] = explode('/', $token, 2);
            if (! ctype_digit($step)) {
                return false;
            }
            if ($base === '*') {
                return true;
            }
            $token = $base;
        }

        if (strpos($token, '-') !== false) {
            [$a, $b] = array_map('intval', explode('-', $token, 2));
            return $a >= $min && $a <= $max && $b >= $a && $b <= $max;
        }

        if (! ctype_digit($token)) {
            return false;
        }

        $value = (int) $token;
        return $value >= $min && $value <= $max;
    }

    protected function tz(string $timezone): \DateTimeZone
    {
        try {
            return new \DateTimeZone($timezone);
        } catch (\Throwable $e) {
            return new \DateTimeZone('UTC');
        }
    }
}
