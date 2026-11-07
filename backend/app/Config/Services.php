<?php

namespace Config;

use App\Libraries\RcAuth;
use CodeIgniter\Config\BaseService;

/**
 * Services Configuration file.
 *
 * Services are simply other classes/libraries that the system uses
 * to do its job. This is used by CodeIgniter to allow the core of the
 * framework to be swapped out easily without affecting the usage within
 * the rest of your application.
 */
class Services extends BaseService
{
    /** RigCraft sign-in state (staff + shoppers) and CSRF token: service('rcauth'). */
    public static function rcauth(bool $getShared = true): RcAuth
    {
        if ($getShared) {
            return static::getSharedInstance('rcauth');
        }

        return new RcAuth();
    }
}
