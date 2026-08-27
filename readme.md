# alengo/sulu-redirect-bundle

CSV-driven permanent redirects for Symfony / Sulu sites. Maps legacy URLs to their new
location at `kernel.request`, before routing and security run. Built for site migrations
where a list of old → new URLs has to be honoured with proper `301` responses.

## How it works

A single kernel listener (priority `40`, before the router at `32`) resolves the incoming
host to a Sulu webspace via `WebspaceManager` (using the webspaces.xml config for the
current environment). It then looks up the domainless request URI in that webspace's CSV
(`config/app/{webspaceKey}_redirects.csv`) and, on a match, issues a redirect to the same
host + target path. Unknown hosts and unmatched URIs fall through to normal routing.
The CSV is parsed into an in-memory hash map (O(1) lookup) and cached across requests via
`cache.app`, keyed by the file's modification time — editing a CSV invalidates it
automatically, no `cache:clear` needed.

### Environments (wildcard hosts)

In `dev`/`stage`/`test`, Sulu webspaces typically declare the `{host}` wildcard, so
**any** host resolves to the webspace and redirects fire regardless of the incoming
domain. This is safe by construction: the redirect target is always built on the
**incoming host** (`$request->getSchemeAndHttpHost() . $target`), so a match on
`foo.example.com` redirects to `foo.example.com`, never to a different domain. In `prod`,
webspaces pin concrete hosts, so only real production hosts match and everything else
falls through to normal routing (typically a `404`).

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
    csv_dir: '%kernel.project_dir%/config/app'   # directory holding the per-webspace CSVs
    csv_pattern: '{webspace}_redirects.csv'       # {webspace} → webspace key
    delimiter: ';'
    status_code: 301                              # 301 permanent, 302 temporary
    priority: 40                                  # must stay > 32 (before the router)
```

Allowed domains are **not** configured — the incoming host is matched against Sulu's
webspaces.xml for the current environment. A host that belongs to no webspace is ignored.

## The CSV

One CSV per webspace, named after the webspace key (e.g. `hasslacher_redirects.csv`). One
redirect per line, source and target separated by the delimiter. Both columns are
**domainless** — an absolute path (with optional query), starting with `/`. Lines whose
source does not start with `/` (comments, blanks) are skipped. Matching is exact against
the incoming request URI (path + query), and the redirect keeps the incoming host.

```csv
/data/_dateimanager/produkte/HNT-Produkthinweise-DE.pdf;/media/2979/download/Produkthinweise.pdf?v=1
/cli/xml_sitemap.php?LNG=de;/de
```

## License

MIT © alengo
