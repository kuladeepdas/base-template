<?php

namespace Inspire\Frontend\variables;

use Inspire\Frontend\Frontend;

class PrefetchVariable
{
	/**
	 * Forward all calls to the service
	 *
	 * @param  string $name
	 * @param  array $arguments
	 */
    public function __call($name, $arguments)
    {
        return Frontend::$plugin->prefetch->$name(...$arguments);
    }
}
