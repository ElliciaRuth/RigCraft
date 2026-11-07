<?php

namespace App\Libraries;

use App\Exceptions\ApiError;
use Config\RigCraft;
use DateTimeImmutable;

/**
 * RigCraft unit rules: serial hashes, the pass rule, warranty dates, and the
 * validation of unit / stress-test fields shared by intake, edit and re-test.
 */
class UnitRules
{
    private RigCraft $rc;

    public function __construct()
    {
        $this->rc = config('RigCraft');
    }

    // -----------------------------------------------------------------
    // Hashes
    // -----------------------------------------------------------------

    /**
     * The MODEL token of a serial hash: the longest word containing a digit
     * (the model number), plus a trailing variant suffix such as Ti / Super / XT.
     *   "NVIDIA GeForce RTX 3070 Ti" -> "3070TI"
     *   "AMD Ryzen 7 5800X"          -> "5800X"
     *   "Intel Core i7-12700K"       -> "12700K"
     */
    public static function modelToken(string $modelName): string
    {
        $words    = preg_split('/[^A-Z0-9]+/', strtoupper($modelName), -1, PREG_SPLIT_NO_EMPTY);
        $suffixes = ['TI', 'SUPER', 'XT', 'XTX', 'GRE', 'X3D'];

        $best = -1;
        foreach ($words as $i => $w) {
            if (preg_match('/\d/', $w) && ($best < 0 || strlen($w) > strlen($words[$best]))) {
                $best = $i;
            }
        }

        if ($best >= 0) {
            $token = $words[$best];
            if (isset($words[$best + 1]) && in_array($words[$best + 1], $suffixes, true)) {
                $token .= $words[$best + 1];
            }
        } else {
            $token = implode('', $words);
        }

        return substr($token !== '' ? $token : 'UNIT', 0, 10);
    }

    /** Normalise a hash from the URL or body, or 400. */
    public static function readHash(?string $raw): string
    {
        $hash = strtoupper(trim((string) $raw));
        if (! preg_match('/^RC-[A-Z0-9]{1,10}-\d{4}$/', $hash)) {
            throw new ApiError(400, 'Invalid hash format. Expected e.g. RC-3070TI-8941.');
        }

        return $hash;
    }

    // -----------------------------------------------------------------
    // Pass rule & warranty
    // -----------------------------------------------------------------

    /** Pass rule: peak strictly ≤ the ceiling AND zero memory errors. Returns [passed, reason|null]. */
    public function evaluateTest(float $peak, int $memErrors): array
    {
        $reasons = [];
        if ($peak > $this->rc->tempCeiling) {
            $reasons[] = "Peak temp {$peak}°C exceeds the " . number_format($this->rc->tempCeiling, 1) . '°C ceiling.';
        }
        if ($memErrors > 0) {
            $reasons[] = "{$memErrors} memory error(s) detected.";
        }

        return [! $reasons, $reasons ? implode(' ', $reasons) : null];
    }

    /** Warranty runs from the sale date for sold units (the buyer's cover), otherwise from the test date. */
    public static function warranty(string $status, int $months, ?string $testDate, ?string $soldAt, string $intakeDate): array
    {
        $start    = new DateTimeImmutable($soldAt ?? $testDate ?? $intakeDate);
        $expires  = $start->modify("+{$months} months");
        $eligible = in_array($status, ['Certified', 'Sold'], true) && $months > 0;

        return [
            'months'     => $months,
            'active'     => $eligible && $expires > new DateTimeImmutable('now'),
            'starts_on'  => $start->format('Y-m-d'),
            'expires_on' => $eligible ? $expires->format('Y-m-d') : null,
            'from_sale'  => $soldAt !== null,
        ];
    }

    // -----------------------------------------------------------------
    // Field rules (CodeIgniter validation)
    // -----------------------------------------------------------------

