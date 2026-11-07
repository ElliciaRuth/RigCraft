<?php

namespace App\Controllers\Api;

use App\Exceptions\ApiError;
use App\Libraries\OrderService;
use App\Models\OrderModel;

/** Staff order queue (route filter apiauth:staff). */
class Orders extends BaseApiController
{
    /** GET /api/orders[?status=Placed][&q=] */
    public function index()
    {
        $status = trim((string) ($this->request->getGet('status') ?? ''));
        $q      = trim((string) ($this->request->getGet('q') ?? ''));
        if ($status !== '' && ! in_array($status, $this->rc->orderStatuses, true)) {
            throw new ApiError(400, 'Unknown order status.');
        }

        $orders = model(OrderModel::class);

        return $this->ok([
            'items'  => $orders->queue($status, $q),
            'counts' => $orders->countsByStatus($this->rc->orderStatuses),
        ]);
    }

    /** POST /api/orders/{order_no}/status  { status: Completed | Cancelled } — cancelling restocks. */
    public function setStatus(string $orderNo = '')
    {
        $status = (string) ($this->body()['status'] ?? '');
        if (! in_array($status, ['Completed', 'Cancelled'], true)) {
            throw new ApiError(400, 'Status must be Completed or Cancelled.');
        }

        $restocked = (new OrderService())->changeStatus(strtoupper(trim(urldecode($orderNo))), $status);

        return $this->ok(['status' => $status, 'restocked' => $restocked]);
    }
}
