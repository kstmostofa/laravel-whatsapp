<?php

namespace Kstmostofa\LaravelWhatsApp\Http\Controllers;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Kstmostofa\LaravelWhatsApp\Web\WebClient;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Proxies media downloads from the sidecar to the browser. The browser can't
 * hit the sidecar directly because the sidecar's auth token is server-side
 * only — and CORS/firewall rules typically keep 127.0.0.1:3000 unreachable
 * from a different origin anyway. This controller streams the bytes through
 * Laravel using the sidecar's Bearer token.
 *
 * Cached client-side for 1 hour (sidecar sets `Cache-Control`).
 */
class MediaProxyController extends Controller
{
    public function show(Request $request, string $session, string $messageId, WebClient $client): StreamedResponse
    {
        $url = sprintf(
            'http://%s:%d/sessions/%s/messages/%s/media',
            $client->host(),
            $client->port(),
            rawurlencode($session),
            rawurlencode($messageId),
        );

        $headers = $client->token() ? ['Authorization' => 'Bearer '.$client->token()] : [];

        try {
            $upstream = (new GuzzleClient([
                'timeout' => 30,
                'http_errors' => false,
            ]))->get($url, [
                'headers' => $headers,
                'stream' => true,
            ]);
        } catch (GuzzleException $e) {
            abort(502, 'Sidecar unreachable: '.$e->getMessage());
        }

        if ($upstream->getStatusCode() >= 400) {
            abort($upstream->getStatusCode(), (string) $upstream->getBody());
        }

        $body = $upstream->getBody();

        return new StreamedResponse(function () use ($body) {
            while (! $body->eof()) {
                echo $body->read(8192);
                flush();
            }
        }, 200, [
            'Content-Type' => $upstream->getHeaderLine('Content-Type') ?: 'application/octet-stream',
            'Content-Length' => $upstream->getHeaderLine('Content-Length') ?: null,
            'Content-Disposition' => $upstream->getHeaderLine('Content-Disposition') ?: null,
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
