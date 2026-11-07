<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown anywhere in a request to stop it with a JSON error:
 *   { ok: false, error: <message>, ...extra }
 * BaseApiController::_remap() turns it into the response.
 */
final class ApiError extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly array $extra = [],
    ) {
        parent::__construct($message, $status);
    }

    /** 422 with per-field messages: { fields: { name: message } }. */
    public static function validation(array $fields, string $message = 'Please fix the highlighted fields.'): self
    {
        return new self(422, $message, ['fields' => $fields]);
    }

    public static function notFound(string $message, array $extra = []): self
    {
        return new self(404, $message, $extra);
    }

    public static function conflict(string $message, array $extra = []): self
    {
        return new self(409, $message, $extra);
    }
}
