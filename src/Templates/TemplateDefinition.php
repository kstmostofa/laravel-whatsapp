<?php

namespace Kstmostofa\LaravelWhatsApp\Templates;

/**
 * An approved Cloud API message template, as returned by
 * `{waba-id}/message_templates`, plus everything needed to send it:
 * which placeholders it has, how to turn filled-in values into Meta's
 * `components` payload, and a plain-text preview.
 *
 * Field IDs look like `body__1`, `header__media`, `button__0__code` — no dots,
 * because Livewire reads dots in wire:model paths as nested keys.
 *
 * Handles positional (`{{1}}`) and named (`{{first_name}}`) parameters, text
 * and media headers, URL buttons with a dynamic suffix, and copy-code buttons.
 */
class TemplateDefinition
{
    protected const PLACEHOLDER = '/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/';

    protected const MEDIA_FORMATS = ['IMAGE' => 'image', 'VIDEO' => 'video', 'DOCUMENT' => 'document'];

    /**
     * @param  array<int, array<string, mixed>>  $components
     */
    public function __construct(
        public readonly string $name,
        public readonly string $language,
        public readonly ?string $category = null,
        public readonly ?string $status = null,
        public readonly array $components = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $template  one entry of Meta's `data` array
     */
    public static function fromArray(array $template): self
    {
        return new self(
            name: (string) ($template['name'] ?? ''),
            language: (string) ($template['language'] ?? 'en_US'),
            category: $template['category'] ?? null,
            status: $template['status'] ?? null,
            components: array_values(array_filter($template['components'] ?? [], 'is_array')),
        );
    }

    /** Stable identifier for forms: `name|language`. */
    public function key(): string
    {
        return $this->name.'|'.$this->language;
    }

    /**
     * Every value the sender has to fill in, in display order.
     *
     * @return array<int, array{id: string, label: string, kind: 'text'|'media', media?: string}>
     */
    public function fields(): array
    {
        $fields = [];

        foreach ($this->components as $component) {
            $type = strtoupper((string) ($component['type'] ?? ''));

            if ($type === 'HEADER') {
                $format = strtoupper((string) ($component['format'] ?? 'TEXT'));

                if (isset(self::MEDIA_FORMATS[$format])) {
                    $media = self::MEDIA_FORMATS[$format];
                    $fields[] = ['id' => 'header__media', 'label' => 'Header '.$media.' URL', 'kind' => 'media', 'media' => $media];
                } else {
                    foreach ($this->placeholders($component['text'] ?? '') as $key) {
                        $fields[] = ['id' => "header__{$key}", 'label' => "Header {{{$key}}}", 'kind' => 'text'];
                    }
                }
            } elseif ($type === 'BODY') {
                foreach ($this->placeholders($component['text'] ?? '') as $key) {
                    $fields[] = ['id' => "body__{$key}", 'label' => "Body {{{$key}}}", 'kind' => 'text'];
                }
            } elseif ($type === 'BUTTONS') {
                foreach (array_values($component['buttons'] ?? []) as $i => $button) {
                    $buttonType = strtoupper((string) ($button['type'] ?? ''));
                    $text = $button['text'] ?? 'Button '.($i + 1);

                    if ($buttonType === 'URL') {
                        foreach ($this->placeholders($button['url'] ?? '') as $key) {
                            $fields[] = ['id' => "button__{$i}__{$key}", 'label' => "\"{$text}\" link {{{$key}}}", 'kind' => 'text'];
                        }
                    } elseif ($buttonType === 'COPY_CODE') {
                        $fields[] = ['id' => "button__{$i}__code", 'label' => "\"{$text}\" code", 'kind' => 'text'];
                    }
                }
            }
        }

        return $fields;
    }

    /**
     * IDs of fields left empty.
     *
     * @param  array<string, mixed>  $values  field id => value
     * @return array<int, string>
     */
    public function missing(array $values): array
    {
        return array_values(array_map(
            fn (array $f) => $f['id'],
            array_filter($this->fields(), fn (array $f) => trim((string) ($values[$f['id']] ?? '')) === ''),
        ));
    }

    /**
     * Meta's `components` array for sendTemplate().
     *
     * @param  array<string, mixed>  $values  field id => value
     * @return array<int, array<string, mixed>>
     */
    public function components(array $values): array
    {
        $out = [];

        foreach ($this->components as $component) {
            $type = strtoupper((string) ($component['type'] ?? ''));

            if ($type === 'HEADER') {
                $format = strtoupper((string) ($component['format'] ?? 'TEXT'));

                if (isset(self::MEDIA_FORMATS[$format])) {
                    $media = self::MEDIA_FORMATS[$format];
                    $out[] = ['type' => 'header', 'parameters' => [
                        ['type' => $media, $media => ['link' => (string) ($values['header__media'] ?? '')]],
                    ]];
                } elseif ($params = $this->textParameters($component['text'] ?? '', 'header', $values)) {
                    $out[] = ['type' => 'header', 'parameters' => $params];
                }
            } elseif ($type === 'BODY') {
                if ($params = $this->textParameters($component['text'] ?? '', 'body', $values)) {
                    $out[] = ['type' => 'body', 'parameters' => $params];
                }
            } elseif ($type === 'BUTTONS') {
                foreach (array_values($component['buttons'] ?? []) as $i => $button) {
                    $buttonType = strtoupper((string) ($button['type'] ?? ''));

                    if ($buttonType === 'URL' && $keys = $this->placeholders($button['url'] ?? '')) {
                        $out[] = [
                            'type' => 'button',
                            'sub_type' => 'url',
                            'index' => (string) $i,
                            'parameters' => array_map(
                                fn ($key) => ['type' => 'text', 'text' => (string) ($values["button__{$i}__{$key}"] ?? '')],
                                $keys,
                            ),
                        ];
                    } elseif ($buttonType === 'COPY_CODE') {
                        $out[] = [
                            'type' => 'button',
                            'sub_type' => 'copy_code',
                            'index' => (string) $i,
                            'parameters' => [['type' => 'coupon_code', 'coupon_code' => (string) ($values["button__{$i}__code"] ?? '')]],
                        ];
                    }
                }
            }
        }

        return $out;
    }

    /**
     * The message as the recipient will roughly see it. Unfilled placeholders
     * stay visible as `{{1}}`.
     *
     * @param  array<string, mixed>  $values
     * @return array{header: ?string, body: ?string, footer: ?string, buttons: array<int, string>}
     */
    public function preview(array $values = []): array
    {
        $preview = ['header' => null, 'body' => null, 'footer' => null, 'buttons' => []];

        foreach ($this->components as $component) {
            $type = strtoupper((string) ($component['type'] ?? ''));

            match ($type) {
                'HEADER' => $preview['header'] = isset(self::MEDIA_FORMATS[strtoupper((string) ($component['format'] ?? 'TEXT'))])
                    ? '['.ucfirst(self::MEDIA_FORMATS[strtoupper((string) $component['format'])]).']'
                    : $this->fill($component['text'] ?? '', 'header', $values),
                'BODY' => $preview['body'] = $this->fill($component['text'] ?? '', 'body', $values),
                'FOOTER' => $preview['footer'] = $component['text'] ?? null,
                'BUTTONS' => $preview['buttons'] = array_values(array_map(
                    fn ($b) => (string) ($b['text'] ?? ''),
                    $component['buttons'] ?? [],
                )),
                default => null,
            };
        }

        return $preview;
    }

    /**
     * @return array<int, string>  placeholder keys in order of first appearance
     */
    protected function placeholders(string $text): array
    {
        preg_match_all(self::PLACEHOLDER, $text, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<int, array<string, string>>
     */
    protected function textParameters(string $text, string $prefix, array $values): array
    {
        return array_map(function (string $key) use ($prefix, $values) {
            $param = ['type' => 'text', 'text' => (string) ($values["{$prefix}__{$key}"] ?? '')];

            // Named-parameter templates must say which placeholder each value fills.
            if (! ctype_digit($key)) {
                $param['parameter_name'] = $key;
            }

            return $param;
        }, $this->placeholders($text));
    }

    /**
     * @param  array<string, mixed>  $values
     */
    protected function fill(string $text, string $prefix, array $values): string
    {
        return (string) preg_replace_callback(self::PLACEHOLDER, function ($m) use ($prefix, $values) {
            $value = trim((string) ($values["{$prefix}__{$m[1]}"] ?? ''));

            return $value !== '' ? $value : $m[0];
        }, $text);
    }
}
