# Design: Webspace-ready redirects (alengo/sulu-redirect-bundle 2.0.0)

Date: 2026-08-26
Status: Approved (pending spec review)

## Goal

Turn the flat, single-CSV redirect bundle into a **webspace-aware** one:

- Allowed domains are no longer configured by hand — they are derived from Sulu's
  `webspaces/*.xml` for the **current environment**.
- Each webspace has its own CSV, named after the webspace key
  (e.g. `hasslacher_redirects.csv`).
- CSV lines are **domainless** (path + optional query only). The redirect target stays
  on the **incoming host**.

This is a breaking change → released as **2.0.0**.

## Why the webspace must be resolved manually

The redirect listener runs at `kernel.request` priority **40**, before Sulu's
`RouterListener` (priority 32) so unmatched legacy URLs redirect instead of 404ing.
Sulu's `RequestAnalyzer` only populates the current webspace **inside** the
`RouterListener` (priority 32) — i.e. *after* our listener. Therefore we cannot use
`RequestAnalyzer` and must resolve host → webspace ourselves via `WebspaceManager`.

`WebspaceManagerInterface` is injected with the current environment and, when called
with `environment = null`, resolves against that current environment automatically.
This is exactly the "allowed domains from webspaces.xml + environment" requirement.

## Data flow (RedirectListener::onKernelRequest)

1. Skip if not the main request.
2. `$host = $request->getHost()`.
3. `$webspaceKey = WebspaceHostResolver::resolveWebspaceKey($host)`
   - Uses `WebspaceManager::findPortalInformationsByHostIncludingSubdomains($host)`
     (env = `null` → current environment).
   - Returns the first match's `getWebspaceKey()`, or `null`.
   - `null` → host belongs to no webspace in this environment → **skip** (replaces the
     old `allowed_domains` list entirely).
4. `$source = $request->getRequestUri()` — domainless (path + query, no scheme/host).
5. `$target = RedirectMap::resolve($webspaceKey, $source)`.
   - Loads `config/app/{webspaceKey}_redirects.csv`, exact match on the source column.
   - `null` → no rule → **skip**.
6. `$event->setResponse(new RedirectResponse($request->getSchemeAndHttpHost() . $target, $statusCode))`.
   - Target is a domainless path → prefixed with the incoming scheme+host.

## CSV format (domainless)

File: `config/app/{webspaceKey}_redirects.csv`, one rule per line, delimiter `;`.
Both columns are domainless and start with `/`:

```
/data/_dateimanager/produkte/HNT-Produkthinweise-DE.pdf;/media/2979/download/Produkthinweise_HASSLACHER-Gruppe_fin_24-08-2026.pdf?v=1
/cli/xml_sitemap.php?LNG=de;/de
```

- Lines whose source column does **not** start with `/` (comments, blanks, garbage) are
  skipped. This replaces the old `parse_url` scheme+host+path validation.
- Matching is **exact** against `$request->getRequestUri()`, i.e. query-sensitive
  (`/path` does not match `/path?utm=x`) — same semantics as 1.x. In prod the
  http-cache-bundle strips tracking params upstream, so exact matches are the norm.

## Components (bundle `src/`)

- **`Webspace\WebspaceHostResolver`**
  - Depends on `WebspaceManagerInterface`.
  - `resolveWebspaceKey(string $host): ?string`.
  - Encapsulates all "domains from XML + environment" logic; independently testable.
- **`Redirect\RedirectMap`**
  - `resolve(string $webspaceKey, string $requestUri): ?string`.
  - Lazily loads & caches **per webspace key** the file
    `{csvDir}/{filename from csvPattern}`. O(1) hash map `[source => target]`.
  - Cross-request cache via `cache.app`, key includes the file `filemtime` (auto-invalidates
    on edit). Memoized per key within the request.
  - CSV parse: BOM strip, `str_getcsv` with the configured delimiter, keep rows with ≥2
    columns whose source starts with `/`.
- **`EventSubscriber\RedirectListener`**
  - Orchestrates steps 1–6 above. Registered via `kernel.event_listener` tag
    (event `kernel.request`, method `onKernelRequest`, priority from config).
- **`DependencyInjection\{Configuration, AlengoRedirectExtension}`**
  - Registers the three services programmatically; when `enabled: false`, nothing is
    registered.

## Configuration (2.0.0)

