<?php

namespace Kstmostofa\LaravelWhatsApp\Webhooks;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies Meta's X-Hub-Signature-256 header against the raw request body using
 * the configured app_secret. Skips verification entirely when `verify_signature`
 * is disabled (useful for local testing with ngrok).
 *
 * Fail-closed: if verification is enabled but APP_SECRET isn't configured, we
 * return 503 rather than letting unverified payloads through. The 503 also
 * triggers Meta to retry the delivery once the secret is set.
 */
class VerifySignatureMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $config = config('laravel-whatsapp.webhook');

        if (! ($config['verify_signature'] ?? true)) {
            return $next($request);
        }

        $secret = $config['app_secret'] ?? null;

        if (! $secret) {
            Log::error('laravel-whatsapp: webhook signature verification enabled but WHATSAPP_APP_SECRET is not set — rejecting inbound webhook');

            return new \Illuminate\Http\Response('service misconfigured: WHATSAPP_APP_SECRET missing', 503);
        }

        $header = $request->header('X-Hub-Signature-256', '');
        if (! str_starts_with($header, 'sha256=')) {
            return new \Illuminate\Http\Response('missing signature', 401);
        }

        $expected = substr($header, 7);
        $computed = hash_hmac('sha256', $request->getContent(), $secret);

        if (! hash_equals($expected, $computed)) {
            return new \Illuminate\Http\Response('invalid signature', 401);
        }

        return $next($request);
    }
}
