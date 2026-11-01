<?php

namespace Kstmostofa\LaravelWhatsApp\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Kstmostofa\LaravelWhatsApp\Models\Concerns\UsesWhatsAppConnection;

/**
 * @property int $id
 * @property string $backend 'web' | 'cloud'
 * @property ?string $session_id
 * @property ?string $wa_message_id
 * @property string $direction 'inbound' | 'outbound'
 * @property ?string $chat_id
 * @property ?string $from_id
 * @property ?string $to_id
 * @property string $type
 * @property ?string $body
 * @property ?array<string, mixed> $payload
 * @property ?string $status
 * @property ?int $ack whatsapp-web.js ack level: -1 error, 0 pending, 1 server, 2 device, 3 read, 4 played
 * @property ?Carbon $wa_timestamp
 * @property ?Carbon $deleted_at shown as a "deleted" placeholder; not SoftDeletes
 * @property bool $deleted_for_everyone
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property ?string $media_path set once StoreMessageMedia copied the media to the configured disk
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

    /**
     * @return BelongsTo<WaSession, $this>
     */
    public function session()
    {
        return $this->belongsTo(WaSession::class, 'session_id');
    }

    public function hasStoredMedia(): bool
    {
        return $this->media_path !== null && $this->media_path !== '';
    }

    /**
     * @param  Builder<WaMessage>  $query
     * @return Builder<WaMessage>
     */
    public function scopeInbound($query)
    {
        return $query->where('direction', 'inbound');
    }

    /**
     * @param  Builder<WaMessage>  $query
     * @return Builder<WaMessage>
     */
    public function scopeOutbound($query)
    {
        return $query->where('direction', 'outbound');
    }

    /**
     * @param  Builder<WaMessage>  $query
     * @return Builder<WaMessage>
     */
    public function scopeForChat($query, string $chatId)
    {
        return $query->where('chat_id', $chatId);
    }
}
