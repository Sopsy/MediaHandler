<?php
declare(strict_types=1);

use MediaHandler\Converter\FfmpegStatus;
use MediaHandler\Model\ProcessingStatus;

$status = new FfmpegStatus($_GET['basename'], $_GET['duration']);

echo "<p>Status: {$status->status()->value}</p>";
if ($status->status() === ProcessingStatus::PROCESSING) {
    echo "<p>Progress: {$status->progress()}</p>";
}