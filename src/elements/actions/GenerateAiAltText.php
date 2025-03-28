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
                if (isset($job['description']) && strpos($job['description'], "Element ID: {$element->id}") !== false) {
                    $hasExistingJob = true;
                    break;
                }
            }

            if ($hasExistingJob) {
                Craft::$app->getSession()->setNotice(Craft::t('ai-alt-text', "{$element->filename} (ID: {$element->id}) is already being processed within an existing queued job. Please wait for the existing job to finish before attempting to process it again."));
                continue;
            }

            if ($element->kind !== Asset::KIND_IMAGE) {
                continue;
            }

            $queue->push(new GenerateAiAltTextJob([
                'description' => Craft::t('ai-alt-text', 'Generating alt text for {filename}, Element ID: {id}', [
                    'filename' => $element->filename,
                    'id' => $element->id,
                ]),
                'elementId' => $element->id,
            ]));
        }

        return true;
    }
}
