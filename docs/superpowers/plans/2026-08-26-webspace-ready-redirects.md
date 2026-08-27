# Webspace-ready Redirects Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn `alengo/sulu-redirect-bundle` into a webspace-aware redirector (2.0.0): per-webspace domainless CSVs, allowed domains derived from Sulu's webspaces.xml for the current environment, redirect target on the incoming host.

**Architecture:** A `kernel.request` listener (priority 40, before Sulu's RouterListener at 32) resolves the incoming host to a webspace key via `WebspaceManager`, looks up the domainless request URI in that webspace's CSV (`config/app/{key}_redirects.csv`), and issues a 301 to the same host + target path. Three focused services: `WebspaceHostResolver`, `RedirectMap`, `RedirectListener`.

**Tech Stack:** PHP 8.1+, Symfony 6.4/7 (EventDispatcher, HttpKernel, HttpFoundation, DI, Config, Cache-Contracts), Sulu 2.6/3 (`WebspaceManagerInterface`).

## Global Constraints

- Bundle namespace: `Alengo\SuluRedirectBundle`; bundle class `AlengoRedirectBundle`; config alias `alengo_redirect`.
- Composer constraints (verbatim): `php: ^8.1`; `sulu/sulu: ^2.6 || ^3.0`; `symfony/config`, `symfony/dependency-injection`, `symfony/event-dispatcher`, `symfony/http-foundation`, `symfony/http-kernel`: `^6.4 || ^7.0`; `symfony/cache-contracts: ^2.5 || ^3.0`.
- WebspaceManager service id (concrete; interface is a private alias): `sulu_core.webspace.webspace_manager`.
- Listener priority default: `40` (must be `> 32`, before RouterListener).
- Redirect target stays on the incoming host: `$request->getSchemeAndHttpHost() . $target`.
- CSV is domainless: both columns must start with `/`; delimiter `;`; matching is exact against `$request->getRequestUri()` (query-sensitive).
- No `Co-Authored-By` trailers in this bundle repo's commits.
- Bundle dir: `/Users/alex/Data/DEV/bundles/alengoRedirectBundle`. Project dir: `/Users/alex/Data/Daten/alengo/Projekte/Hasslacher/2026/www`.
- No PHPUnit harness exists in the bundle; the only place the Sulu stack is wired is the project. Verification uses standalone PHP scripts and kernel tests run **from the project** against its installed autoloader, plus PHPStan (level max) and php-cs-fixer.

---

### Task 1: Widen bundle deps + wire project to the local path repo for development

**Files:**
- Modify: `/Users/alex/Data/DEV/bundles/alengoRedirectBundle/composer.json`
- Modify: `/Users/alex/Data/Daten/alengo/Projekte/Hasslacher/2026/www/composer.json`

**Interfaces:**
- Produces: a symlinked `dev-main` install of the bundle in the project's `vendor/`, so all later edits are live-testable from the project.

- [ ] **Step 1: Update bundle `composer.json` require block**

Replace the `require` block in `/Users/alex/Data/DEV/bundles/alengoRedirectBundle/composer.json` with:

```json
    "require": {
        "php": "^8.1",
        "sulu/sulu": "^2.6 || ^3.0",
        "symfony/cache-contracts": "^2.5 || ^3.0",
        "symfony/config": "^6.4 || ^7.0",
        "symfony/dependency-injection": "^6.4 || ^7.0",
        "symfony/event-dispatcher": "^6.4 || ^7.0",
        "symfony/http-foundation": "^6.4 || ^7.0",
        "symfony/http-kernel": "^6.4 || ^7.0"
    },
```

- [ ] **Step 2: Validate the bundle composer.json**

Run: `cd /Users/alex/Data/DEV/bundles/alengoRedirectBundle && composer validate --no-check-publish`
Expected: `./composer.json is valid`

- [ ] **Step 3: Add the path repo back to the project and require dev-main**

In `/Users/alex/Data/Daten/alengo/Projekte/Hasslacher/2026/www/composer.json`, add to the `repositories` array (after the vcs entry):

```json
        {
            "type": "path",
            "url": "/Users/alex/Data/DEV/bundles/alengoRedirectBundle",
            "options": {
                "symlink": true
            }
        }
```

- [ ] **Step 4: Re-resolve the bundle as a symlinked dev-main**

Run:
```bash
cd /Users/alex/Data/Daten/alengo/Projekte/Hasslacher/2026/www && composer require "alengo/sulu-redirect-bundle:dev-main" --no-scripts
```
Expected: `Symlinking from /Users/alex/Data/DEV/bundles/alengoRedirectBundle`

- [ ] **Step 5: Confirm the symlink is live**

Run: `ls -ld /Users/alex/Data/Daten/alengo/Projekte/Hasslacher/2026/www/vendor/alengo/sulu-redirect-bundle`
Expected: output starts with `l` (a symlink).

- [ ] **Step 6: Commit (bundle repo)**

```bash
cd /Users/alex/Data/DEV/bundles/alengoRedirectBundle
git add composer.json
git commit -m "Widen deps to Sulu 2.6+/Symfony 6.4+ and add sulu/sulu dependency"
```

---

### Task 2: RedirectMap — per-webspace, domainless

**Files:**
- Modify (full rewrite): `/Users/alex/Data/DEV/bundles/alengoRedirectBundle/src/Redirect/RedirectMap.php`
- Test (throwaway script): `<scratchpad>/rm2_test.php`

**Interfaces:**
- Produces: `RedirectMap::__construct(string $csvDir, string $csvPattern = '{webspace}_redirects.csv', string $delimiter = ';', ?CacheInterface $cache = null)` and `RedirectMap::resolve(string $webspaceKey, string $requestUri): ?string`.

- [ ] **Step 1: Write the failing verification script**

Create `<scratchpad>/rm2_test.php` (replace `<scratchpad>` with the session scratchpad dir):

```php
<?php
require '/Users/alex/Data/Daten/alengo/Projekte/Hasslacher/2026/www/vendor/autoload.php';
use Alengo\SuluRedirectBundle\Redirect\RedirectMap;

$dir = sys_get_temp_dir() . '/rm2_' . uniqid();
mkdir($dir);
file_put_contents($dir . '/hasslacher_redirects.csv',
    "\xEF\xBB\xBF# comment, skipped\n"
    . "/data/alt.pdf;/media/2979/download/neu.pdf?v=1\n"
    . "/cli/xml_sitemap.php?LNG=de;/de\n"
    . "http://x/full;/nope\n"      // source not domainless -> skipped
    . "/only-one-column\n"          // <2 cols -> skipped
);

$m = new RedirectMap($dir, '{webspace}_redirects.csv', ';', null);
$ok = true;
$ok &= '/media/2979/download/neu.pdf?v=1' === $m->resolve('hasslacher', '/data/alt.pdf');
$ok &= '/de' === $m->resolve('hasslacher', '/cli/xml_sitemap.php?LNG=de');
$ok &= null === $m->resolve('hasslacher', '/data/alt.pdf?utm=x');   // query-sensitive
$ok &= null === $m->resolve('hasslacher', '/full');                 // non-domainless source skipped
$ok &= null === $m->resolve('other', '/data/alt.pdf');              // no CSV for 'other'
echo $ok ? "PASS\n" : "FAIL\n";
array_map('unlink', glob($dir . '/*'));
rmdir($dir);
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php <scratchpad>/rm2_test.php`
Expected: FAIL or a fatal error (old `RedirectMap::resolve` has a different signature).

- [ ] **Step 3: Rewrite `src/Redirect/RedirectMap.php`**

```php
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
```

- [ ] **Step 4: Run the script to verify it passes**

Run: `php <scratchpad>/rm2_test.php`
Expected: `PASS`

- [ ] **Step 5: Commit (bundle repo)**

```bash
cd /Users/alex/Data/DEV/bundles/alengoRedirectBundle
git add src/Redirect/RedirectMap.php
git commit -m "RedirectMap: per-webspace domainless CSV lookup"
```

---

### Task 3: WebspaceHostResolver

**Files:**
- Create: `/Users/alex/Data/DEV/bundles/alengoRedirectBundle/src/Webspace/WebspaceHostResolver.php`

**Interfaces:**
- Consumes: `sulu_core.webspace.webspace_manager` (`WebspaceManagerInterface`).
- Produces: `WebspaceHostResolver::__construct(WebspaceManagerInterface $webspaceManager)` and `WebspaceHostResolver::resolveWebspaceKey(string $host): ?string`.

**Note:** the WebspaceManager is a private service, so it cannot be fetched from a booted
dev container via `get()`. This task therefore only creates and lints the class; its
behaviour (`hasslacher.wip` → `hasslacher`, foreign host → `null`) is verified end-to-end
through the wired kernel test in Task 6 (the `match` vs `foreign` lines prove it).

- [ ] **Step 1: Create `src/Webspace/WebspaceHostResolver.php`**

```php
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

namespace Alengo\SuluRedirectBundle\Webspace;

use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;

/**
 * Resolves an incoming HTTP host to the key of the webspace it belongs to, using the
 * webspaces.xml configuration for the current environment.
 *
 * This runs before Sulu's RequestAnalyzer (which only populates the webspace inside the
 * RouterListener at priority 32), so the webspace is resolved directly via the
 * WebspaceManager. Passing environment = null lets the manager use the current
 * environment it was configured with.
 */
class WebspaceHostResolver
{
    public function __construct(
        private readonly WebspaceManagerInterface $webspaceManager,
    ) {
    }

    public function resolveWebspaceKey(string $host): ?string
    {
        $portalInformations = $this->webspaceManager->findPortalInformationsByHostIncludingSubdomains($host);

        foreach ($portalInformations as $portalInformation) {
            $webspaceKey = $portalInformation->getWebspaceKey();

            if (null !== $webspaceKey && '' !== $webspaceKey) {
                return $webspaceKey;
            }
        }

        return null;
    }
}
```

- [ ] **Step 2: Lint the file**

Run: `php -l /Users/alex/Data/DEV/bundles/alengoRedirectBundle/src/Webspace/WebspaceHostResolver.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit (bundle repo)**

```bash
cd /Users/alex/Data/DEV/bundles/alengoRedirectBundle
git add src/Webspace/WebspaceHostResolver.php
git commit -m "Add WebspaceHostResolver (host -> webspace key via WebspaceManager)"
```

---

### Task 4: RedirectListener — orchestrate

**Files:**
- Modify (full rewrite): `/Users/alex/Data/DEV/bundles/alengoRedirectBundle/src/EventSubscriber/RedirectListener.php`

**Interfaces:**
- Consumes: `WebspaceHostResolver::resolveWebspaceKey(string): ?string`, `RedirectMap::resolve(string, string): ?string`.
- Produces: `RedirectListener::__construct(WebspaceHostResolver $webspaceHostResolver, RedirectMap $redirectMap, int $statusCode = Response::HTTP_MOVED_PERMANENTLY)` and `onKernelRequest(RequestEvent): void`.

- [ ] **Step 1: Rewrite `src/EventSubscriber/RedirectListener.php`**

```php
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

namespace Alengo\SuluRedirectBundle\EventSubscriber;

use Alengo\SuluRedirectBundle\Redirect\RedirectMap;
use Alengo\SuluRedirectBundle\Webspace\WebspaceHostResolver;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Redirects legacy URLs to their new target early in the request lifecycle.
 *
 * Registered on kernel.request at a high priority (configurable, default 40) so it fires
 * before Sulu's RouterListener (priority 32) — which would otherwise throw a 404 for
 * legacy URLs that have no route. The incoming host is mapped to a webspace key; the
 * webspace's CSV is consulted for the (domainless) request URI; on a match a redirect to
 * the same host + target path is issued.
 */
final class RedirectListener
{
    public function __construct(
        private readonly WebspaceHostResolver $webspaceHostResolver,
        private readonly RedirectMap $redirectMap,
        private readonly int $statusCode = Response::HTTP_MOVED_PERMANENTLY,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        $webspaceKey = $this->webspaceHostResolver->resolveWebspaceKey($request->getHost());

        if (null === $webspaceKey) {
            return; // host belongs to no webspace in this environment
        }

        $target = $this->redirectMap->resolve($webspaceKey, $request->getRequestUri());

        if (null === $target) {
            return; // no redirect rule for this URI
        }

        $event->setResponse(new RedirectResponse(
            $request->getSchemeAndHttpHost() . $target,
            $this->statusCode,
        ));
    }
}
```

- [ ] **Step 2: Lint the file**

Run: `php -l /Users/alex/Data/DEV/bundles/alengoRedirectBundle/src/EventSubscriber/RedirectListener.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit (bundle repo)**

```bash
cd /Users/alex/Data/DEV/bundles/alengoRedirectBundle
git add src/EventSubscriber/RedirectListener.php
git commit -m "RedirectListener: host->webspace->CSV->301 on incoming host"
```

---

### Task 5: DI Configuration + Extension

**Files:**
- Modify (full rewrite): `/Users/alex/Data/DEV/bundles/alengoRedirectBundle/src/DependencyInjection/Configuration.php`
- Modify (full rewrite): `/Users/alex/Data/DEV/bundles/alengoRedirectBundle/src/DependencyInjection/AlengoRedirectExtension.php`

**Interfaces:**
- Consumes: the three service classes from Tasks 2–4, and `sulu_core.webspace.webspace_manager`.
- Produces: config keys `enabled`, `csv_dir`, `csv_pattern`, `delimiter`, `status_code`, `priority`; three registered private services with the listener tagged `kernel.event_listener`.

- [ ] **Step 1: Rewrite `src/DependencyInjection/Configuration.php`**

```php
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

namespace Alengo\SuluRedirectBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\HttpFoundation\Response;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('alengo_redirect');

        $treeBuilder->getRootNode()
            ->children()
                ->booleanNode('enabled')
                    ->info('Master switch. When false the listener is not registered at all.')
                    ->defaultTrue()
                ->end()
                ->scalarNode('csv_dir')
                    ->info('Directory holding the per-webspace redirect CSV files.')
                    ->cannotBeEmpty()
                    ->defaultValue('%kernel.project_dir%/config/app')
                ->end()
                ->scalarNode('csv_pattern')
                    ->info('CSV filename pattern; {webspace} is replaced by the webspace key.')
                    ->cannotBeEmpty()
                    ->defaultValue('{webspace}_redirects.csv')
                ->end()
                ->scalarNode('delimiter')
                    ->info('CSV field delimiter.')
                    ->cannotBeEmpty()
                    ->defaultValue(';')
                ->end()
                ->integerNode('status_code')
                    ->info('HTTP status code used for the redirect (301 permanent, 302 temporary).')
                    ->defaultValue(Response::HTTP_MOVED_PERMANENTLY)
                ->end()
                ->integerNode('priority')
                    ->info('kernel.request listener priority. Must be > 32 so the redirect fires before the (Sulu) RouterListener, which would otherwise throw a 404 for legacy URLs that have no route.')
                    ->defaultValue(40)
                ->end()
            ->end();

        return $treeBuilder;
    }
}
```

- [ ] **Step 2: Rewrite `src/DependencyInjection/AlengoRedirectExtension.php`**

```php
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

namespace Alengo\SuluRedirectBundle\DependencyInjection;

use Alengo\SuluRedirectBundle\EventSubscriber\RedirectListener;
use Alengo\SuluRedirectBundle\Redirect\RedirectMap;
use Alengo\SuluRedirectBundle\Webspace\WebspaceHostResolver;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\HttpKernel\KernelEvents;

class AlengoRedirectExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        if (!$config['enabled']) {
            return;
        }

        $resolverDefinition = new Definition(WebspaceHostResolver::class, [
            new Reference('sulu_core.webspace.webspace_manager'),
        ]);
        $resolverDefinition->setPublic(false);
        $container->setDefinition(WebspaceHostResolver::class, $resolverDefinition);

        $mapDefinition = new Definition(RedirectMap::class, [
            $config['csv_dir'],
            $config['csv_pattern'],
            $config['delimiter'],
            // cache.app is always present in a full-stack app; degrade gracefully if not.
            new Reference('cache.app', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]);
        $mapDefinition->setPublic(false);
        $container->setDefinition(RedirectMap::class, $mapDefinition);

        $listenerDefinition = new Definition(RedirectListener::class, [
            new Reference(WebspaceHostResolver::class),
            new Reference(RedirectMap::class),
            $config['status_code'],
        ]);
        $listenerDefinition->setPublic(false);
        $listenerDefinition->addTag('kernel.event_listener', [
            'event' => KernelEvents::REQUEST,
            'method' => 'onKernelRequest',
            'priority' => $config['priority'],
        ]);
        $container->setDefinition(RedirectListener::class, $listenerDefinition);
    }

    public function getAlias(): string
    {
        return 'alengo_redirect';
    }
}
```

- [ ] **Step 3: Clear the project cache and lint the container**

Run:
```bash
cd /Users/alex/Data/Daten/alengo/Projekte/Hasslacher/2026/www && php bin/console cache:clear --no-warmup && php bin/console lint:container
```
Expected: `[OK] The container was linted successfully`

- [ ] **Step 4: Confirm listener priority beats the router**

Run:
```bash
cd /Users/alex/Data/Daten/alengo/Projekte/Hasslacher/2026/www && php bin/console debug:event-dispatcher kernel.request 2>&1 | grep -iE "Router|Redirect" | head -4
```
Expected: `Alengo\SuluRedirectBundle\EventSubscriber\RedirectListener` (priority 40) listed **above** `Sulu\Bundle\WebsiteBundle\EventListener\RouterListener` (priority 32).

- [ ] **Step 5: Commit (bundle repo)**

```bash
cd /Users/alex/Data/DEV/bundles/alengoRedirectBundle
git add src/DependencyInjection/Configuration.php src/DependencyInjection/AlengoRedirectExtension.php
git commit -m "DI: webspace-based config (csv_dir/csv_pattern) and 3-service wiring"
```

---

### Task 6: Migrate project data + config, verify end-to-end

**Files:**
- Rename: `/Users/alex/Data/Daten/alengo/Projekte/Hasslacher/2026/www/config/app/redirects.csv` → `.../config/app/hasslacher_redirects.csv`
- Modify: `/Users/alex/Data/Daten/alengo/Projekte/Hasslacher/2026/www/config/packages/alengo_redirect.yaml`
- Test (throwaway script): reuse `<scratchpad>/redirect_kernel_test.php` (already exists) + one new host.

**Interfaces:**
- Consumes: the whole wired bundle from Tasks 2–5.

- [ ] **Step 1: Rename the CSV and rewrite it domainless**

```bash
cd /Users/alex/Data/Daten/alengo/Projekte/Hasslacher/2026/www
git mv config/app/redirects.csv config/app/hasslacher_redirects.csv 2>/dev/null || mv config/app/redirects.csv config/app/hasslacher_redirects.csv
```

Then set the content of `config/app/hasslacher_redirects.csv` to:

```
# Format (domainless): /alte-pfad-und-query;/neue-pfad-und-query
/data/_dateimanager/produkte/HNT-Produkthinweise-DE.pdf;/media/2979/download/Produkthinweise_HASSLACHER-Gruppe_fin_24-08-2026.pdf?v=1
```

- [ ] **Step 2: Slim down `config/packages/alengo_redirect.yaml`**

Set its full content to:

```yaml
alengo_redirect:
    enabled: true
    # csv_dir defaults to %kernel.project_dir%/config/app
    # csv_pattern defaults to {webspace}_redirects.csv  -> config/app/hasslacher_redirects.csv
    # status_code: 301 # default
    # priority: 40 # default (must stay > 32, before the Sulu router)
```

- [ ] **Step 3: Clear cache**

Run: `cd /Users/alex/Data/Daten/alengo/Projekte/Hasslacher/2026/www && php bin/console cache:clear --no-warmup`
Expected: `[OK]`

- [ ] **Step 4: Kernel test — matching legacy URL redirects on the incoming host**

Create/overwrite `<scratchpad>/redirect_kernel_test.php`:

```php
<?php
use App\Kernel;
use Sulu\Component\HttpKernel\SuluKernel;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;

$root = '/Users/alex/Data/Daten/alengo/Projekte/Hasslacher/2026/www';
require $root . '/vendor/autoload.php';
(new Dotenv())->bootEnv($root . '/.env');

function handle(string $url): array {
    $kernel = new Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG'], SuluKernel::CONTEXT_WEBSITE);
    $response = $kernel->handle(Request::create($url));
    return [$response->getStatusCode(), $response->headers->get('Location')];
}

// 1) known webspace host + matching legacy URL -> 301 on the same host
[$s1, $l1] = handle('https://hasslacher.wip/data/_dateimanager/produkte/HNT-Produkthinweise-DE.pdf');
echo "match:   $s1 $l1\n";

// 2) known webspace host + unknown URL -> not a redirect (routing handles it)
[$s2, $l2] = handle('https://hasslacher.wip/gibt-es-nicht-xyz');
echo "nomatch: $s2 " . ($l2 ?? '(no location)') . "\n";

// 3) foreign host -> listener skips (no webspace) -> not our 301
[$s3, $l3] = handle('https://foo.example.com/data/_dateimanager/produkte/HNT-Produkthinweise-DE.pdf');
echo "foreign: $s3 " . ($l3 ?? '(no location)') . "\n";
```

- [ ] **Step 5: Run the kernel test**

Run: `php <scratchpad>/redirect_kernel_test.php`
Expected:
```
match:   301 https://hasslacher.wip/media/2979/download/Produkthinweise_HASSLACHER-Gruppe_fin_24-08-2026.pdf?v=1
nomatch: 404 (no location)
foreign: 301 https://foo.example.com/media/2979/download/Produkthinweise_HASSLACHER-Gruppe_fin_24-08-2026.pdf?v=1
```
Required assertions:
- `match` MUST be a 301 to exactly that hasslacher.wip URL.
- `nomatch` MUST NOT be a 301 to our media path (404/other non-redirect is correct).
- `foreign` in **dev/stage/test** is a 301 **to the same foreign host** — this is expected
  and accepted (the webspace uses the `{host}` wildcard in those environments; see the
  spec's "Accepted behavior: wildcard-host environments"). It is safe because the target
  host equals the incoming host. In **prod** the same foreign host would return 404 (prod
  pins concrete hosts). So the check here is: `foreign` redirects to **its own** host, never
  to a different domain.

- [ ] **Step 6: Commit (project repo)**

```bash
cd /Users/alex/Data/Daten/alengo/Projekte/Hasslacher/2026/www
git add config/app/hasslacher_redirects.csv config/packages/alengo_redirect.yaml
git commit -m "Redirect bundle: webspace-based domainless CSV (hasslacher_redirects.csv)"
```

---

### Task 7: Quality gates + readme

**Files:**
- Modify: `/Users/alex/Data/DEV/bundles/alengoRedirectBundle/readme.md`

**Interfaces:** none (docs + gates only).

- [ ] **Step 1: PHPStan level max over the bundle**

Run:
```bash
cd /Users/alex/Data/Daten/alengo/Projekte/Hasslacher/2026/www && vendor/bin/phpstan analyze /Users/alex/Data/DEV/bundles/alengoRedirectBundle/src --level max --autoload-file vendor/autoload.php --no-progress
```
Expected: `[OK] No errors`
If errors: fix them inline in the bundle source, re-run until clean.

- [ ] **Step 2: php-cs-fixer over the bundle**

Run:
```bash
cd /Users/alex/Data/DEV/bundles/alengoRedirectBundle && /Users/alex/Data/Daten/alengo/Projekte/Hasslacher/2026/www/vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php
```
Expected: fixes applied or `0 of N files` — either way end state is clean. Re-run with `--dry-run` to confirm `0` fixable.

- [ ] **Step 3: Rewrite the `## Configuration`, `## The CSV`, and `## How it works` sections of `readme.md`**

Set `## How it works` to:

```markdown
## How it works

A single kernel listener (priority `40`, before the router at `32`) resolves the incoming
host to a Sulu webspace via `WebspaceManager` (using the webspaces.xml config for the
current environment). It then looks up the domainless request URI in that webspace's CSV
(`config/app/{webspaceKey}_redirects.csv`) and, on a match, issues a redirect to the same
host + target path. Unknown hosts and unmatched URIs fall through to normal routing.
The CSV is parsed into an in-memory hash map (O(1) lookup) and cached across requests via
`cache.app`, keyed by the file's modification time — editing a CSV invalidates it
automatically, no `cache:clear` needed.
```

Set `## Configuration` to:

```markdown
## Configuration

Create `config/packages/alengo_redirect.yaml`. All keys are optional; defaults shown:

​```yaml
alengo_redirect:
    enabled: true
    csv_dir: '%kernel.project_dir%/config/app'   # directory holding the per-webspace CSVs
    csv_pattern: '{webspace}_redirects.csv'       # {webspace} → webspace key
    delimiter: ';'
    status_code: 301                              # 301 permanent, 302 temporary
    priority: 40                                  # must stay > 32 (before the router)
​```

Allowed domains are **not** configured — the incoming host is matched against Sulu's
webspaces.xml for the current environment. A host that belongs to no webspace is ignored.
```

Set `## The CSV` to:

```markdown
## The CSV

One CSV per webspace, named after the webspace key (e.g. `hasslacher_redirects.csv`). One
redirect per line, source and target separated by the delimiter. Both columns are
**domainless** — an absolute path (with optional query), starting with `/`. Lines whose
source does not start with `/` (comments, blanks) are skipped. Matching is exact against
the incoming request URI (path + query), and the redirect keeps the incoming host.

​```csv
/data/_dateimanager/produkte/HNT-Produkthinweise-DE.pdf;/media/2979/download/Produkthinweise.pdf?v=1
/cli/xml_sitemap.php?LNG=de;/de
​```
```

(Remove the backtick-escaping zero-width marks; they are only shown here to keep this plan's fences intact.)

- [ ] **Step 4: Commit (bundle repo)**

```bash
cd /Users/alex/Data/DEV/bundles/alengoRedirectBundle
git add -A
git commit -m "Docs + quality: webspace-ready readme, PHPStan/cs-fixer clean"
```

---

### Task 8: Release 2.0.0 and switch the project to ^2.0

**Files:**
- Modify: `/Users/alex/Data/Daten/alengo/Projekte/Hasslacher/2026/www/composer.json`
- Modify: `/Users/alex/Data/Daten/alengo/Projekte/Hasslacher/2026/www/CLAUDE.md` (bundle table row)

**Interfaces:** none.

- [ ] **Step 1: Tag and push the bundle 2.0.0**

```bash
cd /Users/alex/Data/DEV/bundles/alengoRedirectBundle
git tag 2.0.0
git push origin main
git push origin 2.0.0
```
Expected: branch + tag pushed.

- [ ] **Step 2: PAUSE — user syncs 2.0.0 into the private Packagist**

Ask the user to sync `alengo/sulu-redirect-bundle` 2.0.0 in the private Packagist (`sulu.repo.packagist.com/alengo/`). Wait for confirmation before continuing.

- [ ] **Step 3: Remove the path repo and switch the project to ^2.0**

In `/Users/alex/Data/Daten/alengo/Projekte/Hasslacher/2026/www/composer.json`:
- remove the `path` repository entry added in Task 1
- set the require to `"alengo/sulu-redirect-bundle": "^2.0"`

Then:
```bash
cd /Users/alex/Data/Daten/alengo/Projekte/Hasslacher/2026/www
rm -rf vendor/alengo/sulu-redirect-bundle
composer update "alengo/sulu-redirect-bundle" --no-scripts
```
Expected: `Installing alengo/sulu-redirect-bundle (2.0.0)` from Packagist (a real dir, not a symlink).

- [ ] **Step 4: Final verification against the Packagist install**

Run:
```bash
cd /Users/alex/Data/Daten/alengo/Projekte/Hasslacher/2026/www && php bin/console cache:clear --no-warmup && php <scratchpad>/redirect_kernel_test.php
```
Expected: same as Task 6 Step 5 (`match: 301 …`, `nomatch: 404`, `foreign: 404`).

- [ ] **Step 5: Update the CLAUDE.md bundle table row**

Replace the `alengoRedirectBundle` row in `/Users/alex/Data/Daten/alengo/Projekte/Hasslacher/2026/www/CLAUDE.md` with:

```markdown
| `alengoRedirectBundle` | `alengo/sulu-redirect-bundle` | Webspace-aware CSV redirects: per-webspace domainless CSV `config/app/{webspaceKey}_redirects.csv`, allowed hosts derived from webspaces.xml + environment, 301 on the incoming host at `kernel.request` (priority 40, before the router). Config: `config/packages/alengo_redirect.yaml`. Distinct from `sulu/redirect-bundle` (admin-managed routes) |
```

- [ ] **Step 6: Commit (project repo)**

```bash
cd /Users/alex/Data/Daten/alengo/Projekte/Hasslacher/2026/www
git add composer.json composer.lock CLAUDE.md
git commit -m "Use alengo/sulu-redirect-bundle ^2.0 (webspace-ready) from Packagist"
```

---

## Notes for the implementer

- The bundle is developed live via the path-repo symlink (Task 1) so edits in
  `/Users/alex/Data/DEV/bundles/alengoRedirectBundle` are immediately visible in the
  project's `vendor/`. Only Task 8 switches back to the Packagist install.
- `<scratchpad>` = the session scratchpad directory. Throwaway test scripts live there,
  never in the project or bundle repo.
- `.env.local` and `.env` reads via Bash/Read may be permission-blocked; the kernel test
  scripts boot the kernel themselves (which reads env internally) and sidestep that.
- Every bundle commit: no `Co-Authored-By` trailer.
