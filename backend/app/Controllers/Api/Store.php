<?php

namespace App\Controllers\Api;

use App\Exceptions\ApiError;
use App\Libraries\OrderService;
use App\Libraries\UnitRules;
use App\Models\ComponentModel;

/**
 * Public store endpoints: the catalogue, a unit's public test report, and
 * live price/availability for a cart. Never exposes buyout cost or sale price.
 */
class Store extends BaseApiController
{
    /** GET /api/shop[?category=GPU] — certified units for sale + public counters. */
    public function shop()
    {
        $category = (string) ($this->request->getGet('category') ?? '');
        if ($category !== '' && ! in_array($category, $this->rc->categories, true)) {
            throw new ApiError(400, 'Unknown category.');
        }

        $components = model(ComponentModel::class);
        $items      = $components->forSale($category !== '' ? $category : null);

        $savingsPcts = [];
        foreach ($items as &$it) {
            ComponentModel::castNumbers($it, ['listing_price', 'retail_price', 'peak_temp_celsius']);
            $it['warranty_months'] = (int) $it['warranty_months'];

            // "You save" vs brand-new SRP — only when the SRP is actually higher.
            $it['savings']     = null;
            $it['savings_pct'] = null;
            if ($it['retail_price'] !== null && $it['retail_price'] > $it['listing_price']) {
                $it['savings']     = round($it['retail_price'] - $it['listing_price'], 2);
                $it['savings_pct'] = (int) round($it['savings'] / $it['retail_price'] * 100);
                $savingsPcts[]     = $it['savings_pct'];
            }
        }
        unset($it);

        return $this->ok([
            'currency'   => $this->rc->currency,
            'items'      => $items,
            'categories' => $components->forSaleCategories(),
            'stats'      => array_map('intval', $components->publicStats()) + [
                'avg_savings_pct' => $savingsPcts ? (int) round(array_sum($savingsPcts) / count($savingsPcts)) : 0,
                'max_savings_pct' => $savingsPcts ? max($savingsPcts) : 0,
            ],
            'rules' => ['temp_ceiling_celsius' => $this->rc->tempCeiling, 'warranty_months' => $this->rc->defaultWarranty],
        ]);
    }

    /** GET /api/verify/{hash} — the public test report behind a unit's QR label. */
    public function verify(string $rawHash = '')
    {
        $hash = UnitRules::readHash(urldecode($rawHash));
        $row  = model(ComponentModel::class)->verifyRow($hash);
        if (! $row) {
            throw ApiError::notFound('No RigCraft unit is registered under this hash.', ['hash' => $hash]);
        }

        return $this->ok([
            'currency'  => $this->rc->currency,
            'component' => [
                'serial_hash'     => $row['serial_hash'],
                'serial_number'   => $row['serial_number'],
                'model_name'      => $row['model_name'],
                'brand'           => $row['brand'],
                'category'        => $row['category'],
                'intake_date'     => $row['intake_date'],
                'listing_price'   => (float) $row['listing_price'],
                'status'          => $row['status'],
                'warranty_months' => (int) $row['warranty_months'],
            ],
            'diagnostic' => $row['stress_status'] === null ? null : [
                'tested_by'              => $row['tested_by'],
                'test_duration_mins'     => (int) $row['test_duration_mins'],
                'peak_temp_celsius'      => (float) $row['peak_temp_celsius'],
                'fan_speed_pct'          => (int) $row['fan_speed_pct'],
                'memory_error_count'     => (int) $row['memory_error_count'],
                'thermal_paste_replaced' => (bool) $row['thermal_paste_replaced'],
                'paste_brand'            => $row['paste_brand'],
                'ultrasonic_cleaned'     => (bool) $row['ultrasonic_cleaned'],
                'benchmark_tool'         => $row['benchmark_tool'],
                'stress_status'          => $row['stress_status'],
                'test_date'              => $row['test_date'],
                'temp_ceiling_celsius'   => $this->rc->tempCeiling,
            ],
            'warranty' => UnitRules::warranty($row['status'], (int) $row['warranty_months'],
                $row['test_date'], $row['sold_at'], $row['intake_date']),
        ]);
    }

    /** GET /api/cart?hashes=RC-…,RC-… — current price and availability, in the shopper's cart order. */
    public function cart()
    {
        $hashes = (new OrderService())->readHashList((string) ($this->request->getGet('hashes') ?? ''));
        if (! $hashes) {
            return $this->ok(['items' => [], 'missing' => []]);
        }

        $found = [];
        foreach (model(ComponentModel::class)->cartRows($hashes) as $r) {
            ComponentModel::castNumbers($r, ['listing_price', 'retail_price']);
            $r['warranty_months'] = (int) $r['warranty_months'];
            $r['available']       = $r['status'] === 'Certified' && $r['listing_price'] > 0;
            unset($r['status']);   // only "for sale or not" is public here
            $found[$r['serial_hash']] = $r;
        }

        $items = [];
        foreach ($hashes as $h) {
            if (isset($found[$h])) {
                $items[] = $found[$h];
            }
        }

        return $this->ok([
            'items'   => $items,
            'missing' => array_values(array_diff($hashes, array_keys($found))),
        ]);
    }
}
