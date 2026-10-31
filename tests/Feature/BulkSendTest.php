<?php

namespace Kstmostofa\LaravelWhatsApp\Tests\Feature;

use Illuminate\Bus\PendingBatch;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Kstmostofa\LaravelWhatsApp\Exceptions\CloudApiException;
use Kstmostofa\LaravelWhatsApp\Facades\WhatsApp;
use Kstmostofa\LaravelWhatsApp\Jobs\SendBulkMessage;
use Kstmostofa\LaravelWhatsApp\Notifications\WhatsAppMessage;
use Kstmostofa\LaravelWhatsApp\Testing\SentMessage;
use Kstmostofa\LaravelWhatsApp\Tests\TestCase;

class BulkSendTest extends TestCase
{
    public function test_one_job_per_unique_recipient_minus_exceptions(): void
    {
        Queue::fake();

        $count = WhatsApp::bulk(
            ['+966 51 111 1111', '966511111111', '+966522222222', '+966533333333', ''],
            'Sale starts today',
        )->except(['00966533333333'])->dispatch();

        $this->assertSame(2, $count);
        Queue::assertPushed(SendBulkMessage::class, 2);
        Queue::assertPushed(SendBulkMessage::class, fn ($job) => $job->to === '+966 51 111 1111' && $job->message->body === 'Sale starts today');
    }

    public function test_per_recipient_messages_and_routing(): void
    {
        Queue::fake();

        $customers = [
            (object) ['name' => 'Ali', 'phone' => '+966511111111'],
            (object) ['name' => 'Sara', 'phone' => null],
            (object) ['name' => 'Omar', 'phone' => '+966522222222'],
        ];

        WhatsApp::bulk($customers, fn ($c) => "Hi {$c->name}")
            ->routeUsing(fn ($c) => $c->phone)
            ->onQueue('marketing')
            ->dispatch();

        Queue::assertPushedOn('marketing', SendBulkMessage::class, fn ($job) => $job->message->body === 'Hi Omar');
        Queue::assertPushed(SendBulkMessage::class, 2);
    }

    public function test_web_sends_are_spaced_out_and_cloud_sends_are_not(): void
    {
        config([
            'laravel-whatsapp.bulk.web_delay_min' => 5,
            'laravel-whatsapp.bulk.web_delay_max' => 5,
        ]);

        $web = WhatsApp::bulk(['966511111111@c.us', '966522222222@c.us', '966533333333@c.us'], 'hi')->jobs();
        $offsets = array_map(fn ($job) => (int) round(now()->diffInSeconds($job->delay, true)), $web);
        $this->assertSame([0, 5, 10], $offsets);

        $cloud = WhatsApp::bulk(['+966511111111', '+966522222222'], 'hi')->jobs();
        $this->assertNull($cloud[0]->delay);
        $this->assertNull($cloud[1]->delay);
    }

    public function test_jobs_are_rate_limited_per_sender(): void
    {
        [$cloud] = WhatsApp::bulk(['+966511111111'], 'hi')->jobs();
        [$web] = WhatsApp::bulk(['966511111111@c.us'], WhatsAppMessage::create('hi')->viaWeb('sales'))->jobs();

        $this->assertInstanceOf(RateLimited::class, $cloud->middleware()[0]);
        $this->assertStringStartsWith('cloud:', $cloud->rateLimitKey());
        $this->assertSame('web:sales', $web->rateLimitKey());

        $limit = RateLimiter::limiter(SendBulkMessage::LIMITER)($web);
        $this->assertSame(10, $limit->maxAttempts);
        $this->assertSame('web:sales', $limit->key);
    }

    public function test_running_the_jobs_sends_through_the_right_backend(): void
    {
        WhatsApp::fake();
        config(['laravel-whatsapp.bulk.per_minute.cloud' => 1000]);

        WhatsApp::bulk(['+966511111111', '+966522222222'], WhatsAppMessage::create()->template('spring_sale'))->dispatch();

        WhatsApp::assertSentCount(2);
        WhatsApp::assertTemplateSent('spring_sale', '+966511111111');
        WhatsApp::assertTemplateSent('spring_sale', '+966522222222');
        WhatsApp::assertSent(fn (SentMessage $m) => $m->isCloud());
    }

    public function test_batches_can_be_dispatched(): void
    {
        Bus::fake();

        WhatsApp::bulk(['+966511111111', '+966522222222'], 'hi')->dispatchAsBatch('Spring sale');

        Bus::assertBatched(fn (PendingBatch $batch) => $batch->name === 'Spring sale'
            && $batch->jobs->count() === 2
            && $batch->allowsFailures());
    }

    public function test_permanent_cloud_errors_are_recognised(): void
    {
        $this->assertTrue((new CloudApiException('x', 400, ['code' => 131026]))->isPermanent());
        $this->assertFalse((new CloudApiException('x', 500, ['code' => 1]))->isPermanent());
    }
}
