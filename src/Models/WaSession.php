<?php

namespace Kstmostofa\LaravelWhatsApp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Kstmostofa\LaravelWhatsApp\Models\Concerns\UsesWhatsAppConnection;

/**
 * @property string $id
 * @property string $backend 'web' | 'cloud'
 * @property string $status
 * @property ?string $phone_number
 * @property ?string $push_name
 * @property ?Carbon $last_qr_at
 * @property ?Carbon $ready_at
 * @property ?Carbon $disconnected_at
 * @property ?array<string, mixed> $meta
 */
class WaSession extends Model
{
    use UsesWhatsAppConnection;

    protected $table = 'wa_sessions';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = [
        'last_qr_at' => 'datetime',
        'ready_at' => 'datetime',
        'disconnected_at' => 'datetime',
        'meta' => 'array',
    ];

    /**
     * @return HasMany<WaMessage, $this>
     */
    public function messages()
    {
        return $this->hasMany(WaMessage::class, 'session_id');
    }

    /**
     * @return HasMany<WaContact, $this>
     */
    public function contacts()
    {
        return $this->hasMany(WaContact::class, 'session_id');
    }

    public function isReady(): bool
    {
        return $this->status === 'ready';
    }
}
