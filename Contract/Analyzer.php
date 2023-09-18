<?php
declare(strict_types=1);

namespace MediaHandler\Contract;

interface Analyzer
{
    public function hasAudio(): bool;

    public function hasVideo(): bool;

    public function duration(): float;

    public function isCorrupted(): bool;
}