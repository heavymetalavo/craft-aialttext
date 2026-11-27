<?php

namespace heavymetalavo\craftaialttext\web\assets\utility;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * Styles and script for the AI Alt Text utility's alt text bar.
 */
class UtilityAsset extends AssetBundle
{
    /**
     * @inheritdoc
     */
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [CpAsset::class];
        $this->css = ['utility.css'];
        $this->js = ['utility.js'];

        parent::init();
    }
}
