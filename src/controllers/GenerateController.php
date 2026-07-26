<?php

namespace heavymetalavo\craftaialttext\controllers;

use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Http\RespondsWithFlash;
use CraftCms\Cms\Support\Facades\Sites;
use Exception;
use heavymetalavo\craftaialttext\AiAltText;
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

        // Check the user can save this asset (covers assets uploaded by other users too).
        // Craft has both a user model and a user element; the auth guard and craftUser() both
        // return the model, while Element::canSave() requires the element and TypeErrors on the
        // model. asElement() bridges the two. (Craft's own code pairs craftUser() with the
        // Elements service's canSave($element, $user), which does accept the model.)
        $user = $this->request->craftUser()?->asElement();

        if (!$user || !$asset->canSave($user)) {
            Log::warning('AI Alt Text: Permission denied', [
                'userId' => $user?->id,
                'assetId' => $assetId,
            ]);

            return response()->json([
                'success' => false,
                'message' => t('You do not have permission to save this asset', category: 'ai-alt-text'),
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
        // The routes are POST-only, so a GET is rejected with a 405 before reaching here.
        abort_unless($this->request->user()?->can('accessCp'), 403);
        // Require permission to run bulk actions
        abort_unless($this->request->user()?->can(AiAltText::PERMISSION_BULK_ACTIONS), 403);


        $queuedCount = 0;
        $siteId = $this->request->input('siteId');

        if ($siteId) {
            $site = Sites::getSiteById((int)$siteId);
            if (!$site) {
                session()->flash('cp-notification-error', [
                    AiAltText::t('Invalid site ID: {siteId}', ['siteId' => $siteId]),
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
                    AiAltText::t('Queued alt text generation for {count} assets in site {site}', [
                        'count' => $queuedCount,
                        'site' => $sites[0]->name,
                    ]),
                    ['icon' => 'info', 'iconLabel' => t('Notice')],
                ]);
            } else {
                session()->flash('cp-notification-notice', [
                    AiAltText::t('Queued alt text generation for {count} assets across all sites', [
                        'count' => $queuedCount,
                    ]),
                    ['icon' => 'info', 'iconLabel' => t('Notice')],
                ]);
            }
        } catch (Exception $e) {
            Log::error('Error queueing alt text generation: ' . $e->getMessage());
            session()->flash('cp-notification-error', [
                AiAltText::t('Error: {message}', ['message' => $e->getMessage()]),
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
        // The routes are POST-only, so a GET is rejected with a 405 before reaching here.
        abort_unless($this->request->user()?->can('accessCp'), 403);
        // Require permission to run bulk actions
        abort_unless($this->request->user()?->can(AiAltText::PERMISSION_BULK_ACTIONS), 403);


        $queuedCount = 0;
        $siteId = $this->request->input('siteId');

        if ($siteId) {
            $site = Sites::getSiteById((int)$siteId);
            if (!$site) {
                session()->flash('cp-notification-error', [
                    AiAltText::t('Invalid site ID: {siteId}', ['siteId' => $siteId]),
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
                    AiAltText::t('Queued alt text generation for {count} assets in site {site}.', [
                        'count' => $queuedCount,
                        'site' => $sites[0]->name,
                    ]),
                    ['icon' => 'info', 'iconLabel' => t('Notice')],
                ]);
            } else {
                session()->flash('cp-notification-notice', [
                    AiAltText::t('Queued alt text generation for {count} assets across all sites.', [
                        'count' => $queuedCount,
                    ]),
                    ['icon' => 'info', 'iconLabel' => t('Notice')],
                ]);
            }
        } catch (Exception $e) {
            Log::error('Error queueing alt text generation for all assets: ' . $e->getMessage());
            session()->flash('cp-notification-error', [
                AiAltText::t('Error: {message}', ['message' => $e->getMessage()]),
                ['icon' => 'alert', 'iconLabel' => t('Error')],
            ]);
        }

        return redirect(cp_url('utilities/ai-alt-text-bulk-actions'));
    }
}
