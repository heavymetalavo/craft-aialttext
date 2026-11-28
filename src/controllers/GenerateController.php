<?php

namespace heavymetalavo\craftaialttext\controllers;

use Craft;
use craft\elements\Asset;
use craft\web\Controller;
use Exception;
use heavymetalavo\craftaialttext\AiAltText;
use heavymetalavo\craftaialttext\jobs\GenerateAiAltTextForAssets;
use yii\web\{ForbiddenHttpException, Response};

/**
 * Generate Controller
 */
class GenerateController extends Controller
{
    /**
     * @inheritdoc
     */
    protected array|bool|int $allowAnonymous = false;

    /**
     * Generate AI alt text for a single asset
     *
     * @return Response
     */
    public function actionSingleAsset(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $assetId = $this->request->getRequiredBodyParam('assetId');
        $siteId = $this->request->getRequiredBodyParam('siteId');

        // Get the asset
        $asset = Asset::find()->id($assetId)->siteId($siteId)->one();
        if (!$asset) {
            return $this->asJson([
                'success' => false,
                'message' => Craft::t('ai-alt-text', 'Asset not found'),
            ]);
        }

        // Check the user can save this asset (covers assets uploaded by other users too)
        $user = Craft::$app->getUser()->getIdentity();
        if (!$user || !$asset->canSave($user)) {
            throw new ForbiddenHttpException('User is not permitted to save this asset');
        }

        try {
            $service = AiAltText::getInstance()->aiAltTextService;

            if (($reason = $service->exclusionReason($asset)) !== null) {
                return $this->asJson([
                    'success' => false,
                    'message' => Craft::t('ai-alt-text', 'Nothing was generated: {reason}', ['reason' => $reason]),
                ]);
            }

            if (!$service->createJob($asset, true)) {
                return $this->asJson([
                    'success' => false,
                    'message' => Craft::t('ai-alt-text', 'Nothing was generated: the asset is already queued, is not an image, or is an SVG while SVG processing is off or Craft\'s image driver can\'t read SVGs'),
                ]);
            }

            // This site is generated inline, but the other sites are queued when translated
            // results are saved to each site, so say so rather than imply it's all done.
            return $this->asJson([
                'success' => true,
                'message' => $service->queuesOtherSites()
                    ? Craft::t('ai-alt-text', 'Alt text generated. The other sites have been queued.')
                    : Craft::t('ai-alt-text', 'Alt text generated'),
            ]);
        } catch (Exception $e) {
            Craft::error('Error processing alt text generation: ' . $e->getMessage(), __METHOD__);

            return $this->asJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Generate AI alt text for assets without alt text
     *
     * @return Response
     */
    public function actionGenerateAssetsWithoutAltText(): Response
    {
        return $this->queueBulkGeneration(false);
    }

    /**
     * Generate AI alt text for ALL assets
     *
     * @return Response
     */
    public function actionGenerateAllAssets(): Response
    {
        return $this->queueBulkGeneration(true);
    }

    /**
     * Queues a batched job per site that fans out into one job per asset, so the request returns
     * immediately whatever the number of assets.
     *
     * @param bool $includeExisting Whether to include assets that already have alt text
     */
    private function queueBulkGeneration(bool $includeExisting): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('accessCp');
        // Require permission to run bulk actions
        $this->requirePermission(AiAltText::PERMISSION_BULK_ACTIONS);

        $redirect = $this->redirect('utilities/ai-alt-text-bulk-actions');

        // Refuse up front rather than queueing jobs that would each fail with the same error
        $configurationError = AiAltText::getInstance()->aiAltTextService->getConfigurationError();

        if ($configurationError !== null) {
            Craft::$app->getSession()->setError($configurationError);
            return $redirect;
        }

        $siteIdParam = $this->request->getParam('siteId');
        $siteName = null;

        if ($siteIdParam) {
            $site = Craft::$app->getSites()->getSiteById((int)$siteIdParam);

            if (!$site) {
                Craft::$app->getSession()->setError(
                    Craft::t('ai-alt-text', 'Invalid site ID: {siteId}', ['siteId' => $siteIdParam])
                );
                return $redirect;
            }

            $sites = [$site];
            $siteName = $site->name;
        } else {
            $sites = Craft::$app->getSites()->getAllSites();
        }

        // Optionally limited to one volume, or a path in it, whatever the sites
        $volume = null;
        $path = trim((string)$this->request->getParam('path'), "/ \t");
        $volumeUid = $this->request->getParam('volumeUid');

        if ($volumeUid) {
            $volume = Craft::$app->getVolumes()->getVolumeByUid($volumeUid);

            if (!$volume) {
                Craft::$app->getSession()->setError(Craft::t('ai-alt-text', 'Invalid volume.'));
                return $redirect;
            }

            if ($path !== '' && ($pathError = AiAltText::getInstance()->aiAltTextService->scopePathError($path)) !== null) {
                Craft::$app->getSession()->setError($pathError);
                return $redirect;
            }
        } elseif ($path !== '') {
            Craft::$app->getSession()->setError(Craft::t('ai-alt-text', 'Choose a volume for the path.'));
            return $redirect;
        }

        try {
            foreach ($sites as $site) {
                Craft::$app->getQueue()->push(new GenerateAiAltTextForAssets([
                    'siteId' => $site->id,
                    'includeExisting' => $includeExisting,
                    'allSites' => !$siteIdParam,
                    'volumeId' => $volume?->id,
                    'path' => $path !== '' ? $path : null,
                ]));
            }
        } catch (Exception $e) {
            Craft::error('Error queueing bulk alt text generation: ' . $e->getMessage(), __METHOD__);

            Craft::$app->getSession()->setError(
                Craft::t('ai-alt-text', 'Error: {message}', ['message' => $e->getMessage()])
            );
            return $redirect;
        }

        if ($volume) {
            $scope = $volume->handle . ($path !== '' ? "/$path" : '');

            Craft::$app->getSession()->setNotice($siteName !== null
                ? Craft::t('ai-alt-text', 'Queueing alt text generation for {scope} on site {site}. Watch the queue for progress.', ['scope' => $scope, 'site' => $siteName])
                : Craft::t('ai-alt-text', 'Queueing alt text generation for {scope} across all sites. Watch the queue for progress.', ['scope' => $scope]));

            return $redirect;
        }

        Craft::$app->getSession()->setNotice($siteName !== null
            ? Craft::t('ai-alt-text', 'Queueing alt text generation for site {site}. Watch the queue for progress.', ['site' => $siteName])
            : Craft::t('ai-alt-text', 'Queueing alt text generation across all sites. Watch the queue for progress.'));

        return $redirect;
    }
}
