# Upgrade UI & Release Noise — Implementation Plan

**Status:** Planned · **Created:** 2026-10-22 · **Related:** [PHP_SECURITY_PATCH_PLAN.md](PHP_SECURITY_PATCH_PLAN.md)

## 1. Background

After deploying `4.1.0-beta.4` to `coolify.edesy.in`, the dashboard sidebar still shows **Upgrade**. Investigation:

| Question | Finding |
|---|---|
| Is it offering an *upstream* Coolify upgrade? | **No.** `CheckForUpdatesJob` reads `config('constants.coolify.versions_url')` → `https://raw.githubusercontent.com/edesylabs/coolify/refs/heads/main/versions.json` (our fork). |
| Why is an upgrade available? | Pushing the `deploy.sh` commit (`0e2a1bdb9`) triggered `release.yml`, which bumped to **`4.1.0-beta.5`** (run `39742888358`). Server runs beta.4 → `new_version_available = true`. |
| What does clicking it do? | `UpdateCoolify` pulls `ghcr.io/edesylabs/coolify:4.1.0-beta.5` and runs our `scripts/upgrade.sh`. Correct and safe (beta.4 already has the `scripts/upgrade.sh` path fix). |
| So what's wrong? | (a) The upgrade UI **links to upstream** (`coollabsio` releases, `coolify.io` docs), so it *looks* like an official Coolify upgrade. (b) **Script/doc-only commits create releases**, which produce pointless "Upgrade available" prompts for identical app code. |

## 2. Changes

### 2.1 Make the GitHub repository configurable (single source of truth)

`config/constants.php` — add to the `coolify` array, next to `versions_url`:

```php
'github_repository' => env('COOLIFY_GITHUB_REPOSITORY', 'edesylabs/coolify'),
```

Why: the same `owner/repo` is needed in several views. Keeping it in config (not hard-coded) follows the project rule of never calling `env()` outside config files, and lets another fork override it via `.env`.

### 2.2 Upgrade dialog links → our fork

`resources/views/livewire/upgrade.blade.php`

| Line | Current | New |
|---|---|---|
| 64 | `href="https://github.com/coollabsio/coolify/releases"` | `href="https://github.com/{{ config('constants.coolify.github_repository') }}/releases"` |
| 67 | `href="https://coolify.io/docs/upgrade"` | `href="https://github.com/{{ config('constants.coolify.github_repository') }}/blob/main/scripts/README.md"` |

The second link is the "if something goes wrong" guide. `scripts/README.md` documents `deploy.sh`, which is the manual recovery path for our fork (re-run it on the server).

Optional wording tweak on line 63: "You can review the changelogs here" → "You can review the release notes here". `release.yml` publishes a GitHub Release per version, so this link now has real content.

### 2.3 Sidebar version link → our fork

`resources/views/components/version.blade.php` (rendered in `navbar.blade.php:83`)

```diff
-    href="https://github.com/coollabsio/coolify/releases/tag/v{{ config('constants.coolify.version') }}" target="_blank">
+    href="https://github.com/{{ config('constants.coolify.github_repository') }}/releases/tag/v{{ config('constants.coolify.version') }}" target="_blank">
```

Today, clicking `v4.1.0-beta.4` opens a **non-existent upstream tag** (404). `release.yml` creates tags as `v<version>` (`tag_name: v${{ needs.bump-version.outputs.new_version }}`), so the new URL resolves.

### 2.4 Stop script/doc-only commits from creating releases

`.github/workflows/release.yml` — extend `on.push.paths-ignore`:

```diff
     paths-ignore:
       - "CHANGELOG.md"
       - "versions.json"
       - ".github/workflows/coolify-helper.yml"
       - ".github/workflows/coolify-realtime.yml"
       - "docker/coolify-helper/Dockerfile"
       - "docker/coolify-realtime/Dockerfile"
       - "templates/**"
+      - "scripts/**"
+      - "**.md"
```

Why it's safe:
- Servers fetch `scripts/install.sh`, `scripts/upgrade.sh` and `scripts/deploy.sh` **directly from GitHub raw** at run time. They are not baked into the image, so changing them needs no new release.
- GitHub skips the workflow only when **every** changed file matches `paths-ignore`. A commit touching `scripts/` **and** app code still releases.
- `workflow_dispatch` is unaffected, so a manual release is always possible.

⚠️ Known side effect (pre-existing, not introduced here): `docker/coolify-helper/Dockerfile` is already ignored, so a **helper-only** change never rebuilds the helper image. If that matters, either remove that line or trigger the release manually (`gh workflow run release.yml -f version_bump=patch`).

