<?php
declare(strict_types=1);

namespace MediaHandler\Helper;

use Imagick;
use ImagickException;
use MediaHandler\Exception\CorruptFile;
use MediaHandler\Exception\TooBigFile;

use function _;
use function getimagesize;

final class ImageSizeValidator
{
    public function __construct(
        private readonly string $filePath,
        private readonly int $maxPixels,
    ) {
    }

    /**
     * @throws CorruptFile
     * @throws TooBigFile
     */
    public function validate(): void
    {
        [$width, $height] = getimagesize($this->filePath);
        $width = (int)$width;
        $height = (int)$height;

        if ($width === 0 || $height === 0) {
            // Try imagemagick, GD fails with AVIF at least.
            try {
                $sizes = (new Imagick($this->filePath))->getImageGeometry();
            } catch (ImagickException $e) {
                throw new CorruptFile(_('Could not get image dimensions'), 3, $e);
            }

            if ($sizes['width'] === 0 || $sizes['height'] === 0) {
                throw new CorruptFile(_('Could not get image dimensions'), 2);
            }

            $width = $sizes['width'];
            $height = $sizes['height'];
        }

        if (($width * $height) > $this->maxPixels) {
            throw new TooBigFile(_('Image dimensions exceeds the maximum image size'), 3);
        }
    }
}