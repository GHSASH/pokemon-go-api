<?php

declare(strict_types=1);

namespace PokemonGoApi\PogoAPI\Renderer\Types;

use function preg_replace;
use function sprintf;

final class RaidBossGraphic
{
    private string $imageContent;
    private int $imageHeight;
    private int $imageWidth;

    public function __construct(string $imageContent, int $imageWidth, int $imageHeight)
    {
        $this->imageContent = $imageContent;
        $this->imageHeight  = $imageHeight;
        $this->imageWidth   = $imageWidth;
    }

    public function getImageContent(): string
    {
        return sprintf(
            '%s<!-- CONVERT_IMAGE_SIZE=%s -->',
            preg_replace('~\s+~', ' ', $this->imageContent),
            $this->getWindowSize()
        );
    }

    public function getWindowSize(): string
    {
        return sprintf('%d,%d', $this->imageWidth, $this->imageHeight);
    }
}
