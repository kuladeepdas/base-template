<?php

namespace Kdgraphics\BaseTemplate\twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class ImagesTwigExtension extends AbstractExtension
{
    private ImagesHelper $imagesHelper;

    public function __construct()
    {
        $this->imagesHelper = new ImagesHelper();
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction(
                'singleImg',
                fn(mixed $image, mixed $size = 768, array $options = [], string $classes = '') =>
                    $this->imagesHelper->singleImg($image, $size, $options, $classes),
                ['is_safe' => ['html']]
            ),
            new TwigFunction(
                'responsiveImg',
                fn(
                    mixed $image,
                    array $styles = [],
                    array $options = [],
                    string $sizes = '100vw',
                    string $classes = ''
                ) => $this->imagesHelper->responsiveImg($image, $styles, $options, $sizes, $classes),
                ['is_safe' => ['html']]
            ),
        ];
    }

    public function getGlobals(): array
    {
        return [
            'images' => $this->imagesHelper,
        ];
    }
}
