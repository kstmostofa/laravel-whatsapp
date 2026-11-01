<?php

namespace Kstmostofa\LaravelWhatsApp\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Kstmostofa\LaravelWhatsApp\Facades\WhatsApp;
use Kstmostofa\LaravelWhatsApp\Livewire\Compose;
use Kstmostofa\LaravelWhatsApp\Livewire\SessionsIndex;
use Kstmostofa\LaravelWhatsApp\Models\WaMessage;
use Kstmostofa\LaravelWhatsApp\Tests\TestCase;
use Livewire\Livewire;

/**
 * `dist/laravel-whatsapp.css` is compiled once and shipped, for apps using
 * WHATSAPP_UI_CSS_MODE=standalone. If Flux (or one of our views) starts
 * using a class the file doesn't contain, the UI breaks in that mode: in
 * 1.2.0, Flux 2.21's card classes were missing, so cards drew black borders
 * and an unstyled ::after layer swallowed every click.
 *
 * This renders the pages and fails on any class without a rule. When it
 * fails, rebuild the stylesheet:
 *
 *   LARAVEL_WHATSAPP_BUILD_APP=/path/to/laravel-app-with-flux ./dist-src/build.sh
 */
class StandaloneCssCoverageTest extends TestCase
{
    use RefreshDatabase;

    /** Classes that are markers for other selectors and never get rules of their own. */
    protected const MARKERS = '/^(group|peer)(\/[\w-]+)?$/';

    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);
        $app['config']->set('laravel-whatsapp.ui.css_mode', 'standalone');
    }

    public function test_every_class_the_ui_renders_exists_in_the_standalone_stylesheet(): void
    {
        WhatsApp::fake(); // no sidecar or Meta calls; lists come back empty
        $this->seedConversation();

        $html = [];

        foreach (['', 'sessions', 'compose', 'groups', 'contacts', 'webhooks', 'webhooks?view=events', 'status'] as $page) {
            $html[] = $this->get('/whatsapp/'.$page)->assertOk()->getContent();
        }

        // A conversation with every bubble variant.
        $html[] = $this->get('/whatsapp/chats/main?chat=966511111111@c.us')->assertOk()->getContent();

        // States that only exist after interaction.
        $html[] = Livewire::test(Compose::class)->set('type', 'image')->html();
        $html[] = Livewire::test(Compose::class)->set('type', 'template')->html();
        $html[] = Livewire::test(Compose::class)->set('error', 'x')->set('result', '{}')->html();
        $html[] = Livewire::test(SessionsIndex::class)->set('showQr', true)->set('qrFor', 'main')->set('qrStatus', 'ready')->html();

        $css = file_get_contents(__DIR__.'/../../dist/laravel-whatsapp.css');
        $missing = [];

        foreach ($html as $document) {
            foreach ($this->classesIn($document) as $class) {
                if (! str_contains($css, $this->selectorFor($class))) {
                    $missing[$class] = true;
                }
            }
        }

        $this->assertSame([], array_keys($missing), sprintf(
            "dist/laravel-whatsapp.css has no rules for %d class(es) the UI renders, so standalone mode will look and behave broken.\n"
            .'Rebuild it: LARAVEL_WHATSAPP_BUILD_APP=/path/to/laravel-app-with-flux ./dist-src/build.sh',
            count($missing),
        ));
    }

    protected function seedConversation(): void
    {
        $base = ['backend' => 'web', 'session_id' => 'main', 'chat_id' => '966511111111@c.us', 'from_id' => '966511111111@c.us'];

        WaMessage::create($base + ['wa_message_id' => 'false_A', 'direction' => 'inbound', 'type' => 'chat', 'body' => 'hi',
            'payload' => ['reactions' => ['me' => '👍', 'x' => '👍'], 'quoted' => ['stanzaId' => 'Q', 'body' => 'earlier', 'from' => null]]]);
        WaMessage::create($base + ['wa_message_id' => 'true_B', 'direction' => 'outbound', 'type' => 'chat', 'body' => 'hello', 'ack' => 3]);
        WaMessage::create($base + ['wa_message_id' => 'true_C', 'direction' => 'outbound', 'type' => 'chat', 'body' => 'gone', 'deleted_at' => now()]);
        WaMessage::create($base + ['wa_message_id' => 'false_D', 'direction' => 'inbound', 'type' => 'image', 'payload' => ['hasMedia' => true]]);
        WaMessage::create($base + ['wa_message_id' => 'false_E', 'direction' => 'inbound', 'type' => 'document', 'payload' => ['filename' => 'a.pdf']]);
        WaMessage::create($base + ['wa_message_id' => 'false_F', 'direction' => 'inbound', 'type' => 'audio', 'payload' => []]);
        WaMessage::create($base + ['wa_message_id' => 'false_G', 'direction' => 'inbound', 'type' => 'video', 'payload' => []]);
    }

    /**
     * Static class names in the rendered HTML.
     *
     * @return array<int, string>
     */
    protected function classesIn(string $html): array
    {
        preg_match_all('/\sclass="([^"]*)"/', $html, $matches);

        $classes = [];

        foreach ($matches[1] as $attribute) {
            foreach (preg_split('/\s+/', html_entity_decode($attribute, ENT_QUOTES | ENT_HTML5)) ?: [] as $class) {
                if ($class !== '' && ! preg_match(self::MARKERS, $class)) {
                    $classes[$class] = true;
                }
            }
        }

        return array_keys($classes);
    }

    /** The selector Tailwind emits for a class: everything but [A-Za-z0-9_-] backslash-escaped. */
    protected function selectorFor(string $class): string
    {
        return '.'.preg_replace('/[^A-Za-z0-9_-]/', '\\\\$0', $class);
    }
}
