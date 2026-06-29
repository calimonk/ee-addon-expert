# Design: license-gated release registry (`registry:` update source)

Status: design / not yet built. Captures the agreed approach for updating
**private / commercial** add-ons through a license-keyed endpoint instead of
a GitHub PAT on every customer site.

## Summary

Add a second update-source type to Addon Expert alongside `github:owner/repo`:

```
registry:https://<vendor-host>/releases
```

The customer site sends a **license key** (not a GitHub token). A vendor
worker validates the key, decides which release the key is entitled to,
and returns a signed, short-lived download URL + a `sha256`. The vendor's
**single GitHub token lives only in the worker**; no customer site ever
holds GitHub credentials.

This reuses Codebit's existing license backend — the Cloudflare Worker at
`html-caching-admin.nivoli.workers.dev` (source: `html-edge-cache/admin/worker.js`),
which already does `POST /licenses/verify {key, site_url, plugin_version}`
→ `{active, tier, expires_at, tenant_slug, …}` with KV-backed tenant/tier
state. The client template is `wp-cf-image/src/Service/License.php`.

## Threat model — repo gating is the whole game

The worker's GitHub token can read **multiple** private repos. The danger
is the *confused deputy*: a caller with any valid license tricking the
worker into fetching/serving a repo they aren't entitled to (or leaking
private source). Defenses, layered:

1. **The client never names a repo.** The request carries a `product`
   *slug*, never an `owner/repo`. The worker maps `product → repo`
   server-side. A caller cannot point the worker at an arbitrary repo.
2. **Explicit repo allowlist in worker config.** Even the server-side
   `product → repo` map is constrained to a hardcoded allowlist of
   distributable repos. A config typo can't reach an unintended repo.
3. **Per-license entitlement check.** The license/tenant record lists
   which products that key may fetch. Validated before any GitHub call.
4. **Minimize the token itself.** A **fine-grained PAT scoped to only the
   distribution repos**, `Contents: read` + `Metadata: read`. If it leaks,
   blast radius is just those repos, read-only.
5. **Signed, short-lived, single-artifact URLs.** The returned download
   URL is scoped to one release asset and expires (minutes). Never hand
   back the raw GitHub URL or the token.

So: client says "I'm license `K`, I want `product=cf_image`"; worker says
"key `K` is entitled to `cf_image`, which maps to allowlisted repo
`nivoli/cf-image-pro`, here's a 5-minute signed URL + sha256." The repo
identity is decided entirely server-side.

## Server contract (new worker route)

Fit into the existing `/licenses/*` router; reuse its key-validation +
tenant/tier resolution.

```
POST /releases/latest
  { "key": "<license>", "product": "<slug>", "current_version": "x.y.z" }

200 (entitled, update available or current):
  {
    "ok": true,
    "product": "cf_image",
    "version": "1.11.0",
    "current": false,            // true if current_version is already latest
    "notes": "…changelog…",
    "min_php": "8.1",
    "min_ee": "7.2.0",
    "url": "https://…/dl/<one-time-token>",   // signed, short-lived
    "sha256": "…",
    "size": 123456
  }

402 / 403: { "ok": false, "reason": "expired" | "not_entitled" | "unknown_product" }
401:       { "ok": false, "reason": "invalid_key" }
```

Artifact source — pick one:
- **R2 objects** published on each release (simplest, fastest, fully under
  vendor control). Recommended.
- **GitHub proxy**: worker fetches the private release with its token and
  streams/redirects. No duplicate storage, but couples uptime to GitHub
  and is slower.

Either way the response `url` is a worker-signed URL, not GitHub's.

## Client design (Addon Expert)

Mirror the existing GitHub path so the install/trust/finalize pipeline is
untouched:

- `UpdateSourceRegistry` already resolves a source per add-on. Extend the
  manifest/admin-map syntax to accept `registry:<base-url>` in addition to
  `github:owner/repo`.
- New `Service/RegistryReleaseChecker` with the same shape as
  `GitHubReleaseChecker` (`latest()`, `cached()`, `refresh()`), POSTing
  `{key, product, current_version}` to `<base-url>`. Same 1h cache +
  sentinel-on-failure semantics.
- `ReleaseInstaller` learns to download the manifest's signed `url` and
  **verify the returned `sha256`** before swap. (TOFU repo-identity pinning
  is GitHub-specific; for `registry:` the integrity anchor is the
  vendor-signed URL + sha256, which is at least as strong.)
- License key stored **config/env-first**, settings-field fallback — same
  model as the PAT discussion. Per-add-on key override for multi-vendor.
- Slimmed port of `wp-cf-image/src/Service/License.php` (activate / verify /
  status / offline-grace), so WP and EE share the pattern.

`product` slug resolution: the add-on declares it in `addon.setup.php`
(e.g. `'registry' => ['url' => '…', 'product' => 'cf_image']`), or the
admin sets it on the Releases screen.

## Generic + partner service

- **Generic in Addon Expert.** The `registry:` source takes a configurable
  base URL — any vendor can stand up a manifest endpoint matching the
  contract above and ship add-ons that update through it. Nothing about it
  is Codebit-specific; no hardcoded Nivoli host. This keeps the MIT add-on
  clean (no "why does this free tool phone home").
- **Hosted by Codebit for partners.** Codebit's worker is the reference
  implementation; vendors who don't want to run their own can use Codebit's
  hosted registry (license issuance + R2 hosting + the signed-download
  route). Surface this as a "distribute your private/paid EE add-on through
  Addon Expert — contact us" note on codebit.nl. Onboarding a partner =
  add their repo to the allowlist + their products to the entitlement map +
  issue license keys.

## Security recap

- GitHub token: one fine-grained, read-only, repo-scoped PAT, **worker
  secret only**.
- Repo reachable only via server-side `product → allowlisted repo` map;
  client never names a repo.
- Entitlement checked per license before any fetch.
- Downloads signed + short-lived + sha256-verified client-side.
- Never log the license key or the token (carry over the audit-log guard).

## Explicitly out of scope

- Crawling / scraping the ExpressionEngine.com store for third-party
  add-ons (no public update API; brittle + ToS-risky). Store add-ons stay
  manual-ZIP or their own updater.
- EE.com `vendor-api/import-license` is about entitlement records, not
  update delivery — orthogonal; can be done in parallel for EE-store sales.

## Suggested phasing

1. Worker: `/releases/latest` route + R2 artifact publish + allowlist +
   entitlement check. (Lives in `html-edge-cache/admin/worker.js`.)
2. Addon Expert: `registry:` source + `RegistryReleaseChecker` +
   sha256-verified download + license-key storage + Settings field.
3. Docs + the codebit.nl "partners, contact us" note.

Target: a 2.2.0 / 2.3.0 line for the Addon Expert side once the worker
route exists.
