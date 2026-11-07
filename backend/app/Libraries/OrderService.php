<?php

namespace App\Libraries;

use App\Exceptions\ApiError;
use App\Models\OrderItemModel;
use App\Models\OrderModel;
use CodeIgniter\Database\BaseConnection;
use Config\RigCraft;
use Throwable;

/**
 * Checkout and order status changes. Each runs in one transaction with the
 * affected rows locked, so two shoppers can never buy the same unit and a
 * cancelled order always puts its units back on sale.
 */
class OrderService
{
    private BaseConnection $db;
    private RigCraft $rc;

    public function __construct()
    {
        $this->db = db_connect();
        $this->rc = config('RigCraft');
    }

    /** Normalise hashes from user input: upper-case, valid format, unique, capped. */
    public function readHashList(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $h) {
            $h = strtoupper(trim(is_scalar($h) ? (string) $h : ''));
            if (preg_match('/^RC-[A-Z0-9]{1,10}-\d{4}$/', $h)) {
                $out[$h] = true;
            }
        }

        return array_slice(array_keys($out), 0, $this->rc->maxCartItems);
    }

    /**
     * Place an order for $hashes and mark each unit Sold.
     * $details: contact_name, contact_phone, fulfillment, address, payment_method, notes, expected_total.
     * Returns [order_no, total].
     */
    public function checkout(array $customer, array $hashes, array $details): array
    {
        $db = $this->db;
        $db->transBegin();

        try {
            // Lock the rows so a second checkout for the same unit waits, then sees it Sold.
            $in   = implode(',', array_fill(0, count($hashes), '?'));
            $rows = [];
            foreach ($db->query("SELECT id, serial_hash, model_name, brand, category, listing_price, status
                                 FROM components WHERE serial_hash IN ({$in}) FOR UPDATE", $hashes)->getResultArray() as $r) {
                $rows[$r['serial_hash']] = $r;
            }

            $unavailable = [];
            foreach ($hashes as $h) {
                $r = $rows[$h] ?? null;
                if (! $r || $r['status'] !== 'Certified' || (float) $r['listing_price'] <= 0) {
                    $unavailable[] = $h;
                }
            }
            if ($unavailable) {
                throw ApiError::conflict(count($unavailable) === 1
                    ? 'One item in your cart was just sold or taken off sale. We removed it — please review your order.'
                    : count($unavailable) . ' items in your cart were just sold or taken off sale. We removed them — please review your order.',
                    ['unavailable' => $unavailable]);
            }

            $total = round(array_sum(array_map(static fn ($r) => (float) $r['listing_price'], $rows)), 2);
            if ($details['expected_total'] !== null && abs($details['expected_total'] - $total) > 0.004) {
                throw ApiError::conflict('A price in your cart changed since you added it. Please check the new total and place the order again.',
                    ['price_changed' => true, 'total' => $total]);
            }

            // Short public reference; retry on the rare collision.
            $orders  = model(OrderModel::class);
            $orderId = null;
            $orderNo = '';
            for ($try = 0; $try < 5 && $orderId === null; $try++) {
                $orderNo = 'RCO-' . date('ymd') . '-' . random_int(1000, 9999);
                if ($orders->where('order_no', $orderNo)->countAllResults() > 0) {
                    continue;
                }
                $orders->insert([
                    'order_no'       => $orderNo,
                    'customer_id'    => $customer['id'],
                    'fulfillment'    => $details['fulfillment'],
                    'payment_method' => $details['payment_method'],
                    'contact_name'   => $details['contact_name'],
                    'contact_phone'  => $details['contact_phone'],
                    'address'        => $details['address'],
                    'notes'          => $details['notes'],
                    'total'          => $total,
                ]);
                $orderId = (int) $orders->getInsertID();
            }
            if ($orderId === null) {
                throw new \RuntimeException('Could not generate a unique order number.');
            }

            $items = model(OrderItemModel::class);
            foreach ($hashes as $h) {
                $r = $rows[$h];
                $items->insert([
                    'order_id'     => $orderId,
                    'component_id' => $r['id'],
                    'serial_hash'  => $r['serial_hash'],
                    'model_name'   => $r['model_name'],
                    'brand'        => $r['brand'],
                    'category'     => $r['category'],
                    'price'        => $r['listing_price'],
                ]);
                $db->query("UPDATE components SET status = 'Sold', sold_price = ?, sold_at = CURDATE() WHERE id = ?",
                    [$r['listing_price'], $r['id']]);
            }

            $db->transCommit();
        } catch (Throwable $e) {
            $db->transRollback();

            throw $e;
        }

        return [$orderNo, $total];
    }

    /**
     * Move a Placed order to Completed or Cancelled. Cancelling puts its units
     * back on sale. With $customerId only that shopper's order matches (anyone
     * else's reads as "not found"). Returns the number of units restocked.
     */
    public function changeStatus(string $orderNo, string $status, ?int $customerId = null): int
    {
        $db = $this->db;
        $db->transBegin();

        try {
            $sql    = 'SELECT id, status FROM orders WHERE order_no = ?';
            $params = [$orderNo];
            if ($customerId !== null) {
                $sql .= ' AND customer_id = ?';
                $params[] = $customerId;
            }
            $order = $db->query($sql . ' FOR UPDATE', $params)->getRowArray();

            if (! $order) {
                throw ApiError::notFound('Order not found.');
            }
            if ($order['status'] !== 'Placed') {
                throw ApiError::conflict($customerId !== null && $order['status'] === 'Completed'
                    ? "This order was already handed over, so it can't be cancelled here. Contact the store about returns."
                    : 'This order is already ' . strtolower($order['status']) . '.');
            }

            $restocked = 0;
            if ($status === 'Cancelled') {
                $db->query(
                    "UPDATE components c JOIN order_items i ON i.component_id = c.id
                     SET c.status = 'Certified', c.sold_price = NULL, c.sold_at = NULL
                     WHERE i.order_id = ? AND c.status = 'Sold'",
                    [$order['id']]
                );
                $restocked = $db->affectedRows();
            }

            $db->query('UPDATE orders SET status = ? WHERE id = ?', [$status, $order['id']]);
            $db->transCommit();
        } catch (Throwable $e) {
            $db->transRollback();

            throw $e;
        }

        return $restocked;
    }
}
