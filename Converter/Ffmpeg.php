<?php
declare(strict_types=1);

namespace MediaHandler\Converter;

use JsonException;
use MediaHandler\Contract\Converter;
use MediaHandler\Contract\ConverterOutputType;
use RuntimeException;
use stdClass;

use function escapeshellarg;
use function exec;
use function filesize;
use function is_file;
use function json_decode;
use function rename;
use function shell_exec;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const JSON_THROW_ON_ERROR;

final class Ffmpeg implements Converter
{
    private int $videoInputFileIndex = 0;
    private int $audioInputFileIndex = 0;
    private int $videoStreamIndex = 0;
    private int $audioStreamIndex = 0;

    public function __construct(
        private readonly string $filePath,
        private readonly ConverterOutputType $outputType,
        private readonly int $niceValue = 19
    ) {
    }

    public function convert(): string
    {
        if (!is_file($this->filePath)) {
            throw new RuntimeException("File '{$this->filePath}' does not exist", 1);
        }

        [
            'dirname' => $dirname,
            'basename' => $basename,
            'filename' => $filename
        ] = pathinfo($this->filePath);

        $tempDir = sys_get_temp_dir();
        $destFile = "{$dirname}/{$basename}.{$this->outputType->format()}";
        $audioStream = false;
        $videoStream = false;

        // Figure out data from the file
        /** @psalm-suppress ForbiddenCode - Much easier than to try to use ffprobe without shell_exec */
        $ffprobe = shell_exec(
                "nice -n {$this->niceValue} ffprobe -loglevel warning -show_streams -show_format -of json " .
                escapeshellarg($this->filePath)
            ) ?? '';

        try {
            /** @var stdClass $ffprobeResponse */
            $ffprobeResponse = json_decode($ffprobe, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException("No video streams found in file '{$filename}'", 2);
        }

        if (empty($ffprobeResponse->streams)) {
            throw new RuntimeException("No video streams found in file '{$filename}'", 3);
        }

        /** @var stdClass[] $streams */
        $streams = $ffprobeResponse->streams;
        foreach ($streams as $stream) {
            // Use the first stream because that's usually the best one
            if ($videoStream === false && $stream->codec_type === 'video') {
                $this->videoStreamIndex = (int)$stream->index;
                $videoStream = $stream;
            }
            if ($audioStream === false && $stream->codec_type === 'audio') {
                $this->audioStreamIndex = (int)$stream->index;
                $audioStream = $stream;
            }
        }

        // If we got a static video stream, loop again and see if we can have a real video instead
        if ($videoStream && $videoStream->avg_frame_rate === '0/0') {
            foreach ($streams as $stream) {
                if ($stream->codec_type === 'video' && $stream->avg_frame_rate !== '0/0') {
                    $videoStream = $stream;
                    break;
                }
            }
        }

        if (
            ($this->outputType->requireVideo() && $videoStream === false) ||
            (!$this->outputType->allowVideo() && $videoStream !== false) ||
            ($this->outputType->requireAudio() && $audioStream === false) ||
            (!$this->outputType->allowAudio() && $audioStream !== false)
        ) {
            throw new RuntimeException("Streams do not match converter requirements in file '{$filename}'", 4);
        }

        // VIDEO CODEC
        if ($videoStream) {
            if (!empty($videoStream->width) && !empty($videoStream->height)) {
                if ($videoStream->width < 640 && $videoStream->height < 360) {
                    $videoWidth = 426;
                    $videoHeight = 240;
                    $preset = 'slow';
                } elseif ($videoStream->width < 854 && $videoStream->height < 480) {
                    $videoWidth = 640;
                    $videoHeight = 360;
                    $preset = 'medium';
                } elseif ($videoStream->width < 1280 && $videoStream->height < 720) {
                    $videoWidth = 854;
                    $videoHeight = 480;
                    $preset = 'medium';
                } elseif ($videoStream->width < 1920 && $videoStream->height < 1080) {
                    $videoWidth = 1280;
                    $videoHeight = 720;
                    $preset = 'fast';
                } else {
                    $videoWidth = 1920;
                    $videoHeight = 1080;
                    $preset = 'faster';
                }
            } else {
                $videoWidth = 854;
                $videoHeight = 480;
                $preset = 'faster';
            }

            $videoFmt = '-c:v libx264 -pix_fmt yuv420p -crf 24 -preset:v ' . escapeshellarg($preset);
            $videoFmt .= ' -profile:v high -level:v 5.1 -filter_complex "';
            $videoFmt .= 'scale=' . escapeshellarg((string)$videoWidth) . ':' . escapeshellarg((string)$videoHeight);
            $videoFmt .= ':force_original_aspect_ratio=decrease,pad=ceil(iw/2)*2:ceil(ih/2)*2,setsar=1';
            $videoFmt .= '" -vsync 2 -r 60';

            // AVG frame rate of 0/0 should mean a static image (cover art)
            if ($videoStream->avg_frame_rate === '0/0') {
                $imageMap = "-map 0:{$this->videoStreamIndex}";
                $this->audioInputFileIndex = 1;
                $this->videoStreamIndex = 0;

                $videoFmt = "{$imageMap} -f image2pipe - | nice -n {$this->niceValue} ffmpeg -hide_banner" .
                    "-loglevel error -r 60 -i pipe: -i " . escapeshellarg($this->filePath) . " {$videoFmt}";
            }
        } else {
            $videoFmt = '-vn';
        }

        // AUDIO CODEC
        if ($audioStream !== false) {
            $bitrate = 192000;
            $streamBitrate = (int)($audioStream->max_bit_rate ?? $audioStream->bit_rate ?? 0);

            if ($streamBitrate !== 0) {
                if ($audioStream->codec_name === 'aac') {
                    // AAC, just round down, leave some space so 127999 does not go to 96000.
                    if ($streamBitrate <= 127000) {
                        $bitrate = 96000;
                    } elseif ($streamBitrate <= 191000) {
                        $bitrate = 128000;
                    }
                } elseif ($streamBitrate < 191000) {
                    $bitrate = 96000;
                } elseif ($streamBitrate < 255000) {
                    $bitrate = 128000;
                }
            }

            // Figure out channel count
            $channels = 2;
            if ((int)$audioStream->channels === 1) {
                $channels = 1;
            }

            // Figure out sample rate
            $sampleRate = 48000;
            if (!empty($audioStream->sample_rate)) {
                $streamSampleRate = (int)$audioStream->sample_rate;
                if ($streamSampleRate !== 0) {
                    if ($streamSampleRate < 9000) {
                        $sampleRate = 8000;
                    } elseif ($streamSampleRate < 12000) {
                        $sampleRate = 11025;
                    } elseif ($streamSampleRate < 23000) {
                        $sampleRate = 22050;
                    } elseif ($streamSampleRate < 45000) {
                        $sampleRate = 44100;
                    }
                }
            }

            $audioFmt = '-c:a aac -ac ' . escapeshellarg((string)$channels);
            $audioFmt .= ' -ar ' . escapeshellarg((string)$sampleRate);
            $audioFmt .= ' -b:a ' . escapeshellarg(($bitrate / 1000) . 'k');
        } else {
            $audioFmt = '-an';
        }

        // Map input streams according to ffprobe
        $streamMap = '';
        if ($videoStream) {
            $streamMap .= " -map {$this->videoInputFileIndex}:{$this->videoStreamIndex}";
        }
        if ($audioStream) {
            $streamMap .= " -map {$this->audioInputFileIndex}:{$this->audioStreamIndex}";
        }

        // Convert
        $tempFile = tempnam($tempDir, 'mediahandler-ffmpeg-');
        $progressFilePath = $tempDir . '/mediahandler-progress-' . $basename . '.txt';
		if (is_file($progressFilePath)) {
			unlink($progressFilePath);
		}
		
        exec(
            "nice -n {$this->niceValue} ffmpeg -hide_banner -loglevel error -i " . escapeshellarg($this->filePath) .
            " {$videoFmt} {$audioFmt} -sn -dn -map_metadata -1 -map_chapters -1 -movflags faststart" .
            " -progress " . escapeshellarg($progressFilePath) . " -nostats" .
            " {$streamMap} -max_muxing_queue_size 9999 -f " . escapeshellarg($this->outputType->format()) . " -y " .
            escapeshellarg($tempFile) . " 2>&1"
        );

        if (is_file($progressFilePath)) {
            unlink($progressFilePath);
        }

        if (!is_file($tempFile) || filesize($tempFile) === 0) {
            if (is_file($tempFile)) {
                unlink($tempFile);
            }
			
			file_put_contents($progressFilePath, 'FAIL');

            throw new RuntimeException(
                "{$filename}: Media conversion failed, converted file does not exist or is empty", 5
            );
        }

        if (is_file($destFile)) {
            unlink($destFile);
        }
        rename($tempFile, $destFile);

        if (!is_file($destFile)) {
			file_put_contents($progressFilePath, 'FAIL');
			
            throw new RuntimeException(
                "{$filename}: Media conversion failed, move after conversion failed", 6
            );
        }

        return $destFile;
    }
}