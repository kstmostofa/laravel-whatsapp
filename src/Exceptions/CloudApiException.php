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

    /**
     * Meta error codes that will never succeed on retry.
     * See https://developers.facebook.com/docs/whatsapp/cloud-api/support/error-codes/
     */
    public const PERMANENT_ERROR_CODES = [
        100,    // Invalid parameter
        131026, // Message undeliverable (e.g. not on WhatsApp)
        131047, // Re-engagement message — 24h window expired, template required
        131051, // Unsupported message type
        368,    // Temporarily blocked for policy violations (no point retrying soon)
    ];

    public function isPermanent(): bool
    {
        return in_array($this->metaErrorCode(), self::PERMANENT_ERROR_CODES, true);
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
