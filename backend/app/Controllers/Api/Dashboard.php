<?php

namespace App\Controllers\Api;

use App\Models\ComponentModel;
use DateTimeImmutable;

/** GET /api/stats — numbers for the staff dashboard. */
class Dashboard extends BaseApiController
{
    public function stats()
    {
        $db = db_connect();

        // ---- Money (₱) ----
        $money = array_map('floatval', $db->query(
            "SELECT COALESCE(SUM(CASE WHEN status = 'Certified' THEN listing_price END), 0)          AS stock_value,
                    COALESCE(SUM(CASE WHEN status = 'Certified' THEN buyout_cost END), 0)            AS stock_cost,
                    COALESCE(SUM(CASE WHEN status = 'Sold' THEN sold_price END), 0)                  AS revenue,
                    COALESCE(SUM(CASE WHEN status = 'Sold' THEN sold_price - buyout_cost END), 0)    AS profit,
                    COALESCE(SUM(CASE WHEN status = 'Rejected' THEN buyout_cost END), 0)             AS rejected_cost,
                    COALESCE(SUM(CASE WHEN status IN ('Intake','Testing') THEN buyout_cost END), 0)  AS pending_cost,
                    COALESCE(SUM(CASE WHEN status = 'Sold' AND sold_at >= CURDATE() - INTERVAL 30 DAY THEN sold_price END), 0) AS revenue_30d
             FROM components"
        )->getRowArray());

        // ---- Test quality (all runs) ----
        $t = $db->query(
            "SELECT COUNT(*) AS runs,
                    COALESCE(SUM(stress_status = 'PASSED'), 0) AS passed,
                    AVG(CASE WHEN stress_status = 'PASSED' THEN peak_temp_celsius END) AS avg_pass_temp
             FROM diagnostic_logs"
        )->getRowArray();
        $tests = [
            'runs'          => (int) $t['runs'],
            'passed'        => (int) $t['passed'],
            'pass_rate'     => $t['runs'] > 0 ? round($t['passed'] / $t['runs'] * 100, 1) : null,
            'avg_pass_temp' => $t['avg_pass_temp'] === null ? null : round((float) $t['avg_pass_temp'], 1),
        ];

        // ---- Weekly intake (last 8 weeks, Monday-based), split by latest test outcome ----
        $monday = new DateTimeImmutable('monday this week');
        $weeks  = [];
        for ($i = 7; $i >= 0; $i--) {
            $start = $monday->modify("-{$i} weeks")->format('Y-m-d');
            $weeks[$start] = ['week_start' => $start, 'passed' => 0, 'failed' => 0, 'untested' => 0];
        }
        $rows = model(ComponentModel::class)->withLatestLog()
            ->select('c.intake_date, d.stress_status')
            ->where('c.intake_date >=', array_key_first($weeks))
            ->get()->getResultArray();
        foreach ($rows as $r) {
            $d   = new DateTimeImmutable($r['intake_date']);
            $key = $d->modify('-' . ((int) $d->format('N') - 1) . ' days')->format('Y-m-d');
            if (! isset($weeks[$key])) {
                continue;
            }
            $bucket = $r['stress_status'] === 'PASSED' ? 'passed' : ($r['stress_status'] === 'FAILED' ? 'failed' : 'untested');
            $weeks[$key][$bucket]++;
        }

        // ---- In-stock mix by category ----
        $categories = [];
        foreach ($db->query("SELECT category, COUNT(*) AS n FROM components WHERE status = 'Certified'
                             GROUP BY category ORDER BY n DESC")->getResultArray() as $r) {
            $categories[$r['category']] = (int) $r['n'];
        }

        // ---- Work queues & activity ----
        $awaiting = $db->query(
            "SELECT serial_hash, model_name, status, intake_date FROM components
             WHERE status IN ('Intake','Testing') ORDER BY intake_date ASC, id ASC LIMIT 6"
        )->getResultArray();

        $recentTests = $db->query(
            'SELECT c.serial_hash, c.model_name, d.tested_by, d.peak_temp_celsius, d.stress_status, d.test_date
             FROM diagnostic_logs d JOIN components c ON c.id = d.component_id
             ORDER BY d.test_date DESC, d.id DESC LIMIT 6'
        )->getResultArray();
        foreach ($recentTests as &$r) {
            $r['peak_temp_celsius'] = (float) $r['peak_temp_celsius'];
        }
        unset($r);

        $recentSales = $db->query(
            "SELECT serial_hash, model_name, sold_price, buyout_cost, sold_at FROM components
             WHERE status = 'Sold' ORDER BY sold_at DESC, id DESC LIMIT 5"
        )->getResultArray();
        foreach ($recentSales as &$r) {
            ComponentModel::castNumbers($r, ['sold_price', 'buyout_cost']);
        }
        unset($r);

        return $this->ok([
            'currency'     => $this->rc->currency,
            'counts'       => model(ComponentModel::class)->countsByStatus($this->rc->statuses),
            'money'        => $money,
            'tests'        => $tests,
            'weekly'       => array_values($weeks),
            'categories'   => $categories,
            'awaiting'     => $awaiting,
            'recent_tests' => $recentTests,
            'recent_sales' => $recentSales,
            'rules'        => ['temp_ceiling_celsius' => $this->rc->tempCeiling],
        ]);
    }
}
