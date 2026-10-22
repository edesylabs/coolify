# PHP Security Patch Plan — php2shell (CVE-2026-109618 / CVE-2026-109619)

**Status:** Implemented in the working tree (Option A, v4.5.3). Not yet committed or rolled out · **Priority:** Critical / urgent · **Created:** 2026-10-22

## 1. Summary

| Item | Detail |
|---|---|
| CVEs | CVE-2026-109618 (memory corruption during OOM handling), CVE-2026-109619 (use-after-free in state persisted between requests) |
| Severity | CVSS v4.0 **9.2** each — unauthenticated remote code execution as the web worker user |
| Affected | Every PHP SAPI serving web requests, **including PHP-FPM (what Coolify uses)**. All versions back to 5.4 affected by at least one bug |
| Fixed in | **PHP 8.4.27** (also 8.5.12, 8.3.36, 8.2.35) — released 2026-10-21 |
| Workaround | None. Upgrading the PHP runtime is the only fix |
| Exploitation | Details leaked pre-patch; early in-the-wild exploitation reported; public exploit expected |

**No application code or `composer.json` changes are needed.** The vulnerable component is the PHP runtime shipped in our Docker base image `serversideup/php`.

## 2. Current State (findings)

### 2.1 Base image is a floating tag

```
docker/production/Dockerfile:3   ARG SERVERSIDEUP_PHP_VERSION=8.4-fpm-nginx-alpine
docker/development/Dockerfile:3  ARG SERVERSIDEUP_PHP_VERSION=8.4-fpm-nginx-alpine
```

- The floating tag `8.4-fpm-nginx-alpine` was republished upstream on **2026-10-21 17:47 UTC as serversideup v5.0.2 → PHP 8.4.27 (patched)**.
- That same floating tag silently moved from serversideup **v4 → v5 (major)** on **2026-10-08**. Any of our images built since then may already be on v5 without a commit recording it.

### 2.2 Builds may reuse the old (vulnerable) base layer

> **Update (after pulling main at `fdd76f204`):** the shipping pipeline is now **`.github/workflows/release.yml`**. On every push to `main` it bumps the version, builds all images and creates a GitHub release. `build-custom-images.yml` is now a manual backup that runs only via `workflow_dispatch`.

Both workflows cached the base image without forcing a pull, as shown in this original snippet:

```yaml
cache-from: type=gha
cache-to: type=gha,mode=max
```

With an unchanged `FROM` line and GHA layer cache, BuildKit can resolve the base from cache and ship the **old PHP**. There is no `pull: true` on the build step.

The upstream-inherited workflows (`coolify-staging-build.yml`, `coolify-production-build.yml`) have the same pattern (`cache-from` gha/registry, no `pull`).

### 2.3 Other images — not affected

| Image | PHP? | Action |
|---|---|---|
| `docker/coolify-helper/Dockerfile` | No | None |
| `docker/coolify-realtime/Dockerfile` | No (Node) | None |
| `docker/testing-host/Dockerfile` | No | None |
| `coolify-production-build.yml` "Get Version" step uses `php:8.2-alpine3.16` | CLI only, short-lived, reads a local file, does not serve requests | Not exploitable via this vector; optional hygiene bump (see §3.5) |

## 3. Implementation Plan

### Decision: which patched tag to pin

| Option | Tag | Pros | Cons |
|---|---|---|---|
| **A (recommended)** | `8.4-fpm-nginx-alpine-v4.5.3` | Patched PHP 8.4.27; stays on v4 → pure security fix, zero behavioural change vs. pre-Oct-8 builds | Must do the v5 migration later anyway |
| B | `8.4-fpm-nginx-alpine-v5.0.2` | Patched; already where the floating tag points; no follow-up needed | Bundles v5 breaking changes into an urgent security release (see §5) |

> **Recommendation:** Ship Option A now. Track the v5 upgrade as a separate change (§6).
> If images built after 2026-10-08 are already in production and running fine on v5, Option B is also acceptable — check before deciding: `docker image inspect ghcr.io/edesylabs/coolify:latest --format '{{.Created}}'`. A build date after 2026-10-08 with a fresh base pull means the image is already on v5. Also run `docker exec coolify env | grep PHP_OPCACHE`.

