<?php

namespace heavymetalavo\craftaialttext\controllers;

use Craft;
use craft\elements\Asset;
use craft\web\Controller;
use Exception;
use heavymetalavo\craftaialttext\AiAltText;
use heavymetalavo\craftaialttext\jobs\GenerateAiAltTextForAssets;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

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
            AiAltText::getInstance()->aiAltTextService->createJob($asset, true);

            // Return success
            return $this->asJson([
                'success' => true,
                'message' => Craft::t('ai-alt-text', 'Alt text generation has been queued'),
            ]);
        } catch (Exception $e) {
            Craft::error('Error queueing alt text generation: ' . $e->getMessage(), __METHOD__);

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
     * immediately however large the library is.
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

        try {
            foreach ($sites as $site) {
                Craft::$app->getQueue()->push(new GenerateAiAltTextForAssets([
                    'siteId' => $site->id,
                    'includeExisting' => $includeExisting,
                ]));
            }
        } catch (Exception $e) {
            Craft::error('Error queueing bulk alt text generation: ' . $e->getMessage(), __METHOD__);

            Craft::$app->getSession()->setError(
                Craft::t('ai-alt-text', 'Error: {message}', ['message' => $e->getMessage()])
            );
            return $redirect;
        }

        Craft::$app->getSession()->setNotice($siteName !== null
            ? Craft::t('ai-alt-text', 'Queueing alt text generation for site {site}. Watch the queue for progress.', ['site' => $siteName])
            : Craft::t('ai-alt-text', 'Queueing alt text generation across all sites. Watch the queue for progress.'));

        return $redirect;
    }
}
