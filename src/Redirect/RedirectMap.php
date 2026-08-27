<?php

declare(strict_types=1);

/*
 * This file is part of Alengo\SuluRedirectBundle.
 *
 * (c) alengo
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Alengo\SuluRedirectBundle\Redirect;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Loads a webspace's domainless redirect CSV once and exposes an O(1) lookup of
 * old request-URI → new request-URI (both path + optional query, no host).
 *
 * One CSV per webspace, resolved from {csvDir}/{csvPattern} with {webspace} replaced by
 * the webspace key. Parsed maps are memoized per key for the request and, when a cache
 * pool is available, cached across requests keyed by the file's modification time.
 */
class RedirectMap
{
    /** @var array<string, array<string, string>> */
    private array $maps = [];

    public function __construct(
        private readonly string $csvDir,
        private readonly string $csvPattern = '{webspace}_redirects.csv',
        private readonly string $delimiter = ';',
        private readonly ?CacheInterface $cache = null,
    ) {
    }

    /**
     * Returns the redirect target (domainless) for the given request URI within the
     * webspace, or null if none matches.
     */
    public function resolve(string $webspaceKey, string $requestUri): ?string
    {
        return $this->map($webspaceKey)[$requestUri] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private function map(string $webspaceKey): array
    {
        if (isset($this->maps[$webspaceKey])) {
            return $this->maps[$webspaceKey];
        }

        $path = $this->csvDir . '/' . \str_replace('{webspace}', $webspaceKey, $this->csvPattern);

        if (!\is_readable($path)) {
            return $this->maps[$webspaceKey] = [];
        }

        if (null === $this->cache) {
            return $this->maps[$webspaceKey] = $this->parse($path);
        }

        $modifiedAt = \filemtime($path);
        $cacheKey = 'alengo_redirect.map.' . \hash('xxh128', $path) . '.' . ($modifiedAt ?: 0);

        return $this->maps[$webspaceKey] = $this->cache->get(
            $cacheKey,
            fn (ItemInterface $item): array => $this->parse($path),
        );
    }

    /**
     * @return array<string, string>
     */
    private function parse(string $path): array
    {
        $lines = \file($path, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);

        if (false === $lines) {
            return [];
        }

        // Strip a UTF-8 BOM from the first line if present.
        if (isset($lines[0])) {
            $lines[0] = \preg_replace('/^\xEF\xBB\xBF/', '', $lines[0]);
        }

        $map = [];

        foreach ($lines as $line) {
            $row = \str_getcsv((string) $line, $this->delimiter, '"', '\\');

            if (\count($row) < 2) {
                continue;
            }

            $source = \trim((string) ($row[0] ?? ''));
            $target = \trim((string) ($row[1] ?? ''));

            // Domainless: both columns must be absolute paths. Skips comments/garbage.
            if (!\str_starts_with($source, '/') || !\str_starts_with($target, '/')) {
                continue;
            }

            $map[$source] = $target;
        }

        return $map;
    }
}
