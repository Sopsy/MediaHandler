<?php
declare(strict_types=1);

namespace MediaHandler\Contract;

interface ConverterOutputType
{
    public function format(): string;

    public function allowAudio(): bool;

    public function requireAudio(): bool;

    public function allowVideo(): bool;

    public function requireVideo(): bool;
}