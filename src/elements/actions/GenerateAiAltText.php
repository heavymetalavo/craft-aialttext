<?php

namespace heavymetalavo\craftaialttext\elements\actions;

use Craft;
use craft\base\ElementAction;
use craft\elements\Asset;
use craft\elements\db\ElementQueryInterface;
use heavymetalavo\craftaialttext\AiAltText;
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

        $generatedOrQueuedCount = 0;
        $skippedCount = 0;

        foreach ($query->all() as $asset) {
            if (!$asset instanceof Asset) {
                continue;
            }

            // Set the current site id on asset
            $asset = Asset::find()->id($asset->id)->siteId($query->siteId)->one();

            if (!$asset) {
                continue;
            }

            // Skip assets the user isn't allowed to save (saveElement() doesn't enforce this itself).
            // canSave() also covers assets uploaded by other users (savePeerAssets).
            if (!$asset->canSave($user)) {
                $skippedCount++;
                continue;
            }

            // Generates the current site inline and queues any other sites. False means it skipped the asset.
            if (AiAltText::getInstance()->aiAltTextService->createJob($asset, true)) {
                $generatedOrQueuedCount++;
            } else {
                $skippedCount++;
            }
        }

        // Skipping is otherwise invisible: without this the user gets an unqualified
        // success notice even when nothing they selected was processed.
        if ($skippedCount > 0) {
            $this->setMessage(Craft::t('ai-alt-text', 'Generated or queued alt text for {count} of {total} assets; {skipped} skipped.', [
                'count' => $generatedOrQueuedCount,
                'total' => $generatedOrQueuedCount + $skippedCount,
                'skipped' => $skippedCount,
            ]));
        }

        return true;
    }
}
