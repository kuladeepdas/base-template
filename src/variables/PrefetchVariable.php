<?php

namespace Kdgraphics\BaseTemplate\variables;

use Kdgraphics\BaseTemplate\BaseTemplate;

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
        return BaseTemplate::$plugin->prefetch->$name(...$arguments);
    }
}
