<?php

namespace Kstmostofa\LaravelWhatsApp\Broadcasting;

/**
 * One place that knows how the per-session broadcast channel is named, so
 * events, the bundled UI, and channel authorization always agree.
 *
 *   SessionChannel::name('main')   // whatsapp.session.main
 *   SessionChannel::pattern()      // whatsapp.session.{session}  (for Broadcast::channel)
 */
final class SessionChannel
{
    public static function enabled(): bool
    {
        return (bool) config('laravel-whatsapp.broadcasting.enabled', false);
    }

    public static function isPrivate(): bool
    {
        return config('laravel-whatsapp.broadcasting.channel_type', 'public') === 'private';
    }

    public static function prefix(): string
    {
        return (string) config('laravel-whatsapp.broadcasting.channel_prefix', 'whatsapp');
    }

    public static function name(string $sessionId): string
    {
        return self::prefix().'.session.'.$sessionId;
    }

    public static function pattern(): string
    {
        return self::prefix().'.session.{session}';
    }

    /**
     * Livewire listener keys (`echo:` / `echo-private:`) for this session,
     * mapped to component methods. Empty when broadcasting is off, so
     * components fall back to polling.
     *
     * @param  array<string, string>  $events  broadcastAs name => method
     * @return array<string, string>
     */
    public static function livewireListeners(string $sessionId, array $events): array
    {
        if (! self::enabled()) {
            return [];
        }

        $channel = (self::isPrivate() ? 'echo-private:' : 'echo:').self::name($sessionId);
        $listeners = [];

        foreach ($events as $event => $method) {
            // The leading dot tells Echo the name is a custom broadcastAs(),
            // not a class name to prefix with the app namespace.
            $listeners["{$channel},.{$event}"] = $method;
        }

        return $listeners;
    }
}
