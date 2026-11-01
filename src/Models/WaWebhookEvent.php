<?php

namespace Kstmostofa\LaravelWhatsApp\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Kstmostofa\LaravelWhatsApp\Models\Concerns\UsesWhatsAppConnection;

/**
 * One raw webhook delivery from Meta, kept so it can be inspected and
 * replayed. Written only when `laravel-whatsapp.webhook.log` is on.
 *
 * Old rows are removed by Laravel's `model:prune` after
 * `webhook.log_retention_days`.
 *
 * @property int $id
 * @property string $backend
 * @property array<string, mixed> $payload
 * @property string $status pending | processed | failed
 * @property int $event_count
 * @property ?string $error
 * @property int $attempts
 * @property ?Carbon $processed_at
 */
class WaWebhookEvent extends Model
{
    use MassPrunable;
    use UsesWhatsAppConnection;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_FAILED = 'failed';

    protected $table = 'wa_webhook_events';

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'event_count' => 'integer',
        'attempts' => 'integer',
        'processed_at' => 'datetime',
    ];

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        $days = max(1, (int) config('laravel-whatsapp.webhook.log_retention_days', 14));

        return static::query()->where('created_at', '<', now()->subDays($days));
    }

    /**
     * @param  Builder<WaWebhookEvent>  $query
     * @return Builder<WaWebhookEvent>
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    /** Short description of what the delivery contained, for lists. */
    public function summary(): string
    {
        $kinds = [];

        foreach ($this->payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];

                if (! empty($value['messages'])) {
                    $kinds[] = count($value['messages']).' message(s)';
                }
                if (! empty($value['statuses'])) {
                    $kinds[] = count($value['statuses']).' status(es)';
                }
                if (($change['field'] ?? null) === 'message_template_status_update') {
                    $kinds[] = 'template status';
                }
            }
        }

        return $kinds ? implode(', ', $kinds) : 'no recognised events';
    }
}
