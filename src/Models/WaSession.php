<?php

namespace Kstmostofa\LaravelWhatsApp\Models;

use Illuminate\Database\Eloquent\Model;
use Kstmostofa\LaravelWhatsApp\Models\Concerns\UsesWhatsAppConnection;

/**
 * @property string $id
 * @property string $backend  'web' | 'cloud'
 * @property string $status
 * @property ?string $phone_number
 * @property ?string $push_name
 * @property ?\Illuminate\Support\Carbon $last_qr_at
 * @property ?\Illuminate\Support\Carbon $ready_at
 * @property ?\Illuminate\Support\Carbon $disconnected_at
 * @property ?array $meta
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

    public function messages()
    {
        return $this->hasMany(WaMessage::class, 'session_id');
    }

    public function contacts()
    {
        return $this->hasMany(WaContact::class, 'session_id');
    }

    public function isReady(): bool
    {
        return $this->status === 'ready';
    }
}
