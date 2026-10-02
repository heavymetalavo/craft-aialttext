<?php

namespace heavymetalavo\craftaialttext\jobs;

use Craft;
use craft\base\Batchable;
use craft\db\QueryBatcher;
use craft\elements\Asset;
use craft\queue\BaseBatchedJob;
use heavymetalavo\craftaialttext\AiAltText;
use Throwable;

/**
 * Queues a per-asset alt text job for each image asset on one site, in batches, so the bulk
 * actions don't have to walk the library inside the web request.
 */
class GenerateAiAltTextForAssets extends BaseBatchedJob
{
    /**
     * @var int The site to queue assets for.
     */
    public int $siteId = 0;

    /**
     * @var bool Whether to include assets that already have alt text.
     */
    public bool $includeExisting = false;

    /**
     * @inheritdoc
     */
    protected function loadData(): Batchable
    {
        // Not filtered on hasAlt(false): batches are read by offset, and the per-asset jobs queued
        // by one batch run before the next batch does, so a shrinking result set would skip assets.
        // Assets that already have alt text are skipped in processItem() instead.
        return new QueryBatcher(
            Asset::find()
                ->kind(Asset::KIND_IMAGE)
                ->siteId($this->siteId)
                // Include disabled assets, matching the figures the utility and `stats` report.
                ->status(null)
                ->orderBy(['elements.id' => SORT_ASC])
        );
    }

    /**
     * @inheritdoc
     */
    protected function processItem(mixed $item): void
    {
        if (!$this->includeExisting && !empty($item->alt)) {
            return;
        }

        try {
            // Queue only: the caller has already chosen the sites, so skip the
            // saveTranslatedResultsToEachSite fan-out.
            AiAltText::getInstance()->aiAltTextService->createJob(
                $item,
                currentSiteId: $this->siteId,
                skipSaveTranslatedResultsToEachSiteSetting: true,
            );
        } catch (Throwable $e) {
            Craft::error("Error queueing alt text generation for asset {$item->id}: " . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        $description = Craft::t('ai-alt-text', 'Queueing AI alt text generation');

        if (count(Craft::$app->getSites()->getAllSites()) > 1) {
            $site = Craft::$app->getSites()->getSiteById($this->siteId);
            $description .= $site ? " ({$site->name})" : '';
        }

        return $description;
    }
}