### 3.1 Pin the base image — production

`docker/production/Dockerfile`

```diff
 # Versions
-# https://hub.docker.com/r/serversideup/php/tags?name=8.4-fpm-nginx-alpine
-ARG SERVERSIDEUP_PHP_VERSION=8.4-fpm-nginx-alpine
+# https://hub.docker.com/r/serversideup/php/tags?name=8.4-fpm-nginx-alpine
+# Pinned: v4.5.3 ships PHP 8.4.27 (fixes CVE-2026-109618 / CVE-2026-109619)
+ARG SERVERSIDEUP_PHP_VERSION=8.4-fpm-nginx-alpine-v4.5.3
```

Both `FROM serversideup/php:${SERVERSIDEUP_PHP_VERSION}` lines (stage `base` at line 18 and the final stage at line 55) use this ARG — no other edits needed.

### 3.2 Pin the base image — development

`docker/development/Dockerfile`

```diff
-ARG SERVERSIDEUP_PHP_VERSION=8.4-fpm-nginx-alpine
+ARG SERVERSIDEUP_PHP_VERSION=8.4-fpm-nginx-alpine-v4.5.3
```

Keep dev and prod on the same tag so local testing matches what ships.

### 3.3 Force a fresh base pull in CI — `release.yml` (primary) and `build-custom-images.yml` (manual backup)

✅ `pull: true` was added to the build step in **both** workflows. The original instructions are kept below for reference.

Add `pull: true` to the **build-coolify** job so BuildKit always re-resolves the `FROM` image digest from the registry instead of trusting the cache:

```diff
       - name: Build and push coolify image
         uses: docker/build-push-action@v5
         with:
           context: .
           file: ./docker/production/Dockerfile
+          pull: true
           push: true
```

Notes:
- Changing the tag string in §3.1 already invalidates the `FROM` cache key, so §3.3 is belt-and-braces — but it also protects future upstream security rebuilds of a pinned tag.
- `pull: true` keeps layer caching for later steps (composer, npm) — it only refreshes the base. **Do not** add `no-cache: true` permanently; if a one-off clean build is wanted, trigger the workflow via `workflow_dispatch` after temporarily adding it, then remove.
- Optional: add `scope=coolify` to `cache-from`/`cache-to` for this job. All three jobs currently share the default GHA cache scope, which causes cache thrash/evictions:
  ```yaml
  cache-from: type=gha,scope=coolify
  cache-to: type=gha,mode=max,scope=coolify
  ```

### 3.4 Same change in upstream-inherited workflows (secondary)

Only needed if these are still used by us; apply for consistency:

- `.github/workflows/coolify-staging-build.yml` — build step (~line 71): add `pull: true`
- `.github/workflows/coolify-production-build.yml` — build step (~line 63): add `pull: true`

### 3.5 Optional hygiene (not required for the CVE)

- `coolify-production-build.yml` "Get Version" step: `php:8.2-alpine3.16` → `php:8.4-cli-alpine`. Alpine 3.16 is EOL. Low priority; does not serve web traffic.

### 3.6 Version bump & release

So self-hosted / remote instances can pick up the fix through the normal upgrade path:

- **Do not bump the version by hand.** `release.yml` bumps `config/constants.php` and `versions.json` automatically from the commit message. A `fix(security): ...` commit is a patch bump, so **`4.1.0-beta.1` → `4.1.0-beta.2`**. A `feat:` commit would be a minor bump instead.
- ⚠️ `release.yml` pushes the new `versions.json` **before** the images finish building. Servers that check for updates during the build will fail to pull and keep their current containers, then succeed on the next check. This is harmless but worth knowing.
- Changelog entry: `fix(security): bump PHP to 8.4.27 (CVE-2026-109618, CVE-2026-109619)`

### 3.7 Curl-installed servers (`scripts/install.sh` → `scripts/upgrade.sh`)

