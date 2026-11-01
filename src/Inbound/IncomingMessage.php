<?php

namespace Kstmostofa\LaravelWhatsApp\Inbound;

use Kstmostofa\LaravelWhatsApp\Client\CloudAccounts;
use Kstmostofa\LaravelWhatsApp\Events\InteractiveReplied;
use Kstmostofa\LaravelWhatsApp\Events\MediaReceived;
use Kstmostofa\LaravelWhatsApp\Events\MessageReceived as CloudMessageReceived;
use Kstmostofa\LaravelWhatsApp\Events\Web\MessageCreated;
use Kstmostofa\LaravelWhatsApp\Events\Web\MessageReceived as WebMessageReceived;
use Kstmostofa\LaravelWhatsApp\Notifications\WhatsAppMessage;
use Kstmostofa\LaravelWhatsApp\Support\MessageSender;
use Kstmostofa\LaravelWhatsApp\Web\WebClient;

/**
 * One incoming message, the same shape for both backends. What the inbound
 * router hands to your handlers.
 *
 *   WhatsApp::router()->hears('hi', fn (IncomingMessage $m) => $m->reply('Hello!'));
 */
class IncomingMessage
{
    /**
     * @param  'cloud'|'web'  $backend
     * @param  string  $sender  Cloud phone_number_id or Web session ID that received it
     * @param  object  $event  the original package event
     */
    public function __construct(
        public readonly string $backend,
        public readonly string $sender,
        public readonly ?string $chatId,
        public readonly ?string $from,
        public readonly ?string $messageId,
        public readonly string $type,
        public readonly ?string $text,
        public readonly ?string $buttonId,
        public readonly bool $fromMe,
        public readonly object $event,
    ) {}

    /**
     * Build from a package event, or null for events the router doesn't
     * handle (acks, revokes, and the outbound-only MessageCreated).
     */
    public static function fromEvent(object $event): ?self
    {
        if ($event instanceof MessageCreated) {
            return null;
        }

        if ($event instanceof WebMessageReceived) {
            $m = $event->message();

            return new self(
                backend: 'web',
                sender: $event->sessionId,
                chatId: $event->chatId(),
                from: $m['author'] ?? $event->from(),
                messageId: $event->messageId(),
                type: (string) ($m['type'] ?? 'chat'),
                text: $event->body(),
                buttonId: null,
                fromMe: $event->fromMe(),
                event: $event,
            );
        }

        if ($event instanceof InteractiveReplied) {
            return new self('cloud', $event->phoneNumberId, $event->from(), $event->from(), $event->payload['id'] ?? null,
                'interactive', $event->replyTitle(), $event->replyId(), false, $event);
        }

        if ($event instanceof MediaReceived) {
            return new self('cloud', $event->phoneNumberId, $event->from(), $event->from(), $event->payload['id'] ?? null,
                (string) $event->mediaType(), $event->caption(), null, false, $event);
        }

        if ($event instanceof CloudMessageReceived) {
            return new self('cloud', $event->phoneNumberId, $event->from(), $event->from(), $event->messageId(),
                (string) ($event->payload['type'] ?? 'text'), $event->text(), null, false, $event);
        }

        return null;
    }

    public function isGroup(): bool
    {
        return str_ends_with((string) $this->chatId, '@g.us');
    }

    public function isStatus(): bool
    {
        return $this->chatId === 'status@broadcast';
    }

    public function hasMedia(): bool
    {
        return in_array($this->type, ['image', 'video', 'audio', 'ptt', 'document', 'sticker'], true);
    }

    /** Text body, caption, or button title — '' when there is none. */
    public function text(): string
    {
        return (string) $this->text;
    }

    /**
     * Cloud account name this message arrived on (`default`, `sales`, …).
     * Null for Web messages and unconfigured numbers.
     */
    public function account(): ?string
    {
        return $this->backend === 'cloud' ? app(CloudAccounts::class)->nameFor($this->sender) : null;
    }

    /**
     * Answer in the same chat, from the same number or session. Quotes the
     * original unless `$quote` is false.
     *
     * @return array<string, mixed> the backend's response
     */
    public function reply(WhatsAppMessage|string $message, bool $quote = true): array
    {
        $message = is_string($message) ? WhatsAppMessage::create($message) : clone $message;

        if ($this->backend === 'web') {
            $message->viaWeb($this->sender);
        } elseif ($account = $this->account()) {
            $message->fromAccount($account);
        } else {
            $message->viaCloud($this->sender);
        }

        if ($quote && $this->messageId && $message->template === null) {
            $message->replyTo($this->messageId);
        }

        return app(MessageSender::class)->send($message, (string) $this->chatId);
    }

    /**
     * React to this message. On the Cloud API the reaction is a message to
     * the sender; on the Web sidecar it's attached directly.
     *
     * @return array<string, mixed>
     */
    public function react(string $emoji): array
    {
        if ($this->messageId === null) {
            return [];
        }

        if ($this->backend === 'web') {
            return app(WebClient::class)->session($this->sender)->messages()->react($this->messageId, $emoji);
        }

        return app(CloudAccounts::class)->client($this->account())
            ->messages($this->account() ? null : $this->sender)
            ->sendReaction((string) $this->chatId, $this->messageId, $emoji);
    }

    /**
     * Show blue ticks to the sender. Cloud marks this message read; Web
     * marks the whole chat seen.
     *
     * @return array<string, mixed>
     */
    public function markRead(): array
    {
        if ($this->backend === 'web') {
            return app(WebClient::class)->session($this->sender)->markChatSeen((string) $this->chatId);
        }

        return $this->messageId === null ? [] : app(CloudAccounts::class)->client($this->account())
            ->messages($this->account() ? null : $this->sender)
            ->markAsRead($this->messageId);
    }
}
