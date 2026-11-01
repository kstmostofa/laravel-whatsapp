<?php

namespace Kstmostofa\LaravelWhatsApp\Webhooks;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Kstmostofa\LaravelWhatsApp\Models\WaWebhookEvent;

class WebhookController extends Controller
{
    public function __construct(protected WebhookDispatcher $dispatcher) {}

    /**
     * One-time verification handshake during webhook setup in Meta's dashboard.
     * Meta sends: ?hub.mode=subscribe&hub.verify_token=X&hub.challenge=Y
     * If X matches our configured verify_token, we echo Y back verbatim.
     */
    public function verify(Request $request): Response
    {
        $mode = $request->query('hub_mode') ?? $request->query('hub.mode');
        $token = $request->query('hub_verify_token') ?? $request->query('hub.verify_token');
        $challenge = $request->query('hub_challenge') ?? $request->query('hub.challenge');

        $expected = config('laravel-whatsapp.webhook.verify_token');

        if ($mode === 'subscribe' && $token !== null && $expected !== null && hash_equals((string) $expected, (string) $token)) {
            return new Response((string) $challenge, 200, ['Content-Type' => 'text/plain']);
        }

        return new Response('forbidden', 403);
    }

    /**
     * Live webhook delivery.
     *
     * Without the event log, a listener exception returns 500 and Meta
     * retries the delivery later. With the log on, the delivery is stored
     * first; a listener failure marks it `failed` and still returns 200,
     * because you can replay it once the bug is fixed — and a Meta retry
     * would re-run the listeners that did succeed.
     */
    public function receive(Request $request): Response
    {
        $payload = $request->all();

        if (! $this->logging()) {
            $this->dispatcher->dispatch($payload);

            return new Response('', 200);
        }

        try {
            $record = WaWebhookEvent::create([
                'backend' => 'cloud',
                'payload' => $payload,
                'status' => WaWebhookEvent::STATUS_PENDING,
            ]);
        } catch (\Throwable) {
            // Log table missing — fall back to plain dispatch rather than drop the delivery.
            $this->dispatcher->dispatch($payload);

            return new Response('', 200);
        }

        $this->dispatcher->process($record);

        return new Response('', 200);
    }

    protected function logging(): bool
    {
        return (bool) config('laravel-whatsapp.webhook.log', false);
    }
}
