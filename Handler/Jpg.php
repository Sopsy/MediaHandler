<?php
declare(strict_types=1);

namespace MediaHandler\Handler;

use MediaHandler\Contract\Analyzer;
use MediaHandler\Contract\Handler;
use MediaHandler\Exception\CorruptFile;
use MediaHandler\Helper\ImageSizeValidator;
use RuntimeException;

use function _;
use function escapeshellarg;
use function exec;
use function filesize;
use function is_file;
use function rename;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

final class Jpg implements Handler
{
    public function __construct(
        private readonly string $filePath,
        private readonly int $maxTotalPixels,
        private readonly int $maxDimensions,
        private readonly Analyzer $analyzer
    ) {
    }

    public function handle(): void
    {
        if ($this->analyzer->isCorrupted()) {
            throw new CorruptFile(_('This file contains errors and can\'t be used.'), 10);
        }

        (new ImageSizeValidator($this->filePath, $this->maxTotalPixels))->validate();

        $cmd = 'nice --adjustment=19 convert';

        // Set limits
        $cmd .= ' -limit area 512MiB -limit memory 128MiB -limit map 256MiB -limit disk 1GiB -limit time 60';

        // Input file
        $cmd .= ' ' . escapeshellarg($this->filePath);

        // Keep only the first frame
        $cmd .= '[0]';

        // Reset virtual canvas
        $cmd .= ' +repage';

        // Set filter
        $cmd .= ' -filter triangle';

        // Resize larger than maxDimensions
        $cmd .= " -resize {$this->maxDimensions}x{$this->maxDimensions}\>";

        // Rotate by EXIF rotation tag
        $cmd .= ' -auto-orient';

        // Set quality
        $cmd .= ' -quality 80';

        // Strip color profiles, comments, etc.
        $cmd .= ' -strip';

        // Set white background (for transparent images)
        $cmd .= ' -background white';

        // Remove alpha channel
        $cmd .= ' -alpha remove';

        // Flatten (merge layers)
        $cmd .= ' -flatten';

        // Save as progressive JPEG
        $cmd .= ' -interlace plane';

        // Output file
        $tempFile = tempnam(sys_get_temp_dir(), 'mediahandler-jpg-');
        $cmd .= ' ' . escapeshellarg("jpg:{$tempFile}");

        exec($cmd);

        if (!is_file($tempFile) || filesize($tempFile) === 0) {
            if (is_file($tempFile)) {
                unlink($tempFile);
            }
            throw new RuntimeException('Image conversion resulted in an empty file', 5);
        }

        rename($tempFile, $this->filePath);
    }

    public function type(): string
    {
        return 'jpg';
    }

    public function duration(): int
    {
        return 0;
    }

    public function hasAudio(): bool
    {
        return false;
    }

    public function needsProcessing(): bool
    {
        return false;
    }

    public function generatesSeparateImage(): bool
    {
        return false;
    }

    public function generatedImagePath(): string
    {
        throw new RuntimeException('Tried to get a generated image from ' . __CLASS__ . ' which does not generate one', 6);
    }
}