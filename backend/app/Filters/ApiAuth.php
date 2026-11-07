<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Route guard. Use as 'apiauth:staff', 'apiauth:admin' or 'apiauth:customer'.
 * Answers 401/403 as JSON. A customer 401 carries auth: 'customer' so the Vue
 * app sends people to the store sign-in instead of the staff login.
 */
class ApiAuth implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $need = $arguments[0] ?? 'staff';
        $auth = service('rcauth');

        if ($need === 'customer') {
            return $auth->customer() ? null
                : $this->deny(401, 'Please sign in to your RigCraft account to continue.', ['auth' => 'customer']);
        }

        $user = $auth->user();
        if (! $user) {
            return $this->deny(401, 'Your session has ended. Please sign in again.', ['auth' => 'staff']);
        }
        if ($need === 'admin' && $user['role'] !== 'admin') {
            return $this->deny(403, 'You do not have permission to do that.');
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }

    private function deny(int $status, string $message, array $extra = []): ResponseInterface
    {
        return service('response')
            ->setStatusCode($status)
            ->setHeader('Cache-Control', 'no-store')
            ->setJSON(['ok' => false, 'error' => $message] + $extra);
    }
}
