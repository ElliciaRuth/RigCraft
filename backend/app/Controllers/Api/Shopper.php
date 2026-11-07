<?php

namespace App\Controllers\Api;

use App\Exceptions\ApiError;
use App\Libraries\OrderService;
use App\Models\OrderModel;

/**
 * Signed-in shopper endpoints (route filter apiauth:customer):
 * checkout, order history and cancelling an order before handover.
 */
class Shopper extends BaseApiController
{
    /** POST /api/checkout — place an order for the units in the cart; they become Sold. */
    public function checkout()
    {
        if ($this->auth->user()) {
            throw new ApiError(403, "Staff accounts can't place orders. Sign out of the staff console first.");
        }

        $orders = new OrderService();
        $b      = $this->trimStrings($this->body());
        $hashes = $orders->readHashList($b['items'] ?? []);

        $b['fulfillment']    = ($b['fulfillment'] ?? '') === '' ? 'Pickup' : $b['fulfillment'];
        $b['payment_method'] = ($b['payment_method'] ?? '') === '' ? 'Cash' : $b['payment_method'];
        $delivery            = $b['fulfillment'] === 'Delivery';
        $maxTotal            = $this->rc->maxPrice * $this->rc->maxCartItems;

        $this->check($b, [
            'contact_name'   => ['rules' => 'required|max_length[80]', 'errors' => ['required' => 'Enter the name for this order.', 'max_length' => 'Max 80 characters.']],
            'contact_phone'  => ['rules' => 'required|max_length[20]|ph_phone', 'errors' => ['required' => 'Enter a mobile number so we can confirm the order.', 'max_length' => 'Max 20 characters.', 'ph_phone' => 'Enter a valid mobile number, e.g. 0917 123 4567.']],
            'fulfillment'    => ['rules' => 'in_list[' . implode(',', $this->rc->fulfillmentOptions) . ']', 'errors' => ['in_list' => 'Choose pickup or delivery.']],
            'address'        => ['rules' => ($delivery ? 'required' : 'permit_empty') . '|max_length[300]', 'errors' => ['required' => 'Enter the delivery address.', 'max_length' => 'Max 300 characters.']],
            'payment_method' => ['rules' => 'in_list[' . implode(',', $this->rc->paymentMethods) . ']', 'errors' => ['in_list' => 'Choose cash or GCash.']],
            'notes'          => ['rules' => 'permit_empty|max_length[500]', 'errors' => ['max_length' => 'Max 500 characters.']],
            'expected_total' => ['rules' => "permit_empty|numeric|greater_than_equal_to[0]|less_than_equal_to[{$maxTotal}]", 'errors' => ['numeric' => 'Invalid total.', 'greater_than_equal_to' => 'Invalid total.', 'less_than_equal_to' => 'Invalid total.']],
        ]);
        if (! $hashes) {
            throw new ApiError(422, 'Your cart is empty.');
        }

        [$orderNo, $total] = $orders->checkout($this->auth->customer(), $hashes, [
            'contact_name'   => $b['contact_name'],
            'contact_phone'  => $b['contact_phone'],
            'fulfillment'    => $b['fulfillment'],
            'address'        => $delivery ? $b['address'] : null,
            'payment_method' => $b['payment_method'],
            'notes'          => ($b['notes'] ?? '') === '' ? null : $b['notes'],
            'expected_total' => isset($b['expected_total']) && $b['expected_total'] !== '' ? (float) $b['expected_total'] : null,
        ]);

        return $this->ok(['order_no' => $orderNo, 'total' => $total, 'items' => count($hashes)], 201);
    }

    /** GET /api/my/orders — the signed-in shopper's orders, newest first. */
    public function orders()
    {
        return $this->ok(['items' => model(OrderModel::class)->forCustomer($this->auth->customer()['id'])]);
    }

    /** POST /api/my/orders/{order_no}/cancel — only while the order is still Placed. */
    public function cancel(string $orderNo = '')
    {
        $restocked = (new OrderService())->changeStatus(strtoupper(trim(urldecode($orderNo))), 'Cancelled', $this->auth->customer()['id']);

        return $this->ok(['status' => 'Cancelled', 'restocked' => $restocked]);
    }
}
