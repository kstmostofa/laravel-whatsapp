<?php

namespace Kstmostofa\LaravelWhatsApp\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Kstmostofa\LaravelWhatsApp\Events\MessageReceived as CloudMessageReceived;
use Kstmostofa\LaravelWhatsApp\Events\Web\MessageReceived as WebMessageReceived;
use Kstmostofa\LaravelWhatsApp\Listeners\PersistIncomingMessage;
use Kstmostofa\LaravelWhatsApp\Livewire\Dashboard;
use Kstmostofa\LaravelWhatsApp\Models\WaContact;
use Kstmostofa\LaravelWhatsApp\Models\WaMessage;
use Kstmostofa\LaravelWhatsApp\Support\ContactNames;
use Kstmostofa\LaravelWhatsApp\Tests\TestCase;
use Livewire\Livewire;

class ContactNamesTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_messages_record_the_senders_display_name_and_group_author(): void
    {
        (new PersistIncomingMessage)->handle(new WebMessageReceived('main', ['message' => [
            'id' => 'false_120363000000000001@g.us_A_966511111111@c.us',
            'from' => '120363000000000001@g.us',
            'author' => '966511111111@c.us',
            'notifyName' => 'Ali',
            'type' => 'chat',
            'body' => 'hi all',
        ]]));

        $contact = WaContact::sole();
        $this->assertSame(['main', '966511111111@c.us', 'Ali', '966511111111'], [$contact->session_id, $contact->wa_id, $contact->pushname, $contact->number]);

        $message = WaMessage::sole();
        $this->assertSame('120363000000000001@g.us', $message->chat_id);
        $this->assertSame('966511111111@c.us', $message->from_id);
    }

    public function test_cloud_messages_record_the_profile_name_from_metadata(): void
    {
        (new PersistIncomingMessage)->handle(new CloudMessageReceived('111', [
            'from' => '966511111111', 'id' => 'wamid.X', 'type' => 'text', 'text' => ['body' => 'hi'],
        ], ['contacts' => [['wa_id' => '966511111111', 'profile' => ['name' => 'Sara']]]]));

        $this->assertSame('Sara', WaContact::where('wa_id', '966511111111')->sole()->pushname);
    }

    public function test_names_are_never_blanked_and_saved_names_win(): void
    {
        WaContact::create(['session_id' => 'main', 'wa_id' => '966511111111@c.us', 'name' => 'Ali (Sales)', 'pushname' => 'Ali']);

        (new PersistIncomingMessage)->handle(new WebMessageReceived('main', ['message' => [
            'id' => 'false_966511111111@c.us_B', 'from' => '966511111111@c.us', 'type' => 'chat', 'body' => 'x', 'notifyName' => null,
        ]]));

        $names = app(ContactNames::class);
        $this->assertSame(['966511111111@c.us' => 'Ali (Sales)'], $names->lookup(['966511111111@c.us', '966522222222@c.us']));
        $this->assertSame('Ali (Sales)', $names->label('966511111111@c.us', $names->lookup(['966511111111@c.us'])));
        $this->assertSame('+966522222222', $names->label('966522222222@c.us'));
        $this->assertSame('+966522222222', ContactNames::format('966522222222'));
        $this->assertSame('120363000000000001@g.us', ContactNames::format('120363000000000001@g.us'));
    }

    public function test_dashboard_shows_names_instead_of_numbers(): void
    {
        WaContact::create(['session_id' => 'main', 'wa_id' => '966511111111@c.us', 'pushname' => 'Ali']);
        WaMessage::create(['backend' => 'web', 'session_id' => 'main', 'direction' => 'inbound', 'chat_id' => '966511111111@c.us', 'type' => 'chat', 'body' => 'hello']);

        Livewire::test(Dashboard::class)
            ->assertSee('Ali')
            ->assertSeeHtml('title="966511111111@c.us"');
    }
}
