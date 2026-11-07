<?php

namespace App\Validation;

/**
 * Extra validation rules (registered in Config\Validation::$ruleSets).
 */
class RigCraftRules
{
    /** Loose check for a Philippine (or international) mobile number: 7–15 digits, common separators. */
    public function ph_phone(?string $str = null): bool
    {
        $str    = (string) $str;
        $digits = preg_replace('/\D/', '', $str);

        return (bool) preg_match('/^\+?[0-9 ()\-]{7,20}$/', $str) && strlen($digits) >= 7 && strlen($digits) <= 15;
    }

    /** A Y-m-d date that is today or earlier. */
    public function not_future(?string $str = null): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $str);

        return $d !== false && $d <= new \DateTimeImmutable('today');
    }

    /** RigCraft unit hash, e.g. RC-3070TI-8941. */
    public function rc_hash(?string $str = null): bool
    {
        return (bool) preg_match('/^RC-[A-Z0-9]{1,10}-\d{4}$/', strtoupper(trim((string) $str)));
    }
}
