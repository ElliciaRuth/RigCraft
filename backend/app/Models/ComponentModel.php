<?php

namespace App\Models;

use App\Exceptions\ApiError;
use CodeIgniter\Model;

/**
 * One row per physical unit bought in for refurbishment.
 * Most reads join each unit to ONLY its most recent stress run (alias d).
 */
class ComponentModel extends Model
{
    protected $table         = 'components';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = [
        'serial_hash', 'serial_number', 'model_name', 'brand', 'category', 'intake_date',
        'buyout_cost', 'listing_price', 'retail_price', 'status', 'warranty_months',
        'sold_price', 'sold_at', 'notes', 'created_by',
    ];

    /** JOIN condition: each component to its latest diagnostic run only. */
    public const LATEST_LOG_ON = 'd.id = (SELECT d2.id FROM diagnostic_logs d2
                                          WHERE d2.component_id = c.id
                                          ORDER BY d2.test_date DESC, d2.id DESC
                                          LIMIT 1)';

    /** Query builder over components c LEFT JOIN latest log d. */
    public function withLatestLog()
    {
        return $this->db->table('components c')->join('diagnostic_logs d', self::LATEST_LOG_ON, 'left', false);
    }

    /** Load a unit by hash, or 404. Pass $forUpdate inside a transaction to lock the row. */
    public function findByHashOrFail(string $hash, bool $forUpdate = false): array
    {
        $row = $this->db->query('SELECT * FROM components WHERE serial_hash = ?' . ($forUpdate ? ' FOR UPDATE' : ''), [$hash])
            ->getRowArray();
        if (! $row) {
            throw ApiError::notFound('No RigCraft unit is registered under this hash.', ['hash' => $hash]);
        }

        return $row;
    }

    public function hashExists(string $hash): bool
    {
        return $this->where('serial_hash', $hash)->countAllResults() > 0;
    }

    /** Hashes of other units already on file with the same manufacturer S/N. */
    public function hashesWithSerial(string $serialNumber): array
    {
        return array_column($this->select('serial_hash')->where('serial_number', $serialNumber)->findAll(), 'serial_hash');
    }

    /** Units per status, with every status present. */
    public function countsByStatus(array $statuses): array
    {
        $counts = array_fill_keys($statuses, 0);
        foreach ($this->select('status, COUNT(*) AS n')->groupBy('status')->findAll() as $r) {
            $counts[$r['status']] = (int) $r['n'];
        }

        return $counts;
    }

    // -----------------------------------------------------------------
    // Public store
    // -----------------------------------------------------------------

    /** Public verification row (no buyout cost or sale price). */
    public function verifyRow(string $hash): ?array
    {
        return $this->withLatestLog()
            ->select('c.serial_hash, c.serial_number, c.model_name, c.brand, c.category,
                      c.intake_date, c.listing_price, c.status, c.warranty_months, c.sold_at,
                      d.tested_by, d.test_duration_mins, d.peak_temp_celsius, d.fan_speed_pct,
                      d.memory_error_count, d.thermal_paste_replaced, d.paste_brand,
                      d.ultrasonic_cleaned, d.benchmark_tool, d.stress_status, d.test_date')
            ->where('c.serial_hash', $hash)
            ->get(1)->getRowArray();
    }

    /** Certified units with a price, newest test first. */
    public function forSale(?string $category = null): array
    {
        $b = $this->withLatestLog()
            ->select('c.serial_hash, c.model_name, c.brand, c.category, c.listing_price, c.retail_price,
                      c.warranty_months, d.peak_temp_celsius, d.benchmark_tool, d.test_date')
            ->where('c.status', 'Certified')
            ->where('c.listing_price >', 0);
        if ($category !== null) {
            $b->where('c.category', $category);
        }

        return $b->orderBy('d.test_date', 'DESC')->orderBy('c.id', 'DESC')->get()->getResultArray();
    }

    /** Public counters for the home page. */
    public function publicStats(): array
    {
        return $this->db->query(
            "SELECT COALESCE(SUM(status IN ('Certified','Sold')), 0) AS certified,
                    COALESCE(SUM(status = 'Sold'), 0)                AS sold,
                    (SELECT COUNT(*) FROM diagnostic_logs)          AS tests_run
             FROM components"
        )->getRowArray();
    }

    /** Count of units for sale per category, most first. */
    public function forSaleCategories(): array
    {
        $out = [];
        foreach ($this->select('category, COUNT(*) AS n')->where('status', 'Certified')->where('listing_price >', 0)
            ->groupBy('category')->orderBy('n', 'DESC')->findAll() as $r) {
            $out[$r['category']] = (int) $r['n'];
        }

        return $out;
    }

    /** Price + availability for the units in a cart. */
    public function cartRows(array $hashes): array
    {
        if (! $hashes) {
            return [];
        }

        return $this->select('serial_hash, model_name, brand, category, listing_price, retail_price, warranty_months, status')
            ->whereIn('serial_hash', $hashes)->findAll();
    }

    // -----------------------------------------------------------------
    // Staff inventory
    // -----------------------------------------------------------------

    /**
     * Inventory search. $f: q, status, category, sort, page, per_page.
     * Returns [items, total].
     */
    public function search(array $f): array
    {
        $apply = function ($b) use ($f) {
            if ($f['q'] !== '') {
                $b->groupStart()
                    ->like('c.serial_hash', $f['q'])
                    ->orLike('c.model_name', $f['q'])
                    ->orLike('c.serial_number', $f['q'])
                    ->orLike('c.brand', $f['q'])
                    ->groupEnd();
            }
            if ($f['status'] !== '') {
                $b->where('c.status', $f['status']);
            }
            if ($f['category'] !== '') {
                $b->where('c.category', $f['category']);
            }

            return $b;
        };

        $total = $apply($this->db->table('components c'))->countAllResults();

        $order = [
            'newest'     => 'c.intake_date DESC, c.id DESC',
            'oldest'     => 'c.intake_date ASC, c.id ASC',
            'price_desc' => 'c.listing_price DESC, c.id DESC',
            'price_asc'  => 'c.listing_price ASC, c.id DESC',
            'temp_desc'  => 'd.peak_temp_celsius IS NULL, d.peak_temp_celsius DESC, c.id DESC',
        ][$f['sort']] ?? 'c.intake_date DESC, c.id DESC';

        $items = $apply($this->withLatestLog())
            ->select('c.serial_hash, c.serial_number, c.model_name, c.brand, c.category, c.status,
                      c.buyout_cost, c.listing_price, c.sold_price, c.sold_at, c.intake_date,
                      d.peak_temp_celsius, d.stress_status, d.tested_by, d.test_date')
            ->orderBy($order, '', false)
            ->get($f['per_page'], ($f['page'] - 1) * $f['per_page'])
            ->getResultArray();

        foreach ($items as &$r) {
            self::castNumbers($r, ['buyout_cost', 'listing_price', 'sold_price', 'peak_temp_celsius']);
        }

        return [$items, $total];
    }

    /** Full staff view of one unit (all columns + who logged it). */
    public function staffRow(string $hash): ?array
    {
        return $this->db->table('components c')
            ->select('c.*, u.full_name AS created_by_name')
            ->join('users u', 'u.id = c.created_by', 'left')
            ->where('c.serial_hash', $hash)
            ->get(1)->getRowArray();
    }

    /** DECIMAL strings → JSON numbers (null stays null). */
    public static function castNumbers(array &$row, array $keys): void
    {
        foreach ($keys as $k) {
            if (array_key_exists($k, $row)) {
                $row[$k] = $row[$k] === null ? null : (float) $row[$k];
            }
        }
    }
}
