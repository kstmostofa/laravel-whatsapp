<?php

namespace Kstmostofa\LaravelWhatsApp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Kstmostofa\LaravelWhatsApp\Models\Concerns\UsesWhatsAppConnection;

/**
 * @property int $id
 * @property string $session_id
 * @property string $wa_id
 * @property ?string $name
 * @property ?string $pushname
 * @property ?string $number
 * @property bool $is_business
 * @property bool $is_my_contact
 * @property bool $is_blocked
 * @property ?Carbon $last_seen_at
 * @property ?array<string, mixed> $meta
 */
class WaContact extends Model
{
    use UsesWhatsAppConnection;

    protected $table = 'wa_contacts';

    protected $guarded = [];

    protected $casts = [
        'is_business' => 'boolean',
        'is_my_contact' => 'boolean',
        'is_blocked' => 'boolean',
        'last_seen_at' => 'datetime',
        'meta' => 'array',
    ];

    /**
     * @return BelongsTo<WaSession, $this>
     */
    public function session()
    {
        return $this->belongsTo(WaSession::class, 'session_id');
    }
}
