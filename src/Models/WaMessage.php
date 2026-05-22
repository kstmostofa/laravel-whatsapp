<?php

namespace Kstmostofa\LaravelWhatsApp\Models;

use Illuminate\Database\Eloquent\Model;
use Kstmostofa\LaravelWhatsApp\Models\Concerns\UsesWhatsAppConnection;

/**
 * @property int $id
 * @property string $backend  'web' | 'cloud'
 * @property ?string $session_id
 * @property ?string $wa_message_id
 * @property string $direction  'inbound' | 'outbound'
 * @property ?string $chat_id
 * @property ?string $from_id
 * @property ?string $to_id
 * @property string $type
 * @property ?string $body
 * @property ?array $payload
 * @property ?string $status
 * @property ?int $ack    whatsapp-web.js ack level: -1 error, 0 pending, 1 server, 2 device, 3 read, 4 played
 * @property ?\Illuminate\Support\Carbon $wa_timestamp
 */
class WaMessage extends Model
{
    use UsesWhatsAppConnection;

    protected $table = 'wa_messages';

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'ack' => 'integer',
        'wa_timestamp' => 'datetime',
        'deleted_at' => 'datetime',
        'deleted_for_everyone' => 'boolean',
    ];

    public function session()
    {
        return $this->belongsTo(WaSession::class, 'session_id');
    }

    public function scopeInbound($query)
    {
        return $query->where('direction', 'inbound');
    }

    public function scopeOutbound($query)
    {
        return $query->where('direction', 'outbound');
    }

    public function scopeForChat($query, string $chatId)
    {
        return $query->where('chat_id', $chatId);
    }
}
