<?php
declare(strict_types=1);

namespace MediaHandler\Handler;

use MediaHandler\Analyzer\Ffmpeg;
use MediaHandler\Analyzer\ImageMagick;
use MediaHandler\Contract\Analyzer;
use MediaHandler\Contract\Handler;
use MediaHandler\Exception\TooBigFile;
use MediaHandler\Helper\ImageSizeValidator;

use function _;

final class Gif implements Handler
{
    private readonly Handler $handler;

    public function __construct(
        private readonly string $filePath,
        private readonly int $maxFrames,
        int $maxDurationSeconds,
        private readonly int $maxTotalPixels,
        private readonly int $maxDimensions,
        private readonly Analyzer $analyzer
    ) {
        if ($this->analyzer->hasVideo()) {
            $this->handler = new Mp4($filePath, $maxDurationSeconds, new Ffmpeg($this->filePath));
        } else {
            $this->handler = new Jpg($filePath, $this->maxTotalPixels, $this->maxDimensions, new ImageMagick($this->filePath));
        }
    }

    public function handle(): void
    {
        if ($this->analyzer->duration() > $this->maxFrames) {
            throw new TooBigFile(_('Too many frames in a GIF file'), 1);
        }

        (new ImageSizeValidator($this->filePath, $this->maxTotalPixels))->validate();

        $this->handler->handle();
    }

    public function type(): string
    {
        return $this->handler->type();
    }

    public function duration(): int
    {
        return $this->handler->duration();
    }

    public function hasAudio(): bool
    {
        return $this->handler->hasAudio();
    }

    public function needsProcessing(): bool
    {
        return $this->handler->needsProcessing();
    }

    public function generatesSeparateImage(): bool
    {
        return $this->handler->generatesSeparateImage();
    }

    public function generatedImagePath(): string
    {
        return $this->handler->generatedImagePath();
    }
}