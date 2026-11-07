<?php

namespace App\Models;

use CodeIgniter\Model;

/** Shopper accounts for the public store (separate from staff users). */
class CustomerModel extends Model
{
    protected $table         = 'customers';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = ['email', 'full_name', 'phone', 'password_hash', 'is_active', 'last_login_at'];
}
