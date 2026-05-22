<?php

namespace Kstmostofa\LaravelWhatsApp\Livewire;

use Illuminate\Support\Facades\Cache;
use Kstmostofa\LaravelWhatsApp\Exceptions\SidecarException;
use Kstmostofa\LaravelWhatsApp\Web\WebClient;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Title('WhatsApp · Contacts')]
#[Layout('laravel-whatsapp::layouts.app')]
class ContactsIndex extends Component
{
    public string $session;

    #[Validate('nullable|string|max:128')]
    public string $search = '';

    #[Validate('nullable|string|max:64')]
    public string $existsCheck = '';

    public ?array $existsResult = null;

    public ?string $error = null;

    public function mount(?string $session = null): void
    {
        $this->session = $session ?? config('laravel-whatsapp.ui.default_session', 'main');
    }

    public function checkExists(): void
    {
        $this->error = null;
        $this->existsResult = null;

        try {
            $this->existsResult = app(WebClient::class)
                ->session($this->session)
                ->contacts()
                ->exists($this->existsCheck);
        } catch (SidecarException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function render()
    {
        $contacts = [];
        try {
            // Cache the sidecar contacts list — for users with thousands of
            // contacts this list is a large payload and slow to fetch.
            $all = Cache::remember(
                "laravel-whatsapp:contacts:{$this->session}",
                (int) config('laravel-whatsapp.ui.contacts_cache_seconds', 30),
                fn () => app(WebClient::class)->session($this->session)->contacts()->all(),
            );
            $contacts = collect($all);
            if ($this->search !== '') {
                $needle = mb_strtolower($this->search);
                $contacts = $contacts->filter(function ($c) use ($needle) {
                    foreach (['name', 'pushname', 'number', 'id'] as $field) {
                        if (str_contains(mb_strtolower((string) ($c[$field] ?? '')), $needle)) {
                            return true;
                        }
                    }

                    return false;
                });
            }
            $contacts = $contacts->take(200)->values()->all();
        } catch (SidecarException $e) {
            $this->error = $this->error ?? $e->getMessage();
        }

        return view('laravel-whatsapp::livewire.contacts-index', compact('contacts'));
    }
}
