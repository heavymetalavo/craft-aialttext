<?php

namespace heavymetalavo\craftaialttext\elements\actions;

use Craft;
use craft\base\ElementAction;
use craft\elements\Asset;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\ElementHelper;
use craft\helpers\Queue;
use heavymetalavo\craftaialttext\jobs\GenerateAiAltText as GenerateAiAltTextJob;
use yii\base\InvalidConfigException;

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
        return Craft::t('ai-alt-text', 'Generate AI Alt Text');
    }

    public function getTriggerLabel(): string
    {
        return Craft::t('ai-alt-text', 'Generate AI Alt Text');
    }

    public function getTriggerHtml(): ?string
    {
        Craft::$app->getView()->registerJsWithVars(fn($type) => <<<JS
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
        $user = Craft::$app->getUser()->getIdentity();

        if (!$user) {
            throw new InvalidConfigException('User not logged in');
        }

        $elementsService = Craft::$app->getElements();
        $queue = Craft::$app->getQueue();

        foreach ($query->all() as $element) {
            if (!$element instanceof Asset) {
                continue;
            }

            // Check if there's already a job for this element
            $existingJobs = $queue->getJobInfo();
            $hasExistingJob = false;
            foreach ($existingJobs as $job) {
                if (isset($job['class']) && 
                    $job['class'] === GenerateAiAltTextJob::class && 
                    isset($job['data']['elementId']) && 
                    $job['data']['elementId'] === $element->id) {
                    $hasExistingJob = true;
                    break;
                }
            }

            if ($hasExistingJob) {
                Craft::$app->getSession()->setFlash('error', Craft::t('ai-alt-text', 'This image is already being processed. Please wait for it to finish before processing another image.'));
                continue;
            }

            if ($element->kind !== Asset::KIND_IMAGE) {
                continue;
            }

            $queue->push(new GenerateAiAltTextJob([
                'description' => Craft::t('ai-alt-text', 'Generating alt text for {filename}', [
                    'filename' => $element->filename,
                ]),
                'elementId' => $element->id,
            ]));
        }

        return true;
    }
}
