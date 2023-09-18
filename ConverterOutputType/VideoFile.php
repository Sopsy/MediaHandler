<?php
declare(strict_types=1);

namespace MediaHandler\ConverterOutputType;

use MediaHandler\Contract\ConverterOutputType;

final class VideoFile implements ConverterOutputType
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
        return false;
    }

    public function allowVideo(): bool
    {
        return true;
    }

    public function requireVideo(): bool
    {
        return true;
    }
}