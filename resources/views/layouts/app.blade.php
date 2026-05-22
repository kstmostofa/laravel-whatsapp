<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'WhatsApp' }}</title>

    @php
        $cssMode = config('laravel-whatsapp.ui.css_mode', 'vite');
    @endphp
    @if ($cssMode === 'vite')
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @elseif ($cssMode === 'standalone')
        <link rel="stylesheet" href="{{ route('whatsapp.ui.assets.css') }}">
    @endif
    @fluxAppearance
    @livewireStyles
    <style>[x-cloak]{display:none!important}</style>
</head>
<body class="min-h-screen bg-zinc-50 dark:bg-wa-canvas-dark">

@php
    $prefix = config('laravel-whatsapp.ui.route_prefix', 'whatsapp');
@endphp

<flux:header sticky class="bg-white dark:bg-wa-surface-dark border-b border-zinc-200 dark:border-zinc-700">
    <flux:container class="!max-w-screen-2xl flex items-center gap-6 py-0">

        {{-- Brand --}}
        <flux:brand
            :href="url($prefix)"
            name="WhatsApp"
            class="dark:hidden"
        >
            <x-slot name="logo">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-wa-500 text-white">
                    <flux:icon.chat-bubble-oval-left variant="solid" class="size-5" />
                </div>
            </x-slot>
        </flux:brand>
        <flux:brand
            :href="url($prefix)"
            name="WhatsApp"
            class="hidden dark:flex"
        >
            <x-slot name="logo">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-wa-600 text-white">
                    <flux:icon.chat-bubble-oval-left variant="solid" class="size-5" />
                </div>
            </x-slot>
        </flux:brand>

        {{-- Primary nav --}}
        <flux:navbar class="-mb-px max-lg:hidden">
            <flux:navbar.item icon="squares-2x2"            :href="url($prefix)"             :current="request()->path() === $prefix">Overview</flux:navbar.item>
            <flux:navbar.item icon="device-phone-mobile"    :href="url($prefix.'/sessions')" :current="request()->is($prefix.'/sessions*')">Sessions</flux:navbar.item>
            <flux:navbar.item icon="pencil-square"          :href="url($prefix.'/compose')"  :current="request()->is($prefix.'/compose*')">Compose</flux:navbar.item>
            <flux:navbar.item icon="chat-bubble-left-right" :href="url($prefix.'/chats')"    :current="request()->is($prefix.'/chats*')">Conversations</flux:navbar.item>
            <flux:navbar.item icon="user-group"             :href="url($prefix.'/groups')"   :current="request()->is($prefix.'/groups*')">Groups</flux:navbar.item>
            <flux:navbar.item icon="users"                  :href="url($prefix.'/contacts')" :current="request()->is($prefix.'/contacts*')">Contacts</flux:navbar.item>
            <flux:navbar.item icon="bolt"                   :href="url($prefix.'/webhooks')" :current="request()->is($prefix.'/webhooks*')">Webhooks</flux:navbar.item>
            <flux:navbar.item icon="heart"                  :href="url($prefix.'/status')"   :current="request()->is($prefix.'/status*')">Status</flux:navbar.item>
        </flux:navbar>

        {{-- Mobile nav --}}
        <flux:dropdown class="lg:hidden">
            <flux:button icon="bars-2" variant="ghost" size="sm" />
            <flux:navmenu>
                <flux:navmenu.item icon="squares-2x2"            :href="url($prefix)">Overview</flux:navmenu.item>
                <flux:navmenu.item icon="device-phone-mobile"    :href="url($prefix.'/sessions')">Sessions</flux:navmenu.item>
                <flux:navmenu.item icon="pencil-square"          :href="url($prefix.'/compose')">Compose</flux:navmenu.item>
                <flux:navmenu.item icon="chat-bubble-left-right" :href="url($prefix.'/chats')">Conversations</flux:navmenu.item>
                <flux:navmenu.item icon="user-group"             :href="url($prefix.'/groups')">Groups</flux:navmenu.item>
                <flux:navmenu.item icon="users"                  :href="url($prefix.'/contacts')">Contacts</flux:navmenu.item>
                <flux:navmenu.item icon="bolt"                   :href="url($prefix.'/webhooks')">Webhooks</flux:navmenu.item>
                <flux:navmenu.item icon="heart"                  :href="url($prefix.'/status')">Status</flux:navmenu.item>
            </flux:navmenu>
        </flux:dropdown>

        <flux:spacer />

        {{-- Always-visible health indicator --}}
        <livewire:whatsapp.status-indicator />


        {{-- Theme toggle — icon-only, swaps to reflect current appearance --}}
        <div x-data="{ value: $flux.appearance }">
            <flux:dropdown position="bottom" align="end">
                <flux:tooltip content="Theme">
                    <flux:button variant="ghost" size="sm" aria-label="Theme" class="!px-2">
                        <flux:icon.sun              x-show="value === 'light'"  x-cloak class="size-5" />
                        <flux:icon.moon             x-show="value === 'dark'"   x-cloak class="size-5" />
                        <flux:icon.computer-desktop x-show="value === 'system'" x-cloak class="size-5" />
                    </flux:button>
                </flux:tooltip>
                <flux:menu>
                    <flux:menu.radio.group
                        x-model="value"
                        x-on:change="$flux.appearance = value">
                        <flux:menu.radio value="light"  icon="sun">Light</flux:menu.radio>
                        <flux:menu.radio value="dark"   icon="moon">Dark</flux:menu.radio>
                        <flux:menu.radio value="system" icon="computer-desktop">System</flux:menu.radio>
                    </flux:menu.radio.group>
                </flux:menu>
            </flux:dropdown>
        </div>

        {{-- CTA --}}
        <flux:button :href="url($prefix.'/sessions')" variant="primary" size="sm" icon="plus" class="!bg-wa-600 hover:!bg-wa-700">
            New session
        </flux:button>

    </flux:container>
</flux:header>

<flux:main container class="!max-w-screen-2xl">
    {{ $slot }}
</flux:main>

@fluxScripts
@livewireScripts
</body>
</html>
