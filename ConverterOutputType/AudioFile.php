<?php
declare(strict_types=1);

namespace MediaHandler\ConverterOutputType;

use MediaHandler\Contract\ConverterOutputType;

final class AudioFile implements ConverterOutputType
{
    public function format(): string
    {
        return 'mp4';
    }

    public function allowAudio(): bool
    {
        return true;
    }

    public function requireAudio(): bool
    {
        return true;
    }

    public function allowVideo(): bool
    {
        return false;
    }

    public function requireVideo(): bool
    {
        return false;
    }
}