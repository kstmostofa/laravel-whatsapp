<?php

namespace Kstmostofa\LaravelWhatsApp\Client\Resources;

use Illuminate\Container\Container;
use Kstmostofa\LaravelWhatsApp\Client\CloudClient;
use Kstmostofa\LaravelWhatsApp\Events\MessageSent;
use Kstmostofa\LaravelWhatsApp\Support\Recipient;

class MessagesResource extends Resource
{
    public function __construct(CloudClient $client, protected string $phoneNumberId)
    {
        parent::__construct($client);
    }

    protected function endpoint(): string
    {
        return "{$this->phoneNumberId}/messages";
    }

    /**
     * @return array<string, mixed>
     */
    public function sendText(string $to, string $body, bool $previewUrl = false, ?string $contextMessageId = null): array
    {
        return $this->send($to, 'text', [
            'text' => [
                'preview_url' => $previewUrl,
                'body' => $body,
            ],
        ], $contextMessageId);
    }

    /**
     * Send a Meta-approved template. Components are the standard Cloud API shape:
     *   [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'John']]]]
     *
     * @param  array<int, array<string, mixed>>  $components
     * @return array<string, mixed>
     */
    public function sendTemplate(string $to, string $templateName, string $languageCode = 'en_US', array $components = []): array
    {
        return $this->send($to, 'template', [
            'template' => array_filter([
                'name' => $templateName,
                'language' => ['code' => $languageCode],
                'components' => $components ?: null,
            ]),
        ]);
    }

    /**
     * @param  array{id?: string, link?: string, caption?: string}  $image  one of id|link required
     * @return array<string, mixed>
     */
    public function sendImage(string $to, array $image, ?string $contextMessageId = null): array
    {
        return $this->send($to, 'image', ['image' => $image], $contextMessageId);
    }

    /**
     * @param  array{id?: string, link?: string, caption?: string}  $video
     * @return array<string, mixed>
     */
    public function sendVideo(string $to, array $video, ?string $contextMessageId = null): array
    {
        return $this->send($to, 'video', ['video' => $video], $contextMessageId);
    }

    /**
     * @param  array{id?: string, link?: string}  $audio
     * @return array<string, mixed>
     */
    public function sendAudio(string $to, array $audio, ?string $contextMessageId = null): array
    {
        return $this->send($to, 'audio', ['audio' => $audio], $contextMessageId);
    }

    /**
     * @param  array{id?: string, link?: string, filename?: string, caption?: string}  $document
     * @return array<string, mixed>
     */
    public function sendDocument(string $to, array $document, ?string $contextMessageId = null): array
    {
        return $this->send($to, 'document', ['document' => $document], $contextMessageId);
    }

    /**
     * @param  array{id?: string, link?: string}  $sticker
     * @return array<string, mixed>
     */
    public function sendSticker(string $to, array $sticker): array
    {
        return $this->send($to, 'sticker', ['sticker' => $sticker]);
    }

