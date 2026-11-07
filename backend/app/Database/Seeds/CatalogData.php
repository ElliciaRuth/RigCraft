<?php

namespace App\Database\Seeds;

use App\Libraries\UnitRules;

/**
 * The extended product catalogue: 30 certified units (CPUs, GPUs, RAM,
 * motherboards, SSDs), each with one passing stress test.
 *
 * One source for both CatalogSeeder (php spark db:seed CatalogSeeder) and the
 * matching block in database/rigcraft_db.sql, so hashes and serials are the
 * same everywhere. Dates are relative: "N days ago".
 *
 * Prices are PHP (₱): buyout = what we paid, listing = our price,
 * retail = brand-new SRP shown as "you save".
 */
final class CatalogData
{
    /** [model, brand, category, buyout, listing, retail, hash suffix, manufacturer S/N, intake days ago, technician] */
    private const UNITS = [
        ['ADATA Ultimate SU650 512GB',                    'ADATA',     'Storage',      800,  1250,  1995, '4417', '2M1820058713',        34, 'aisha'],
        ['AMD Ryzen 5 3400G',                             'AMD',       'CPU',         2800,  3995,  6995, '6052', '9JA2731S90269',       31, 'marcus'],
        ['AMD Ryzen 5 5500',                              'AMD',       'CPU',         2400,  3495,  5295, '3381', '9MB4207R40115',       29, 'aisha'],
        ['AMD Ryzen 5 7500F',                             'AMD',       'CPU',         5200,  6995,  8995, '7714', '9PC3318T70482',       12, 'marcus'],
        ['AMD Ryzen 7 5700X',                             'AMD',       'CPU',         5000,  6995,  9995, '2268', '9HB2915P60631',       18, 'aisha'],
        ['ASRock Radeon RX 7600 Challenger White 8GB',    'ASRock',    'GPU',         8800, 11995, 16995, '5830', 'AR7600CLW2311047',    16, 'marcus'],
        ['ASRock Radeon RX 7600 XT Challenger 16GB OC',   'ASRock',    'GPU',        12000, 15995, 21995, '9147', 'AR7600XTC2402218',     9, 'marcus'],
        ['ASRock B450M Steel Legend',                     'ASRock',    'Motherboard', 2300,  3495,  5995, '1609', 'BB1M90100377',        40, 'aisha'],
        ['ASRock B550M Pro RS',                           'ASRock',    'Motherboard', 3500,  4995,  7495, '4482', 'BH2N10301925',        22, 'marcus'],
        ['ASUS Dual Radeon RX 7600 V2 OC 8GB',            'ASUS',      'GPU',         8500, 11495, 16495, '3075', 'R9YVCM019284',        14, 'aisha'],
        ['Colorful iGame GeForce RTX 3060 Ti Advanced OC','Colorful',  'GPU',        11000, 14995, 24995, '8821', 'CF3060TIA21330592',   37, 'marcus'],
        ['Colorful iGame GeForce RTX 4070 SUPER Ultra W OC','Colorful','GPU',        26000, 33995, 42995, '1953', 'CF4070SUW24080317',    7, 'aisha'],
        ['G.Skill Flare X5 32GB DDR5-6000',               'G.Skill',   'RAM',         3500,  4995,  6995, '6640', 'F5-6000J3238F16GX2',  11, 'aisha'],
        ['G.Skill Ripjaws V 16GB DDR4-3200',              'G.Skill',   'RAM',         1200,  1795,  2795, '2905', 'F4-3200C16D-16GVKB',  26, 'marcus'],
        ['Gigabyte A520M DS3H AC',                        'Gigabyte',  'Motherboard', 2000,  2995,  4695, '7381', 'SN21384013066',       30, 'marcus'],
        ['Gigabyte B550M K',                              'Gigabyte',  'Motherboard', 2800,  3995,  5995, '5126', 'SN23157020481',       19, 'aisha'],
        ['Gigabyte GeForce GTX 1650 Mini ITX OC 4G',      'Gigabyte',  'GPU',         3500,  4995,  9495, '8034', 'SN20234059917',       44, 'aisha'],
        ['Gigabyte GeForce RTX 4060 Windforce OC 8G',     'Gigabyte',  'GPU',        10500, 13995, 18495, '4708', 'SN24026011583',        8, 'marcus'],
        ['Intel Core i5-14400F',                          'Intel',     'CPU',         6500,  8495, 11495, '3359', 'X335J503',             6, 'aisha'],
        ['Kingston Fury Beast 16GB DDR4-3200',            'Kingston',  'RAM',         1250,  1895,  2895, '9512', 'KF432C16BBK2-16',     24, 'aisha'],
        ['Lexar NM620 512GB NVMe M.2',                    'Lexar',     'Storage',     1000,  1595,  2495, '6273', 'NLD512G220510244',    20, 'marcus'],
        ['MSI B450M-A PRO MAX II',                        'MSI',       'Motherboard', 1900,  2795,  4295, '2491', '602-7C52-030B2304',   27, 'marcus'],
        ['Palit GeForce RTX 3050 StormX 6GB',             'Palit',     'GPU',         5500,  7495, 10995, '7166', 'BJ240300418',         15, 'aisha'],
        ['Samsung 870 EVO 500GB',                         'Samsung',   'Storage',     1700,  2495,  3995, '3847', 'S6PWNJ0T402871',      33, 'marcus'],
        ['Samsung 990 PRO 1TB NVMe M.2',                  'Samsung',   'Storage',     4600,  6295,  8995, '5590', 'S73VNJ0W118342',      10, 'aisha'],
        ['Sapphire Pure Radeon RX 7800 XT 16GB',          'Sapphire',  'GPU',        21000, 26995, 33995, '2736', 'SP7800XTP23120086',   13, 'marcus'],
        ['TeamGroup MP33 512GB NVMe M.2',                 'TeamGroup', 'Storage',      900,  1395,  2195, '8259', 'TM11-1902190350001',  36, 'aisha'],
        ['TeamGroup T-Force Delta RGB 16GB DDR4-3200',    'TeamGroup', 'RAM',         1450,  2095,  3195, '1328', 'TF3D416G3200HC16F',   17, 'marcus'],
        ['TeamGroup T-Force Vulcan Z 16GB DDR4-3200',     'TeamGroup', 'RAM',         1150,  1695,  2595, '6981', 'TLZGD416G3200HC16F',  23, 'aisha'],
        ['XFX Speedster QICK 319 Radeon RX 7800 XT 16GB', 'XFX',       'GPU',        19500, 25495, 31995, '4013', 'XFX7800XTQ319A0942',   5, 'marcus'],
    ];

