<?php
declare(strict_types=1);

namespace MediaHandler\Contract;

use MediaHandler\Exception\CorruptFile;
use MediaHandler\Exception\TooBigFile;

interface Handler
{
    /**
     * @throws CorruptFile
     * @throws TooBigFile
     */
    public function handle(): void;

    public function type(): string;

    public function duration(): int;

    public function hasAudio(): bool;

    public function needsProcessing(): bool;

    public function generatesSeparateImage(): bool;

    public function generatedImagePath(): string;
}