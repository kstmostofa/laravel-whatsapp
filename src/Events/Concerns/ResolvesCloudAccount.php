<?php

namespace Kstmostofa\LaravelWhatsApp\Events\Concerns;

use Kstmostofa\LaravelWhatsApp\Client\CloudAccounts;

/**
 * For Cloud API events: which configured account (`default`, `sales`, …)
 * the event's phone number belongs to.
 */
trait ResolvesCloudAccount
{
    /**
     * The account name, or null when the number isn't configured.
     *
     *   WhatsApp::cloud($event->account())->messages()->sendText($event->from(), 'Thanks!');
     */
    public function account(): ?string
    {
        return app(CloudAccounts::class)->nameFor($this->phoneNumberId);
    }
}
