<?php

namespace Kstmostofa\LaravelWhatsApp\Tests\Feature;

use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kstmostofa\LaravelWhatsApp\Livewire\ChatHistory;
use Kstmostofa\LaravelWhatsApp\Models\WaMessage;
use Kstmostofa\LaravelWhatsApp\Tests\Support\MocksGuzzle;
use Kstmostofa\LaravelWhatsApp\Tests\TestCase;
use Kstmostofa\LaravelWhatsApp\Web\WebClient;
use Livewire\Livewire;

class HistoryImportTest extends TestCase
{
    use MocksGuzzle;
    use RefreshDatabase;

    protected function history(): array
    {
        return [
            ['id' => 'false_966511111111@c.us_A', 'from' => '966511111111@c.us', 'to' => '966500000000@c.us', 'body' => 'hi', 'type' => 'chat', 'fromMe' => false, 'timestamp' => 1750000000, 'ack' => 3],
            ['id' => 'true_966511111111@c.us_B', 'from' => '966500000000@c.us', 'to' => '966511111111@c.us', 'body' => 'hello', 'type' => 'chat', 'fromMe' => true, 'timestamp' => 1750000060, 'ack' => 3],
        ];
    }

    public function test_sync_command_imports_recent_chats_without_duplicates(): void
    {
        $client = $this->app->make(WebClient::class);
        $chats = [
            ['id' => 'old@c.us', 'timestamp' => 100],
            ['id' => '966511111111@c.us', 'timestamp' => 200],
        ];
        $this->mockGuzzleOn($client, [
            new Response(200, [], json_encode($chats)),
            new Response(200, [], json_encode($this->history())),
            new Response(200, [], json_encode($this->history())),
        ]);

        $this->artisan('whatsapp:web:sync', ['session' => 'main', '--chats' => 1, '--limit' => 30])
            ->expectsOutputToContain('Imported 2 messages from 1 chats.')
            ->assertSuccessful();

        $this->assertStringContainsString('sessions/main/chats/966511111111%40c.us/messages?limit=30', (string) $this->lastRequest()->getUri());

        $this->artisan('whatsapp:web:sync', ['session' => 'main', '--chat' => ['966511111111@c.us']])->assertSuccessful();

        $this->assertSame(2, WaMessage::count());

        $outbound = WaMessage::where('direction', 'outbound')->sole();
        $this->assertSame('966511111111@c.us', $outbound->chat_id);
        $this->assertSame(3, $outbound->ack);
        $this->assertSame('read', $outbound->status);
        $this->assertNull(WaMessage::where('direction', 'inbound')->sole()->ack);
    }

    public function test_chat_screen_can_import_history_for_the_open_chat(): void
    {
        $client = $this->app->make(WebClient::class);
        $this->mockGuzzleOn($client, [
            // First render loads the chat list (then it's cached), then the import.
            new Response(200, [], json_encode([['id' => '966511111111@c.us', 'name' => 'Ali', 'timestamp' => 1]])),
            new Response(200, [], json_encode($this->history())),
        ]);

        Livewire::test(ChatHistory::class, ['session' => 'main'])
            ->set('selectedChat', '966511111111@c.us')
            ->call('importHistory')
            ->assertSet('error', null)
            ->assertSee('hello');

        $this->assertSame(2, WaMessage::count());
    }
}
