<?php

namespace Kstmostofa\LaravelWhatsApp\Http\Controllers;

use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the standalone, pre-compiled UI stylesheet that ships with the
 * package at `dist/laravel-whatsapp.css`. Used when the host app sets
 * `WHATSAPP_UI_CSS_MODE=standalone` — for apps that don't have Tailwind v4
 * set up, or use a different CSS framework (Bootstrap, etc.) and don't
 * want to taint their main bundle with our utility classes.
 *
 * Cached aggressively because the file is build-time immutable.
 */
class UiAssetsController extends Controller
{
    public function css(): BinaryFileResponse
    {
        $path = realpath(__DIR__.'/../../../dist/laravel-whatsapp.css');

        abort_unless($path && is_file($path), 404, 'Standalone CSS file not built. Run dist-src/build.sh in the package.');

        return response()->file($path, [
            'Content-Type' => 'text/css; charset=utf-8',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
