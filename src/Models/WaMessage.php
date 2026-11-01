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
 * @property ?string $media_path  set once StoreMessageMedia copied the media to the configured disk
 * @property ?string $media_mime
 * @property ?int $media_size
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
        'media_size' => 'integer',
    ];

    public function session()
    {
        return $this->belongsTo(WaSession::class, 'session_id');
    }

    public function hasStoredMedia(): bool
    {
        return $this->media_path !== null && $this->media_path !== '';
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
