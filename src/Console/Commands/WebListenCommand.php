<?php

namespace Kstmostofa\LaravelWhatsApp\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Events\Dispatcher;
use Kstmostofa\LaravelWhatsApp\Web\WebClient;

/**
 * Long-running daemon: opens an HTTP SSE stream to the sidecar's
 * /sessions/{id}/events endpoint, parses each event, looks up the
 * configured Laravel event class (laravel-whatsapp.web.events.*), and
 * dispatches it on the local event bus.
 *
 * Implementation note: PHP's `fopen('http://…', 'r')` buffers the response
 * until EOF, which never happens for SSE — so we use cURL with
 * CURLOPT_WRITEFUNCTION to consume chunks as they arrive.
 *
 * Run under Supervisor / systemd in production.
 */
class WebListenCommand extends Command
{
    protected $signature = 'whatsapp:web:listen
        {session? : Session ID to subscribe to (defaults to laravel-whatsapp.ui.default_session)}
        {--reconnect-delay=2 : Seconds to wait before reconnecting on dropped stream}';

    protected $description = 'Subscribe to a sidecar session\'s event stream and dispatch each event as a Laravel event.';

    protected string $buffer = '';

    protected bool $quit = false;

    /**
     * The sidecar sends an SSE comment every ~20s. If nothing at all arrives
     * for this long the socket is half-open and we reconnect.
     */
    protected const STALL_SECONDS = 60;

    public function handle(WebClient $client, Dispatcher $events): int
    {
        $sessionId = $this->argument('session')
            ?: (string) config('laravel-whatsapp.ui.default_session', 'main');

        if ($sessionId === '') {
            $this->error('No session specified and no default_session configured.');
            $this->line('  Pass one explicitly: <info>php artisan whatsapp:web:listen <session-id></info>');
            $this->line('  Or set: <info>WHATSAPP_UI_DEFAULT_SESSION=main</info> in your .env');

            return self::FAILURE;
        }

        $eventMap = config('laravel-whatsapp.web.events', []);
        $delay = max(1, (int) $this->option('reconnect-delay'));

        $url = sprintf('http://%s:%d/sessions/%s/events',
            $client->host(),
            $client->port(),
            rawurlencode($sessionId),
        );

        $this->info("Subscribing to {$url}");

        $this->trapSignals();

        while (! $this->shouldQuit()) {
            $this->buffer = '';

            $error = $this->consume($url, $client->token(), $sessionId, $eventMap, $events);

            if ($this->shouldQuit()) {
                break;
            }

            if ($error) {
                $this->warn("Stream dropped: {$error}. Reconnecting in {$delay}s…");
            } else {
                $this->warn("Stream closed cleanly. Reconnecting in {$delay}s…");
            }

            for ($i = 0; $i < $delay && ! $this->shouldQuit(); $i++) {
                sleep(1);
            }
        }

        $this->info('Listener stopped.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, class-string>  $eventMap
     * @return string|null  cURL error message if any, null on clean exit
     */
    protected function consume(string $url, ?string $token, string $sessionId, array $eventMap, Dispatcher $events): ?string
    {
        $ch = curl_init($url);

        if (! $ch) {
            return 'curl_init failed';
        }

        $headers = ['Accept: text/event-stream', 'Cache-Control: no-cache'];
        if ($token) {
            $headers[] = 'Authorization: Bearer '.$token;
        }

        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 0,           // no overall timeout — SSE is long-lived
            CURLOPT_CONNECTTIMEOUT => 10,
            // Abort when the stream goes silent — heartbeats keep a healthy
            // stream above this floor, a dead half-open socket drops below it.
            CURLOPT_LOW_SPEED_LIMIT => 1,
            CURLOPT_LOW_SPEED_TIME => self::STALL_SECONDS,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_BUFFERSIZE => 256,
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use ($sessionId, $eventMap, $events) {
                $this->buffer .= $data;

                // Parse one SSE event at a time. An event ends at "\n\n".
                while (($eot = strpos($this->buffer, "\n\n")) !== false) {
                    $raw = substr($this->buffer, 0, $eot);
                    $this->buffer = substr($this->buffer, $eot + 2);

                    [$eventName, $payload] = $this->parseSseEvent($raw);

                    if ($eventName !== null) {
                        $this->dispatchEvent($eventName, $payload, $sessionId, $eventMap, $events);
                    }
                }

                if ($this->shouldQuit()) {
                    return 0; // abort transfer
                }

                return strlen($data);
            },
        ]);

        $ok = curl_exec($ch);
        // Aborting from the write callback on shutdown is not an error.
        $err = curl_errno($ch) && ! $this->quit ? curl_error($ch) : null;
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($ok === false && $err) {
            return $err;
        }

        if ($httpCode >= 400) {
            return "HTTP {$httpCode}";
        }

        return null;
    }

    /**
     * @return array{0: string|null, 1: string}  [eventName, dataJsonString]
     */
    protected function parseSseEvent(string $raw): array
    {
        $eventName = null;
        $data = '';

        foreach (explode("\n", $raw) as $line) {
            $line = rtrim($line, "\r");
            if ($line === '' || str_starts_with($line, ':')) {
                continue;
            }

            if (str_starts_with($line, 'event: ')) {
                $eventName = substr($line, 7);
            } elseif (str_starts_with($line, 'event:')) {
                $eventName = ltrim(substr($line, 6));
            } elseif (str_starts_with($line, 'data: ')) {
                $data .= ($data === '' ? '' : "\n").substr($line, 6);
            } elseif (str_starts_with($line, 'data:')) {
                $data .= ($data === '' ? '' : "\n").ltrim(substr($line, 5));
            }
        }

        return [$eventName, $data];
    }

    /**
     * @param  array<string, class-string>  $eventMap
     */
    protected function dispatchEvent(string $event, string $json, string $sessionId, array $eventMap, Dispatcher $events): void
    {
        $eventClass = $eventMap[$event] ?? null;

        if (! $eventClass || ! class_exists($eventClass)) {
            return;
        }

        $payload = $json !== '' ? json_decode($json, true) : [];
        if (! is_array($payload)) {
            $payload = ['raw' => $json];
        }

        $events->dispatch(new $eventClass($sessionId, $payload));
    }

    /**
     * Stop gracefully on SIGTERM / SIGINT (Supervisor, systemd, Ctrl+C) instead
     * of being SIGKILLed mid-dispatch after the stop timeout.
     */
    protected function trapSignals(): void
    {
        if (! function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);

        foreach ([SIGTERM, SIGINT] as $signal) {
            pcntl_signal($signal, function () {
                $this->quit = true;
            });
        }
    }

    protected function shouldQuit(): bool
    {
        return $this->quit;
    }
}
