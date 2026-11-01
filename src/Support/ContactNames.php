<?php

namespace Kstmostofa\LaravelWhatsApp\Support;

use Kstmostofa\LaravelWhatsApp\Models\WaContact;

/**
 * Turns WhatsApp IDs and phone numbers into something a person can read,
 * using what the package knows from wa_contacts: the name saved on the
 * paired phone (contact sync), else the sender's own display name (learnt
 * from incoming messages), else a formatted number.
 */
class ContactNames
{
    /**
     * Names for a batch of IDs in one query. IDs without a known name are
     * left out — use label() to fall back to a formatted number.
     *
     * @param  iterable<mixed>  $ids
     * @return array<string, string> id => name
     */
    public function lookup(iterable $ids): array
    {
        $ids = array_values(array_unique(array_filter(
            is_array($ids) ? $ids : iterator_to_array($ids),
            fn ($id) => is_string($id) && $id !== '',
        )));

        if ($ids === []) {
            return [];
        }

        try {
            $rows = WaContact::query()
                ->whereIn('wa_id', $ids)
                ->orderByDesc('updated_at')
                ->get(['wa_id', 'name', 'pushname']);
        } catch (\Throwable) {
            return []; // table not migrated
        }

        $names = [];

        foreach ($rows as $row) {
            $name = trim((string) ($row->name ?: $row->pushname));

            if ($name !== '' && ! isset($names[$row->wa_id])) {
                $names[$row->wa_id] = $name;
            }
        }

        return $names;
    }

    /**
     * The best label for one ID, given names from lookup().
     *
     * @param  array<string, string>  $names
     */
    public function label(?string $id, array $names = []): string
    {
        if ($id === null || $id === '') {
            return '—';
        }

        return $names[$id] ?? self::format($id);
    }

    /**
     * `966512345678@c.us` and `966512345678` → `+966512345678`. Groups,
     * linked-device IDs and anything else come back unchanged.
     */
    public static function format(string $id): string
    {
        $user = str_ends_with($id, '@c.us') ? explode('@', $id)[0] : $id;

        return ctype_digit($user) ? '+'.$user : $id;
    }
}