    /**
     * @return array<string, mixed>
     */
    public function sendLocation(string $to, float $latitude, float $longitude, ?string $name = null, ?string $address = null): array
    {
        return $this->send($to, 'location', [
            'location' => array_filter([
                'latitude' => $latitude,
                'longitude' => $longitude,
                'name' => $name,
                'address' => $address,
            ]),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $contacts  Cloud-API contact objects
     * @return array<string, mixed>
     */
    public function sendContacts(string $to, array $contacts): array
    {
        return $this->send($to, 'contacts', ['contacts' => $contacts]);
    }

    /**
     * @param  array{type: string, body?: array<string, mixed>, header?: array<string, mixed>, footer?: array<string, mixed>, action: array<string, mixed>}  $interactive
     * @return array<string, mixed>
     */
    public function sendInteractive(string $to, array $interactive): array
    {
        return $this->send($to, 'interactive', ['interactive' => $interactive]);
    }

    /**
     * Up to 3 reply buttons. Taps arrive as InteractiveReplied with the
     * button's ID — route them with WhatsApp::router()->onButton('id', …).
     *
     *   ->sendButtons($to, 'Confirm your order?', ['yes' => 'Yes', 'no' => 'No'])
     *
     * @param  array<string, string>  $buttons  id => title (max 20 characters)
     * @return array<string, mixed>
     */
    public function sendButtons(string $to, string $body, array $buttons, ?string $header = null, ?string $footer = null): array
    {
        $this->assertCount(count($buttons), 1, 3, 'buttons');

        $action = ['buttons' => array_map(
            fn ($id, $title) => ['type' => 'reply', 'reply' => ['id' => (string) $id, 'title' => (string) $title]],
            array_keys($buttons),
            $buttons,
        )];

        return $this->sendInteractive($to, $this->interactive('button', $body, $action, $header, $footer));
    }

    /**
     * A list behind a button — up to 10 rows across up to 10 sections.
     *
     *   ->sendList($to, 'Pick a branch', 'Branches', [
     *       'Riyadh' => ['olaya' => 'Olaya', 'malqa' => ['title' => 'Malqa', 'description' => 'Open late']],
     *   ])
     *
     * @param  array<string, array<string, string|array{title: string, description?: string}>>  $sections  section title => [row id => title or row]
     * @return array<string, mixed>
     */
    public function sendList(string $to, string $body, string $buttonText, array $sections, ?string $header = null, ?string $footer = null): array
    {
        $rowCount = array_sum(array_map('count', $sections));
        $this->assertCount($rowCount, 1, 10, 'list rows');

        $action = [
            'button' => $buttonText,
            'sections' => array_map(fn ($title, $rows) => [
                'title' => (string) $title,
                'rows' => array_map(fn ($id, $row) => array_filter([
                    'id' => (string) $id,
                    'title' => is_array($row) ? $row['title'] : (string) $row,
                    'description' => is_array($row) ? ($row['description'] ?? null) : null,
                ], fn ($v) => $v !== null), array_keys($rows), $rows),
            ], array_keys($sections), $sections),
        ];

        return $this->sendInteractive($to, $this->interactive('list', $body, $action, $header, $footer));
    }

    /**
     * A message with one button that opens a URL.
     *
     * @return array<string, mixed>
     */
    public function sendCtaUrl(string $to, string $body, string $buttonText, string $url, ?string $header = null, ?string $footer = null): array
    {
        return $this->sendInteractive($to, $this->interactive('cta_url', $body, [
            'name' => 'cta_url',
            'parameters' => ['display_text' => $buttonText, 'url' => $url],
        ], $header, $footer));
    }

    /**
     * Ask the customer to share their location. Their answer arrives as a
     * normal `location` message.
     *
     * @return array<string, mixed>
     */
    public function sendLocationRequest(string $to, string $body): array
    {
        return $this->sendInteractive($to, $this->interactive('location_request_message', $body, ['name' => 'send_location']));
    }

    /**
     * One product from a Meta commerce catalog.
     *
     * @return array<string, mixed>
     */
    public function sendProduct(string $to, string $catalogId, string $productRetailerId, ?string $body = null, ?string $footer = null): array
    {
        return $this->sendInteractive($to, array_filter([
            'type' => 'product',
            'body' => $body !== null ? ['text' => $body] : null,
            'footer' => $footer !== null ? ['text' => $footer] : null,
            'action' => ['catalog_id' => $catalogId, 'product_retailer_id' => $productRetailerId],
        ]));
    }

    /**
     * Several catalog products, grouped into sections (max 30 products).
     *
     * @param  array<string, array<int, string>>  $sections  section title => product retailer IDs
     * @return array<string, mixed>
     */
    public function sendProductList(string $to, string $catalogId, string $header, string $body, array $sections, ?string $footer = null): array
    {
        $this->assertCount(array_sum(array_map('count', $sections)), 1, 30, 'products');

        return $this->sendInteractive($to, array_filter([
            'type' => 'product_list',
            'header' => ['type' => 'text', 'text' => $header],
            'body' => ['text' => $body],
            'footer' => $footer !== null ? ['text' => $footer] : null,
            'action' => [
                'catalog_id' => $catalogId,
                'sections' => array_map(fn ($title, $ids) => [
                    'title' => (string) $title,
                    'product_items' => array_map(fn ($id) => ['product_retailer_id' => (string) $id], array_values($ids)),
                ], array_keys($sections), $sections),
            ],
        ]));
    }

    /**
     * Open a WhatsApp Flow. The customer's submission arrives as an
     * InteractiveReplied event — read it with $event->flowResponse().
     *
     * @param  array<string, mixed>  $data  initial data for `$screen`; only used with a screen
     * @return array<string, mixed>
     */
    public function sendFlow(
        string $to,
        string $flowId,
        string $buttonText,
        string $body,
        ?string $screen = null,
        array $data = [],
        ?string $flowToken = null,
        bool $draft = false,
        ?string $header = null,
        ?string $footer = null,
    ): array {
        $parameters = array_filter([
            'flow_message_version' => '3',
            'flow_id' => $flowId,
            'flow_cta' => $buttonText,
            'flow_token' => $flowToken,
            'mode' => $draft ? 'draft' : null,
            'flow_action' => $screen !== null ? 'navigate' : 'data_exchange',
            'flow_action_payload' => $screen !== null ? array_filter(['screen' => $screen, 'data' => $data ?: null]) : null,
        ], fn ($v) => $v !== null);

        return $this->sendInteractive($to, $this->interactive('flow', $body, [
            'name' => 'flow',
            'parameters' => $parameters,
        ], $header, $footer));
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    protected function interactive(string $type, string $body, array $action, ?string $header = null, ?string $footer = null): array
    {
        return array_filter([
            'type' => $type,
            'header' => $header !== null ? ['type' => 'text', 'text' => $header] : null,
            'body' => ['text' => $body],
            'footer' => $footer !== null ? ['text' => $footer] : null,
            'action' => $action,
        ]);
    }

    protected function assertCount(int $count, int $min, int $max, string $what): void
    {
        if ($count < $min || $count > $max) {
            throw new \InvalidArgumentException("WhatsApp allows {$min}–{$max} {$what}; got {$count}.");
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function sendReaction(string $to, string $messageId, string $emoji): array
    {
        return $this->send($to, 'reaction', [
            'reaction' => [
                'message_id' => $messageId,
                'emoji' => $emoji,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function markAsRead(string $messageId): array
    {
        return $this->request('POST', $this->endpoint(), [
            'json' => [
                'messaging_product' => 'whatsapp',
                'status' => 'read',
                'message_id' => $messageId,
            ],
        ]);
    }

    /**
     * Generic send. Use the typed helpers above where you can; this is the escape hatch.
     *
     * @param  array<string, mixed>  $payload  Type-specific body keys (e.g. ['text' => [...]])
     * @return array<string, mixed>
     */
    public function send(string $to, string $type, array $payload, ?string $contextMessageId = null): array
    {
        $body = array_merge([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => Recipient::normalize($to),
            'type' => $type,
        ], $payload);

        if ($contextMessageId !== null) {
            $body['context'] = ['message_id' => $contextMessageId];
        }

        $response = $this->request('POST', $this->endpoint(), ['json' => $body]);

        $this->announce($body, $response);

        return $response;
    }

    /**
     * Fire MessageSent so listeners (and the built-in persistence) see every
     * outbound message, however it was sent.
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $response
     */
    protected function announce(array $body, array $response): void
    {
        $container = Container::getInstance();

        if ($container->bound('events')) {
            $container->make('events')->dispatch(new MessageSent($this->phoneNumberId, $body, $response));
        }
    }
}
