<?php

namespace heavymetalavo\craftaialttext\elements\actions;

use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Element\Actions\ElementAction;
use CraftCms\Cms\Element\Queries\Contracts\ElementQueryInterface;
use CraftCms\Cms\Support\Facades\HtmlStack;
use CraftCms\Cms\Support\Facades\InputNamespace;
use heavymetalavo\craftaialttext\services\AiAltTextService;
use Illuminate\Support\Facades\Auth;

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
        $user = Auth::user();

        if (!$user) {
            throw new \LogicException('User not logged in');
        }

        $queuedCount = 0;
        $skippedCount = 0;

        $queuedCount = 0;
        $skippedCount = 0;

        foreach ($query->all() as $asset) {
            if (!$asset instanceof Asset) {
                continue;
            }

            $asset = Asset::find()->id($asset->id)->siteId($query->siteId)->one();

            if (!$asset) {
                continue;
            }

            // Skip assets the user isn't allowed to save (saving doesn't enforce this itself).
            // canSave() also covers assets uploaded by other users (savePeerAssets).
            if (!$asset->canSave($user)) {
                $skippedCount++;
                continue;
            }

            app(AiAltTextService::class)->createJob($asset, true);
            $queuedCount++;
        }

        // Skipping is otherwise invisible: without this the user gets an unqualified
        // success notice even when nothing they selected was processed.
        if ($skippedCount > 0) {
            $this->setMessage(t('Queued {queued} of {total} assets for alt text generation; {skipped} skipped (no permission to save).', [
                'queued' => $queuedCount,
                'total' => $queuedCount + $skippedCount,
                'skipped' => $skippedCount,
            ], category: 'ai-alt-text'));
        }

        return true;
    }
}
