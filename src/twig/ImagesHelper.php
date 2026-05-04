<?php

namespace Kdgraphics\BaseTemplate\twig;

use Craft;
use Twig\Error\LoaderError;

class ImagesHelper
{
    public function singleImg(mixed $image, mixed $size = 768, array $options = [], string $classes = ''): mixed
    {
        return $this->callMacro('singleImg', [$image, $size, $options, $classes]);
    }

    public function responsiveImg(
        mixed $image,
        array $styles = [],
        array $options = [],
        string $sizes = '100vw',
        string $classes = ''
    ): mixed {
        return $this->callMacro('responsiveImg', [$image, $styles, $options, $sizes, $classes]);
    }

    private function callMacro(string $macro, array $args): mixed
    {
        $twig = Craft::$app->getView()->getTwig();
        $templateWrapper = null;

        foreach (['_helpers/images', '_helpers/images.twig'] as $template) {
            try {
                $templateWrapper = $twig->load($template);
                break;
            } catch (LoaderError) {
                // Try next candidate.
            }
        }

        if ($templateWrapper === null) {
            return '';
        }

        $method = 'macro_' . $macro;
        $template = $templateWrapper->unwrap();

        if (!method_exists($template, $method)) {
            return '';
        }

        return $template->$method(...$args);
    }
}

