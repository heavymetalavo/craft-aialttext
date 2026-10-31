<?php

namespace heavymetalavo\craftaialttext\jobs;

use Craft;
use craft\elements\Asset;
use craft\errors\ElementNotFoundException;
use craft\queue\BaseJob;
use Exception;
use heavymetalavo\craftaialttext\AiAltText;
use heavymetalavo\craftaialttext\errors\RateLimitException;
use Throwable;

/**
 * Generate Alt Text queue job
 */
class GenerateAiAltText extends BaseJob
{
    public ?int $assetId = null;
    public ?int $siteId = null;

    /**
     * @var int[] Other sites that share the site's alt text, saved with the same result
     */
    public array $sharedSiteIds = [];

    /**
     * @var int How many times this asset's job has been put back in the queue after hitting a rate limit
     */
    public int $rateLimitRetries = 0;

    /**
     * @var int How many times a job is put back in the queue after hitting a rate limit, before it fails
     */
    public const MAX_RATE_LIMIT_RETRIES = 5;

    /**
     * Errors are not caught so Craft marks the job as failed and offers a retry, except a rate limit: the job is put
     * back in the queue to run later, after the provider's `Retry-After` or a wait that doubles each time from 30
     * seconds, up to MAX_RATE_LIMIT_RETRIES times. Other jobs for the same provider and model wait too, rather than
     * hitting the limit again, so a bulk run slows to the rate the account allows.
     *
     * @throws ElementNotFoundException
     * @throws Exception
     * @throws Throwable
     */
    function execute($queue): void
    {
        // query for the asset
        $asset = Asset::find()->id($this->assetId)->siteId($this->siteId)->one();

        // check if the asset exists
        if (!$asset) {
            throw new ElementNotFoundException("Asset not found: $this->assetId");
        }

        $service = AiAltText::getInstance()->aiAltTextService;

        // Another job hit the rate limit, so wait until it's lifted, without counting it as a retry
        if (($wait = $service->rateLimitWait()) > 0) {
            $this->requeue($wait, $this->rateLimitRetries);
            return;
        }

        try {
            // Generates the alt text and saves the asset, or throws
            $altText = $service->generateAltText($asset, $this->siteId, $this->sharedSiteIds);
        } catch (Throwable $e) {
            $rateLimit = RateLimitException::find($e);

            if ($rateLimit === null || $this->rateLimitRetries >= self::MAX_RATE_LIMIT_RETRIES) {
                throw $e;
            }

            // 30 seconds, then 1, 2, 4 and 8 minutes, unless the provider said how long
            $delay = min($rateLimit->retryAfter ?? 30 * 2 ** $this->rateLimitRetries, 3600);
            $service->pauseForRateLimit($delay);
            Craft::warning("Rate limited generating alt text for asset $this->assetId, trying again in {$delay}s (retry " . ($this->rateLimitRetries + 1) . ' of ' . self::MAX_RATE_LIMIT_RETRIES . '): ' . $e->getMessage(), __METHOD__);
            $this->requeue($delay, $this->rateLimitRetries + 1);
            return;
        }

        Craft::info("Successfully generated alt text for asset $this->assetId: " . $altText, __METHOD__);
    }

    /**
     * Puts a copy of this job back in the queue, to run after $delay seconds. It keeps the description, which the
     * duplicate-job check matches on, so the asset isn't queued again meanwhile.
     */
    private function requeue(int $delay, int $rateLimitRetries): void
    {
        Craft::$app->getQueue()->delay($delay)->push(new self([
            'description' => $this->description,
            'assetId' => $this->assetId,
            'siteId' => $this->siteId,
            'sharedSiteIds' => $this->sharedSiteIds,
            'rateLimitRetries' => $rateLimitRetries,
        ]));
    }

    protected function defaultDescription(): ?string
    {
        return "Generate alt text";
    }
}
