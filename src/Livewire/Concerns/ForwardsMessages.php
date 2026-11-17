<?php

declare(strict_types=1);

namespace Kstmostofa\LaravelWhatsApp\Livewire\Concerns;

use Kstmostofa\LaravelWhatsApp\Exceptions\SidecarException;
use Kstmostofa\LaravelWhatsApp\Web\WebClient;

/**
 * The chat screen's forward modal: pick a message, search the chat list,
 * send it on. Used by ChatHistory.
 */
trait ForwardsMessages
{
    public bool $showForward = false;

    public ?string $forwardingId = null;

    public string $forwardSearch = '';

    public function startForward(string $wamId): void
    {
        $this->forwardingId = $wamId;
        $this->forwardSearch = '';
        $this->showForward = true;
    }

    public function cancelForward(): void
    {
        $this->showForward = false;
        $this->forwardingId = null;
    }

    public function forwardTo(string $chatId): void
    {
        if (! $this->forwardingId) {
            return;
        }

        $this->error = null;

        try {
            app(WebClient::class)->session($this->session)->messages()->forward($this->forwardingId, $chatId);
            $this->notice = 'Message forwarded.';
        } catch (SidecarException $e) {
            $this->error = $e->getMessage();
        }

        $this->cancelForward();
    }

    /**
     * Chats offered in the forward modal, filtered by the search box.
     *
     * @param  array<int, array<string, mixed>>  $chats
     * @return array<int, array<string, mixed>>
     */
    protected function forwardTargets(array $chats): array
    {
        if (! $this->showForward) {
            return [];
        }

        $needle = mb_strtolower($this->forwardSearch);
        $forwardTargets = array_values(array_filter(
            $chats,
            fn ($c) => $needle === '' || str_contains(mb_strtolower(($c['name'] ?? '').' '.($c['id'] ?? '')), $needle),
        ));

        return array_slice($forwardTargets, 0, 30);
    }
}
