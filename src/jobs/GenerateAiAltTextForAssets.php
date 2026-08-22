<?php

namespace heavymetalavo\craftaialttext\jobs;

use Craft;
use craft\elements\Asset;
use craft\queue\BaseJob;
use heavymetalavo\craftaialttext\AiAltText;
use Throwable;

/**
 * Queues a per-asset alt text job for every matching asset.
 *
 * The bulk actions used to walk the whole library inside the web request that triggered them. That
 * is fine on a small site and a guaranteed timeout on a large one, leaving an unknown number of
 * jobs queued and a 504 instead of a flash message.
 *
 * This job does the walking instead, so the request returns immediately and the batching happens
 * in a queue worker where there is no request timeout. It's the same shape Craft uses for resaving
 * elements: one job whose work is to create the real jobs.
 */
class GenerateAiAltTextForAssets extends BaseJob
{
    /**
     * @var int[] Site IDs to queue assets for.
     */
    public array $siteIds = [];

    /**
     * @var bool Whether to include assets that already have alt text.
     */
    public bool $includeExisting = false;

    /**
     * @var int How many assets to load at a time.
     */
    public int $batchSize = 100;

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        $service = AiAltText::getInstance()->aiAltTextService;

        $total = 0;
        foreach ($this->siteIds as $siteId) {
            $total += $this->query($siteId)->count();
        }

        if ($total === 0) {
            Craft::info('No assets matched the bulk alt text request.', __METHOD__);
            return;
        }

        $processed = 0;

        foreach ($this->siteIds as $siteId) {
            $offset = 0;

            while (true) {
                $assets = $this->query($siteId)
                    ->orderBy(['elements.id' => SORT_ASC])
                    ->offset($offset)
                    ->limit($this->batchSize)
                    ->all();

                if (!$assets) {
                    break;
                }

                foreach ($assets as $asset) {
                    try {
                        // Queue only (no off-queue generation), skipping the
                        // saveTranslatedResultsToEachSite fan-out: the caller has already decided
                        // exactly which sites to process.
                        $service->createJob($asset, false, $siteId, false, true, true);
                    } catch (Throwable $e) {
                        Craft::error(
                            "Error queueing alt text generation for asset {$asset->id}: " . $e->getMessage(),
                            __METHOD__
                        );
                    }

                    $processed++;
                    $this->setProgress($queue, $processed / $total);
                }

                $offset += $this->batchSize;
            }
        }

        Craft::info("Queued alt text generation for {$processed} assets.", __METHOD__);
    }

    /**
     * The asset query for one site.
     *
     * `status(null)` so disabled assets are included, matching the figures the utility and the
     * `stats` console command report.
     */
    private function query(int $siteId): \craft\elements\db\AssetQuery
    {
        $query = Asset::find()
            ->kind(Asset::KIND_IMAGE)
            ->siteId($siteId)
            ->status(null);

        if (!$this->includeExisting) {
            $query->hasAlt(false);
        }

        return $query;
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('ai-alt-text', 'Queueing AI alt text generation');
    }
}
