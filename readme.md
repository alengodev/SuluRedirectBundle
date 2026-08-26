# alengo/sulu-redirect-bundle

CSV-driven permanent redirects for Symfony / Sulu sites. Maps legacy URLs to their new
location at `kernel.request`, before routing and security run. Built for site migrations
where a list of old → new URLs has to be honoured with proper `301` responses.

## How it works

A single kernel listener resolves the current absolute request URI against a CSV file
(`old-url;new-url` per line). On a match it returns a `RedirectResponse`; otherwise the
request continues to normal routing untouched. The CSV is parsed into an in-memory hash map
(O(1) lookup) and cached across requests via `cache.app`, keyed by the file's modification
time — editing the CSV invalidates the cache automatically, no `cache:clear` needed.

## Installation

```bash
composer require alengo/sulu-redirect-bundle
```

With Symfony Flex the bundle is registered automatically. Otherwise add it to
`config/bundles.php`:

```php
return [
    // ...
    Alengo\SuluRedirectBundle\AlengoRedirectBundle::class => ['all' => true],
];
```

## Configuration

Create `config/packages/alengo_redirect.yaml`. All keys are optional; defaults shown:

```yaml
alengo_redirect:
    enabled: true
    csv_path: '%kernel.project_dir%/config/app/redirects.csv'
    delimiter: ';'
    allowed_domains: []          # empty = every host; otherwise e.g. ['example.com', 'www.example.com']
    status_code: 301             # 301 permanent, 302 temporary
    priority: 40                 # kernel.request listener priority; must be > 32 (before the router)
```

The listener runs at `kernel.request` with a priority of `40` by default so it fires **before**
Symfony's / Sulu's `RouterListener` (priority `32`). A lower priority would let the router throw a
`404 NotFoundHttpException` for legacy URLs that have no matching route, before the redirect can run.

## The CSV

One redirect per line, source and target separated by the configured delimiter. The source
must be a full absolute URL (scheme + host + path); lines without a valid source URL — such
as comment lines — are skipped. Matching is exact against the incoming absolute URI
(including query string and trailing slash).

```csv
https://www.example.com/old-page.html;https://www.example.com/en/new-page
https://www.example.com/cli/sitemap.php?LNG=de;https://www.example.com/de
```

## License

MIT © alengo
