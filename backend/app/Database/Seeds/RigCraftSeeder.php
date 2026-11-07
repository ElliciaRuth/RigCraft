<?php

namespace App\Database\Seeds;

use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Seeder;

/**
 * Sample data: 3 staff accounts, 1 demo shopper, 12 units and their stress
 * runs. Dates are relative to today. Only runs on an empty database.
 *
 *   php spark db:seed RigCraftSeeder
 *
 * Logins (change them after first sign-in):
 *   admin / admin123 (Administrator) · marcus, aisha / tech1234 (Technicians)
 *   Shopper: juan@example.com / customer123
 */
class RigCraftSeeder extends Seeder
{
    public function run(): void
    {
        if ($this->db->table('users')->countAllResults() > 0) {
            CLI::write('RigCraft already has data; seeding skipped. (Re-import database/rigcraft_db.sql to reset.)', 'yellow');

            return;
        }

        $this->db->transStart();

        // Passwords: admin123 / tech1234 (bcrypt)
        $this->db->table('users')->insertBatch([
            ['id' => 1, 'username' => 'admin',  'full_name' => 'System Administrator', 'role' => 'admin',      'password_hash' => '$2y$10$dTa/gpJ8ADbn7r3yeF/wvufo5oHS6LYRkOQtOxlY1rAqMjWYTEBOq'],
            ['id' => 2, 'username' => 'marcus', 'full_name' => 'Marcus Reyes',         'role' => 'technician', 'password_hash' => '$2y$10$T9y6OAlPeDYsJCA973J94ekBAyy0XJongzgXA/8RVahcLHxKc8L6a'],
            ['id' => 3, 'username' => 'aisha',  'full_name' => 'Aisha Patel',          'role' => 'technician', 'password_hash' => '$2y$10$T9y6OAlPeDYsJCA973J94ekBAyy0XJongzgXA/8RVahcLHxKc8L6a'],
        ]);

        // Demo shopper. Password: customer123 (bcrypt)
        $this->db->table('customers')->insert([
            'id' => 1, 'email' => 'juan@example.com', 'full_name' => 'Juan Dela Cruz', 'phone' => '0917 555 0123',
            'password_hash' => '$2y$10$kraGUoW9tmxD6sdszFF1r.v0LMpfTX3hT8X8nfzvanNX.iRfgzGr2',
        ]);

        // retail_price = illustrative brand-new SRP used for the shop's "you save" figures.
        $this->db->query(<<<'SQL'
            INSERT INTO components
              (id, serial_hash, serial_number, model_name, brand, category, intake_date,
               buyout_cost, listing_price, retail_price, status, warranty_months, sold_price, sold_at, notes, created_by)
            VALUES
              (1,  'RC-3070TI-8941',    '2216K0471983',     'NVIDIA GeForce RTX 3070 Ti',          'NVIDIA',   'GPU', CURDATE() - INTERVAL 10 DAY, 13500.00, 19999.00, 32995.00, 'Certified', 6, NULL, NULL, 'Founders Edition. Minor scuff on backplate.', 2),
              (2,  'RC-5800X-3317',     '9HA2741P10388',    'AMD Ryzen 7 5800X',                   'AMD',      'CPU', CURDATE() - INTERVAL 6 DAY,   6000.00,  8995.00, 14500.00, 'Certified', 6, NULL, NULL, NULL, 3),
              (3,  'RC-6700XT-5502',    'SR6700XT2208431',  'AMD Radeon RX 6700 XT',               'Sapphire', 'GPU', CURDATE() - INTERVAL 4 DAY,   9500.00,     0.00, 21995.00, 'Rejected',  0, NULL, NULL, 'Artifacts under load. Likely VRAM fault — part out.', 2),
              (4,  'RC-3060-4410',      'MSI3060V12-55102', 'NVIDIA GeForce RTX 3060 12GB',        'MSI',      'GPU', CURDATE() - INTERVAL 52 DAY,  8500.00, 12999.00, 19995.00, 'Sold',      6, 12500.00, CURDATE() - INTERVAL 30 DAY, NULL, 3),
              (5,  'RC-12400F-7731',    'X2B41700F9921',    'Intel Core i5-12400F',                'Intel',    'CPU', CURDATE() - INTERVAL 45 DAY,  4200.00,  6495.00,  9495.00, 'Sold',      6,  6300.00, CURDATE() - INTERVAL 20 DAY, NULL, 2),
              (6,  'RC-6600-2089',      'MSI6600P2104477',  'AMD Radeon RX 6600',                  'MSI',      'GPU', CURDATE() - INTERVAL 38 DAY,  6000.00,  9499.00, 15995.00, 'Certified', 6, NULL, NULL, 'Failed first run on stock paste; passed after re-paste.', 3),
              (7,  'RC-2080SUPER-5126', 'ZT2080S19C0331',   'NVIDIA GeForce RTX 2080 Super',       'Zotac',    'GPU', CURDATE() - INTERVAL 33 DAY,  9000.00,     0.00, NULL,     'Rejected',  0, NULL, NULL, 'Hotspot runaway even after re-paste. Fan bearing noisy.', 2),
              (8,  'RC-5600X-9902',     '9JB4410K20017',    'AMD Ryzen 5 5600X',                   'AMD',      'CPU', CURDATE() - INTERVAL 28 DAY,  3800.00,  5995.00,  9995.00, 'Sold',      6,  5800.00, CURDATE() - INTERVAL 9 DAY,  NULL, 3),
              (9,  'RC-3080-6614',      'MSI3080GZT10G8812','NVIDIA GeForce RTX 3080 10GB',        'MSI',      'GPU', CURDATE() - INTERVAL 21 DAY, 19500.00, 27999.00, 44995.00, 'Certified', 6, NULL, NULL, 'Gaming Z Trio. Thermal pads replaced.', 2),
              (10, 'RC-32GB-3381',      'CMK32GX4M2E3200',  'Corsair Vengeance LPX 32GB DDR4-3200','Corsair',  'RAM', CURDATE() - INTERVAL 15 DAY,  2500.00,  3995.00,  6495.00, 'Certified', 6, NULL, NULL, '2 x 16GB kit.', 3),
              (11, 'RC-4070-1258',      'GB4070WF3OC7702',  'NVIDIA GeForce RTX 4070',             'Gigabyte', 'GPU', CURDATE() - INTERVAL 2 DAY,  24000.00, 32999.00, 38995.00, 'Testing',   6, NULL, NULL, 'Queued for stress test.', 2),
              (12, 'RC-1660SUPER-7740', 'EVGA1660S06G5521', 'NVIDIA GeForce GTX 1660 Super',       'EVGA',     'GPU', CURDATE() - INTERVAL 1 DAY,   3500.00,     0.00, NULL,     'Intake',    6, NULL, NULL, 'Just arrived. Needs cleaning.', 3)
            SQL);

        $this->db->query(<<<'SQL'
            INSERT INTO diagnostic_logs
              (component_id, technician_id, tested_by, test_duration_mins, peak_temp_celsius, fan_speed_pct,
               memory_error_count, thermal_paste_replaced, paste_brand, ultrasonic_cleaned,
               benchmark_tool, stress_status, test_date)
            VALUES
              (1,  2, 'Marcus Reyes', 30, 68.4,  62, 0, TRUE,  'Arctic MX-6',              TRUE,  'FurMark 2',     'PASSED', NOW() - INTERVAL 8 DAY),
              (2,  3, 'Aisha Patel',  30, 70.9,  74, 0, TRUE,  'Thermal Grizzly Kryonaut', FALSE, 'Prime95',       'PASSED', NOW() - INTERVAL 5 DAY),
              (3,  2, 'Marcus Reyes', 30, 81.7, 100, 3, TRUE,  'Arctic MX-6',              TRUE,  'FurMark 2',     'FAILED', NOW() - INTERVAL 3 DAY),
              (4,  3, 'Aisha Patel',  30, 66.1,  55, 0, TRUE,  'Arctic MX-6',              TRUE,  'FurMark 2',     'PASSED', NOW() - INTERVAL 50 DAY),
              (5,  2, 'Marcus Reyes', 30, 64.0,  48, 0, TRUE,  'Noctua NT-H2',             FALSE, 'Cinebench R23', 'PASSED', NOW() - INTERVAL 44 DAY),
              (6,  3, 'Aisha Patel',  30, 74.5,  88, 0, FALSE, NULL,                       TRUE,  'FurMark 2',     'FAILED', NOW() - INTERVAL 37 DAY),
              (6,  3, 'Aisha Patel',  30, 69.8,  70, 0, TRUE,  'Arctic MX-6',              TRUE,  'FurMark 2',     'PASSED', NOW() - INTERVAL 35 DAY),
              (7,  2, 'Marcus Reyes', 30, 78.9, 100, 0, TRUE,  'Thermal Grizzly Kryonaut', TRUE,  'FurMark 2',     'FAILED', NOW() - INTERVAL 32 DAY),
              (8,  3, 'Aisha Patel',  30, 69.0,  70, 0, TRUE,  'Arctic MX-4',              FALSE, 'Prime95',       'PASSED', NOW() - INTERVAL 27 DAY),
              (9,  2, 'Marcus Reyes', 30, 71.6,  80, 0, TRUE,  'Honeywell PTM7950',        TRUE,  'FurMark 2',     'PASSED', NOW() - INTERVAL 20 DAY),
              (10, 3, 'Aisha Patel',  60, 47.5,   0, 0, FALSE, NULL,                       FALSE, 'MemTest86',     'PASSED', NOW() - INTERVAL 14 DAY)
            SQL);

        $this->db->transComplete();
        CLI::write('RigCraft sample data added: 3 staff, 1 shopper, 12 units, 11 stress runs.', 'green');
    }
}
