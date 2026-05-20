<?php

namespace heavymetalavo\craftaialttext\controllers;

use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Http\RespondsWithFlash;
use CraftCms\Cms\Support\Facades\Sites;
use Exception;
use heavymetalavo\craftaialttext\services\AiAltTextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

use function CraftCms\Cms\cp_url;
use function CraftCms\Cms\t;

/**
 * Generate Controller
 *
 * Handles CP actions for generating AI alt text.
 * Routes are defined in routes/actions.php.
 */
class GenerateController
{
    use RespondsWithFlash;

    public function __construct(
        protected Request $request,
    ) {}

    /**
     * Generate AI alt text for a single asset (AJAX).
     */
    public function actionSingleAsset(): JsonResponse
    {
        $assetId = $this->request->input('assetId');
        $siteId = $this->request->input('siteId');

        if (!$assetId || !$siteId) {
            return response()->json([
                'success' => false,
                'message' => t('Missing assetId or siteId', category: 'ai-alt-text'),
            ], 400);
        }

        $asset = Asset::find()->id($assetId)->siteId($siteId)->one();
        if (!$asset) {
            return response()->json([
                'success' => false,
                'message' => t('Asset not found', category: 'ai-alt-text'),
            ], 404);
        }

        $user = $this->request->user();
        $volumePermission = 'saveAssets:' . $asset->getVolume()->uid;

        if (!$user || !$user->can($volumePermission)) {
            Log::warning('AI Alt Text: Permission denied', [
                'userId' => $user?->id,
                'assetId' => $assetId,
                'permission' => $volumePermission,
            ]);

            return response()->json([
                'success' => false,
                'message' => t('You do not have permission to save assets in this volume', category: 'ai-alt-text'),
            ], 403);
        }

        try {
            app(AiAltTextService::class)->createJob($asset, true);

            return response()->json([
                'success' => true,
                'message' => t('Alt text generation has been queued', category: 'ai-alt-text'),
            ]);
        } catch (Exception $e) {
            Log::error('Error queueing alt text generation: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Queue alt text generation for all image assets without existing alt text.
     */
    public function actionGenerateAssetsWithoutAltText(): RedirectResponse
    {
        abort_unless($this->request->user()?->can('accessCp'), 403);

        $queuedCount = 0;
        $siteId = $this->request->input('siteId');

        if ($siteId) {
            $site = Sites::getSiteById((int)$siteId);
            if (!$site) {
                session()->flash('cp-notification-error', [
                    t('Invalid site ID: {siteId}', ['siteId' => $siteId], 'ai-alt-text'),
                    ['icon' => 'alert', 'iconLabel' => t('Error')],
                ]);
                return redirect(cp_url('utilities/ai-alt-text-bulk-actions'));
            }
            $sites = [$site];
        } else {
            $sites = Sites::getAllSites()->all();
        }

        try {
            foreach ($sites as $site) {
                $offset = 0;
                $limit = 100;

                while (true) {
                    $assets = Asset::find()
                        ->kind('image')
                        ->siteId($site->id)
                        ->hasAlt(false)
                        ->offset($offset)
                        ->limit($limit)
                        ->all();

                    if (empty($assets)) {
                        break;
                    }

                    foreach ($assets as $asset) {
                        if (!empty($asset->alt)) {
                            continue;
                        }

                        try {
                            app(AiAltTextService::class)->createJob($asset, false, $site->id, false, true, true);
                            $queuedCount++;
                        } catch (Exception $e) {
                            Log::error('Error queuing job for asset ' . $asset->id . ': ' . $e->getMessage());
                        }
                    }

                    $offset += $limit;

                    if (function_exists('gc_collect_cycles')) {
                        gc_collect_cycles();
                    }
                }
            }

            if ($siteId) {
                session()->flash('cp-notification-notice', [
                    t('Queued alt text generation for {count} assets in site {site}', [
                        'count' => $queuedCount,
                        'site' => $sites[0]->name,
                    ], 'ai-alt-text'),
                    ['icon' => 'info', 'iconLabel' => t('Notice')],
                ]);
            } else {
                session()->flash('cp-notification-notice', [
                    t('Queued alt text generation for {count} assets across all sites', [
                        'count' => $queuedCount,
                    ], 'ai-alt-text'),
                    ['icon' => 'info', 'iconLabel' => t('Notice')],
                ]);
            }
        } catch (Exception $e) {
            Log::error('Error queueing alt text generation: ' . $e->getMessage());
            session()->flash('cp-notification-error', [
                t('Error: {message}', ['message' => $e->getMessage()], 'ai-alt-text'),
                ['icon' => 'alert', 'iconLabel' => t('Error')],
            ]);
        }

        return redirect(cp_url('utilities/ai-alt-text-bulk-actions'));
    }

    /**
     * Queue alt text generation for ALL image assets.
     */
    public function actionGenerateAllAssets(): RedirectResponse
    {
        abort_unless($this->request->user()?->can('accessCp'), 403);

        $queuedCount = 0;
        $siteId = $this->request->input('siteId');

        if ($siteId) {
            $site = Sites::getSiteById((int)$siteId);
            if (!$site) {
                session()->flash('cp-notification-error', [
                    t('Invalid site ID: {siteId}', ['siteId' => $siteId], 'ai-alt-text'),
                    ['icon' => 'alert', 'iconLabel' => t('Error')],
                ]);
                return redirect(cp_url('utilities/ai-alt-text-bulk-actions'));
            }
            $sites = [$site];
        } else {
            $sites = Sites::getAllSites()->all();
        }

        try {
            foreach ($sites as $site) {
                $offset = 0;
                $limit = 100;

                while (true) {
                    $assets = Asset::find()
                        ->kind('image')
                        ->siteId($site->id)
                        ->offset($offset)
                        ->limit($limit)
                        ->all();

                    if (empty($assets)) {
                        break;
                    }

                    foreach ($assets as $asset) {
                        try {
                            app(AiAltTextService::class)->createJob($asset, false, $site->id, false, true, true);
                            $queuedCount++;
                        } catch (Exception $e) {
                            Log::error('Error queuing job for asset ' . $asset->id . ': ' . $e->getMessage());
                        }
                    }

                    $offset += $limit;

                    if (function_exists('gc_collect_cycles')) {
                        gc_collect_cycles();
                    }
                }
            }

            if ($siteId) {
                session()->flash('cp-notification-notice', [
                    t('Queued alt text generation for {count} assets in site {site}.', [
                        'count' => $queuedCount,
                        'site' => $sites[0]->name,
                    ], 'ai-alt-text'),
                    ['icon' => 'info', 'iconLabel' => t('Notice')],
                ]);
            } else {
                session()->flash('cp-notification-notice', [
                    t('Queued alt text generation for {count} assets across all sites.', [
                        'count' => $queuedCount,
                    ], 'ai-alt-text'),
                    ['icon' => 'info', 'iconLabel' => t('Notice')],
                ]);
            }
        } catch (Exception $e) {
            Log::error('Error queueing alt text generation for all assets: ' . $e->getMessage());
            session()->flash('cp-notification-error', [
                t('Error: {message}', ['message' => $e->getMessage()], 'ai-alt-text'),
                ['icon' => 'alert', 'iconLabel' => t('Error')],
            ]);
        }

        return redirect(cp_url('utilities/ai-alt-text-bulk-actions'));
    }
}
