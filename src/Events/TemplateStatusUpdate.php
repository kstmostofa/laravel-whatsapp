<?php

declare(strict_types=1);

namespace Kstmostofa\LaravelWhatsApp\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Kstmostofa\LaravelWhatsApp\Events\Concerns\ReadsPayload;

/**
 * Meta has approved, rejected, or paused one of your message templates.
 * `event` is the lifecycle action; `message_template_*` keys identify which template.
 */
class TemplateStatusUpdate
{
    use Dispatchable, ReadsPayload, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload  the `value` object from the change
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $phoneNumberId,
        public array $payload,
        public array $metadata = [],
    ) {}

    public function event(): ?string
    {
        return self::stringOrNull($this->payload['event'] ?? null);
    }

    public function templateName(): ?string
    {
        return self::stringOrNull($this->payload['message_template_name'] ?? null);
    }

    public function reason(): ?string
    {
        return self::stringOrNull($this->payload['reason'] ?? null);
    }
}
