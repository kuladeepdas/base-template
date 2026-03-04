<?php

namespace Inspire\Frontend\models;

use craft\base\Model;

/**
 * @property-read array $autoloadBundles
 * @property-read array $manifestPath
 */
class Settings extends Model
{
    /** @var array */
    public array $autoloadBundles = [];

    /** @var string */
    public string $manifestPath = '@webroot/manifest.json';
}
