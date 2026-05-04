<?php

namespace Kdgraphics\BaseTemplate\embeddedvideos;

use Kdgraphics\BaseTemplate\BaseTemplate;
use yii\base\UnknownPropertyException;

class Plugin
{
    public static ?self $plugin = null;

    private BaseTemplate $basePlugin;

    private function __construct(BaseTemplate $basePlugin)
    {
        $this->basePlugin = $basePlugin;
    }

    public static function boot(BaseTemplate $basePlugin): void
    {
        self::$plugin = new self($basePlugin);
    }

    public function getSettings(): object
    {
        return $this->basePlugin->getSettings();
    }

    public function __get(string $name): mixed
    {
        if ($name === 'methods') {
            return $this->basePlugin->getEmbeddedVideos();
        }

        throw new UnknownPropertyException(sprintf('Unknown property: %s::%s', self::class, $name));
    }
}
