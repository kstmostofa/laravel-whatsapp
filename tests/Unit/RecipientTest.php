<?php

namespace Kstmostofa\LaravelWhatsApp\Tests\Unit;

use InvalidArgumentException;
use Kstmostofa\LaravelWhatsApp\Support\Recipient;
use Kstmostofa\LaravelWhatsApp\Tests\TestCase;

class RecipientTest extends TestCase
{
    public function test_normalize_strips_plus_and_punctuation(): void
    {
        $this->assertSame('966512345678', Recipient::normalize('+966 51 234 5678'));
        $this->assertSame('966512345678', Recipient::normalize('+966-(51)-234-5678'));
    }

    public function test_normalize_strips_leading_zeros(): void
    {
        $this->assertSame('966512345678', Recipient::normalize('00966512345678'));
    }

    public function test_normalize_rejects_too_short_input(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Recipient::normalize('123');
    }

    public function test_normalize_rejects_empty_input(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Recipient::normalize('not a number');
    }
}
