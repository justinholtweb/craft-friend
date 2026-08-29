<?php

namespace justinholtweb\friends\web\assets\cp;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset as CraftCpAsset;

/**
 * Control panel styling for the log, the pins list and the tester.
 *
 * No build step: one hand-written stylesheet. Editing it needs
 * `craft clear-caches/cp-resources`, or Craft keeps serving the published copy.
 */
class CpAsset extends AssetBundle
{
    public $sourcePath = __DIR__ . '/dist';

    public $depends = [CraftCpAsset::class];

    public $css = ['friends-cp.css'];
}
