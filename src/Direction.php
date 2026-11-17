<?php

declare(strict_types=1);

namespace Kstmostofa\LaravelWhatsApp;

/**
 * Direction of a stored message. `WaMessage::$direction` stays a plain
 * string; compare with `Direction::Inbound->value`.
 */
enum Direction: string
{
    case Inbound = 'inbound';
    case Outbound = 'outbound';
}