    /** Stress-test profile per category: tool, minutes, peak °C range, fan % range, paste?, ultrasonic? */
    private const TESTS = [
        'GPU'         => ['FurMark 2',     30, [63.0, 71.4], [55, 82], true,  true],
        'CPU'         => ['Prime95',       30, [61.0, 71.2], [48, 78], true,  false],
        'RAM'         => ['MemTest86',     60, [41.0, 52.0], [0, 0],   false, false],
        'Motherboard' => ['OCCT',          30, [52.0, 66.0], [35, 55], false, true],
        'Storage'     => ['OCCT',          30, [38.0, 49.0], [0, 0],   false, false],
    ];
    private const NVME_PEAK = [54.0, 68.0];   // M.2 drives run hotter than SATA
    private const PASTES    = ['Arctic MX-6', 'Thermal Grizzly Kryonaut', 'Noctua NT-H2', 'Honeywell PTM7950', 'Arctic MX-4'];
    private const CPU_TOOLS = ['Prime95', 'Cinebench R23'];

    /**
     * One row per unit: ['component' => [...], 'log' => [...], 'technician' => username,
     * 'intake_days' => int, 'test_days' => int].
     */
    public static function rows(): array
    {
        $rows = [];
        foreach (self::UNITS as [$model, $brand, $cat, $buy, $list, $srp, $sfx, $sn, $days, $tech]) {
            $seed = crc32($model);   // deterministic "random" test values per unit
            $pick = static fn (array $r, int $salt) => $r[0] + (($seed >> $salt) % 1000) / 999 * ($r[1] - $r[0]);
            [$tool, $mins, $peakRange, $fanRange, $paste, $ultra] = self::TESTS[$cat];

            if ($cat === 'Storage' && str_contains($model, 'NVMe')) {
                $peakRange = self::NVME_PEAK;
            }
            if ($cat === 'CPU') {
                $tool = self::CPU_TOOLS[$seed % 2];
            }

            $rows[] = [
                'technician'  => $tech,
                'intake_days' => $days,
                'test_days'   => max(1, $days - 1 - ($seed % 2)),
                'component'   => [
                    'serial_hash'     => sprintf('RC-%s-%s', UnitRules::modelToken($model), $sfx),
                    'serial_number'   => $sn,
                    'model_name'      => $model,
                    'brand'           => $brand,
                    'category'        => $cat,
                    'buyout_cost'     => $buy,
                    'listing_price'   => $list,
                    'retail_price'    => $srp,
                    'status'          => 'Certified',
                    'warranty_months' => 6,
                    'notes'           => null,
                ],
                'log' => [
                    'test_duration_mins'     => $mins,
                    'peak_temp_celsius'      => round($pick($peakRange, 3), 1),
                    'fan_speed_pct'          => (int) round($pick($fanRange, 7)),
                    'memory_error_count'     => 0,
                    'thermal_paste_replaced' => $paste,
                    'paste_brand'            => $paste ? self::PASTES[$seed % count(self::PASTES)] : null,
                    'ultrasonic_cleaned'     => $ultra,
                    'benchmark_tool'         => $tool,
                    'stress_status'          => 'PASSED',
                ],
            ];
        }

        return $rows;
    }
}
