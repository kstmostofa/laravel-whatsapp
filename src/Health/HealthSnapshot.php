<?php

namespace Kstmostofa\LaravelWhatsApp\Health;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Kstmostofa\LaravelWhatsApp\Client\CloudClient;
use Kstmostofa\LaravelWhatsApp\Exceptions\CloudApiException;
use Kstmostofa\LaravelWhatsApp\Exceptions\SidecarException;
use Kstmostofa\LaravelWhatsApp\Models\WaMessage;
use Kstmostofa\LaravelWhatsApp\Web\SidecarManager;
use Kstmostofa\LaravelWhatsApp\Web\WebClient;

/**
 * Single source of truth for system status. Gathers signals from both backends
 * and caches them so the header indicator and the status page don't double-hit
 * the network on every page load.
 *
 * Status values, lowest-to-highest concern:
 *   ok | degraded | down | not_configured
 *
 * `gather()` returns ['sidecar' => […], 'cloud' => […], 'overall' => 'ok|…'].
 */
class HealthSnapshot
{
    public const STATUS_OK = 'ok';
    public const STATUS_DEGRADED = 'degraded';
    public const STATUS_DOWN = 'down';
    public const STATUS_NOT_CONFIGURED = 'not_configured';

    protected const CACHE_TTL = 60; // seconds

    public function __construct(
        protected SidecarManager $sidecar,
        protected WebClient $web,
        protected CloudClient $cloud,
        protected CacheRepository $cache,
    ) {
    }

    /**
     * @return array{sidecar: array, cloud: array, overall: string}
     */
    public function gather(bool $fresh = false): array
    {
        $sidecar = $this->sidecar($fresh);
        $cloud = $this->cloud($fresh);

        return [
            'sidecar' => $sidecar,
            'cloud' => $cloud,
            'overall' => $this->aggregate([$sidecar['status'], $cloud['status']]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function sidecar(bool $fresh = false): array
    {
        if ($fresh) {
            return $this->collectSidecar();
        }

        return $this->cache->remember('laravel-whatsapp.health.sidecar', self::CACHE_TTL, fn () => $this->collectSidecar());
    }

    /**
     * @return array<string, mixed>
     */
    public function cloud(bool $fresh = false): array
    {
        if ($fresh) {
            return $this->collectCloud();
        }

        return $this->cache->remember('laravel-whatsapp.health.cloud', self::CACHE_TTL, fn () => $this->collectCloud());
    }

    public function flush(): void
    {
        $this->cache->forget('laravel-whatsapp.health.sidecar');
        $this->cache->forget('laravel-whatsapp.health.cloud');
    }

    /**
     * @param  array<int, string>  $statuses
     */
    public function aggregate(array $statuses): string
    {
        if (in_array(self::STATUS_DOWN, $statuses, true)) {
            return self::STATUS_DOWN;
        }
        if (in_array(self::STATUS_DEGRADED, $statuses, true)) {
            return self::STATUS_DEGRADED;
        }
        if (in_array(self::STATUS_OK, $statuses, true)) {
            return self::STATUS_OK;
        }

        return self::STATUS_NOT_CONFIGURED;
    }

    /**
     * @return array{status: string, installed: bool, running: bool, pid: ?int, reachable: bool, latency_ms: ?int, uptime: ?int, sessions: array, endpoint: string, error: ?string}
     */
    protected function collectSidecar(): array
    {
        $installed = $this->sidecar->isInstalled();
        $running = $this->sidecar->isRunning();
        $pid = $this->sidecar->pid();
        $endpoint = sprintf('http://%s:%d', $this->sidecar->host(), $this->sidecar->port());

        $reachable = false;
        $latencyMs = null;
        $uptime = null;
        $sessions = [];
        $error = null;

        if (! config('laravel-whatsapp.web.enabled', false)) {
            return [
                'status' => self::STATUS_NOT_CONFIGURED,
                'installed' => $installed, 'running' => $running, 'pid' => $pid,
                'reachable' => false, 'latency_ms' => null, 'uptime' => null,
                'sessions' => [], 'endpoint' => $endpoint, 'error' => null,
            ];
        }

        if ($running) {
            try {
                $start = microtime(true);
                $health = $this->web->request('GET', 'health');
                $latencyMs = (int) round((microtime(true) - $start) * 1000);
                $reachable = ($health['ok'] ?? false) === true;
                $uptime = isset($health['uptime']) ? (int) $health['uptime'] : null;
                $sessions = $this->web->sessions();
            } catch (SidecarException $e) {
                $error = $e->getMessage();
            }
        }

        return [
            'status' => $this->sidecarStatus($installed, $running, $reachable),
            'installed' => $installed,
            'running' => $running,
            'pid' => $pid,
            'reachable' => $reachable,
            'latency_ms' => $latencyMs,
            'uptime' => $uptime,
            'sessions' => $sessions,
            'endpoint' => $endpoint,
            'error' => $error,
        ];
    }

    protected function sidecarStatus(bool $installed, bool $running, bool $reachable): string
    {
        if (! $installed) {
            return self::STATUS_NOT_CONFIGURED;
        }
        if (! $running) {
            return self::STATUS_DOWN;
        }
        if (! $reachable) {
            return self::STATUS_DEGRADED;
        }

        return self::STATUS_OK;
    }

    /**
     * @return array<string, mixed>
     */
    protected function collectCloud(): array
    {
        $accessToken = config('laravel-whatsapp.access_token');
        $phoneNumberId = config('laravel-whatsapp.phone_number_id');
        $configured = ! empty($accessToken) && ! empty($phoneNumberId);

        $authenticated = false;
        $phoneInfo = null;
        $qualityRating = null;
        $throughput = null;
        $codeVerification = null;
        $error = null;

        if ($configured) {
            try {
                $phoneInfo = $this->cloud->phoneNumber()->get([
                    'verified_name', 'display_phone_number', 'quality_rating',
                    'throughput', 'code_verification_status', 'platform_type',
                ]);
                $authenticated = true;
                $qualityRating = $phoneInfo['quality_rating'] ?? null;
                $throughput = $phoneInfo['throughput']['level'] ?? null;
                $codeVerification = $phoneInfo['code_verification_status'] ?? null;
            } catch (CloudApiException $e) {
                $error = $e->getMessage();
            }
        }

        $lastWebhookAt = null;
        try {
            $last = WaMessage::query()
                ->where('backend', 'cloud')
                ->where('direction', 'inbound')
                ->latest()
                ->first();
            $lastWebhookAt = $last?->created_at;
        } catch (\Throwable) {
            // wa_messages table likely not migrated — silent fallback.
        }

        return [
            'status' => $this->cloudStatus($configured, $authenticated, $qualityRating),
            'configured' => $configured,
            'authenticated' => $authenticated,
            'phone_info' => $phoneInfo,
            'quality_rating' => $qualityRating,
            'throughput' => $throughput,
            'code_verification' => $codeVerification,
            'last_webhook_at' => $lastWebhookAt,
            'error' => $error,
        ];
    }

    protected function cloudStatus(bool $configured, bool $authenticated, ?string $quality): string
    {
        if (! $configured) {
            return self::STATUS_NOT_CONFIGURED;
        }
        if (! $authenticated) {
            return self::STATUS_DOWN;
        }
        if ($quality === 'RED') {
            return self::STATUS_DEGRADED;
        }

        return self::STATUS_OK;
    }
}
