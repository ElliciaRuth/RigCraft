<?php

namespace App\Models;

use CodeIgniter\Model;

/** The units in an order. Name and price are copied at checkout so receipts survive later edits. */
class OrderItemModel extends Model
{
    protected $table         = 'order_items';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = ['order_id', 'component_id', 'serial_hash', 'model_name', 'brand', 'category', 'price'];
}
