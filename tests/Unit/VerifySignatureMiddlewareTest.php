<?php

declare(strict_types=1);

namespace Kstmostofa\LaravelWhatsApp\Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Kstmostofa\LaravelWhatsApp\Tests\TestCase;
use Kstmostofa\LaravelWhatsApp\Webhooks\VerifySignatureMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;

class VerifySignatureMiddlewareTest extends TestCase
{
    private const BODY = '{"object":"whatsapp_business_account","entry":[]}';

    private function handle(?string $signature): int
    {
        $server = $signature === null ? [] : ['HTTP_X_HUB_SIGNATURE_256' => $signature];
        $request = Request::create('/webhooks/whatsapp', 'POST', server: $server, content: self::BODY);

        return (new VerifySignatureMiddleware)
            ->handle($request, fn () => new Response('passed', 200))
            ->getStatusCode();
    }

    private function sign(string $secret): string
    {
        return 'sha256='.hash_hmac('sha256', self::BODY, $secret);
    }

    public function test_valid_signature_passes(): void
    {
        $this->assertSame(200, $this->handle($this->sign('TEST_SECRET')));
    }

    /**
     * @return array<string, array{0: ?string}>
     */
    public static function badSignatures(): array
    {
        return [
            'missing header' => [null],
            'no sha256= prefix' => [hash_hmac('sha256', self::BODY, 'TEST_SECRET')],
            'signed with another secret' => ['sha256='.hash_hmac('sha256', self::BODY, 'OTHER_SECRET')],
            'signature of another body' => ['sha256='.hash_hmac('sha256', '{}', 'TEST_SECRET')],
        ];
    }

    #[DataProvider('badSignatures')]
    public function test_bad_signature_is_rejected(?string $signature): void
    {
        $this->assertSame(401, $this->handle($signature));
    }

    public function test_missing_app_secret_fails_closed(): void
    {
        config(['laravel-whatsapp.webhook.app_secret' => null]);

        $this->assertSame(503, $this->handle($this->sign('')));
    }

    public function test_disabled_verification_lets_unsigned_requests_through(): void
    {
        config(['laravel-whatsapp.webhook.verify_signature' => false]);

        $this->assertSame(200, $this->handle(null));
    }
}
