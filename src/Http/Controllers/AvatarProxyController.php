<?php

namespace Kstmostofa\LaravelWhatsApp\Http\Controllers;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Kstmostofa\LaravelWhatsApp\Web\WebClient;
use Symfony\Component\HttpFoundation\Response;

/**
 * Proxies contact profile pictures from the sidecar to the browser.
 *
 * Performance: server-side cache for 30 minutes (both hits and misses) — a
 * busy chat list with 50+ contacts would otherwise issue 50+ uncached hits to
 * the sidecar, each calling WhatsApp's slow profile-pic lookup. Cache key is
 * the session + contact pair. We also short-circuit known-misses (204) so the
 * browser doesn't re-attempt and falls back to initials immediately.
 */
class AvatarProxyController extends Controller
{
    protected const CACHE_TTL = 1800; // 30 minutes
    protected const MISS_MARKER = '__no_avatar__';

    public function show(string $session, string $contactId, WebClient $client): Response
    {
        $cacheKey = sprintf('laravel-whatsapp:avatar:%s:%s', $session, sha1($contactId));

        $cached = Cache::get($cacheKey);

        if ($cached === self::MISS_MARKER) {
            return $this->emptyResponse();
        }

        if (is_array($cached) && isset($cached['body'])) {
            return new Response($cached['body'], 200, [
                'Content-Type' => $cached['content_type'],
                'Cache-Control' => 'private, max-age=1800',
                'X-Avatar-Cache' => 'HIT',
            ]);
        }

        [$status, $contentType, $body] = $this->fetchFromSidecar($session, $contactId, $client);

        if ($status >= 400 || $body === '') {
            Cache::put($cacheKey, self::MISS_MARKER, self::CACHE_TTL);

            return $this->emptyResponse();
        }

        Cache::put($cacheKey, [
            'content_type' => $contentType,
            'body' => $body,
        ], self::CACHE_TTL);

        return new Response($body, 200, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'private, max-age=1800',
            'X-Avatar-Cache' => 'MISS',
        ]);
    }

    /**
     * @return array{0: int, 1: string, 2: string}  [status, contentType, body]
     */
    protected function fetchFromSidecar(string $session, string $contactId, WebClient $client): array
    {
        $url = sprintf(
            'http://%s:%d/sessions/%s/contacts/%s/picture',
            $client->host(),
            $client->port(),
            rawurlencode($session),
            rawurlencode($contactId),
        );

        $headers = $client->token() ? ['Authorization' => 'Bearer '.$client->token()] : [];

        try {
            $response = (new GuzzleClient([
                'timeout' => 8, // strictly bounded — sidecar already times out at 5s per upstream call
                'http_errors' => false,
            ]))->get($url, ['headers' => $headers]);
        } catch (GuzzleException) {
            return [502, 'application/octet-stream', ''];
        }

        return [
            $response->getStatusCode(),
            $response->getHeaderLine('Content-Type') ?: 'image/jpeg',
            (string) $response->getBody(),
        ];
    }

    /**
     * 204 No Content tells the browser there's no avatar and it should fall
     * back to whatever's behind the <img> (Flux's initials, in our case).
     * Browsers don't log this as an error.
     */
    protected function emptyResponse(): Response
    {
        return new Response('', 204, [
            'Cache-Control' => 'private, max-age=1800',
            'X-Avatar-Cache' => 'MISS-NEGATIVE',
        ]);
    }
}
