<?php

namespace Kstmostofa\LaravelWhatsApp\Livewire;

use Kstmostofa\LaravelWhatsApp\Models\WaMessage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('WhatsApp · Webhooks log')]
#[Layout('laravel-whatsapp::layouts.app')]
class WebhooksLog extends Component
{
    use WithPagination;

    public string $direction = 'all';

    public string $backend = 'all';

    public function render()
    {
        try {
            $query = WaMessage::query()->latest();
            if ($this->direction !== 'all') {
                $query->where('direction', $this->direction);
            }
            if ($this->backend !== 'all') {
                $query->where('backend', $this->backend);
            }
            $messages = $query->paginate(25);
        } catch (\Throwable $e) {
            // Migrations probably haven't run yet — show empty paginator.
            $messages = new \Illuminate\Pagination\LengthAwarePaginator([], 0, 25);
            $error = 'wa_messages table not found. Run `php artisan migrate` after publishing migrations.';
        }

        return view('laravel-whatsapp::livewire.webhooks-log', [
            'messages' => $messages,
            'tableError' => $error ?? null,
        ]);
    }
}
