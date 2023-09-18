<?php
declare(strict_types=1);

namespace MediaHandler\Model;

use finfo;
use MediaHandler\Analyzer\Ffmpeg;
use MediaHandler\Analyzer\ImageMagick;
use MediaHandler\Contract\Handler;
use MediaHandler\Exception\PublicUploadError;
use MediaHandler\Exception\UnsupportedFileType;
use MediaHandler\Handler\Gif;
use MediaHandler\Handler\Jpg;
use MediaHandler\Handler\M4a;
use MediaHandler\Handler\Mp4;
use HttpMessage\Contract\UploadedFile as UploadedFileMessage;
use RuntimeException;

use function _;
use function dirname;
use function is_file;
use function mime_content_type;
use function rename;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const FILEINFO_MIME_TYPE;
use const UPLOAD_ERR_FORM_SIZE;
use const UPLOAD_ERR_INI_SIZE;
use const UPLOAD_ERR_NO_FILE;
use const UPLOAD_ERR_OK;
use const UPLOAD_ERR_PARTIAL;

final class UploadedFile
{
    private readonly Handler $handler;
    private readonly string $tempFile;
    private bool $fileMoved = false;

    /**
     * @param UploadedFileMessage $uploadedFile
     * @param int $maxTotalPixels
     * @param int $maxDimensions
     * @param int $maxFrames
     * @param int $maxDurationSeconds
     * @throws PublicUploadError
     * @throws UnsupportedFileType
     */
    public function __construct(
        private readonly UploadedFileMessage $uploadedFile,
        int $maxTotalPixels,
        int $maxDimensions,
        int $maxFrames,
        int $maxDurationSeconds,
    ) {
        $mimeType = mime_content_type($this->tempFile());

        if ($mimeType === 'application/octet-stream') {
            // application/octet-stream is a default fallback when the file type is unknown.
            // So try to detect again with our custom magic database which has some additions.
            $mimeType =
                (new finfo(FILEINFO_MIME_TYPE, dirname(__DIR__) . '/custom_magic.mime'))->file($this->tempFile());
        }

        if (!is_file($this->tempFile())) {
            throw new RuntimeException("File '{$this->tempFile()}' does not exist", 1);
        }

        switch ($mimeType) {
            case 'image/avif':
            case 'image/heic':
            case 'image/heif':
            case 'image/jpeg':
            case 'image/pjpeg':
            case 'image/png':
            case 'image/webp':
                $this->handler = new Jpg($this->tempFile(), $maxTotalPixels, $maxDimensions, new ImageMagick($this->tempFile()));
                break;
            case 'image/gif':
                $this->handler = new Gif($this->tempFile(), $maxFrames, $maxDurationSeconds, $maxTotalPixels, $maxDimensions, new ImageMagick($this->tempFile()));
                break;
            case 'audio/aac':
            case 'audio/x-hx-aac-adts';
            case 'audio/flac':
            case 'audio/x-matroska':
            case 'audio/mpeg':
            case 'audio/mp3':
            case 'audio/mp4':
            case 'audio/x-m4a':
            case 'video/mp4':
            case 'video/x-m4v':
            case 'video/quicktime':
            case 'video/3gpp':
            case 'video/x-matroska':
            case 'video/webm':
            case 'audio/webm':
            case 'audio/ogg':
            case 'video/ogg':
            case 'application/ogg':
                $analyzer = new Ffmpeg($this->tempFile());
                if ($analyzer->hasVideo()) {
                    $this->handler = new Mp4($this->tempFile(), $maxDurationSeconds, $analyzer);
                } else {
                    $this->handler = new M4a($maxDurationSeconds, $analyzer);
                }
                break;
            case 'application/octet-stream':
                $this->destroy();
                throw new UnsupportedFileType($mimeType, 2);
            default:
                $this->destroy();
                throw new UnsupportedFileType($mimeType, 1);
        }
    }

    public function destroy(): void
    {
        if (isset($this->tempFile) && is_file($this->tempFile)) {
            unlink($this->tempFile);
        }
    }

    /**
     * @return string Full path to the temporary file
     * @throws PublicUploadError
     */
    public function tempFile(): string
    {
        if (!$this->fileMoved) {
            if ($this->uploadedFile->error() !== UPLOAD_ERR_OK) {
                $this->destroy();

                throw new PublicUploadError(
                    match ($this->uploadedFile->error()) {
                        UPLOAD_ERR_PARTIAL => _('Upload was interrupted. Please try again.'),
                        UPLOAD_ERR_NO_FILE => _('No file was uploaded. Please try again.'),
                        UPLOAD_ERR_FORM_SIZE, UPLOAD_ERR_INI_SIZE => _('Your file is too big.'),
                        default => _('Internal error. We\'ll take a look into it.'),
                    }, $this->uploadedFile->error()
                );
            }
            $tempFile = tempnam(sys_get_temp_dir(), 'mediahandler-upload-');
            $this->uploadedFile->moveTo($tempFile);
            $this->fileMoved = true;
            $this->tempFile = $tempFile;
        }

        return $this->tempFile;
    }

    public function handler(): Handler
    {
        return $this->handler;
    }

    public function type(): string
    {
        return $this->handler->type();
    }

    public function duration(): int
    {
        return $this->handler->duration();
    }

    public function hasAudio(): bool
    {
        return $this->handler->hasAudio();
    }

    /**
     * @param string $destination
     * @return bool
     * @throws PublicUploadError
     */
    public function moveTo(string $destination): bool
    {
        return rename($this->tempFile(), $destination);
    }
}