<?php

namespace Kstmostofa\LaravelWhatsApp\Exceptions;

use RuntimeException;
use Throwable;

class CloudApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $errorPayload  The decoded `error` object from Meta's response, if any.
     */
    public function __construct(
        string $message,
        int $code = 0,
        public readonly ?array $errorPayload = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function metaErrorCode(): ?int
    {
        return isset($this->errorPayload['code']) ? (int) $this->errorPayload['code'] : null;
    }

    public function metaErrorSubcode(): ?int
    {
        return isset($this->errorPayload['error_subcode']) ? (int) $this->errorPayload['error_subcode'] : null;
    }

    public function metaErrorType(): ?string
    {
        return $this->errorPayload['type'] ?? null;
    }
}
