<?php

namespace heavymetalavo\craftaialttext\controllers;

use Craft;
use craft\web\Controller;
use craft\elements\Asset;
use yii\web\Response;
use heavymetalavo\craftaialttext\AiAltText;
use heavymetalavo\craftaialttext\jobs\GenerateAiAltText as GenerateAiAltTextJob;

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

        // Check permissions
        $this->requirePermission('saveAssets:' . $asset->getVolume()->uid);

        try {
            $plugin = AiAltText::getInstance();
            $queue = Craft::$app->getQueue();
            
            // Check if there's already a job for this element and site
            $existingJobs = $queue->getJobInfo();
            $hasExistingJob = false;
            foreach ($existingJobs as $job) {
                if (isset($job['description']) && 
                    strpos($job['description'], "Element ID: {$asset->id}") !== false && 
                    strpos($job['description'], "Site: {$siteId}") !== false) {
                    $hasExistingJob = true;
                    break;
                }
            }

            if ($hasExistingJob) {
                return $this->asJson([
                    'success' => false,
                    'message' => Craft::t('ai-alt-text', "Asset {$asset->filename} (ID: {$asset->id}) is already being processed within an existing queued job. Please wait for the existing job to finish before attempting to process it again."),
                ]);
            }

            // Queue a job for the current site
            $queue->push(new GenerateAiAltTextJob([
                'description' => Craft::t('ai-alt-text', 'Generating alt text for {filename} (Element: {id}, Site: {siteId})', [
                    'filename' => $asset->filename,
                    'id' => $asset->id,
                    'siteId' => $siteId,
                ]),
                'elementId' => $asset->id,
                'siteId' => $siteId,
            ]));

            // If we're saving results to each site, queue a job for each site
            $saveTranslatedResultsToEachSite = $plugin->settings->saveTranslatedResultsToEachSite;
            if ($saveTranslatedResultsToEachSite) {
                foreach (Craft::$app->getSites()->getAllSites() as $site) {
                    // Skip the current site
                    if ($site->id === $siteId) {
                        continue;
                    }

                    $queue->push(new GenerateAiAltTextJob([
                        'description' => Craft::t('ai-alt-text', 'Generating alt text for {filename} (Element: {id}, Site: {siteId})', [
                            'filename' => $asset->filename,
                            'id' => $asset->id,
                            'siteId' => $site->id,
                        ]),
                        'elementId' => $asset->id,
                        'siteId' => $site->id,
                    ]));
                }
            }

            // Return success
            return $this->asJson([
                'success' => true,
                'message' => Craft::t('ai-alt-text', 'Alt text generation has been queued'),
            ]);
        } catch (\Exception $e) {
            Craft::error('Error queueing alt text generation: ' . $e->getMessage(), __METHOD__);

            return $this->asJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