### 2.5 Optional — other upstream links (not required)

Found 63 `coolify.io` / `coollabsio` links in `resources/views`. Most are **documentation links that are still accurate for the fork** (Docker, proxy, server guides) and should stay. Only these are fork-identity related; change them if desired:

| File | Link | Suggestion |
|---|---|---|
| `resources/views/components/navbar.blade.php:361` | "Sponsor us" → `coolify.io/sponsorships` | Remove the menu item, or keep to support upstream |
| `resources/views/layouts/base.blade.php:25-31` | OG image/url → `coolify.io`, `cdn.coollabs.io` | Point to `coolify.edesy.in` assets |
| `resources/views/components/emails/footer.blade.php:6` | `coolify.io/docs/contact` | Your support contact |
| `resources/views/livewire/layout-popups.blade.php:27,79` | `coollabs.io/discord` | Your support channel |

## 3. Tests

Add to a new unit test `tests/Unit/ForkLinksTest.php`, following the file-content style used in `tests/Unit/PhpSecurityPatchTest.php`:

```php
it('links upgrade UI to the configured fork repository', function (string $view) {
    $contents = file_get_contents(__DIR__.'/../../'.$view);

    expect($contents)
        ->toContain("config('constants.coolify.github_repository')")
        ->not->toContain('github.com/coollabsio/coolify/releases');
})->with([
    'upgrade modal' => 'resources/views/livewire/upgrade.blade.php',
    'sidebar version' => 'resources/views/components/version.blade.php',
]);

it('defaults the GitHub repository to the edesylabs fork', function () {
    expect(file_get_contents(__DIR__.'/../../config/constants.php'))
        ->toContain("'github_repository' => env('COOLIFY_GITHUB_REPOSITORY', 'edesylabs/coolify')");
});

it('does not release for script or markdown only changes', function () {
    expect(file_get_contents(__DIR__.'/../../.github/workflows/release.yml'))
        ->toContain('- "scripts/**"')
        ->toContain('- "**.md"');
});
```

Run: `./vendor/bin/pest tests/Unit/ForkLinksTest.php` and `./vendor/bin/pint --dirty`.

## 4. Rollout

1. Implement §2.1–2.4 (and any of §2.5) in one commit, e.g. `fix(ui): link upgrade and version to fork releases`.
2. Push → `release.yml` creates **one** more release (`4.1.0-beta.6`). This commit changes app code, so it is *meant* to release. `paths-ignore` only affects later pushes.
3. Wait for `Release` to go green: `gh run list --limit 3` / `gh run watch <id>`.
4. Upgrade the server: click **Upgrade** in the dashboard, or on the server run
   `curl -fsSL https://raw.githubusercontent.com/edesylabs/coolify/refs/heads/main/scripts/deploy.sh | bash`.

## 5. Verification

- [ ] After upgrading, **Upgrade** disappears from the sidebar. The update check runs on `updateCheckFrequency` (`app/Console/Kernel.php:119`); to refresh immediately run `docker exec coolify php artisan tinker --execute="dispatch_sync(new App\Jobs\CheckForUpdatesJob);"`.
- [ ] Sidebar `v4.1.0-beta.6` opens `https://github.com/edesylabs/coolify/releases/tag/v4.1.0-beta.6` (not a 404).
- [ ] When a future release exists, the Upgrade dialog's "here" link opens `github.com/edesylabs/coolify/releases` and the "guide" link opens `scripts/README.md`.
- [ ] Push a commit touching only `scripts/` or a `.md` file → `gh run list` shows **no** new `Release` run, and `versions.json` is unchanged.
- [ ] `docker exec coolify php -v` still reports **8.4.27+**.

## 6. Files to Change (quick reference)

| File | Change | Required? |
|---|---|---|
| `config/constants.php` | Add `github_repository` (env `COOLIFY_GITHUB_REPOSITORY`, default `edesylabs/coolify`) | **Yes** |
| `resources/views/livewire/upgrade.blade.php` | Lines 64, 67: fork releases + `scripts/README.md` | **Yes** |
| `resources/views/components/version.blade.php` | Release-tag link → fork | **Yes** |
| `.github/workflows/release.yml` | `paths-ignore`: add `scripts/**`, `**.md` | **Yes** |
| `tests/Unit/ForkLinksTest.php` | New regression tests (§3) | **Yes** |
| `navbar.blade.php`, `base.blade.php`, email footer, `layout-popups.blade.php` | Fork-identity links (§2.5) | Optional |
