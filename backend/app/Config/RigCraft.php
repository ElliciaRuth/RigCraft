<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * RigCraft business rules. Read with config('RigCraft').
 * The Vue app receives the public ones from GET /api/session.
 */
class RigCraft extends BaseConfig
{
    /** Strict pass ceiling for peak core temperature (°C). Also a CHECK constraint in the DB. */
    public float $tempCeiling = 72.0;

    /** Months of warranty granted to certified units, and the most a unit can be given. */
    public int $defaultWarranty = 6;
    public int $maxWarranty     = 36;

    public array $categories = ['GPU', 'CPU', 'RAM', 'Motherboard', 'PSU', 'Storage', 'Other'];
    public array $statuses   = ['Intake', 'Testing', 'Certified', 'Sold', 'Rejected'];
    public array $roles      = ['admin', 'technician'];

    /** All prices are stored and returned in Philippine pesos. */
    public string $currency = 'PHP';
    public int $maxPrice    = 10_000_000;   // ₱10M sanity cap on any single price

    /** Online store. Payment is collected on pickup/delivery; nothing is charged online. */
    public int $maxCartItems         = 10;
    public array $fulfillmentOptions = ['Pickup', 'Delivery'];
    public array $paymentMethods     = ['Cash', 'GCash'];
    public array $orderStatuses      = ['Placed', 'Completed', 'Cancelled'];

    /** Sign-in throttling (per session) and password policy. */
    public int $loginMaxAttempts  = 5;
    public int $loginLockSeconds  = 60;
    public int $minPasswordLength = 8;
}
