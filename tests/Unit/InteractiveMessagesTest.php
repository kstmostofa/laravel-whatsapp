<?php

namespace Kstmostofa\LaravelWhatsApp\Tests\Unit;

use InvalidArgumentException;
use Kstmostofa\LaravelWhatsApp\Events\InteractiveReplied;
use Kstmostofa\LaravelWhatsApp\Facades\WhatsApp;
use Kstmostofa\LaravelWhatsApp\Inbound\IncomingMessage;
use Kstmostofa\LaravelWhatsApp\Tests\TestCase;

class InteractiveMessagesTest extends TestCase
{
    protected function sentInteractive(): array
    {
        $sent = WhatsApp::sent();

        return end($sent)->payload['interactive'];
    }

    public function test_buttons(): void
    {
        WhatsApp::fake();

        WhatsApp::messages()->sendButtons('+966511111111', 'Confirm your order?', ['yes' => 'Yes', 'no' => 'No'], footer: 'Reply within 24h');

        $this->assertSame([
            'type' => 'button',
            'body' => ['text' => 'Confirm your order?'],
            'footer' => ['text' => 'Reply within 24h'],
            'action' => ['buttons' => [
                ['type' => 'reply', 'reply' => ['id' => 'yes', 'title' => 'Yes']],
                ['type' => 'reply', 'reply' => ['id' => 'no', 'title' => 'No']],
            ]],
        ], $this->sentInteractive());
    }

    public function test_too_many_buttons_are_rejected_before_sending(): void
    {
        WhatsApp::fake();

        try {
            WhatsApp::messages()->sendButtons('+966511111111', 'Pick', ['a' => 'A', 'b' => 'B', 'c' => 'C', 'd' => 'D']);
            $this->fail('Expected an exception');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('WhatsApp allows 1–3 buttons; got 4.', $e->getMessage());
        }

        WhatsApp::assertNothingSent();
    }

    public function test_list_with_titles_and_descriptions(): void
    {
        WhatsApp::fake();

        WhatsApp::messages()->sendList('+966511111111', 'Pick a branch', 'Branches', [
            'Riyadh' => ['olaya' => 'Olaya', 'malqa' => ['title' => 'Malqa', 'description' => 'Open late']],
        ], header: 'Our branches');

        $this->assertSame([
            'type' => 'list',
            'header' => ['type' => 'text', 'text' => 'Our branches'],
            'body' => ['text' => 'Pick a branch'],
            'action' => [
                'button' => 'Branches',
                'sections' => [[
                    'title' => 'Riyadh',
                    'rows' => [
                        ['id' => 'olaya', 'title' => 'Olaya'],
                        ['id' => 'malqa', 'title' => 'Malqa', 'description' => 'Open late'],
                    ],
                ]],
            ],
        ], $this->sentInteractive());
    }

    public function test_cta_url_location_request_and_products(): void
    {
        WhatsApp::fake();
        $messages = WhatsApp::messages();

        $messages->sendCtaUrl('+966511111111', 'Track your parcel', 'Track', 'https://shop.test/t/42');
        $this->assertSame(['name' => 'cta_url', 'parameters' => ['display_text' => 'Track', 'url' => 'https://shop.test/t/42']], $this->sentInteractive()['action']);

        $messages->sendLocationRequest('+966511111111', 'Where should we deliver?');
        $this->assertSame('location_request_message', $this->sentInteractive()['type']);
        $this->assertSame(['name' => 'send_location'], $this->sentInteractive()['action']);

        $messages->sendProduct('+966511111111', 'CAT1', 'SKU-9');
        $this->assertSame(['type' => 'product', 'action' => ['catalog_id' => 'CAT1', 'product_retailer_id' => 'SKU-9']], $this->sentInteractive());

        $messages->sendProductList('+966511111111', 'CAT1', 'New in', 'This week', ['Shoes' => ['SKU-1', 'SKU-2']]);
        $this->assertSame(
            [['title' => 'Shoes', 'product_items' => [['product_retailer_id' => 'SKU-1'], ['product_retailer_id' => 'SKU-2']]]],
            $this->sentInteractive()['action']['sections'],
        );
    }

    public function test_flows_navigate_to_a_screen_or_use_data_exchange(): void
    {
        WhatsApp::fake();

        WhatsApp::messages()->sendFlow('+966511111111', 'FLOW1', 'Book now', 'Book a table', screen: 'BOOKING', data: ['guests' => 2], flowToken: 'tok-1');
        $this->assertSame([
            'flow_message_version' => '3',
            'flow_id' => 'FLOW1',
            'flow_cta' => 'Book now',
            'flow_token' => 'tok-1',
            'flow_action' => 'navigate',
            'flow_action_payload' => ['screen' => 'BOOKING', 'data' => ['guests' => 2]],
        ], $this->sentInteractive()['action']['parameters']);

        WhatsApp::messages()->sendFlow('+966511111111', 'FLOW2', 'Start', 'Survey', draft: true);
        $params = $this->sentInteractive()['action']['parameters'];
        $this->assertSame('data_exchange', $params['flow_action']);
        $this->assertSame('draft', $params['mode']);
        $this->assertArrayNotHasKey('flow_action_payload', $params);
    }

    public function test_flow_responses_are_decoded_and_routable(): void
    {
        $event = new InteractiveReplied('111', [
            'from' => '966511111111', 'id' => 'wamid.F', 'type' => 'interactive',
            'interactive' => ['type' => 'nfm_reply', 'nfm_reply' => ['name' => 'flow', 'response_json' => '{"guests":"4","date":"2026-11-20","flow_token":"tok-1"}']],
        ]);

        $this->assertTrue($event->isFlowResponse());
        $this->assertSame('4', $event->flowResponse()['guests']);

        $got = null;
        WhatsApp::router()->onFlow(function (IncomingMessage $m, array $data) use (&$got) {
            $got = $data;
        });
        event($event);

        $this->assertSame('2026-11-20', $got['date']);
    }

    public function test_non_flow_replies_have_no_flow_response(): void
    {
        $event = new InteractiveReplied('111', ['interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'yes']]]);

        $this->assertFalse($event->isFlowResponse());
        $this->assertNull($event->flowResponse());
    }
}
