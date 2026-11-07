<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Every state-changing request (POST/PUT/PATCH/DELETE) must echo the
 * session's CSRF token in the X-CSRF-Token header. The Vue app gets the
 * token from GET /api/session (and a fresh one after each sign-in/out).
 *
 * Used instead of CodeIgniter's form-oriented CSRF filter so failures come
 * back as the same JSON shape as every other API error.
 */
class ApiCsrf implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return null;
        }

        if (service('rcauth')->csrfValid($request->getHeaderLine('X-CSRF-Token'))) {
            return null;
        }

        return service('response')
            ->setStatusCode(403)
            ->setHeader('Cache-Control', 'no-store')
            ->setJSON(['ok' => false, 'error' => 'Security token expired. Reload the page and try again.', 'csrf' => true]);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
