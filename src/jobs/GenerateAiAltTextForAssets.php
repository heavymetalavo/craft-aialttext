<?php

namespace heavymetalavo\craftaialttext\jobs;

use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Asset\Enums\FileKind;
use CraftCms\Cms\Queue\BatchedJob;
use CraftCms\Cms\Support\Facades\Sites;
use heavymetalavo\craftaialttext\AiAltText;
use heavymetalavo\craftaialttext\services\AiAltTextService;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Queues a per-asset alt text job for each image asset on one site, in batches, so the bulk
 * actions don't have to walk every asset inside the web request.
 */
class GenerateAiAltTextForAssets extends BatchedJob
{
    /**
     * @var string[]|null The pending job descriptions, fetched once per batch for the duplicate-job check.
     * Cleared after each batch because the next batch's job is a clone of this one and gets serialized.
     */
    private ?array $existingJobs = null;

    /**
     * @param int $siteId The site to queue assets for.
     * @param bool $includeExisting Whether to include assets that already have alt text.
     * @param bool $allSites Whether this job is one of a run across every site, so sites that share an
     * asset's alt text with an earlier site skip it.
     */
    public function __construct(
        public int $siteId = 0,
        public bool $includeExisting = false,
        public bool $allSites = false,
    ) {
        parent::__construct();
    }

    /**
     * @inheritdoc
     */
    protected function beforeBatch(): void
    {
        $this->existingJobs = app(AiAltTextService::class)->pendingJobDescriptions();
    }

    /**
     * @inheritdoc
     */
    protected function afterBatch(): void
    {
        $this->existingJobs = null;
    }

    /**
     * @inheritdoc
     */
    protected function getQuery(): Builder
    {
        // Not filtered on hasAlt(false): batches are read by offset, and the per-asset jobs queued
        // by one batch run before the next batch does, so a shrinking result set would skip assets.
        // Assets that already have alt text are skipped in processItem() instead.
        return Asset::find()
            ->kind(FileKind::Image->value)
            ->siteId($this->siteId)
            ->orderBy(['elements.id' => SORT_ASC]);
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
            // Queued once for the sites that share the asset's alt text, or copied from one of them
            app(AiAltTextService::class)->createBulkJob(
                $item,
                $this->includeExisting,
                $this->allSites,
                $this->existingJobs,
            );
        } catch (Throwable $e) {
            Log::error("Error queueing alt text generation for asset {$item->id}: " . $e->getMessage());
        }
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): string
    {
        $description = AiAltText::t('Queueing AI alt text generation');

        if (Sites::getAllSites()->count() > 1) {
            $site = Sites::getSiteById($this->siteId);
            $description .= $site ? " ({$site->name})" : '';
        }

        return $description;
    }
}
