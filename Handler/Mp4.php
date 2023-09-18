<?php
declare(strict_types=1);

namespace MediaHandler\Handler;

use MediaHandler\Contract\Analyzer;
use MediaHandler\Contract\Handler;
use MediaHandler\Exception\CorruptFile;
use MediaHandler\Exception\TooBigFile;
use RuntimeException;

use function _;
use function escapeshellarg;
use function exec;
use function filesize;
use function is_file;
use function round;
use function sprintf;
use function unlink;
use function sys_get_temp_dir;
use function touch;

final class Mp4 implements Handler
{
    private readonly string $generatedThumbPath;

    public function __construct(
        private readonly string $filePath,
        private readonly int $maxDurationSeconds,
        private readonly Analyzer $analyzer
    ) {
        $this->generatedThumbPath = "{$this->filePath}.jpg";
    }

    public function handle(): void
    {
        if ($this->analyzer->isCorrupted()) {
            throw new CorruptFile(_('This file contains errors and can\'t be used.'), 10);
        }

        if (!$this->analyzer->hasVideo()) {
            throw new CorruptFile(_('The file does not seem to contain any video'), 2);
        }

        if ($this->analyzer->duration() < 0) {
            throw new CorruptFile(_('The file reports a negative duration. This can\'t be right...'), 4);
        }

        if ($this->analyzer->duration() === 0.0) {
            throw new CorruptFile(_('Cannot determine duration of the media'), 3);
        }

        if ($this->analyzer->duration() > $this->maxDurationSeconds) {
            throw new TooBigFile(
                sprintf(
                    _('Length: %d minutes, max length: %d minutes'),
                    (int)$this->analyzer->duration() / 60,
                    $this->maxDurationSeconds / 60
                ), 4
            );
        }

        exec(
            'nice --adjustment=19 ffmpeg -i ' . escapeshellarg($this->filePath) . ' -vframes 1 -f image2 '
            . escapeshellarg($this->generatedThumbPath)
        );

        if (!is_file($this->generatedThumbPath) || filesize($this->generatedThumbPath) === 0) {
            if (is_file($this->generatedThumbPath)) {
                unlink($this->generatedThumbPath);
            }
            throw new RuntimeException('Generating a video thumbnail failed', 5);
        
		
		touch(sys_get_temp_dir() . '/mediahandler-progress-' . $basename . '.txt');
    }

    public function type(): string
    {
        return 'mp4';
    }

    public function duration(): int
    {
        return (int)round($this->analyzer->duration());
    }

    public function hasAudio(): bool
    {
        return $this->analyzer->hasAudio();
    }

    public function needsProcessing(): bool
    {
        return true;
    }

    public function generatesSeparateImage(): bool
    {
        return true;
    }

    public function generatedImagePath(): string
    {
        return $this->generatedThumbPath;
    }
}