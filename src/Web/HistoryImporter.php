<?php

namespace Kstmostofa\LaravelWhatsApp\Web;

use Kstmostofa\LaravelWhatsApp\Events\Web\MessageReceived;
use Kstmostofa\LaravelWhatsApp\Listeners\PersistIncomingMessage;

/**
 * Copies recent chat history from the sidecar into `wa_messages`.
 *
 * The listener only sees messages that arrive while it runs, so a fresh
 * install shows empty conversations. This fills them in. Rows are upserted on
 * the WhatsApp message ID, so importing twice — or importing a message the
 * listener already stored — never duplicates anything.
 *
 * Writes directly through the persistence listener rather than dispatching
 * events, so app listeners and broadcasts don't fire for old messages.
 */
class HistoryImporter
{
    public function __construct(
        protected WebClient $client,
        protected PersistIncomingMessage $persister,
    ) {
    }

    /**
     * @return int number of messages written
     */
    public function importChat(string $sessionId, string $chatId, int $limit = 50): int
    {
        $count = 0;

        foreach ($this->client->session($sessionId)->history($chatId, $limit) as $message) {
            $this->persister->handle(new MessageReceived($sessionId, ['message' => $message]));
            $count++;
        }

        return $count;
    }

    /**
     * The most recently active chats, newest first.
     *
     * @return array<int, string>
     */
    public function recentChatIds(string $sessionId, int $chats): array
    {
        $list = $this->client->session($sessionId)->chats();

        usort($list, fn ($a, $b) => ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0));

        $ids = array_values(array_filter(array_map(
            fn ($c) => is_array($c) && is_string($c['id'] ?? null) ? $c['id'] : null,
            $list,
        )));

        return $chats > 0 ? array_slice($ids, 0, $chats) : $ids;
    }
}
