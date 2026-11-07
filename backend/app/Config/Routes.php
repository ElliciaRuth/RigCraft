<?php

use CodeIgniter\Router\RouteCollection;

/**
 * RigCraft JSON API. Apache sends /RigCraft/api/* here (see the project's
 * .htaccess); everything else is the Vue app. POST/PUT/DELETE also pass the
 * 'apicsrf' filter (Config\Filters::$methods).
 *
 * @var RouteCollection $routes
 */
$routes->group('api', ['namespace' => 'App\Controllers\Api'], static function (RouteCollection $routes): void {
    // ---- Public ------------------------------------------------------
    $routes->get('session', 'Session::index');
    $routes->get('shop', 'Store::shop');
    $routes->get('verify/(:segment)', 'Store::verify/$1');
    $routes->get('cart', 'Store::cart');

    $routes->post('auth/staff/login', 'Auth::staffLogin');
    $routes->post('auth/staff/logout', 'Auth::staffLogout');
    $routes->post('auth/customer/login', 'Auth::customerLogin');
    $routes->post('auth/customer/register', 'Auth::customerRegister');
    $routes->post('auth/customer/logout', 'Auth::customerLogout');

    // ---- Shopper (signed-in customer) ---------------------------------
    $routes->group('', ['filter' => 'apiauth:customer'], static function (RouteCollection $routes): void {
        $routes->post('checkout', 'Shopper::checkout');
        $routes->get('my/orders', 'Shopper::orders');
        $routes->post('my/orders/(:segment)/cancel', 'Shopper::cancel/$1');
    });

    // ---- Staff --------------------------------------------------------
    $routes->group('', ['filter' => 'apiauth:staff'], static function (RouteCollection $routes): void {
        $routes->get('stats', 'Dashboard::stats');

        $routes->get('units', 'Units::index');
        $routes->post('units', 'Units::create');
        $routes->get('units/(:segment)', 'Units::show/$1');
        $routes->put('units/(:segment)', 'Units::update/$1');
        $routes->post('units/(:segment)/retest', 'Units::retest/$1');
        $routes->post('units/(:segment)/sell', 'Units::sell/$1');
        $routes->post('units/(:segment)/unsell', 'Units::unsell/$1');
        $routes->post('units/(:segment)/status', 'Units::setStatus/$1');

        $routes->get('orders', 'Orders::index');
        $routes->post('orders/(:segment)/status', 'Orders::setStatus/$1');

        $routes->post('account/password', 'Auth::changePassword');
    });

    // ---- Admin --------------------------------------------------------
    $routes->group('', ['filter' => 'apiauth:admin'], static function (RouteCollection $routes): void {
        $routes->delete('units/(:segment)', 'Units::delete/$1');
        $routes->get('users', 'Users::index');
        $routes->post('users', 'Users::create');
        $routes->put('users/(:num)', 'Users::update/$1');
    });
});

// Unknown endpoints answer in the API's JSON shape. (CodeIgniter echoes what
// this closure returns, so it returns the JSON text and sets the headers.)
$routes->set404Override(static function (): string {
    service('response')
        ->setStatusCode(404)
        ->setContentType('application/json')
        ->setHeader('Cache-Control', 'no-store');

    return json_encode(['ok' => false, 'error' => 'Unknown API endpoint.']);
});
