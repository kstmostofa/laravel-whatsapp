<?php

namespace Kstmostofa\LaravelWhatsApp\Tests\Unit;

use Kstmostofa\LaravelWhatsApp\Templates\TemplateDefinition;
use Kstmostofa\LaravelWhatsApp\Tests\TestCase;

class TemplateDefinitionTest extends TestCase
{
    protected function orderShipped(): TemplateDefinition
    {
        return TemplateDefinition::fromArray([
            'name' => 'order_shipped',
            'language' => 'en_US',
            'category' => 'UTILITY',
            'status' => 'APPROVED',
            'components' => [
                ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Order {{1}}'],
                ['type' => 'BODY', 'text' => 'Hi {{1}}, order {{2}} ships {{3}}. Thanks {{1}}!'],
                ['type' => 'FOOTER', 'text' => 'Reply STOP to opt out'],
                ['type' => 'BUTTONS', 'buttons' => [
                    ['type' => 'URL', 'text' => 'Track', 'url' => 'https://shop.test/t/{{1}}'],
                    ['type' => 'QUICK_REPLY', 'text' => 'Thanks'],
                    ['type' => 'COPY_CODE', 'text' => 'Copy code'],
                ]],
            ],
        ]);
    }

    public function test_fields_cover_every_placeholder_once_in_order(): void
    {
        $fields = $this->orderShipped()->fields();

        $this->assertSame(
            ['header__1', 'body__1', 'body__2', 'body__3', 'button__0__1', 'button__2__code'],
            array_column($fields, 'id'),
        );
        $this->assertSame('Body {{2}}', $fields[2]['label']);
        $this->assertSame('"Track" link {{1}}', $fields[4]['label']);
    }

    public function test_components_match_metas_send_format(): void
    {
        $components = $this->orderShipped()->components([
            'header__1' => '#42',
            'body__1' => 'Ali',
            'body__2' => '#42',
            'body__3' => 'today',
            'button__0__1' => 'ABC42',
            'button__2__code' => 'SAVE10',
        ]);

        $this->assertSame([
            ['type' => 'header', 'parameters' => [['type' => 'text', 'text' => '#42']]],
            ['type' => 'body', 'parameters' => [
                ['type' => 'text', 'text' => 'Ali'],
                ['type' => 'text', 'text' => '#42'],
                ['type' => 'text', 'text' => 'today'],
            ]],
            ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => 'ABC42']]],
            ['type' => 'button', 'sub_type' => 'copy_code', 'index' => '2', 'parameters' => [['type' => 'coupon_code', 'coupon_code' => 'SAVE10']]],
        ], $components);
    }

    public function test_preview_fills_values_and_keeps_missing_placeholders(): void
    {
        $preview = $this->orderShipped()->preview(['body__1' => 'Ali', 'body__2' => '#42']);

        $this->assertSame('Order {{1}}', $preview['header']);
        $this->assertSame('Hi Ali, order #42 ships {{3}}. Thanks Ali!', $preview['body']);
        $this->assertSame('Reply STOP to opt out', $preview['footer']);
        $this->assertSame(['Track', 'Thanks', 'Copy code'], $preview['buttons']);
    }

    public function test_missing_lists_empty_fields(): void
    {
        $this->assertSame(
            ['header__1', 'body__3', 'button__0__1', 'button__2__code'],
            $this->orderShipped()->missing(['body__1' => 'Ali', 'body__2' => '#42', 'body__3' => '  ']),
        );
    }

    public function test_named_parameters_and_media_headers(): void
    {
        $template = TemplateDefinition::fromArray([
            'name' => 'welcome',
            'language' => 'ar',
            'components' => [
                ['type' => 'HEADER', 'format' => 'IMAGE'],
                ['type' => 'BODY', 'text' => 'Welcome {{first_name}}'],
            ],
        ]);

        $this->assertSame(['header__media', 'body__first_name'], array_column($template->fields(), 'id'));
        $this->assertSame('welcome|ar', $template->key());
        $this->assertSame([
            ['type' => 'header', 'parameters' => [['type' => 'image', 'image' => ['link' => 'https://x.test/a.jpg']]]],
            ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Sara', 'parameter_name' => 'first_name']]],
        ], $template->components(['header__media' => 'https://x.test/a.jpg', 'body__first_name' => 'Sara']));
        $this->assertSame('[Image]', $template->preview()['header']);
    }

    public function test_templates_without_parameters_send_no_components(): void
    {
        $template = TemplateDefinition::fromArray([
            'name' => 'hello_world',
            'language' => 'en_US',
            'components' => [['type' => 'BODY', 'text' => 'Hello World']],
        ]);

        $this->assertSame([], $template->fields());
        $this->assertSame([], $template->components([]));
    }
}
