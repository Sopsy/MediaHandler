<?php
declare(strict_types=1);

use MediaHandler\Converter\Ffmpeg;
use MediaHandler\ConverterOutputType\AudioFile;
use MediaHandler\ConverterOutputType\VideoFile;
use MediaHandler\Exception\CorruptFile;
use MediaHandler\Exception\PublicUploadError;
use MediaHandler\Exception\TooBigFile;
use MediaHandler\Exception\UnsupportedFileType;
use MediaHandler\Model\UploadedFile;
use HttpMessage\Exception\PublicErrorException;
use HttpMessage\Message\UploadedFile as UploadedFileMessage;

$files = UploadedFileMessage::fromSuperglobals($_FILES);

if (empty($files)) {
    echo '<form method="POST" enctype="multipart/form-data">
        <input type="file" multiple name="files" />
        <input type="submit" value="submit" />
    </form>';
    die();
}

foreach ($files['files'] as $file) {
    try {
        $uploadedFile = new UploadedFile($file, 50_000_000, 4096, 4000, 900);
    } catch (UnsupportedFileType $e) {
        echo sprintf(
            _("The type of the file you sent (%s) is not supported. Your file might be corrupted."),
            $e->getMessage()
        );
        echo '<br />';
        continue;
    } catch (PublicUploadError $e) {
        echo sprintf(_("File upload failed: %s"), $e->getMessage());
        echo '<br />';
        continue;
    }

    try {
        $uploadedFile->handler()->handle();
    } catch (CorruptFile $e) {
        $uploadedFile->destroy();
        echo sprintf(_('The file you sent is corrupted: %s'), $e->getMessage());
        echo '<br />';
        continue;
    } catch (TooBigFile $e) {
        $uploadedFile->destroy();
        echo sprintf(_('The file you sent is too big: %s'), $e->getMessage());
        echo '<br />';
        continue;
    }

    $destPath = sys_get_temp_dir();
    $filename = hrtime(true);
    if ($uploadedFile->handler()->generatesSeparateImage()) {
        rename(
            $uploadedFile->handler()->generatedImagePath(),
            "{$destPath}/{$filename}-thumb.jpg"
        );
    }

    $uploadedFile->moveTo("{$destPath}/{$filename}.{$uploadedFile->type()}");

    if ($uploadedFile->handler()->needsProcessing()) {
        try {
            if ($uploadedFile->type() === 'm4a') {
                (new Ffmpeg($file, new AudioFile()))->convert();
            } elseif ($uploadedFile->type() === 'mp4') {
                (new Ffmpeg($file, new VideoFile()))->convert();
            }
        } catch (Throwable $e) {
            echo "Handling failed ({$e->getFile()}:{$e->getLine()}): '{$e->getMessage()}' (Code: {$e->getCode()})<br />";
        }
    }

    echo "Uploaded {$file->clientName()} as {$destPath}/{$filename}.{$uploadedFile->type()}.<br />";
}
