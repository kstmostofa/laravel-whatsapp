<?php

namespace Kstmostofa\LaravelWhatsApp\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use Kstmostofa\LaravelWhatsApp\Client\CloudClient;
use Kstmostofa\LaravelWhatsApp\Exceptions\CloudApiException;
use Kstmostofa\LaravelWhatsApp\Tests\Support\MocksGuzzle;
use Kstmostofa\LaravelWhatsApp\Tests\TestCase;

class CloudErrorTest extends TestCase
{
    use MocksGuzzle;

    public function test_4xx_response_throws_cloud_api_exception_with_meta_payload(): void
    {
        $client = $this->app->make(CloudClient::class);
        $this->mockGuzzleOn($client, [
            new Response(400, [], json_encode([
                'error' => [
                    'message' => 'Recipient phone number not in allowed list',
                    'type' => 'OAuthException',
                    'code' => 100,
                    'error_subcode' => 33,
                ],
            ])),
        ]);

        try {
            $client->messages()->sendText('+966512345678', 'hi');
            $this->fail('Expected CloudApiException');
        } catch (CloudApiException $e) {
            $this->assertSame('Recipient phone number not in allowed list', $e->getMessage());
            $this->assertSame(400, $e->getCode());
            $this->assertSame(100, $e->metaErrorCode());
            $this->assertSame(33, $e->metaErrorSubcode());
            $this->assertSame('OAuthException', $e->metaErrorType());
        }
    }
}
