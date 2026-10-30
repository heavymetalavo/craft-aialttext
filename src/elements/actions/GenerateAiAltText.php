<?php

namespace heavymetalavo\craftaialttext\elements\actions;

use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Element\Actions\ElementAction;
use CraftCms\Cms\Element\Queries\Contracts\ElementQueryInterface;
use CraftCms\Cms\Support\Facades\HtmlStack;
use CraftCms\Cms\Support\Facades\InputNamespace;
use Exception;
use heavymetalavo\craftaialttext\AiAltText;
use heavymetalavo\craftaialttext\services\AiAltTextService;
use Illuminate\Support\Facades\Log;

use function CraftCms\Cms\currentUser;
use function CraftCms\Cms\t;

/**
 * Generate Alt Text element action
 */
class GenerateAiAltText extends ElementAction
{
    /**
     * @var string|null The action description
     */
    public ?string $description = null;

    public static function displayName(): string
    {
        return t('Generate AI Alt Text', category: 'ai-alt-text');
    }

    public function getTriggerLabel(): string
    {
        return t('Generate AI Alt Text', category: 'ai-alt-text');
    }

    public function getTriggerHtml(): ?string
    {
        HtmlStack::jsWithVars(fn ($type) => <<<JS
            (() => {
                new Craft.ElementActionTrigger({
                    type: $type,
                    bulk: true,
                    validateSelection: \$selectedItems => {
                        for (let i = 0; i < \$selectedItems.length; i++) {
                            if (\$selectedItems.eq(i).find('.element').data('kind') !== 'image') {
                                return false;
                            }
                        }
                        return true;
                    },
                });
            })();
        JS, [static::class]);

        return null;
    }

    public function performAction(ElementQueryInterface $query): bool
    {
        // asElement(): Craft has both a user model and a user element, and the auth guard returns
        // the model. canSave() requires the element and TypeErrors on the model.
        $user = currentUser()?->asElement();

        if (!$user) {
            throw new \LogicException('User not logged in');
        }

        $service = app(AiAltTextService::class);

        // Refuse up front rather than queueing jobs that would each fail with the same error
        $configurationError = $service->getConfigurationError();

        if ($configurationError !== null) {
            $this->setMessage($configurationError);
            return false;
        }

        $generatedOrQueuedCount = 0;
        $skippedCount = 0;

        $assets = $query->all();

        // A single asset is generated inline for immediate feedback. More than one is a bulk action,
        // queued so the request isn't one blocking provider call per asset.
        $generateInline = count($assets) === 1;

        // Read the queue once for the duplicate-job check, rather than once per asset
        $existingJobs = $generateInline ? null : $service->pendingJobDescriptions();

        foreach ($assets as $asset) {
            if (!$asset instanceof Asset) {
                continue;
            }

            // ElementQuery::$siteId is mixed - an int, an array of ints, or '*' when the index is
            // showing several sites. Passing an array or '*' straight through returns whichever
            // site row the database happens to order first, so alt text would be generated for an
            // arbitrary site. Resolve one concrete site instead.
            $siteId = is_numeric($query->siteId) ? (int)$query->siteId : $asset->siteId;

            $asset = Asset::find()->id($asset->id)->siteId($siteId)->one();

            if (!$asset) {
                continue;
            }

            // Skip assets the user isn't allowed to save (saving doesn't enforce this itself).
            // canSave() also covers assets uploaded by other users (savePeerAssets).
            if (!$asset->canSave($user)) {
                $skippedCount++;
                continue;
            }

            // Generates the current site inline (single asset) or queues it, and queues any other sites.
            // False means it skipped the asset.
            try {
                if ($service->createJob($asset, $generateInline, existingJobs: $existingJobs)) {
                    $generatedOrQueuedCount++;
                } else {
                    $skippedCount++;
                }
            } catch (Exception $e) {
                // Inline generation throws on provider errors, so report the message rather than let it
                // become a generic server error.
                Log::error('Error processing alt text generation: ' . $e->getMessage());
                $this->setMessage($e->getMessage());
                return false;
            }
        }

        // Skipping is otherwise invisible: without this the user gets an unqualified
        // success notice even when nothing they selected was processed.
        if (!$generateInline) {
            $this->setMessage($skippedCount > 0
                ? AiAltText::t('Queued alt text generation for {count} of {total} assets; {skipped} skipped. Watch the queue for progress.', [
                    'count' => $generatedOrQueuedCount,
                    'total' => $generatedOrQueuedCount + $skippedCount,
                    'skipped' => $skippedCount,
                ])
                : AiAltText::t('Queued alt text generation for {count} assets. Watch the queue for progress.', [
                    'count' => $generatedOrQueuedCount,
                ]));
        } elseif ($skippedCount > 0) {
            $this->setMessage(AiAltText::t('Generated or queued alt text for {count} of {total} assets; {skipped} skipped.', [
                'count' => $generatedOrQueuedCount,
                'total' => $generatedOrQueuedCount + $skippedCount,
                'skipped' => $skippedCount,
            ]));
        } elseif ($generatedOrQueuedCount > 0) {
            $this->setMessage($service->queuesOtherSites()
                ? AiAltText::t('Alt text generated. The other sites have been queued.')
                : AiAltText::t('Alt text generated'));
        }

        return true;
    }
}
