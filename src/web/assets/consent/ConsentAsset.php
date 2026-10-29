<?php

namespace roogul\consent\web\assets\consent;

use craft\web\AssetBundle;

class ConsentAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->js = ['consent.js'];
        $this->css = ['consent.css'];
        $this->jsOptions = ['defer' => true];

        parent::init();
    }
}
