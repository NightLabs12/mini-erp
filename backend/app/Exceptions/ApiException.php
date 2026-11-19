<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Exception untuk kesalahan aturan bisnis yang sudah diketahui.
 *
 * Selalu membawa kode mesin (mis. STOCK_INSUFFICIENT) agar klien dapat
 * menampilkan pesan yang tepat tanpa bergantung pada teks.
 */
class ApiException extends RuntimeException
{
    /** @param array<string, list<string>> $errors */
    public function __construct(
        string $message,
        private readonly string $errorCode = 'BUSINESS_ERROR',
        private readonly int $status = 409,
        private readonly array $errors = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** @param array<string, list<string>> $errors */
    public static function conflict(string $message, string $code = 'CONFLICT', array $errors = []): self
    {
        return new self($message, $code, 409, $errors);
    }

    /** @param array<string, list<string>> $errors */
    public static function unprocessable(string $message, string $code = 'UNPROCESSABLE', array $errors = []): self
    {
        return new self($message, $code, 422, $errors);
    }

    public static function notFound(string $message = 'Data tidak ditemukan.', string $code = 'NOT_FOUND'): self
    {
        return new self($message, $code, 404);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }
}
