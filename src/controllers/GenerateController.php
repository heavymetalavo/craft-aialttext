<?php

namespace heavymetalavo\craftaialttext\controllers;

use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Http\RespondsWithFlash;
use CraftCms\Cms\Support\Facades\Sites;
use Exception;
use heavymetalavo\craftaialttext\AiAltText;
use heavymetalavo\craftaialttext\jobs\GenerateAiAltTextForAssets;
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
            $service = app(AiAltTextService::class);

            if (!$service->createJob($asset, true)) {
                return response()->json([
                    'success' => false,
                    'message' => t('Nothing was generated: the asset is already queued, is not an image, or is an SVG while SVG processing is off or Craft\'s image driver can\'t read SVGs', category: 'ai-alt-text'),
                ]);
            }

            // This site is generated inline, but the other sites are queued when translated
            // results are saved to each site, so say so rather than imply it's all done.
            return response()->json([
                'success' => true,
                'message' => $service->queuesOtherSites()
                    ? t('Alt text generated. The other sites have been queued.', category: 'ai-alt-text')
                    : t('Alt text generated', category: 'ai-alt-text'),
            ]);
        } catch (Exception $e) {
            Log::error('Error processing alt text generation: ' . $e->getMessage());

            // A 200 with success false, as the action's script shows the message from the response. An error
            // status would reject the request instead, and show the HTTP client's generic message.
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Queue alt text generation for all image assets without existing alt text.
     */
    public function actionGenerateAssetsWithoutAltText(): RedirectResponse
    {
        return $this->queueBulkGeneration(false);
    }

    /**
     * Queue alt text generation for ALL image assets.
     */
    public function actionGenerateAllAssets(): RedirectResponse
    {
        return $this->queueBulkGeneration(true);
    }

    /**
     * Queues a batched job per site that fans out into one job per asset, so the request returns
     * immediately whatever the number of assets.
     *
     * @param bool $includeExisting Whether to include assets that already have alt text
     */
    private function queueBulkGeneration(bool $includeExisting): RedirectResponse
    {
        // The routes are POST-only, so a GET is rejected with a 405 before reaching here.
        abort_unless($this->request->user()?->can('accessCp'), 403);
        // Require permission to run bulk actions
        abort_unless($this->request->user()?->can(AiAltText::PERMISSION_BULK_ACTIONS), 403);

        $redirect = redirect(cp_url('utilities/ai-alt-text-bulk-actions'));

        // Refuse up front rather than queueing jobs that would each fail with the same error
        $configurationError = app(AiAltTextService::class)->getConfigurationError();

        if ($configurationError !== null) {
            $this->flashError($configurationError);
            return $redirect;
        }

        $siteIdParam = $this->request->input('siteId');
        $siteName = null;

        if ($siteIdParam) {
            $site = Sites::getSiteById((int)$siteIdParam);

            if (!$site) {
                $this->flashError(AiAltText::t('Invalid site ID: {siteId}', ['siteId' => $siteIdParam]));
                return $redirect;
            }

            $sites = [$site];
            $siteName = $site->name;
        } else {
            $sites = Sites::getAllSites()->all();
        }

        try {
            foreach ($sites as $site) {
                dispatch(new GenerateAiAltTextForAssets(
                    siteId: $site->id,
                    includeExisting: $includeExisting,
                    allSites: !$siteIdParam,
                ));
            }
        } catch (Exception $e) {
            Log::error('Error queueing bulk alt text generation: ' . $e->getMessage());
            $this->flashError(AiAltText::t('Error: {message}', ['message' => $e->getMessage()]));
            return $redirect;
        }

        session()->flash('cp-notification-notice', [
            $siteName !== null
                ? AiAltText::t('Queueing alt text generation for site {site}. Watch the queue for progress.', ['site' => $siteName])
                : AiAltText::t('Queueing alt text generation across all sites. Watch the queue for progress.'),
            ['icon' => 'info', 'iconLabel' => t('Notice')],
        ]);

        return $redirect;
    }

    /**
     * Flashes an error notification for the control panel.
     */
    private function flashError(string $message): void
    {
        session()->flash('cp-notification-error', [$message, ['icon' => 'alert', 'iconLabel' => t('Error')]]);
    }
}
