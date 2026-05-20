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

        foreach ($query->all() as $asset) {
            if (!$asset instanceof Asset) {
                continue;
            }

            $asset = Asset::find()->id($asset->id)->siteId($query->siteId)->one();
            app(AiAltTextService::class)->createJob($asset, true);
        }

        return true;
    }
}
