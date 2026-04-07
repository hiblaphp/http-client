<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Interfaces\Builder;

use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Fluent interface for attaching files to a multipart request.
 *
 * All methods implicitly enable multipart/form-data mode and remove
 * any explicit Content-Type header so the transport can append the
 * generated boundary parameter.
 */
interface ConfiguresFilesInterface
{
    /**
     * Attach a single file to the multipart request.
     *
     * $file may be:
     *   - An absolute file path string (must exist and be readable).
     *   - A PHP resource (open file handle).
     *   - A PSR-7 UploadedFileInterface instance.
     *   - A PSR-7 StreamInterface instance.
     *
     * When $filename is null the basename of the path or the PSR-7
     * client filename is used. When $contentType is null the MIME
     * type is detected from the file or defaults to application/octet-stream.
     *
     * @param string  $name Form field name.
     * @param string|resource|UploadedFileInterface|StreamInterface $file
     * @param string|null $filename Override the filename sent to the server.
     * @param string|null $contentType Override the MIME type.
     *
     * @throws \InvalidArgumentException  When $file is not a readable path, resource, StreamInterface, or UploadedFileInterface.
     */
    public function withFile(
        string $name,
        mixed $file,
        ?string $filename = null,
        ?string $contentType = null,
    ): static;

    /**
     * Attach multiple files in one call.
     *
     * Each entry may be any type accepted by withFile(), or an array
     * with the following keys:
     *   - 'path'  (string, required) — absolute file path
     *   - 'name'  (string, optional) — filename override
     *   - 'type'  (string, optional) — MIME type override
     *
     * @param array<string, string|resource|UploadedFileInterface|StreamInterface|array{path: string, name?: string, type?: string}> $files
     */
    public function withFiles(array $files): static;

    /**
     * Convenience method to set multipart fields and attach files in one call.
     *
     * Equivalent to calling withMultipart($data)->withFiles($files).
     *
     * @param array<string, mixed> $data   Form fields to include in the multipart body.
     * @param array<string, string|resource|UploadedFileInterface|StreamInterface|array{path: string, name?: string, type?: string}> $files
     */
    public function multipartWithFiles(array $data = [], array $files = []): static;
}