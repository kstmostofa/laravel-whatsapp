<?php

namespace Kstmostofa\LaravelWhatsApp\Tests\Feature;

use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kstmostofa\LaravelWhatsApp\Models\WaContact;
use Kstmostofa\LaravelWhatsApp\Tests\Support\MocksGuzzle;
use Kstmostofa\LaravelWhatsApp\Tests\TestCase;
use Kstmostofa\LaravelWhatsApp\Web\WebClient;

class ContactsSyncCommandTest extends TestCase
{
    use MocksGuzzle;
    use RefreshDatabase;

    public function test_contacts_are_upserted_per_session(): void
    {
        $contacts = [
            ['id' => '966512345678@c.us', 'name' => 'Ali', 'pushname' => 'Ali K', 'number' => '966512345678', 'isMyContact' => true, 'isBusiness' => false, 'isBlocked' => false],
            ['id' => '966598765432@c.us', 'name' => null, 'pushname' => 'Shop', 'number' => '966598765432', 'isMyContact' => false, 'isBusiness' => true, 'isBlocked' => false],
            ['name' => 'no id — skipped'],
        ];

        $client = $this->app->make(WebClient::class);
        $this->mockGuzzleOn($client, [
            new Response(200, [], json_encode($contacts)),
            new Response(200, [], json_encode([['id' => '966512345678@c.us', 'name' => 'Ali (renamed)']])),
        ]);

        $this->artisan('whatsapp:contacts:sync', ['session' => 'main'])
            ->expectsOutput('main: synced 2 contacts.')
            ->assertSuccessful();

        $this->assertSame(2, WaContact::count());
        $this->assertTrue(WaContact::where('wa_id', '966598765432@c.us')->sole()->is_business);

        $this->artisan('whatsapp:contacts:sync', ['session' => 'main'])->assertSuccessful();

        $this->assertSame(2, WaContact::count());
        $this->assertSame('Ali (renamed)', WaContact::where('wa_id', '966512345678@c.us')->sole()->name);
        $this->assertStringEndsWith('sessions/main/contacts', (string) $this->lastRequest()->getUri());
    }

    public function test_sidecar_errors_fail_the_command(): void
    {
        $client = $this->app->make(WebClient::class);
        $this->mockGuzzleOn($client, [new Response(409, [], json_encode(['error' => 'session not ready (status: qr)']))]);

        $this->artisan('whatsapp:contacts:sync', ['session' => 'main'])
            ->expectsOutput('main: session not ready (status: qr)')
            ->assertFailed();
    }
}
