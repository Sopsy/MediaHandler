<?php
declare(strict_types=1);

namespace MediaHandler\Handler;

use MediaHandler\Contract\Analyzer;
use MediaHandler\Contract\Handler;
use MediaHandler\Exception\CorruptFile;
use MediaHandler\Exception\TooBigFile;
use RuntimeException;

use function _;
use function round;
use function sprintf;
use function sys_get_temp_dir;
use function touch;

final class M4a implements Handler
{
    public function __construct(
        private readonly int $maxDurationSeconds,
        private readonly Analyzer $analyzer
    ) {
    }

    public function handle(): void
    {
        if ($this->analyzer->isCorrupted()) {
            throw new CorruptFile(_('This file contains errors and can\'t be used.'), 10);
        }

        if (!$this->analyzer->hasAudio()) {
            throw new CorruptFile(_('The file does not seem to contain any audio'), 2);
        }

        if ($this->analyzer->duration() < 0) {
            throw new CorruptFile(_('Media duration is negative? This can\'t be right...'), 4);
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
		
		touch(sys_get_temp_dir() . '/mediahandler-progress-' . $basename . '.txt');
    }

    public function type(): string
    {
        return 'm4a';
    }

    public function duration(): int
    {
        return (int)round($this->analyzer->duration());
    }

    public function hasAudio(): bool
    {
        return true;
    }

    public function needsProcessing(): bool
    {
        return true;
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