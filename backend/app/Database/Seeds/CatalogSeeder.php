<?php

namespace App\Database\Seeds;

use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Seeder;

/**
 * Adds the extended product catalogue (see CatalogData) to an existing
 * database. Safe to run more than once: units already on file (same hash or
 * same model name) are skipped.
 *
 *   php spark db:seed CatalogSeeder
 */
class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $techs = array_column($this->db->table('users')->select('id, username, full_name')->get()->getResultArray(), null, 'username');
        $fallback = $this->db->table('users')->select('id, username, full_name')->where('role', 'admin')->get(1)->getRowArray();

        $added = $skipped = 0;
        $this->db->transStart();

        foreach (CatalogData::rows() as $row) {
            $c = $row['component'];
            $exists = $this->db->table('components')
                ->groupStart()->where('serial_hash', $c['serial_hash'])->orWhere('model_name', $c['model_name'])->groupEnd()
                ->countAllResults();
            if ($exists) {
                $skipped++;
                continue;
            }

            $tech = $techs[$row['technician']] ?? $fallback;
            $this->db->table('components')->insert($c + [
                'intake_date' => date('Y-m-d', strtotime("-{$row['intake_days']} days")),
                'created_by'  => $tech['id'] ?? null,
            ]);
            $this->db->table('diagnostic_logs')->insert($row['log'] + [
                'component_id'  => $this->db->insertID(),
                'technician_id' => $tech['id'] ?? null,
                'tested_by'     => $tech['full_name'] ?? 'RigCraft Technician',
                'test_date'     => date('Y-m-d H:i:s', strtotime("-{$row['test_days']} days")),
            ]);
            $added++;
        }

        $this->db->transComplete();
        CLI::write("Catalogue: {$added} unit(s) added, {$skipped} already on file.", 'green');
    }
}