I traced the curl install and auto-update flow. Three existing bugs meant servers would **never receive the patched image**, even after CI rebuilt it:

| # | Bug | Effect | Fix |
|---|---|---|---|
| 1 | `install.sh` and `UpdateCoolify` download `$CDN/upgrade.sh`, but `COOLIFY_CDN` is the repo root and the file is at `scripts/upgrade.sh` (root URL returns **404**) | Fresh installs abort at step 5 (`set -e`). In-app auto-update fails at the download step | `install.sh:711` and `UpdateCoolify.php:62` now fetch `$CDN/scripts/upgrade.sh`. `UpdateCoolify`'s default CDN now matches the fork's |
| 2 | Servers pull `coolify:<version from versions.json>`, but CI only pushed `:latest` and `:<sha>`. GHCR had **no `4.0.0-beta.444` tag** | Versioned install and auto-update can't pull the image Already fixed on main: `release.yml` tags `<image>:<new_version>` and `:latest` |
| 3 | `upgrade.sh` ran `docker compose up` without pulling | A server that already has `coolify:latest` keeps its old, vulnerable image | Added `--pull always` to both `compose up` calls (compose 2.38.2 in the helper image supports it) |
| 4 | `install.sh` called `upgrade.sh <version> <helper-version> <registry> true`, but `upgrade.sh` expects `<image> <registry> <namespace> <skip_backup>` | `REGISTRY_URL` became `latest` and the namespace became `ghcr.io` when they were missing from `.env` | `install.sh:822/824` now passes `<version> <registry> ${COOLIFY_IMAGE_NAMESPACE:-edesylabs} true` |

#### One-time manual upgrade for servers that are already installed

Already-installed servers run the **old** `UpdateCoolify` code (bug 1), so the in-app upgrade button will fail once. After the `Release` workflow finishes (images pushed, `versions.json` shows the new version, expected `4.1.0-beta.2`), run this on each server as root:

```bash
curl -fsSL https://raw.githubusercontent.com/edesylabs/coolify/refs/heads/main/scripts/upgrade.sh \
  -o /data/coolify/source/upgrade.sh
bash /data/coolify/source/upgrade.sh <new-version, e.g. 4.1.0-beta.2> ghcr.io edesylabs
docker exec coolify php -v   # must show 8.4.27 or newer
```

After this, auto-update works normally for future releases. New servers can use the standard curl install command.

## 4. Verification Checklist

### Local
- [ ] `docker build -f docker/production/Dockerfile -t coolify:cve-test --pull .`
- [ ] `docker run --rm --entrypoint php coolify:cve-test -v` → reports **PHP 8.4.27** (or newer)
- [ ] `docker compose -f docker-compose.dev.yml build --pull coolify && docker compose -f docker-compose.dev.yml up -d`
- [ ] `docker exec coolify php -v` → **8.4.27+**
- [ ] App loads, login works, a test deployment runs, Horizon processes jobs
- [ ] `docker exec coolify php artisan test` (feature suite) passes

### CI
- [ ] `Build Custom Coolify Images` run succeeds on `main`
- [ ] Build log shows the base image being pulled (not `CACHED`) for `FROM serversideup/php:8.4-fpm-nginx-alpine-v4.5.3`
- [ ] `docker run --rm --entrypoint php ghcr.io/edesylabs/coolify:<sha> -v` → **8.4.27+**

### Production / every running instance
- [ ] Pull and restart with the new image (`:latest` or `:<sha>`)
- [ ] `docker exec coolify php -v` → **8.4.27+** on **each** host (including both Horizon worker servers)
- [ ] Spot-check error logs / Horizon after rollout
- [ ] Review web/access logs since ~2026-10-15 for anomalous requests or unexpected PHP-FPM worker crashes (pre-patch exploitation was reported) — escalate if anything suspicious

## 5. v5 Breaking Changes — impact assessment (for Option B / later upgrade)

