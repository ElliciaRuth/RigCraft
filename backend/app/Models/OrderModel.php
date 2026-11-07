<?php

namespace App\Models;

use CodeIgniter\Model;

/** One row per online checkout. Payment is collected on pickup/delivery. */
class OrderModel extends Model
{
    protected $table         = 'orders';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = [
        'order_no', 'customer_id', 'status', 'fulfillment', 'payment_method',
        'contact_name', 'contact_phone', 'address', 'notes', 'total',
    ];

    /** A shopper's orders, newest first, with their items. */
    public function forCustomer(int $customerId): array
    {
        $orders = $this->select('id, order_no, status, fulfillment, payment_method, contact_name, contact_phone,
                                 address, notes, total, created_at')
            ->where('customer_id', $customerId)
            ->orderBy('created_at', 'DESC')->orderBy('id', 'DESC')
            ->findAll();

        $orders = $this->attachItems($orders);
        foreach ($orders as &$o) {
            unset($o['id']);
        }

        return $orders;
    }

    /** Staff order queue: optional status filter and free-text search. Open orders first. */
    public function queue(string $status, string $q): array
    {
        $b = $this->db->table('orders o')
            ->select('o.id, o.order_no, o.status, o.fulfillment, o.payment_method, o.contact_name,
                      o.contact_phone, o.address, o.notes, o.total, o.created_at, o.updated_at,
                      cu.email AS customer_email, cu.full_name AS customer_name')
            ->join('customers cu', 'cu.id = o.customer_id');

        if ($status !== '') {
            $b->where('o.status', $status);
        }
        if ($q !== '') {
            $like = $this->db->escapeLikeString($q);
            $esc  = $this->db->escape('%' . $like . '%');
            $b->groupStart()
                ->like('o.order_no', $q)
                ->orLike('o.contact_name', $q)
                ->orLike('cu.email', $q)
                ->orLike('o.contact_phone', $q)
                ->orWhere("EXISTS (SELECT 1 FROM order_items i WHERE i.order_id = o.id
                                   AND (i.serial_hash LIKE {$esc} ESCAPE '!' OR i.model_name LIKE {$esc} ESCAPE '!'))", null, false)
                ->groupEnd();
        }

        $orders = $b->orderBy("(o.status = 'Placed') DESC, o.created_at DESC, o.id DESC", '', false)
            ->get(200)->getResultArray();

        return $this->attachItems($orders);
    }

    /** Orders per status, with every status present. */
    public function countsByStatus(array $statuses): array
    {
        $counts = array_fill_keys($statuses, 0);
        foreach ($this->select('status, COUNT(*) AS n')->groupBy('status')->findAll() as $r) {
            $counts[$r['status']] = (int) $r['n'];
        }

        return $counts;
    }

    /** Order number of the open (not cancelled) online order a unit belongs to, if any. */
    public function activeOrderForComponent(int $componentId): ?string
    {
        $row = $this->db->table('order_items i')
            ->select('o.order_no')
            ->join('orders o', 'o.id = i.order_id')
            ->where('i.component_id', $componentId)
            ->where('o.status !=', 'Cancelled')
            ->get(1)->getRowArray();

        return $row['order_no'] ?? null;
    }

    /** Merge each order's items into its row (and cast the numbers). */
    private function attachItems(array $orders): array
    {
        if (! $orders) {
            return [];
        }

        $items = model(OrderItemModel::class)
            ->select('order_id, serial_hash, model_name, brand, category, price')
            ->whereIn('order_id', array_column($orders, 'id'))
            ->orderBy('id')
            ->findAll();

        $byOrder = [];
        foreach ($items as $it) {
            $oid = (int) $it['order_id'];
            unset($it['order_id']);
            $it['price']     = (float) $it['price'];
            $byOrder[$oid][] = $it;
        }

        foreach ($orders as &$o) {
            $o['id']    = (int) $o['id'];
            $o['total'] = (float) $o['total'];
            $o['items'] = $byOrder[$o['id']] ?? [];
        }

        return $orders;
    }
}
