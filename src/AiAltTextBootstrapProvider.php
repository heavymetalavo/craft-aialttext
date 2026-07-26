<?php

namespace heavymetalavo\craftaialttext;

use CraftCms\Cms\Route\Routes;
use CraftCms\Cms\View\Events\CpTemplateRootsResolving;
use heavymetalavo\craftaialttext\Commands\{GenerateAll, GenerateMissing, GenerateSingle, GenerateStats};
use heavymetalavo\craftaialttext\listeners\RegisterCpTemplateRoot;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
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

        $this->registerActionRoutes();
    }

    /**
     * Registers routes/actions.php under Craft's action-trigger prefixes.
     *
     * Mirrors what Craft's HasRoutes concern does, for the same reason the template root is
     * registered here: the concern never runs, so without this the plugin has no routes at all
     * and both the bulk actions and the single-asset action return 405.
     *
     * The `web` middleware is included so the session is started, and with it the authenticated
     * user — HasRoutes applies only ['craft', 'craft.cp'] to the CP variant.
     */
    private function registerActionRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        $path = dirname(__DIR__) . '/routes/actions.php';

        if (! is_file($path)) {
            return;
        }

        $routes = $this->app->get(Routes::class);
        $handle = 'ai-alt-text';

        $prefixes = array_unique([
            $routes->joinRoutePrefix([$routes->cpActionTriggerRoutePrefix(), $handle]),
            $routes->joinRoutePrefix([$routes->actionTriggerRoutePrefix(), $handle]),
        ]);

        foreach ($prefixes as $prefix) {
            Route::middleware(['web', 'craft', 'craft.cp'])
                ->prefix($prefix)
                ->group($path);
        }
    }
}
