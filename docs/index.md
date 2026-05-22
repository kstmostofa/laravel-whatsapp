---
layout: home

hero:
  name: Laravel WhatsApp
  text: Dual-backend WhatsApp integration for Laravel.
  tagline: Meta Cloud API + whatsapp-web.js sidecar, behind a single facade, with a polished Livewire admin UI.
  actions:
    - theme: brand
      text: Get started →
      link: /getting-started
    - theme: alt
      text: Installation
      link: /installation
    - theme: alt
      text: View on GitHub
      link: https://github.com/kstmostofa/laravel-whatsapp

features:
  - icon:
      src: /icons/cloud.svg
      width: 48
      height: 48
    title: Cloud API (Meta)
    details: Templates, media, business profile, phone-number management. Pure PHP, no extra runtime. Webhook receiver with HMAC verification.
    link: /cloud-api
    linkText: Cloud API docs

  - icon:
      src: /icons/device.svg
      width: 48
      height: 48
    title: Web sidecar
    details: Bundled ~300-line Node service around whatsapp-web.js. Personal-number QR pairing, groups, free-form messages anytime, status, contact lookup.
    link: /web-sidecar
    linkText: Sidecar docs

  - icon:
      src: /icons/window.svg
      width: 48
      height: 48
    title: Livewire + Flux admin UI
    details: Drop-in admin at /whatsapp — Dashboard, Sessions+QR, Compose, Conversations with chat-bubble UI, Groups, Contacts, Webhooks log, Status. Light + dark mode.
    link: /ui
    linkText: UI docs

  - icon:
      src: /icons/bolt.svg
      width: 48
      height: 48
    title: Works with any CSS framework
    details: Tailwind v4 (full theming) OR pre-compiled standalone CSS (~32 KB gz) that works on top of Bootstrap, plain CSS, or no framework at all.
    link: /ui#install-paths

  - icon:
      src: /icons/puzzle.svg
      width: 48
      height: 48
    title: Truly headless option
    details: "Skip the UI entirely. Full access to the WhatsApp:: facade, Events, queued Jobs, webhook receiver, and CLI commands. Build your own UI in any stack."

  - icon:
      src: /icons/server.svg
      width: 48
      height: 48
    title: Production-ready
    details: Separate DB connection, table prefix, indexed message queries, server-side caching, smart auto-scroll, supervised SSE listener. 46 tests, supports Laravel 11 / 12 / 13.
    link: /production

  - icon:
      src: /icons/heart-pulse.svg
      width: 48
      height: 48
    title: Health monitoring
    details: "Built-in /whatsapp/status page + `php artisan whatsapp:health --json --exit-code` for CI and external monitors. Caches probes to avoid hammering Meta or the sidecar."
    link: /api/sidecar

  - icon:
      src: /icons/chat-bubble.svg
      width: 48
      height: 48
    title: Rich messages
    details: Send text, templates, images, videos, audio, documents, stickers, location, contacts, reactions, interactive (buttons/lists). Edit and delete-for-everyone. Inline image previews + audio player in the UI.
---

<style>
:root {
  --vp-c-brand-1: #00A884;
  --vp-c-brand-2: #128C7E;
  --vp-c-brand-3: #075E54;
  --vp-c-brand-soft: rgba(0, 168, 132, 0.14);
}
.dark {
  --vp-c-brand-1: #25D366;
}
</style>

## At a glance

```php
use Kstmostofa\LaravelWhatsApp\Facades\WhatsApp;

// One-line send — auto-picks Cloud or Web by recipient shape
WhatsApp::send('+9665XXXXXXXX', 'Hello');                       // Cloud API
WhatsApp::send('966512345678@c.us', 'Hello via personal number'); // Web sidecar

// Cloud API templates
WhatsApp::messages()->sendTemplate('+9665XXXXXXXX', 'order_ready', 'en_US', [
    ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Munir']]],
]);

// Web sidecar — full personal-account access
WhatsApp::web('main')->groups()->create('Project X', ['9665XXXXXXXX@c.us']);
WhatsApp::web('main')->messages()->sendImage('966...@c.us', ['url' => '…', 'caption' => 'Hi']);

// Queue it
SendMessage::dispatch('+9665XXXXXXXX', 'Queued hi');

// React to inbound events
Event::listen(\Kstmostofa\LaravelWhatsApp\Events\Web\MessageReceived::class, function ($event) {
    Log::info($event->from() . ' said: ' . $event->body());
});
```

## When to use which backend

| Feature | Cloud API | Web sidecar |
|---|---|---|
| Personal number pairing | ❌ | ✅ |
| Group chats | ❌ | ✅ |
| Status / Stories | ❌ | ✅ |
| Free-form messages anytime | ❌ (templates outside 24 h window) | ✅ |
| Approved business templates | ✅ | ❌ |
| Official Meta support, no ban risk | ✅ | ❌ |
| Scales to millions | ✅ | ⚠️ session-bound |
| No extra runtime | ✅ | ❌ (Node + Chromium) |

Most apps use **both** — see [getting started](/getting-started) to install in 5 minutes.
