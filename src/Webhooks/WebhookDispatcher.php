<?php

namespace Kstmostofa\LaravelWhatsApp\Webhooks;

use Illuminate\Contracts\Events\Dispatcher;
use Kstmostofa\LaravelWhatsApp\Models\WaWebhookEvent;
use Throwable;

/**
 * Turns a Meta webhook payload into Laravel events. Shared by the live
 * webhook route and by replays of logged deliveries.
 */
class WebhookDispatcher
{
    public function __construct(protected Dispatcher $events)
    {
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return int number of events dispatched
     */
    public function dispatch(array $payload): int
    {
        $count = 0;

        foreach (PayloadParser::events($payload) as $event) {
            $eventClass = config("laravel-whatsapp.events.{$event['kind']}");

            if ($eventClass && class_exists($eventClass)) {
                $this->events->dispatch(new $eventClass(
                    $event['phone_number_id'],
                    $event['payload'],
                    $event['metadata'],
                ));
                $count++;
            }
        }

        return $count;
    }

    /**
     * Run a logged delivery (again) and record the outcome on the row.
     * Never throws: a failure is stored as `failed` with the error message.
     */
    public function process(WaWebhookEvent $record): bool
    {
        $record->attempts++;

        try {
            $record->event_count = $this->dispatch($record->payload ?? []);
            $record->status = WaWebhookEvent::STATUS_PROCESSED;
            $record->error = null;
            $record->processed_at = now();
            $record->save();

            return true;
        } catch (Throwable $e) {
            $record->status = WaWebhookEvent::STATUS_FAILED;
            $record->error = $e::class.': '.$e->getMessage();
            $record->save();

            report($e);

            return false;
        }
    }
}
