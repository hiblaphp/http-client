<?php

namespace Hibla\HttpClient;

use Hibla\HttpClient\Exceptions\HttpStreamException;
use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * PSR-7 compliant uploaded file implementation.
 *
 * Represents a file uploaded through an HTTP request, providing
 * methods to access file metadata, stream the file contents, and
 * move the file to a new location.
 */
class UploadedFile implements UploadedFileInterface
{
    private ?StreamInterface $stream;
    private ?int $size;
    private int $error;
    private ?string $clientFilename;
    private ?string $clientMediaType;
    private bool $moved = false;
    private ?string $file = null;

    /**
     * Create a new uploaded file instance.
     *
     * @param StreamInterface|string|resource $streamOrFile Stream, file path, or resource
     * @param int|null $size The file size in bytes
     * @param int $error One of PHP's UPLOAD_ERR_XXX constants
     * @param string|null $clientFilename The filename sent by the client
     * @param string|null $clientMediaType The media type sent by the client
     */
    public function __construct(
        $streamOrFile,
        ?int $size = null,
        int $error = UPLOAD_ERR_OK,
        ?string $clientFilename = null,
        ?string $clientMediaType = null
    ) {
        if ($error !== UPLOAD_ERR_OK) {
            $this->stream = null;
        } elseif (is_string($streamOrFile)) {
            $this->file = $streamOrFile;
            $this->stream = null;
        } elseif (is_resource($streamOrFile)) {
            $this->stream = new Stream($streamOrFile);
        } elseif ($streamOrFile instanceof StreamInterface) {
            $this->stream = $streamOrFile;
        } else {
            throw new InvalidArgumentException(
                'Invalid stream or file provided for UploadedFile'
            );
        }

        $this->size = $size;
        $this->error = $error;
        $this->clientFilename = $clientFilename;
        $this->clientMediaType = $clientMediaType;
    }

    /**
     * Create an UploadedFile instance from a $_FILES array entry.
     *
     * @param array{tmp_name?: string, size?: int, error?: int, name?: string, type?: string} $fileSpec A single file specification from $_FILES
     * @return self
     */
    public static function fromArray(array $fileSpec): self
    {
        $tmpName = $fileSpec['tmp_name'] ?? '';
        $size = isset($fileSpec['size']) ? (int)$fileSpec['size'] : null;
        $error = isset($fileSpec['error']) ? (int)$fileSpec['error'] : UPLOAD_ERR_OK;
        $name = isset($fileSpec['name']) ? (string)$fileSpec['name'] : null;
        $type = isset($fileSpec['type']) ? (string)$fileSpec['type'] : null;

        return new self(
            $tmpName,
            $size,
            $error,
            $name,
            $type
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getStream(): StreamInterface
    {
        if ($this->error !== UPLOAD_ERR_OK) {
            throw new HttpStreamException('Cannot retrieve stream due to upload error');
        }

        if ($this->moved) {
            throw new HttpStreamException('Cannot retrieve stream after it has been moved');
        }

        if ($this->stream instanceof StreamInterface) {
            return $this->stream;
        }

        if ($this->file !== null && $this->file !== '' && is_readable($this->file)) {
            $resource = fopen($this->file, 'r');
            if ($resource === false) {
                throw new HttpStreamException('Unable to open uploaded file for reading');
            }

            return new Stream($resource);
        }

        throw new HttpStreamException('No stream available');
    }

    /**
     * {@inheritdoc}
     */
    public function moveTo(string $targetPath): void
    {
        if ($this->moved) {
            throw new HttpStreamException('File has already been moved');
        }

        if ($this->error !== UPLOAD_ERR_OK) {
            throw new HttpStreamException('Cannot move file due to upload error');
        }

        if ($targetPath === '') {
            throw new InvalidArgumentException('Target path cannot be empty');
        }

        $targetDirectory = dirname($targetPath);
        if (! is_dir($targetDirectory) || ! is_writable($targetDirectory)) {
            throw new HttpStreamException('Target directory is not writable');
        }

        if ($this->file !== null) {
            if (PHP_SAPI === 'cli') {
                if (! rename($this->file, $targetPath)) {
                    throw new HttpStreamException('Unable to move uploaded file');
                }
            } else {
                if (! move_uploaded_file($this->file, $targetPath)) {
                    throw new HttpStreamException('Unable to move uploaded file');
                }
            }
        } elseif ($this->stream !== null) {
            $dest = fopen($targetPath, 'wb');
            if ($dest === false) {
                throw new HttpStreamException('Unable to open target file for writing');
            }

            try {
                $this->stream->rewind();
                while (! $this->stream->eof()) {
                    $chunk = $this->stream->read(4096);
                    if ($chunk === '') {
                        break;
                    }
                    fwrite($dest, $chunk);
                }
            } finally {
                fclose($dest);
            }
        } else {
            throw new HttpStreamException('No stream or file available to move');
        }

        $this->moved = true;
    }

    /**
     * {@inheritdoc}
     */
    public function getSize(): ?int
    {
        return $this->size;
    }

    /**
     * {@inheritdoc}
     */
    public function getError(): int
    {
        return $this->error;
    }

    /**
     * {@inheritdoc}
     */
    public function getClientFilename(): ?string
    {
        return $this->clientFilename;
    }

    /**
     * {@inheritdoc}
     */
    public function getClientMediaType(): ?string
    {
        return $this->clientMediaType;
    }

    /**
     * Check if the upload was successful.
     */
    public function isValid(): bool
    {
        return $this->error === UPLOAD_ERR_OK;
    }

    /**
     * Get a human-readable error message.
     */
    public function getErrorMessage(): string
    {
        return match ($this->error) {
            UPLOAD_ERR_OK => 'No error',
            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize directive',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE directive',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'Upload stopped by extension',
            default => 'Unknown upload error',
        };
    }
}
