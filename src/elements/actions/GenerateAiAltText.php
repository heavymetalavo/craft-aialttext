<?php

namespace heavymetalavo\craftaialttext\elements\actions;

use Craft;
use craft\base\ElementAction;
use craft\elements\Asset;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\Cp;
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

        $queuedCount = 0;
        $skippedCount = 0;

        foreach ($query->all() as $asset) {
            if (!$asset instanceof Asset) {
                continue;
            }

            // ElementQuery::$siteId is mixed - an int, an array of ints, or '*' when the index is
            // showing several sites. Passing an array or '*' straight through returns whichever
            // site row the database happens to order first, so alt text would be generated for an
            // arbitrary site. Resolve one concrete site instead.
            $siteId = is_numeric($query->siteId)
                ? (int)$query->siteId
                : (Cp::requestedSite()?->id ?? $asset->siteId);

            // Set the current site id on asset
            $asset = Asset::find()->id($asset->id)->siteId($siteId)->one();

            if (!$asset) {
                continue;
            }

            // Skip assets the user isn't allowed to save (saveElement() doesn't enforce this itself).
            // canSave() also covers assets uploaded by other users (savePeerAssets).
            if (!$asset->canSave($user)) {
                $skippedCount++;
                continue;
            }

            // Create a job for the asset
            AiAltText::getInstance()->aiAltTextService->createJob($asset, true);
            $queuedCount++;
        }

        // Skipping is otherwise invisible: without this the user gets an unqualified
        // success notice even when nothing they selected was processed.
        if ($skippedCount > 0) {
            $this->setMessage(Craft::t('ai-alt-text', 'Queued {queued} of {total} assets for alt text generation; {skipped} skipped (no permission to save).', [
                'queued' => $queuedCount,
                'total' => $queuedCount + $skippedCount,
                'skipped' => $skippedCount,
            ]));
        }

        return true;
    }
}
