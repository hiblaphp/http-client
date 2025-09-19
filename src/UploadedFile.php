<?php

namespace Hibla\Http;

use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use InvalidArgumentException;

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
     * @param array $fileSpec A single file specification from $_FILES
     * @return self
     */
    public static function fromArray(array $fileSpec): self
    {
        return new self(
            $fileSpec['tmp_name'] ?? '',
            $fileSpec['size'] ?? null,
            $fileSpec['error'] ?? UPLOAD_ERR_OK,
            $fileSpec['name'] ?? null,
            $fileSpec['type'] ?? null
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getStream(): StreamInterface
    {
        if ($this->error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Cannot retrieve stream due to upload error');
        }

        if ($this->moved) {
            throw new RuntimeException('Cannot retrieve stream after it has been moved');
        }

        if ($this->stream instanceof StreamInterface) {
            return $this->stream;
        }

        if ($this->file && is_readable($this->file)) {
            $resource = fopen($this->file, 'r');
            if ($resource === false) {
                throw new RuntimeException('Unable to open uploaded file for reading');
            }
            return new Stream($resource);
        }

        throw new RuntimeException('No stream available');
    }

    /**
     * {@inheritdoc}
     */
    public function moveTo(string $targetPath): void
    {
        if ($this->moved) {
            throw new RuntimeException('File has already been moved');
        }

        if ($this->error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Cannot move file due to upload error');
        }

        if ($targetPath === '') {
            throw new InvalidArgumentException('Target path cannot be empty');
        }

        $targetDirectory = dirname($targetPath);
        if (!is_dir($targetDirectory) || !is_writable($targetDirectory)) {
            throw new RuntimeException('Target directory is not writable');
        }

        if ($this->file) {
            if (PHP_SAPI === 'cli') {
                if (!rename($this->file, $targetPath)) {
                    throw new RuntimeException('Unable to move uploaded file');
                }
            } else {
                if (!move_uploaded_file($this->file, $targetPath)) {
                    throw new RuntimeException('Unable to move uploaded file');
                }
            }
        } elseif ($this->stream) {
            $dest = fopen($targetPath, 'wb');
            if ($dest === false) {
                throw new RuntimeException('Unable to open target file for writing');
            }

            try {
                $this->stream->rewind();
                while (!$this->stream->eof()) {
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
            throw new RuntimeException('No stream or file available to move');
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