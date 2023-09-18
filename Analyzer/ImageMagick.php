<?php
declare(strict_types=1);

namespace MediaHandler\Analyzer;

use MediaHandler\Contract\Analyzer;
use RuntimeException;

use function escapeshellarg;
use function exec;
use function is_file;

final class ImageMagick implements Analyzer
{
    private ?float $duration = null;

    public function __construct(private readonly string $filePath)
    {
        if (!is_file($this->filePath)) {
            throw new RuntimeException("File '{$this->filePath}' does not exist", 1);
        }
    }

    public function isCorrupted(): bool
    {
        if ($this->duration() === 0.0) {
            return true;
        }

        $errors = (int)exec(
            'nice --adjustment=14 (identify ' . escapeshellarg($this->filePath) . ' > /dev/null)'
            . ' 2>&1 | wc -l'
        );

        return $errors !== 0;
    }

    public function hasAudio(): bool
    {
        return false;
    }

    public function hasVideo(): bool
    {
        return $this->duration() > 1;
    }

    public function duration(): float
    {
        if ($this->duration === null) {
            $this->duration = (float)exec(
                'nice --adjustment=14 identify ' . escapeshellarg($this->filePath) . ' | wc -l'
            );
        }

        return $this->duration;
    }
}