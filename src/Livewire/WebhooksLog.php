<?php

namespace Kstmostofa\LaravelWhatsApp\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Kstmostofa\LaravelWhatsApp\Models\WaMessage;
use Kstmostofa\LaravelWhatsApp\Models\WaWebhookEvent;
use Kstmostofa\LaravelWhatsApp\Webhooks\WebhookDispatcher;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('WhatsApp · Webhooks log')]
#[Layout('laravel-whatsapp::layouts.app')]
class WebhooksLog extends Component
{
    use WithPagination;

    /** 'messages' (persisted messages) or 'events' (raw webhook deliveries). */
    #[Url]
    #[Validate('in:messages,events')]
    public string $view = 'messages';

    public string $direction = 'all';

    public string $backend = 'all';

    /** Raw events filter: all | pending | processed | failed. */
    public string $eventStatus = 'all';

    /** Webhook event whose payload is open in the modal. */
    public ?int $inspecting = null;

    public bool $showPayload = false;

    public ?string $notice = null;

    public function updated(string $property): void
    {
        if (in_array($property, ['view', 'direction', 'backend', 'eventStatus'], true)) {
            $this->resetPage('messagesPage');
            $this->resetPage('eventsPage');
        }
    }

    public function inspect(int $id): void
    {
        $this->inspecting = $id;
        $this->showPayload = true;
    }

    public function closeInspect(): void
    {
        $this->inspecting = null;
        $this->showPayload = false;
    }

    public function replay(int $id): void
    {
        $record = WaWebhookEvent::find($id);

        if (! $record) {
            return;
        }

        $this->notice = app(WebhookDispatcher::class)->process($record)
            ? "Replayed #{$id}: {$record->event_count} event(s) dispatched."
            : "#{$id} failed again: {$record->error}";
    }

    public function replayFailed(): void
    {
        $dispatcher = app(WebhookDispatcher::class);
        $ok = $failed = 0;

        WaWebhookEvent::query()->failed()->orderBy('id')->each(function (WaWebhookEvent $record) use ($dispatcher, &$ok, &$failed) {
            $dispatcher->process($record) ? $ok++ : $failed++;
        });

        $this->notice = "Replayed {$ok} failed deliveries, {$failed} still failing.";
    }

    /**
     * @return View
     */
    public function render()
    {
        $tableError = null;
        $messages = new LengthAwarePaginator([], 0, 25);
        $events = new LengthAwarePaginator([], 0, 25);
        $failedCount = 0;
        $inspected = null;

        try {
            if ($this->view === 'events') {
                $query = WaWebhookEvent::query()->latest('id');
                if ($this->eventStatus !== 'all') {
                    $query->where('status', $this->eventStatus);
                }
                $events = $query->paginate(25, pageName: 'eventsPage');
                $failedCount = WaWebhookEvent::query()->failed()->count();
                $inspected = $this->inspecting ? WaWebhookEvent::find($this->inspecting) : null;
            } else {
                $query = WaMessage::query()->latest();
                if ($this->direction !== 'all') {
                    $query->where('direction', $this->direction);
                }
                if ($this->backend !== 'all') {
                    $query->where('backend', $this->backend);
                }
                $messages = $query->paginate(25, pageName: 'messagesPage');
            }
        } catch (\Throwable) {
            // Migrations probably haven't run yet.
            $tableError = $this->view === 'events'
                ? 'wa_webhook_events table not found. Publish and run the package migrations.'
                : 'wa_messages table not found. Run `php artisan migrate` after publishing migrations.';
        }

        return view('laravel-whatsapp::livewire.webhooks-log', [
            'messages' => $messages,
            'events' => $events,
            'failedCount' => $failedCount,
            'inspected' => $inspected,
            'tableError' => $tableError,
            'logEnabled' => (bool) config('laravel-whatsapp.webhook.log', false),
        ]);
    }
}
