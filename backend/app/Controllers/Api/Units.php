<?php

namespace App\Controllers\Api;

use App\Exceptions\ApiError;
use App\Libraries\UnitRules;
use App\Models\ComponentModel;
use App\Models\DiagnosticLogModel;
use App\Models\OrderModel;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Staff unit endpoints (route filter apiauth:staff; delete is admin only).
 *
 *   GET    /api/units                    inventory search
 *   POST   /api/units                    intake (optionally with the first stress test)
 *   GET    /api/units/{hash}             one unit + full test history
 *   PUT    /api/units/{hash}             edit identity, pricing, warranty, notes
 *   POST   /api/units/{hash}/retest      log a stress run → Certified / Rejected
 *   POST   /api/units/{hash}/sell        record a counter sale
 *   POST   /api/units/{hash}/unsell      undo a counter sale
 *   POST   /api/units/{hash}/status      Intake ↔ Testing
 *   DELETE /api/units/{hash}             remove the unit and its history (admin)
 */
class Units extends BaseApiController
{
    private UnitRules $rules;
    private ComponentModel $components;

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger): void
    {
        parent::initController($request, $response, $logger);
        $this->rules      = new UnitRules();
        $this->components = model(ComponentModel::class);
    }

    /** GET /api/units?q=&status=&category=&sort=&page=&per_page= */
    public function index()
    {
        $get = fn (string $k, string $d = '') => trim((string) ($this->request->getGet($k) ?? $d));
        $f   = [
            'q'        => $get('q'),
            'status'   => $get('status'),
            'category' => $get('category'),
            'sort'     => $get('sort', 'newest'),
            'page'     => max(1, (int) $get('page', '1')),
            'per_page' => min(50, max(5, (int) $get('per_page', '20'))),
        ];
        if ($f['status'] !== '' && ! in_array($f['status'], $this->rc->statuses, true)) {
            throw new ApiError(400, 'Unknown status.');
        }
        if ($f['category'] !== '' && ! in_array($f['category'], $this->rc->categories, true)) {
            throw new ApiError(400, 'Unknown category.');
        }

        [$items, $total] = $this->components->search($f);

        return $this->ok([
            'currency' => $this->rc->currency,
            'items'    => $items,
            'total'    => $total,
            'page'     => $f['page'],
            'per_page' => $f['per_page'],
            'pages'    => max(1, (int) ceil($total / $f['per_page'])),
            'counts'   => $this->components->countsByStatus($this->rc->statuses),   // unfiltered, for the chips
        ]);
    }

    /** POST /api/units — register a unit, optionally with its first stress test. */
    public function create()
    {
        $b      = $this->trimStrings($this->body());
        $tested = filter_var($b['test_completed'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $this->check($b, $this->rules->unitRules() + ($tested ? $this->rules->testRules() : []));

        $unit = UnitRules::unitFields($b);
        $test = $tested ? UnitRules::testFields($b) : null;

        if ($tested) {
            [$passed, $reason] = $this->rules->evaluateTest($test['peak_temp'], $test['memory_errors']);
            $status   = $passed ? 'Certified' : 'Rejected';
            $warranty = $passed ? $this->rc->defaultWarranty : 0;
        } else {
            [$passed, $reason] = [null, null];
            $status   = 'Testing';
            $warranty = $this->rc->defaultWarranty;   // applies once it certifies
        }

        // Same physical S/N already on file? Not blocked (units can be bought back), but flagged.
        $duplicates = $this->components->hashesWithSerial($unit['serial_number']);
        $user       = $this->auth->user();
        $token      = UnitRules::modelToken($unit['model_name']);
        $db         = db_connect();

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $hash = sprintf('RC-%s-%04d', $token, random_int(1000, 9999));
            if ($this->components->hashExists($hash)) {
                continue;
            }

            $db->transBegin();

            try {
                $this->components->insert($unit + [
                    'serial_hash'     => $hash,
                    'status'          => $status,
                    'warranty_months' => $warranty,
                    'created_by'      => $user['id'],
                ]);
                if ($tested) {
                    model(DiagnosticLogModel::class)->record((int) $this->components->getInsertID(), $test, $user, $passed ? 'PASSED' : 'FAILED');
                }
                $db->transCommit();
            } catch (Throwable $e) {
                $db->transRollback();
                if (self::isDuplicateKey($e)) {
                    continue;   // hash collision: retry
                }

                throw $e;
            }

            return $this->ok([
                'serial_hash'     => $hash,
                'status'          => $status,
                'stress_status'   => $tested ? ($passed ? 'PASSED' : 'FAILED') : null,
                'reason'          => $reason,
                'warranty_months' => $warranty,
                'duplicate_of'    => $duplicates,
            ], 201);
        }

        throw new ApiError(503, 'Could not allocate a unique serial hash. Please retry.');
    }

    /** GET /api/units/{hash} — full staff view with the whole test history. */
    public function show(string $rawHash = '')
    {
        $hash = UnitRules::readHash(urldecode($rawHash));
        $c    = $this->components->staffRow($hash);
        if (! $c) {
            throw ApiError::notFound('No RigCraft unit is registered under this hash.', ['hash' => $hash]);
        }

        $logs = model(DiagnosticLogModel::class)->historyFor((int) $c['id']);

        $component = $c;
        unset($component['id'], $component['created_by']);
        ComponentModel::castNumbers($component, ['buyout_cost', 'listing_price', 'retail_price', 'sold_price']);
        $component['warranty_months'] = (int) $component['warranty_months'];

        return $this->ok([
            'currency'  => $this->rc->currency,
            'component' => $component,
            'logs'      => $logs,
            'warranty'  => UnitRules::warranty($c['status'], (int) $c['warranty_months'],
                $logs[0]['test_date'] ?? null, $c['sold_at'], $c['intake_date']),
            'rules'     => ['temp_ceiling_celsius' => $this->rc->tempCeiling, 'max_warranty' => $this->rc->maxWarranty],
        ]);
    }

    /** PUT /api/units/{hash} — edit identity, pricing, warranty and notes. */
    public function update(string $rawHash = '')
    {
        $hash = UnitRules::readHash(urldecode($rawHash));
        $b    = $this->trimStrings($this->body());
        $this->check($b, $this->rules->unitRules(true));

        $c = $this->components->findByHashOrFail($hash);
        $this->components->update($c['id'], UnitRules::unitFields($b) + ['warranty_months' => (int) $b['warranty_months']]);

        return $this->ok();
    }

    /** POST /api/units/{hash}/retest — log a new stress run and re-evaluate the unit. */
    public function retest(string $rawHash = '')
    {
        $hash = UnitRules::readHash(urldecode($rawHash));
        $b    = $this->trimStrings($this->body());
        $this->check($b, $this->rules->testRules());

        $test              = UnitRules::testFields($b);
        [$passed, $reason] = $this->rules->evaluateTest($test['peak_temp'], $test['memory_errors']);
        $db                = db_connect();

        $db->transBegin();

        try {
            $c = $this->components->findByHashOrFail($hash, true);
            if ($c['status'] === 'Sold') {
                throw ApiError::conflict('Sold units cannot be re-tested. Undo the sale first.');
            }

            model(DiagnosticLogModel::class)->record((int) $c['id'], $test, $this->auth->user(), $passed ? 'PASSED' : 'FAILED');
            $this->components->update($c['id'], [
                'status'          => $passed ? 'Certified' : 'Rejected',
                'warranty_months' => $passed ? max((int) $c['warranty_months'], $this->rc->defaultWarranty) : 0,
            ]);
            $db->transCommit();
        } catch (Throwable $e) {
            $db->transRollback();

            throw $e;
        }

        return $this->ok([
            'status'        => $passed ? 'Certified' : 'Rejected',
            'stress_status' => $passed ? 'PASSED' : 'FAILED',
            'reason'        => $reason,
        ], 201);
    }

    /** POST /api/units/{hash}/sell  { sold_price, sold_at? } — counter sale of a certified unit. */
    public function sell(string $rawHash = '')
    {
        $hash = UnitRules::readHash(urldecode($rawHash));
        $b    = $this->trimStrings($this->body());
        $b['sold_at'] = ($b['sold_at'] ?? '') === '' ? date('Y-m-d') : $b['sold_at'];
        $max = $this->rc->maxPrice;

        $this->check($b, [
            'sold_price' => ['rules' => "required|numeric|greater_than_equal_to[0]|less_than_equal_to[{$max}]", 'errors' => array_fill_keys(['required', 'numeric', 'greater_than_equal_to', 'less_than_equal_to'], 'Enter the sale price.')],
            'sold_at'    => ['rules' => 'valid_date[Y-m-d]|not_future', 'errors' => ['valid_date' => 'Use a valid date.', 'not_future' => 'Date cannot be in the future.']],
        ]);

        $c = $this->components->findByHashOrFail($hash);
        if ($c['status'] !== 'Certified') {
            throw ApiError::conflict('Only certified units can be sold. This unit is ' . $c['status'] . '.');
        }
        if ($b['sold_at'] < $c['intake_date']) {
            throw ApiError::validation(['sold_at' => 'Sale date is before the intake date.']);
        }

        $this->components->update($c['id'], ['status' => 'Sold', 'sold_price' => round((float) $b['sold_price'], 2), 'sold_at' => $b['sold_at']]);

        return $this->ok(['status' => 'Sold']);
    }

    /** POST /api/units/{hash}/unsell — undo a counter sale (online orders are cancelled instead). */
    public function unsell(string $rawHash = '')
    {
        $hash = UnitRules::readHash(urldecode($rawHash));
        $c    = $this->components->findByHashOrFail($hash);
        if ($c['status'] !== 'Sold') {
            throw ApiError::conflict('This unit is not marked as sold.');
        }
        $orderNo = model(OrderModel::class)->activeOrderForComponent((int) $c['id']);
        if ($orderNo !== null) {
            throw ApiError::conflict("This unit was bought online in order {$orderNo}. Cancel that order under Online orders to put it back on sale.");
        }

        $this->components->update($c['id'], ['status' => 'Certified', 'sold_price' => null, 'sold_at' => null]);

        return $this->ok(['status' => 'Certified']);
    }

    /** POST /api/units/{hash}/status  { status: Intake | Testing } — the pre-test workflow. */
    public function setStatus(string $rawHash = '')
    {
        $hash   = UnitRules::readHash(urldecode($rawHash));
        $target = (string) ($this->body()['status'] ?? '');
        if (! in_array($target, ['Intake', 'Testing'], true)) {
            throw new ApiError(422, 'Only Intake and Testing can be set directly. Certify or reject a unit by logging a stress test.');
        }

        $c = $this->components->findByHashOrFail($hash);
        if (! in_array($c['status'], ['Intake', 'Testing'], true)) {
            throw ApiError::conflict('This unit has already been tested.');
        }
        $this->components->update($c['id'], ['status' => $target]);

        return $this->ok(['status' => $target]);
    }

    /** DELETE /api/units/{hash} — admin only; removes the unit and its test history. */
    public function delete(string $rawHash = '')
    {
        $hash = UnitRules::readHash(urldecode($rawHash));
        $c    = $this->components->findByHashOrFail($hash);
        $this->components->delete($c['id']);

        return $this->ok();
    }

    private static function isDuplicateKey(Throwable $e): bool
    {
        for ($x = $e; $x; $x = $x->getPrevious()) {
            if ($x->getCode() === 1062 || str_contains($x->getMessage(), 'Duplicate entry')) {
                return true;
            }
        }

        return false;
    }
}
