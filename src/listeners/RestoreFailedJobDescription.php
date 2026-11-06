<?php

namespace heavymetalavo\craftaialttext\listeners;

use CraftCms\Cms\Queue\JobProgress;
use heavymetalavo\craftaialttext\jobs\GenerateAiAltText as GenerateAiAltTextJob;
use Illuminate\Queue\Events\JobFailed;
use Throwable;

/**
 * Craft's own StoreFailed listener marks failed jobs with description=null, which clears the
 * job name from the queue manager UI. This runs after it and restores the description so
 * failed alt text jobs remain identifiable.
 */
class RestoreFailedJobDescription
{
    public function handle(JobFailed $event): void
    {
        $payload = $event->job->payload();
        $uuid = $payload['uuid'] ?? null;
        if (!$uuid) {
            return;
        }

        $commandData = $payload['data']['command'] ?? '';
        if (empty($commandData)) {
            return;
        }

        try {
            $job = unserialize($commandData);
        } catch (Throwable) {
            return;
        }

        if (!$job instanceof GenerateAiAltTextJob) {
            return;
        }

        app(JobProgress::class)->failed(
            uid: $uuid,
            description: $job->getDescription(),
            error: $event->exception->getMessage(),
        );
    }
}
