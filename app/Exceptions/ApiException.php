<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class ApiException extends HttpException
{
    public static function badRequest(string $message = 'Bad request', ?\Throwable $previous = null): static
    {
        return new static(400, $message, $previous);
    }

    public static function unauthorized(string $message = 'Unauthenticated'): static
    {
        return new static(401, $message);
    }

    public static function forbidden(string $message = 'This action is unauthorized'): static
    {
        return new static(403, $message);
    }

    public static function notFound(string $message = 'Record not found'): static
    {
        return new static(404, $message);
    }

    public static function conflict(string $message = 'Conflicting record', array $headers = []): static
    {
        return new static(409, $message, null, $headers);
    }

    public static function unprocessable(string $message = 'Validation failed', ?\Throwable $previous = null): static
    {
        return new static(422, $message, $previous);
    }
}
