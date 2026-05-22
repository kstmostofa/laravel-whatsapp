<?php

namespace Kstmostofa\LaravelWhatsApp\Webhooks;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class WebhookController extends Controller
{
    public function __construct(protected Dispatcher $events)
    {
    }

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
     * Live webhook delivery. Meta retries on non-2xx responses, so we ALWAYS
     * return 200 once we've parsed the payload and dispatched events — the
     * downstream listeners can fail or queue without re-triggering Meta.
     */
    public function receive(Request $request): Response
    {
        $payload = $request->all();

        foreach (PayloadParser::events($payload) as $event) {
            $eventClass = config("laravel-whatsapp.events.{$event['kind']}");

            if ($eventClass && class_exists($eventClass)) {
                $this->events->dispatch(new $eventClass(
                    $event['phone_number_id'],
                    $event['payload'],
                    $event['metadata'] ?? [],
                ));
            }
        }

        return new Response('', 200);
    }
}
