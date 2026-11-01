<?php

namespace Kstmostofa\LaravelWhatsApp\Tests\Feature;

use GuzzleHttp\Psr7\Response;
use Kstmostofa\LaravelWhatsApp\Client\CloudClient;
use Kstmostofa\LaravelWhatsApp\Events\TemplateStatusUpdate;
use Kstmostofa\LaravelWhatsApp\Livewire\Compose;
use Kstmostofa\LaravelWhatsApp\Templates\TemplateCatalog;
use Kstmostofa\LaravelWhatsApp\Tests\Support\MocksGuzzle;
use Kstmostofa\LaravelWhatsApp\Tests\TestCase;
use Livewire\Livewire;

class ComposeTemplateTest extends TestCase
{
    use MocksGuzzle;

    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);
        $app['config']->set('laravel-whatsapp.phone_number_id', '111');
        $app['config']->set('laravel-whatsapp.business_account_id', '999');
    }

    protected function templatesResponse(): Response
    {
        return new Response(200, [], json_encode(['data' => [
            [
                'name' => 'order_ready', 'language' => 'en_US', 'status' => 'APPROVED', 'category' => 'UTILITY',
                'components' => [['type' => 'BODY', 'text' => 'Hi {{1}}, order {{2}} is ready.']],
            ],
            [
                'name' => 'hello_world', 'language' => 'en_US', 'status' => 'APPROVED',
                'components' => [['type' => 'BODY', 'text' => 'Hello World']],
            ],
        ]]));
    }

    public function test_picking_a_template_shows_fields_and_preview_and_sends_parameters(): void
    {
        $this->mockGuzzleOn($this->app->make(CloudClient::class), [
            $this->templatesResponse(),
            new Response(200, [], json_encode(['messages' => [['id' => 'wamid.X']]])),
        ]);

        Livewire::test(Compose::class)
            ->set('backend', 'cloud')
            ->set('to', '+966512345678')
            ->set('type', 'template')
            ->assertSee('order_ready · en_US · utility')
            ->set('templateKey', 'order_ready|en_US')
            ->assertSet('templateName', 'order_ready')
            ->assertSee('Body {{2}}')
            ->set('templateParams.body__1', 'Ali')
            ->set('templateParams.body__2', '#42')
            ->assertSee('Hi Ali, order #42 is ready.')
            ->call('send')
            ->assertSet('error', null);

        $listRequest = $this->allRequests()[0];
        $this->assertStringContainsString('999/message_templates', (string) $listRequest->getUri());
        $this->assertStringContainsString('status=APPROVED', (string) $listRequest->getUri());

        $sent = json_decode((string) $this->lastRequest()->getBody(), true);
        $this->assertSame('order_ready', $sent['template']['name']);
        $this->assertSame([['type' => 'body', 'parameters' => [
            ['type' => 'text', 'text' => 'Ali'],
            ['type' => 'text', 'text' => '#42'],
        ]]], $sent['template']['components']);
    }

    public function test_empty_template_fields_block_the_send(): void
    {
        $this->mockGuzzleOn($this->app->make(CloudClient::class), [$this->templatesResponse()]);

        Livewire::test(Compose::class)
            ->set('backend', 'cloud')
            ->set('to', '+966512345678')
            ->set('type', 'template')
            ->set('templateKey', 'order_ready|en_US')
            ->set('templateParams.body__1', 'Ali')
            ->call('send')
            ->assertSet('error', 'Fill in every template field: Body {{2}}.');

        $this->assertCount(1, $this->allRequests()); // only the template list, no send
    }

    public function test_catalog_failure_falls_back_to_typing_the_name(): void
    {
        $this->mockGuzzleOn($this->app->make(CloudClient::class), [
            new Response(400, [], json_encode(['error' => ['message' => 'Invalid OAuth access token', 'code' => 190]])),
        ]);

        Livewire::test(Compose::class)
            ->set('backend', 'cloud')
            ->set('type', 'template')
            ->assertSee("Couldn't load templates from Meta: Invalid OAuth access token", false)
            ->assertSee('Template name');
    }

    public function test_template_status_webhook_flushes_the_cached_list(): void
    {
        $this->mockGuzzleOn($this->app->make(CloudClient::class), [$this->templatesResponse(), $this->templatesResponse()]);
        $catalog = $this->app->make(TemplateCatalog::class);

        $catalog->approved();
        $catalog->approved();
        $this->assertCount(1, $this->allRequests());

        event(new TemplateStatusUpdate('111', ['event' => 'APPROVED', 'message_template_name' => 'new_one']));

        $catalog->approved();
        $this->assertCount(2, $this->allRequests());
    }
}
