<?php

namespace heavymetalavo\craftaialttext;

use CraftCms\Cms\View\Events\CpTemplateRootsResolving;
use heavymetalavo\craftaialttext\Commands\{GenerateAll, GenerateMissing, GenerateSingle, GenerateStats};
use heavymetalavo\craftaialttext\listeners\RegisterCpTemplateRoot;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the parts of the plugin that have to be in place before Craft loads plugins.
 *
 * Craft loads plugins from an `app->booting` callback, but `Plugins::loadPlugins()` returns
 * early when `Cms::isInstalled()` is false — which it is that early in the request, because the
 * database isn't reachable yet. It also returns without marking plugins as loaded, so they get
 * loaded lazily later instead. By then two things have already happened:
 *
 *  - ViewServiceProvider has memoised the CP template roots on `app->booted`, with once(), so a
 *    root registered afterwards is never picked up and plugin templates can't resolve.
 *  - The Artisan application has been created, so the `Artisan::starting` callback that plugin
 *    console commands rely on never fires.
 *
 * This provider is discovered and registered by Laravel in the normal way, which is early enough
 * for both. It is deliberately NOT the plugin class: Craft throws if the plugin class itself is
 * declared as a Laravel service provider.
 */
class AiAltTextBootstrapProvider extends ServiceProvider
{
    public function register(): void
    {
        Event::listen(CpTemplateRootsResolving::class, RegisterCpTemplateRoot::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateSingle::class,
                GenerateMissing::class,
                GenerateAll::class,
                GenerateStats::class,
            ]);
        }
    }
}
