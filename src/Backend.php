<?php

declare(strict_types=1);

namespace Kstmostofa\LaravelWhatsApp;

use InvalidArgumentException;

/**
 * The two delivery backends. Methods that take a backend accept this enum or
 * its string value; values the package hands back (models, events, jobs)
 * stay plain strings.
 */
enum Backend: string
{
    case Cloud = 'cloud';
    case Web = 'web';

    /**
     * @throws InvalidArgumentException for an unknown string
     */
    public static function of(self|string $backend): self
    {
        return $backend instanceof self
            ? $backend
            : self::tryFrom($backend) ?? throw new InvalidArgumentException("Unknown backend: {$backend}");
    }
}