| v5 change | Coolify impact |
|---|---|
| `PHP_OPCACHE_VALIDATE_TIMESTAMPS` default `1` → `0` | **Prod:** fine — code is baked into the image, `PHP_OPCACHE_ENABLE=1` (`docker/production/Dockerfile:104`). **Dev:** none — `PHP_OPCACHE_ENABLE=0` (`docker/development/Dockerfile:65`) |
| OPcache memory 128→256 MB, interned strings 8→32, max files 10000→32531; realpath cache TTL 120→600 | Slightly higher memory per container; acceptable. Watch on small servers |
| `PHP_SESSION_COOKIE_HTTPONLY` → `On` | Improvement; Laravel already sets HttpOnly session cookies |
| PHP 7.4 / 8.0 images dropped; FrankenPHP logging changes | N/A — we use 8.4 fpm-nginx |

Conclusion: v5 looks low-risk for Coolify, but should still be smoke-tested on its own.

## 6. Follow-ups (separate tasks)

- [ ] Upgrade base to serversideup v5 (`8.4-fpm-nginx-alpine-v5.x.y`) after a smoke-test cycle
- [ ] Add Renovate/Dependabot (`docker` ecosystem) to propose bumps to the pinned base tag automatically, so pinning doesn't mean missing future security releases
- [ ] Consider a weekly scheduled `workflow_dispatch` of `build-custom-images.yml` with `pull: true` to pick up upstream OS/PHP security rebuilds

## 7. Files to Change (quick reference)

| File | Change | Required? |
|---|---|---|
| `docker/production/Dockerfile` | Pin `SERVERSIDEUP_PHP_VERSION` to `8.4-fpm-nginx-alpine-v4.5.3` | **Yes** |
| `docker/development/Dockerfile` | Same pin | **Yes** |
| `.github/workflows/release.yml` | `pull: true` on the image build step | **Yes** ✅ |
| `.github/workflows/build-custom-images.yml` | `pull: true` (manual backup workflow) | **Yes** ✅ |
| `config/constants.php`, `versions.json` | No manual change; `release.yml` bumps them | — |
| `scripts/install.sh` | Fetch `scripts/upgrade.sh`; fix argument order passed to `upgrade.sh` | **Yes** ✅ |
| `scripts/upgrade.sh` | `--pull always` on `compose up` | **Yes** ✅ |
| `app/Actions/Server/UpdateCoolify.php` | Fetch `scripts/upgrade.sh`; fork default CDN | **Yes** ✅ |
| `tests/Unit/PhpSecurityPatchTest.php` | Regression guard for all of the above | **Yes** ✅ |
| `.github/workflows/coolify-staging-build.yml` | `pull: true` | Not done. Upstream workflow, not used by this fork |
| `.github/workflows/coolify-production-build.yml` | `pull: true` (optional: bump Get Version image) | Not done. Upstream workflow, not used by this fork |

## 8. References

- [Qualys — PHP Patches Two Critical RCE Vulnerabilities](https://blog.qualys.com/vulnerabilities-threat-research/2026/10/20/php-announces-critical-security-release-for-two-vulnerabilities)
- [Tenable — php2shell FAQ](https://www.tenable.com/blog/php2shell-cve-2026-109618-cve-2026-109619-frequently-asked-questions-about-critical-php-remote)
- [Rapid7 — CVE-2026-109618 and CVE-2026-109619](https://www.rapid7.com/blog/post/etr-cve-2026-109618-and-cve-2026-109619-critical-unauthenticated-remote-code-execution-in-php/)
- [Huntress — php2shell Rapid Response](https://www.huntress.com/blog/php2shell-leaked-critical-php-vulnerabilities)
- [Google Cloud — Responding to core PHP vulnerabilities](https://cloud.google.com/blog/products/identity-security/responding-to-core-php-vulnerabilities-cve-2026-109618-cve-2026-109619)
- [serversideup/docker-php releases (v4.5.3, v5.0.2)](https://github.com/serversideup/docker-php/releases)
- [Docker Hub — serversideup/php tags](https://hub.docker.com/r/serversideup/php/tags?name=8.4-fpm-nginx-alpine)
