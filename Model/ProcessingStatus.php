<?php
declare(strict_types=1);

namespace MediaHandler\Model;

enum ProcessingStatus: string
{
    case FAILED = 'failed';
    case PROCESSING = 'processing';
    case QUEUED = 'queued';
    case DONE = 'done';
}