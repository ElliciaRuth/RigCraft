<?php

namespace App\Models;

use CodeIgniter\Model;

/** One row per stress-test run. A unit may be re-tested; the latest run decides its status. */
class DiagnosticLogModel extends Model
{
    protected $table         = 'diagnostic_logs';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = [
        'component_id', 'technician_id', 'tested_by', 'test_duration_mins', 'peak_temp_celsius',
        'fan_speed_pct', 'memory_error_count', 'thermal_paste_replaced', 'paste_brand',
        'ultrasonic_cleaned', 'benchmark_tool', 'stress_status', 'test_date',
    ];

    /** Every run for a unit, newest first, with proper JSON types. */
    public function historyFor(int $componentId): array
    {
        $rows = $this->select('id, tested_by, technician_id, test_duration_mins, peak_temp_celsius, fan_speed_pct,
                               memory_error_count, thermal_paste_replaced, paste_brand, ultrasonic_cleaned,
                               benchmark_tool, stress_status, test_date')
            ->where('component_id', $componentId)
            ->orderBy('test_date', 'DESC')->orderBy('id', 'DESC')
            ->findAll();

        foreach ($rows as &$l) {
            $l['id']                     = (int) $l['id'];
            $l['technician_id']          = $l['technician_id'] === null ? null : (int) $l['technician_id'];
            $l['peak_temp_celsius']      = (float) $l['peak_temp_celsius'];
            $l['fan_speed_pct']          = (int) $l['fan_speed_pct'];
            $l['memory_error_count']     = (int) $l['memory_error_count'];
            $l['test_duration_mins']     = (int) $l['test_duration_mins'];
            $l['thermal_paste_replaced'] = (bool) $l['thermal_paste_replaced'];
            $l['ultrasonic_cleaned']     = (bool) $l['ultrasonic_cleaned'];
        }

        return $rows;
    }

    /** Record a run signed off by $user. $t comes from UnitRules::testFields(). */
    public function record(int $componentId, array $t, array $user, string $stressStatus): void
    {
        $this->insert([
            'component_id'           => $componentId,
            'technician_id'          => $user['id'],
            'tested_by'              => $user['full_name'],
            'test_duration_mins'     => $t['test_duration'],
            'peak_temp_celsius'      => $t['peak_temp'],
            'fan_speed_pct'          => $t['fan_speed'],
            'memory_error_count'     => $t['memory_errors'],
            'thermal_paste_replaced' => (int) $t['paste_replaced'],
            'paste_brand'            => $t['paste_brand'],
            'ultrasonic_cleaned'     => (int) $t['ultrasonic_cleaned'],
            'benchmark_tool'         => $t['benchmark_tool'],
            'stress_status'          => $stressStatus,
        ]);
    }
}
