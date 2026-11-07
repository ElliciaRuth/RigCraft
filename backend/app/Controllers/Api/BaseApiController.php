<?php

namespace App\Controllers\Api;

use App\Exceptions\ApiError;
use App\Libraries\RcAuth;
use CodeIgniter\Controller;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\RigCraft;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Base for every JSON endpoint.
 *
 * Responses always have the same shape the Vue app expects:
 *   success: { ok: true, ...payload }
 *   failure: { ok: false, error: "message", fields?: {...}, ...extra }
 *
 * Any ApiError thrown during the action becomes its JSON error; anything else
 * is logged and reported as a generic 500 so SQL details never leak.
 */
abstract class BaseApiController extends Controller
{
    protected RcAuth $auth;
    protected RigCraft $rc;

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger): void
    {
        parent::initController($request, $response, $logger);

        $this->auth = service('rcauth');
        $this->rc   = config('RigCraft');

        $this->response
            ->setHeader('Cache-Control', 'no-store')
            ->setHeader('X-Content-Type-Options', 'nosniff');
    }

    /** Wraps every action so errors come back as JSON. */
    public function _remap(string $method, ...$params)
    {
        if (! method_exists($this, $method) || str_starts_with($method, '_')) {
            throw PageNotFoundException::forMethodNotFound($method);
        }

        try {
            return $this->{$method}(...$params);
        } catch (ApiError $e) {
            return $this->fail($e->status, $e->getMessage(), $e->extra);
        } catch (Throwable $e) {
            log_message('error', '[RigCraft API] {class}: {message} in {file}:{line}', [
                'class' => $e::class, 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine(),
            ]);
            $isDb = $e instanceof \CodeIgniter\Database\Exceptions\DatabaseException || $e instanceof \mysqli_sql_exception;

            return $this->fail(500, $isDb
                ? 'Database error. Check that MySQL is running and rigcraft_db is imported.'
                : 'Unexpected server error.');
        }
    }

    // -----------------------------------------------------------------
    // Responses
    // -----------------------------------------------------------------

    protected function ok(array $payload = [], int $status = 200): ResponseInterface
    {
        return $this->response->setStatusCode($status)->setJSON(['ok' => true] + $payload);
    }

    protected function fail(int $status, string $message, array $extra = []): ResponseInterface
    {
        return $this->response->setStatusCode($status)->setJSON(['ok' => false, 'error' => $message] + $extra);
    }

    // -----------------------------------------------------------------
    // Input
    // -----------------------------------------------------------------

    /** The JSON request body as an array, or a 400. */
    protected function body(): array
    {
        try {
            $data = $this->request->getJSON(true);
        } catch (Throwable) {
            $data = null;
        }
        if (! is_array($data)) {
            throw new ApiError(400, 'Request body must be a JSON object.');
        }

        return $data;
    }

    /**
     * Run CodeIgniter validation over $data; on failure throw a 422 with
     * { fields: { name: first message } }.
     */
    protected function check(array $data, array $rules, array $messages = [], array $extraErrors = []): void
    {
        $validation = service('validation');
        $validation->reset()->setRules($rules, $messages);
        $validation->run($data);

        $errors = $validation->getErrors() + $extraErrors;
        if ($errors) {
            throw ApiError::validation($errors);
        }
    }

    /** Trim every string value (validation then sees what will be stored). */
    protected function trimStrings(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_string($v)) {
                $data[$k] = trim($v);
            }
        }

        return $data;
    }
}
