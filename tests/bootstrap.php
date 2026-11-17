<?php

// Load Yii and Craft's static helper class so models, validators and Craft::t()
// work without a full Craft application.
define('YII_ENABLE_ERROR_HANDLER', false);
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/vendor/yiisoft/yii2/Yii.php';
require dirname(__DIR__) . '/vendor/craftcms/cms/src/Craft.php';
