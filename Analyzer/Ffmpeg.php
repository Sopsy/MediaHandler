<?php
declare(strict_types=1);

namespace MediaHandler\Analyzer;

use JsonException;
use MediaHandler\Contract\Analyzer;
use RuntimeException;
use stdClass;

use function escapeshellarg;
use function exec;
use function is_file;
use function json_decode;
use function shell_exec;

use const JSON_THROW_ON_ERROR;

final class Ffmpeg implements Analyzer
{
    private ?bool $hasAudio = null;
    private ?bool $hasVideo = null;
    private ?float $duration = null;

    public function __construct(private readonly string $filePath)
    {
        if (!is_file($this->filePath)) {
            throw new RuntimeException("File '{$this->filePath}' does not exist", 1);
        }
    }

    public function isCorrupted(): bool
    {
        exec(
            'nice --adjustment=14 ffmpeg -v error -xerror '
            . '-i ' . escapeshellarg($this->filePath) . ' -f null - 2>&1',
            $output,
            $exitCode
        );

        return $exitCode !== 0;
    }

    public function hasAudio(): bool
    {
        if ($this->hasAudio === null) {
            /** @psalm-suppress ForbiddenCode - Much easier than to try to use ffprobe without shell_exec */
            $audioStreams = shell_exec(
                    'nice --adjustment=19 ffprobe -i ' . escapeshellarg($this->filePath) .
                    ' -show_streams -select_streams a -of json -v quiet'
                ) ?? '';
            try {
                /** @var stdClass $audioInfo */
                $audioInfo = json_decode($audioStreams, false, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return false;
            }

            if (empty($audioInfo->streams)) {
                $this->hasAudio = false;
            } else {
                $this->hasAudio = true;
            }
        }

        return $this->hasAudio;
    }

    public function hasVideo(): bool
    {
        if ($this->hasVideo === null) {
            /** @psalm-suppress ForbiddenCode - Much easier than to try to use ffprobe without shell_exec */
            $videoStreams = shell_exec(
                    'nice --adjustment=19 ffprobe -i ' . escapeshellarg($this->filePath) .
                    ' -show_streams -select_streams v -of json -v quiet'
                ) ?? '';
            try {
                /** @var stdClass $videoInfo */
                $videoInfo = json_decode($videoStreams, false, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return false;
            }

            if (empty($videoInfo->streams)) {
                $this->hasVideo = false;
            } else {
                $this->hasVideo = true;
            }
        }

        return $this->hasVideo;
    }

    public function duration(): float
    {
        if ($this->duration === null) {
            $this->duration = (float)exec(
                'nice --adjustment=19 ffprobe -i ' . escapeshellarg($this->filePath)
                . ' -show_entries format=duration -of csv="p=0" -v quiet'
            );

            if ($this->duration === 0.0) {
                // Probably corrupted headers, try to get the duration with ffmpeg
                $durationMicroseconds = (int)exec(
                    'nice --adjustment=19 ffmpeg -hide_banner -v quiet -i ' . escapeshellarg($this->filePath)
                    . ' -progress - -f null - | grep -o -P "(?<=out_time_us=).*"'
                );

                if ($durationMicroseconds !== 0) {
                    $this->duration = (float)$durationMicroseconds / 1000_000;
                }
            }
        }

        return $this->duration;
    }
}