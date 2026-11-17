<?php

declare(strict_types=1);

namespace Kstmostofa\LaravelWhatsApp\Events\Concerns;

/**
 * Typed reads from decoded webhook / sidecar payloads, which are only as
 * typed as the JSON that arrived.
 */
trait ReadsPayload
{
    /**
     * Scalars become strings (what PHP's coercive mode did before strict
     * types); arrays, objects and null become null.
     */
    protected static function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }
}