```yaml
alengo_redirect:
    enabled: true
    csv_dir: '%kernel.project_dir%/config/app'   # directory holding the per-webspace CSVs
    csv_pattern: '{webspace}_redirects.csv'       # filename pattern; {webspace} → webspace key
    delimiter: ';'
    status_code: 301
    priority: 40
```

- **Removed:** `csv_path`, `allowed_domains`.
- **Added:** `csv_dir` (default `%kernel.project_dir%/config/app`), `csv_pattern`
  (default `{webspace}_redirects.csv`).

## Dependencies (composer.json)

Broad constraints, so the bundle is reusable in older alengo Sulu projects:

| Package | Constraint |
|---|---|
| `php` | `^8.1` |
| `sulu/sulu` | `^2.6 \|\| ^3.0` |
| `symfony/config` `symfony/dependency-injection` `symfony/event-dispatcher` `symfony/http-foundation` `symfony/http-kernel` | `^6.4 \|\| ^7.0` |
| `symfony/cache-contracts` | `^2.5 \|\| ^3.0` |

All APIs used exist in Symfony 6.4. The `WebspaceManager` methods
(`findPortalInformationsByHostIncludingSubdomains`, `PortalInformation::getWebspaceKey`)
are long-standing Sulu 2.x APIs.

**Test caveat:** functional (kernel) tests run only against the installed Sulu 3.0.4 /
Symfony 7.4. Sulu 2.6 compatibility is argued from stable-API usage, not executed here.

## Error handling / edge cases

- Host not in any webspace → skip; routing proceeds (normal 404 if no route).
- Webspace has no CSV file / unreadable / empty → `resolve` returns `null` → skip.
- CSV row with <2 columns or a source not starting with `/` → skipped.
- Sub-requests → skipped (`isMainRequest`).

## Accepted behavior: wildcard-host environments

The `hasslacher` webspace uses `<url>{host}/{localization}</url>` for the `dev`, `stage`
and `test` environments — Sulu's `{host}` wildcard, which matches **any** hostname. So in
those environments `WebspaceHostResolver` resolves *every* host to the `hasslacher`
webspace, and a redirect fires for any host that requests a matching CSV path. Only `prod`
pins concrete hosts (`hasslacher.alengo.dev` + custom-url `hasslacher.com`), where a
foreign host correctly resolves to no webspace → no redirect.

This is accepted as-is (decision 2026-08-26), for two reasons:

1. It is the faithful consequence of the webspace configuration — the wildcard *is* the
   configured "allowed domain" in those environments.
2. It is safe: the redirect target is always the **incoming** host
   (`$request->getSchemeAndHttpHost() . $target`), so a foreign host is only ever
   redirected to a path on *itself* — there is no cross-domain open-redirect vector.

Consequence for verification: in `dev`, a foreign host returns a 301 (to itself), not a
404. The meaningful assertions are therefore `match → 301 (correct target)` and
`nomatch → not our 301`.

## Project changes (Hasslacher)

- `composer.json`: `alengo/sulu-redirect-bundle: ^2.0`.
- `config/app/redirects.csv` → `config/app/hasslacher_redirects.csv`, rewritten domainless:
  `/data/_dateimanager/produkte/HNT-Produkthinweise-DE.pdf;/media/2979/download/Produkthinweise_HASSLACHER-Gruppe_fin_24-08-2026.pdf?v=1`
- `config/packages/alengo_redirect.yaml`: drop `allowed_domains` and the `priority: 40`
  override (40 is now the default); keep it minimal (or empty).

## Verification

- `WebspaceHostResolver`: `hasslacher.wip` → `hasslacher`; a foreign host → `null`.
- `RedirectMap`: temp domainless CSV → exact match returns target, comment/garbage skipped,
  missing file → `null`.
- Kernel test (Sulu 3.0.4): request `https://hasslacher.wip/data/…HNT-Produkthinweise-DE.pdf`
  → 301 → `https://hasslacher.wip/media/2979/download/…`; request on an unknown host →
  no redirect (falls through to routing).
- `debug:event-dispatcher kernel.request` shows RedirectListener (40) before RouterListener (32).
- PHPStan level max clean; php-cs-fixer clean.

## Release

- Bundle 2.0.0, tag `2.0.0`, push. Packagist sync by the user.
- After sync: project `composer update` to `^2.0`, then final live verification.
