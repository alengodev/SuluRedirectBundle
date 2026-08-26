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
 * Loads the redirect CSV once and exposes an O(1) lookup of old-URI → new-URI.
 *
 * The parsed map is memoized for the request and, when a cache pool is available, cached
 * across requests keyed by the file's modification time so edits invalidate it automatically.
 */
class RedirectMap
{
    /** @var array<string, string>|null */
    private ?array $map = null;

    public function __construct(
        private readonly string $csvPath,
        private readonly string $delimiter = ';',
        private readonly ?CacheInterface $cache = null,
    ) {
    }

    /**
     * Returns the redirect target for the given absolute request URI, or null if none matches.
     */
    public function resolve(string $uri): ?string
    {
        return $this->map()[$uri] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private function map(): array
    {
        if (null !== $this->map) {
            return $this->map;
        }

        if (!\is_readable($this->csvPath)) {
            return $this->map = [];
        }

        if (null === $this->cache) {
            return $this->map = $this->parse();
        }

        $modifiedAt = \filemtime($this->csvPath);
        $cacheKey = 'alengo_redirect.map.' . \hash('xxh128', $this->csvPath) . '.' . ($modifiedAt ?: 0);

        return $this->map = $this->cache->get($cacheKey, fn (ItemInterface $item): array => $this->parse());
    }

    /**
     * @return array<string, string>
     */
    private function parse(): array
    {
        $lines = \file($this->csvPath, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);

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

            $oldUrl = \trim((string) ($row[0] ?? ''));
            $newUrl = \trim((string) ($row[1] ?? ''));

            if ('' === $oldUrl || '' === $newUrl) {
                continue;
            }

            // Only keep well-formed absolute source URLs; skip comments/garbage lines.
            $parts = \parse_url($oldUrl);
            if (!isset($parts['scheme'], $parts['host'], $parts['path'])) {
                continue;
            }

            $map[$oldUrl] = $newUrl;
        }

        return $map;
    }
}
