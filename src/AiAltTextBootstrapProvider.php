<?php

namespace heavymetalavo\craftaialttext;

use CraftCms\Cms\Route\Routes;
use CraftCms\Cms\View\TemplateMode;
use CraftCms\Cms\View\TemplateRoots;
use heavymetalavo\craftaialttext\Commands\{GenerateAll, GenerateMissing, GenerateSingle, GenerateStats};
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
 *  - ViewServiceProvider has passed the CP template roots to Laravel's view factory, which it
 *    does once, straight after the service providers boot.
 *  - The Artisan application has been created, so the `Artisan::starting` callback that plugin
 *    console commands rely on never fires.
 *
 * This provider is discovered and registered by Laravel in the normal way, which is early enough
 * for both. It is deliberately NOT the plugin class: Craft throws if the plugin class itself is
 * declared as a Laravel service provider.
 */
class AiAltTextBootstrapProvider extends ServiceProvider
{
    private const HANDLE = 'ai-alt-text';

    public function boot(TemplateRoots $templateRoots): void
    {
        $this->registerCpTemplateRoot($templateRoots);

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
     * Registers `src/templates` as the control panel template root, so `ai-alt-text/_settings`
     * and `ai-alt-text/_utility` resolve.
     *
     * Craft's HasViews concern registers the same directory when the plugin boots. Doing it here
     * as well means the root is in place whether or not the plugin has loaded yet, and TemplateRoots
     * keys roots by path, so the two registrations don't produce a duplicate.
     */
    private function registerCpTemplateRoot(TemplateRoots $templateRoots): void
    {
        $templateRoots->register(TemplateMode::Cp, self::HANDLE, __DIR__ . '/templates');
    }

    /**
     * Registers routes/actions.php under Craft's action-trigger prefixes.
     *
     * Mirrors what Craft's HasRoutes concern does, for the same reason the template root is
     * registered here: the concern never runs, so without this the plugin has no routes at all
     * and both the bulk actions and the single-asset action return 405.
     *
     * The `web` middleware is included so the session is started, and with it the authenticated
     * user. Both prefixes get the CP middleware, because the actions are only called from the CP.
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

        $prefixes = array_unique([
            $routes->joinRoutePrefix([$routes->cpActionTriggerRoutePrefix(), self::HANDLE]),
            $routes->joinRoutePrefix([$routes->actionTriggerRoutePrefix(), self::HANDLE]),
        ]);

        foreach ($prefixes as $prefix) {
            Route::middleware(['web', 'craft', 'craft.cp'])
                ->prefix($prefix)
                ->group($path);
        }
    }
}
