<?php

declare(strict_types=1);

namespace Kstmostofa\LaravelWhatsApp;

/**
 * Processing state of a logged webhook. `WaWebhookEvent::$status` stays a
 * plain string; compare with `WebhookStatus::Failed->value`.
 */
enum WebhookStatus: string
{
    case Pending = 'pending';
    case Processed = 'processed';
    case Failed = 'failed';
}
