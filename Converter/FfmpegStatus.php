<?php
declare(strict_types=1);

namespace MediaHandler\Converter;

use MediaHandler\Model\ProcessingStatus;

use function array_reverse;
use function array_values;
use function file;
use function file_get_contents;
use function is_file;
use function max;
use function min;
use function preg_grep;
use function round;
use function str_replace;
use function sys_get_temp_dir;

use const FILE_IGNORE_NEW_LINES;

final class FfmpegStatus
{
    private readonly string $progressFile;

    public function __construct(
        private readonly string $fileBasename,
        private readonly string $fileDuration,
    ) {
        $this->progressFile = sys_get_temp_dir() . 'mediahandler-progress-' . $this->fileBasename . '.txt';
    }

    public function status(): ProcessingStatus
    {
        if (!is_file($this->progressFile)) {
            return ProcessingStatus::DONE;
        }

        $status = file_get_contents($this->progressFile);
        if ($status === '') {
            return ProcessingStatus::QUEUED;
        }
        if ($status === 'FAIL') {
            return ProcessingStatus::FAILED;
        }

        return ProcessingStatus::PROCESSING;
    }

    public function progress(): int
    {
        /** @var string[] $progressContents */
        $progressContents = array_reverse(file($this->progressFile, FILE_IGNORE_NEW_LINES));
        $durationRow = array_values(preg_grep('/out_time_us=\d+/', $progressContents))[0];
        $duration = (int)round((int)str_replace('out_time_us=', '', $durationRow) / 1000_000, 0);

        return max(0, min(100, (int)round(($duration / $this->fileDuration) * 100, 0)));
    }
}