    /** Identity + pricing fields (intake and edit). */
    public function unitRules(bool $withWarranty = false): array
    {
        $max   = $this->rc->maxPrice;
        $money = fn (bool $required, string $label) => [
            'rules'  => ($required ? 'required' : 'permit_empty') . "|numeric|greater_than_equal_to[0]|less_than_equal_to[{$max}]",
            'errors' => [
                'required'              => 'Required.',
                'numeric'               => 'Must be a number.',
                'greater_than_equal_to' => "{$label} must be between 0 and " . number_format($max) . '.',
                'less_than_equal_to'    => "{$label} must be between 0 and " . number_format($max) . '.',
            ],
        ];

        $rules = [
            'model_name'    => ['rules' => 'required|max_length[120]', 'errors' => ['required' => 'Enter the full model name.', 'max_length' => 'Max 120 characters.']],
            'serial_number' => ['rules' => 'required|max_length[64]',  'errors' => ['required' => "Copy the S/N from the unit's sticker.", 'max_length' => 'Max 64 characters.']],
            'brand'         => ['rules' => 'required|max_length[60]',  'errors' => ['required' => 'Brand is required.', 'max_length' => 'Max 60 characters.']],
            'category'      => ['rules' => 'required|in_list[' . implode(',', $this->rc->categories) . ']', 'errors' => ['required' => 'Pick a category.', 'in_list' => 'Pick a category from the list.']],
            'buyout_cost'   => $money(true, 'Cost'),
            'listing_price' => $money(true, 'Price'),
            'retail_price'  => $money(false, 'SRP'),
            'notes'         => ['rules' => 'permit_empty|max_length[1000]', 'errors' => ['max_length' => 'Max 1000 characters.']],
        ];
        if ($withWarranty) {
            $rules['warranty_months'] = [
                'rules'  => "required|is_natural|less_than_equal_to[{$this->rc->maxWarranty}]",
                'errors' => ['required' => 'Required.', 'is_natural' => 'Whole number of months.', 'less_than_equal_to' => "0–{$this->rc->maxWarranty} months."],
            ];
        }

        return $rules;
    }

    /** Stress-test result fields (intake and re-test). */
    public function testRules(): array
    {
        return [
            'peak_temp'     => ['rules' => 'required|numeric|greater_than_equal_to[0]|less_than_equal_to[150]', 'errors' => $this->rangeMsg('0–150 °C.')],
            'fan_speed'     => ['rules' => 'required|is_natural|less_than_equal_to[100]',                       'errors' => $this->rangeMsg('Whole number, 0–100 %.')],
            'memory_errors' => ['rules' => 'permit_empty|is_natural|less_than_equal_to[1000000]',               'errors' => $this->rangeMsg('Whole number, 0 or more.')],
            'test_duration' => ['rules' => 'permit_empty|is_natural_no_zero|less_than_equal_to[1440]',          'errors' => $this->rangeMsg('1–1440 minutes.')],
            'benchmark_tool'=> ['rules' => 'permit_empty|max_length[60]', 'errors' => ['max_length' => 'Max 60 characters.']],
            'paste_brand'   => ['rules' => 'permit_empty|max_length[60]', 'errors' => ['max_length' => 'Max 60 characters.']],
        ];
    }

    /** Validated identity fields → column values. */
    public static function unitFields(array $in): array
    {
        $money = fn ($v) => $v === null || $v === '' ? null : round((float) $v, 2);

        return [
            'model_name'    => $in['model_name'],
            'serial_number' => $in['serial_number'],
            'brand'         => $in['brand'],
            'category'      => $in['category'],
            'buyout_cost'   => $money($in['buyout_cost']),
            'listing_price' => $money($in['listing_price']),
            'retail_price'  => $money($in['retail_price'] ?? null),
            'notes'         => ($in['notes'] ?? '') === '' ? null : $in['notes'],
        ];
    }

    /** Validated stress-test fields → normalised values. */
    public static function testFields(array $in): array
    {
        $paste = trim((string) ($in['paste_brand'] ?? ''));
        $tool  = trim((string) ($in['benchmark_tool'] ?? ''));

        return [
            'peak_temp'          => round((float) $in['peak_temp'], 1),
            'fan_speed'          => (int) $in['fan_speed'],
            'memory_errors'      => (int) ($in['memory_errors'] ?? 0),
            'test_duration'      => (int) (($in['test_duration'] ?? '') === '' || $in['test_duration'] === null ? 30 : $in['test_duration']),
            'benchmark_tool'     => $tool !== '' ? $tool : 'FurMark 2',
            'paste_brand'        => $paste !== '' ? $paste : null,
            'paste_replaced'     => $paste !== '',
            'ultrasonic_cleaned' => filter_var($in['ultrasonic_cleaned'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    private function rangeMsg(string $msg): array
    {
        return array_fill_keys(['required', 'numeric', 'is_natural', 'is_natural_no_zero', 'greater_than_equal_to', 'less_than_equal_to'], $msg);
    }
}
