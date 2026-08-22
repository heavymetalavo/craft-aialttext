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
     * Queues a single job that fans out into one job per asset.
     *
     * Previously both actions walked every asset on every site inside this request, 100 at a time.
     * On a large library that is a guaranteed timeout, leaving an unknown number of jobs queued and
     * a 504 instead of a flash message. Handing the walking to a queue worker keeps the request
     * fast regardless of library size.
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

            $siteIds = [$site->id];
            $siteName = $site->name;
        } else {
            $siteIds = array_map(
                static fn($site) => $site->id,
                Craft::$app->getSites()->getAllSites()
            );
        }

        try {
            Craft::$app->getQueue()->push(new GenerateAiAltTextForAssets([
                'siteIds' => $siteIds,
                'includeExisting' => $includeExisting,
            ]));
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
