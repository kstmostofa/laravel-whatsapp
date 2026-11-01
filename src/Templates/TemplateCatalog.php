<?php

namespace Kstmostofa\LaravelWhatsApp\Templates;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Container\Container;
use Kstmostofa\LaravelWhatsApp\Client\CloudClient;

/**
 * The approved message templates of the configured WhatsApp Business
 * Account, cached so the Compose page doesn't call Meta on every render.
 *
 * The cache is dropped automatically when Meta reports a template status
 * change (approved, rejected, paused…) through the webhook.
 */
class TemplateCatalog
{
    /** Pages of 100 to follow. 500 approved templates is plenty for a picker. */
    protected const MAX_PAGES = 5;

    public function __construct(
        protected Container $container,
        protected Cache $cache,
    ) {
    }

    /**
     * @return array<int, TemplateDefinition>  sorted by name, then language
     */
    public function approved(): array
    {
        $ttl = (int) config('laravel-whatsapp.ui.templates_cache_seconds', 600);

        $raw = $ttl > 0
            ? $this->cache->remember($this->cacheKey(), $ttl, fn () => $this->fetch())
            : $this->fetch();

        $templates = array_map(fn (array $t) => TemplateDefinition::fromArray($t), $raw);
        usort($templates, fn ($a, $b) => [$a->name, $a->language] <=> [$b->name, $b->language]);

        return $templates;
    }

    public function find(string $key): ?TemplateDefinition
    {
        foreach ($this->approved() as $template) {
            if ($template->key() === $key) {
                return $template;
            }
        }

        return null;
    }

    public function flush(): void
    {
        $this->cache->forget($this->cacheKey());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function fetch(): array
    {
        $resource = $this->container->make(CloudClient::class)->templates();
        $templates = [];
        $after = null;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $response = $resource->list(array_filter([
                'status' => 'APPROVED',
                'limit' => 100,
                'fields' => 'name,language,status,category,components',
                'after' => $after,
            ]));

            foreach ($response['data'] ?? [] as $template) {
                if (is_array($template) && ($template['status'] ?? 'APPROVED') === 'APPROVED') {
                    $templates[] = $template;
                }
            }

            $after = $response['paging']['cursors']['after'] ?? null;

            if (! $after || empty($response['paging']['next'])) {
                break;
            }
        }

        return $templates;
    }

    protected function cacheKey(): string
    {
        return 'laravel-whatsapp:templates:'.(config('laravel-whatsapp.business_account_id') ?: 'default');
    }
}
