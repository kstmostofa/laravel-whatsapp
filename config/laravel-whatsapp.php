<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Meta Graph API connection (Cloud API backend)
    |--------------------------------------------------------------------------
    |
    | Used by WhatsApp::messages(), media(), businessProfile(), phoneNumber(),
    | templates(). Set these for any Cloud API usage. Leave blank to use the
    | Web backend only.
    |
    */

    'base_host' => env('WHATSAPP_BASE_HOST', 'graph.facebook.com'),

    'api_version' => env('WHATSAPP_API_VERSION', 'v21.0'),

    'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),

    'business_account_id' => env('WHATSAPP_BUSINESS_ACCOUNT_ID'),

    'access_token' => env('WHATSAPP_ACCESS_TOKEN'),

    'timeout' => (int) env('WHATSAPP_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Webhook receiver (Cloud API)
    |--------------------------------------------------------------------------
    */

    'webhook' => [
        'enabled' => env('WHATSAPP_WEBHOOK_ENABLED', true),
        'route' => env('WHATSAPP_WEBHOOK_ROUTE', 'webhooks/whatsapp'),
        'middleware' => ['api'],
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
        'app_secret' => env('WHATSAPP_APP_SECRET'),
        'verify_signature' => env('WHATSAPP_VERIFY_SIGNATURE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cloud API event dispatch
    |--------------------------------------------------------------------------
    */

    'events' => [
        'message' => \Kstmostofa\LaravelWhatsApp\Events\MessageReceived::class,
        'status' => \Kstmostofa\LaravelWhatsApp\Events\MessageStatusUpdate::class,
        'interactive' => \Kstmostofa\LaravelWhatsApp\Events\InteractiveReplied::class,
        'media' => \Kstmostofa\LaravelWhatsApp\Events\MediaReceived::class,
        'template_status' => \Kstmostofa\LaravelWhatsApp\Events\TemplateStatusUpdate::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Web backend (whatsapp-web.js sidecar)
    |--------------------------------------------------------------------------
    |
    | The sidecar is a small Node service we ship under sidecar/ that wraps
    | whatsapp-web.js. It gives you the features Cloud API doesn't expose:
    | personal-number QR pairing, groups, free-form messages anytime, etc.
    |
    | `php artisan whatsapp:sidecar:install` runs `npm ci` inside it.
    | `php artisan whatsapp:sidecar:start`   spawns the Node process.
    | `php artisan whatsapp:web:listen`      streams its events into Laravel.
    |
    */

    'web' => [
        'enabled' => env('WHATSAPP_WEB_ENABLED', false),

        // Network coordinates that BOTH the sidecar binds to AND Laravel connects to.
        //
        //   host = the interface the sidecar listens on. Default `127.0.0.1`
        //          (localhost-only — safest, since the sidecar holds your
        //          WhatsApp session). Change to `0.0.0.0` only if you need
        //          to expose it (e.g. Laravel on a different server), and
        //          put a real firewall + WHATSAPP_WEB_TOKEN in front of it.
        //
        //   port = the TCP port. Default 3000 to avoid collisions with the
        //          common Vite dev port (5173) and Laravel's default (8000).
        //          Change both env vars together — they're used for the
        //          sidecar's bind AND Laravel's outbound connection.
        //
        //   NOTE: `host` is unrelated to your APP_URL / Laravel domain. Even
        //   when your Laravel app is served at https://app.example.com, the
        //   sidecar stays on 127.0.0.1 because Laravel reaches it locally.
        //   Only set this to a domain/IP when sidecar and Laravel are on
        //   different machines.
        'host' => env('WHATSAPP_WEB_HOST', '127.0.0.1'),
        'port' => (int) env('WHATSAPP_WEB_PORT', 3000),
        'token' => env('WHATSAPP_WEB_TOKEN'),
        'timeout' => (int) env('WHATSAPP_WEB_TIMEOUT', 60),

        'sidecar' => [
            'path' => env('WHATSAPP_WEB_SIDECAR_PATH', base_path('vendor/kstmostofa/laravel-whatsapp/sidecar')),
            'node_binary' => env('WHATSAPP_WEB_NODE_BINARY', 'node'),
            'npm_binary' => env('WHATSAPP_WEB_NPM_BINARY', 'npm'),
            'session_dir' => env('WHATSAPP_WEB_SESSION_DIR', storage_path('app/whatsapp-sidecar/sessions')),
            'pid_file' => env('WHATSAPP_WEB_PID_FILE', storage_path('app/whatsapp-sidecar/sidecar.pid')),
            'log_file' => env('WHATSAPP_WEB_LOG_FILE', storage_path('logs/whatsapp-sidecar.log')),
            'err_file' => env('WHATSAPP_WEB_ERR_FILE', storage_path('logs/whatsapp-sidecar.err.log')),
        ],

        'events' => [
            'message' => \Kstmostofa\LaravelWhatsApp\Events\Web\MessageReceived::class,
            'ready' => \Kstmostofa\LaravelWhatsApp\Events\Web\SessionReady::class,
            'qr' => \Kstmostofa\LaravelWhatsApp\Events\Web\QrGenerated::class,
            'disconnected' => \Kstmostofa\LaravelWhatsApp\Events\Web\Disconnected::class,
            'message_ack' => \Kstmostofa\LaravelWhatsApp\Events\Web\MessageAck::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Bundled admin UI (Livewire)
    |--------------------------------------------------------------------------
    |
    | If livewire/livewire is installed, the package auto-registers a small
    | admin under `/whatsapp` covering: dashboard, sessions+QR, compose, chats,
    | groups, contacts, webhooks log. Drop `livewire/livewire` and the UI just
    | disappears — the rest of the package keeps working.
    |
    | Wrap the routes in your own auth middleware; the default is `web` only,
    | which is fine for local but NOT for production.
    |
    */

    'ui' => [
        'enabled' => env('WHATSAPP_UI_ENABLED', true),
        'route_prefix' => env('WHATSAPP_UI_PREFIX', 'whatsapp'),
        'middleware' => ['web'],
        'tailwind_cdn' => env('WHATSAPP_UI_TAILWIND_CDN', true),
        'default_session' => env('WHATSAPP_UI_DEFAULT_SESSION', 'main'),
        'poll_interval' => env('WHATSAPP_UI_POLL_INTERVAL', '5s'),

        // How the UI's CSS is delivered to the browser:
        //   'vite'       — (default) call @vite(['resources/css/app.css', 'resources/js/app.js']).
        //                  Requires the host app to have Tailwind v4 + Vite set up, with
        //                  @source paths for vendor/livewire/flux/stubs +
        //                  vendor/kstmostofa/laravel-whatsapp/resources/views in app.css.
        //                  Pro: full theme customization, smallest tree-shaken bundle.
        //   'standalone' — link the pre-compiled stylesheet shipped with the package
        //                  (dist/laravel-whatsapp.css, ~32 KB gzipped). No Tailwind / Vite /
        //                  npm needed on the host. Works whether the host uses Bootstrap,
        //                  plain CSS, or no framework at all. Brand colors baked in.
        //   'none'       — render UI HTML without injecting any CSS link. Use if you want to
        //                  bundle our views' styles into your own pipeline manually.
        'css_mode' => env('WHATSAPP_UI_CSS_MODE', 'vite'),

        // Max number of chats rendered in the conversations list. WhatsApp users
        // often have hundreds — rendering them all would issue hundreds of
        // avatar requests. Most-recent-first, set 0 for unlimited.
        'chat_list_limit' => (int) env('WHATSAPP_UI_CHAT_LIST_LIMIT', 50),

        // Conversation pane: how many messages to load initially per chat, and
        // how many to add per "Load older" click. Stays fast even for chats
        // with hundreds of thousands of rows — query is an indexed range scan
        // on (session_id, chat_id) ORDER BY id DESC LIMIT N.
        'messages_initial' => (int) env('WHATSAPP_UI_MESSAGES_INITIAL', 50),
        'messages_page_size' => (int) env('WHATSAPP_UI_MESSAGES_PAGE_SIZE', 50),

        // How long Laravel caches the sidecar chats/contacts lists.
        // wire:poll fires every 5s so this prevents a sidecar HTTP roundtrip
        // per tick. 0 disables caching.
        'chats_cache_seconds' => (int) env('WHATSAPP_UI_CHATS_CACHE_SECONDS', 3),
        'contacts_cache_seconds' => (int) env('WHATSAPP_UI_CONTACTS_CACHE_SECONDS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Broadcasting (live UI updates via Reverb / Pusher / Ably)
    |--------------------------------------------------------------------------
    |
    | When enabled, the Web events implement ShouldBroadcast so the UI can
    | react instantly to new messages, QR codes, and ack changes via Laravel
    | Echo. When disabled (default), the UI falls back to wire:poll.
    |
    */

    'broadcasting' => [
        'enabled' => env('WHATSAPP_BROADCAST', false),
        'channel_prefix' => env('WHATSAPP_BROADCAST_PREFIX', 'whatsapp'),
        'channel_type' => env('WHATSAPP_BROADCAST_CHANNEL', 'public'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Opt-in persistence — write events to the WA models
    |--------------------------------------------------------------------------
    */

    'persist' => [
        'incoming_messages' => env('WHATSAPP_PERSIST_INCOMING', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Database — connection used by the WaSession / WaMessage / WaContact models
    |--------------------------------------------------------------------------
    |
    | `connection` is a key from `config/database.php`. Leave null to use the
    | app's default. Useful in production if you want to isolate WhatsApp data
    | from your main app DB (e.g. a separate Postgres or a write replica).
    |
    | `prefix` is prepended to every WA table name. Use it if you have to share
    | a database with another app and want to namespace these tables.
    |
    | Both also apply at migration time — published migrations call
    | `Schema::connection(config('laravel-whatsapp.database.connection'))->...` and
    | the table name is `prefix . 'wa_…'`.
    |
    */

    'database' => [
        'connection' => env('WHATSAPP_DB_CONNECTION'),
        'prefix' => env('WHATSAPP_DB_PREFIX', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue used by SendMessage and bulk jobs
    |--------------------------------------------------------------------------
    */

    'queue' => [
        'connection' => env('WHATSAPP_QUEUE_CONNECTION'),
        'queue' => env('WHATSAPP_QUEUE', 'default'),
    ],

];
