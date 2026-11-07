<?php

namespace App\Controllers\Api;

/**
 * GET /api/session — who is signed in, the CSRF token and the public rules.
 * The Vue app calls this once on start and again after every sign-in/out.
 */
class Session extends BaseApiController
{
    public function index()
    {
        return $this->ok($this->snapshot());
    }

    /** Shared with the sign-in endpoints, which return a fresh snapshot. */
    public static function snapshotFor(): array
    {
        $auth = service('rcauth');
        $rc   = config('RigCraft');

        return [
            'user'     => $auth->user(),
            'customer' => $auth->customer(),
            'can_shop' => $auth->shopper() !== null,   // cart + checkout: signed-in shopper, no staff session
            'csrf'     => $auth->csrfToken(),
            'rules'    => [
                'currency'             => $rc->currency,
                'temp_ceiling_celsius' => $rc->tempCeiling,
                'warranty_months'      => $rc->defaultWarranty,
                'max_warranty'         => $rc->maxWarranty,
                'max_price'            => $rc->maxPrice,
                'max_cart_items'       => $rc->maxCartItems,
                'min_password_length'  => $rc->minPasswordLength,
                'categories'           => $rc->categories,
                'statuses'             => $rc->statuses,
            ],
        ];
    }

    private function snapshot(): array
    {
        return self::snapshotFor();
    }
}
