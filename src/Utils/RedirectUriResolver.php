<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Utils;

use Hibla\HttpClient\Uri;
use Psr\Http\Message\UriInterface;

/**
 * Utility class for resolving relative and absolute URIs during HTTP redirects.
 */
final class RedirectUriResolver
{
    /**
     * Resolves a relative or absolute Location header against the base URI.
     *
     * @param UriInterface $base The base URI (the original request URI).
     * @param string $location The Location header value from the redirect response.
     *
     * @return UriInterface The fully resolved absolute URI.
     */
    public static function resolve(UriInterface $base, string $location): UriInterface
    {
        $locationUri = new Uri($location);

        if ($locationUri->getScheme() !== '') {
            return $locationUri;
        }

        if ($locationUri->getHost() !== '') {
            return $locationUri->withScheme($base->getScheme());
        }

        $newUri = $base->withQuery($locationUri->getQuery())
            ->withFragment($locationUri->getFragment())
        ;

        if ($locationUri->getPath() === '') {
            return $newUri;
        }

        if (\str_starts_with($locationUri->getPath(), '/')) {
            return $newUri->withPath(self::removeDotSegments($locationUri->getPath()));
        }

        $basePath = $base->getPath();
        if ($basePath === '') {
            $basePath = '/';
        }

        $lastSlashPos = \strrpos($basePath, '/');
        $dir = $lastSlashPos !== false ? \substr($basePath, 0, $lastSlashPos + 1) : '/';
        $mergedPath = $dir . $locationUri->getPath();

        return $newUri->withPath(self::removeDotSegments($mergedPath));
    }

    /**
     * Removes dot segments from a path per RFC 3986 section 5.2.4.
     */
    private static function removeDotSegments(string $path): string
    {
        if (! \str_contains($path, '.')) {
            return $path;
        }

        $parts = \explode('/', $path);
        $result = [];

        foreach ($parts as $part) {
            if ($part === '.' || $part === '') {
                continue;
            }
            if ($part === '..') {
                \array_pop($result);
            } else {
                $result[] = $part;
            }
        }

        $newPath = '/' . \implode('/', $result);

        if (\str_ends_with($path, '/') || \str_ends_with($path, '/.') || \str_ends_with($path, '/..')) {
            if ($newPath !== '/') {
                $newPath .= '/';
            }
        }

        return $newPath;
    }
}
