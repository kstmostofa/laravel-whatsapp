<?php

declare(strict_types=1);

namespace Kstmostofa\LaravelWhatsApp\Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Kstmostofa\LaravelWhatsApp\Tests\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Blade does not compile directives inside component attributes, so
 * `<flux:button wire:click="manage(@js($id))">` reaches the browser as the
 * literal text `@js($id)` and Livewire throws a SyntaxError on click. Use
 * `{{ \Illuminate\Support\Js::from($id) }}` there instead.
 */
class BladeDirectivesTest extends TestCase
{
    public function test_no_view_leaves_a_js_directive_uncompiled(): void
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../resources/views'));
        $uncompiled = [];

        foreach ($files as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $compiled = Blade::compileString((string) file_get_contents($file->getPathname()));

            if (preg_match_all('/@js(on)?\(/', $compiled, $matches) > 0) {
                $uncompiled[] = str_replace(realpath(__DIR__.'/../..').'/', '', (string) $file->getRealPath());
            }
        }

        $this->assertSame([], $uncompiled, 'These views use @js or @json inside a component attribute, where Blade leaves it as text.');
    }
}
