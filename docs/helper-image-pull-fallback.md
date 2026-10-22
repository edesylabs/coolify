# Helper image pull fallback

Runbook and implementation checklist for deployments that fail with:

```
Unable to find image 'ghcr.io/coollabsio/coolify-helper:1.0.17' locally
docker: Error response from daemon: Head "https://ghcr.io/v2/coollabsio/coolify-helper/manifests/1.0.17": denied: denied
```

## What happened

- Every application deployment starts a helper container (`ghcr.io/coollabsio/coolify-helper:<helper_version>`).
  The version comes from `config/constants.php` (`helper_version`), and the image can be overridden with `HELPER_IMAGE`.
- The image is public. Anonymous pulls work.
- On `hetzner-s2-server`, `/root/.docker/config.json` held a stale or revoked `ghcr.io` login. Docker always sends
  stored credentials, and ghcr.io answers an invalid token with `denied`, even for a public image. Docker does not
  retry anonymously.
- The problem stayed hidden while the old helper image was cached. After the Coolify update bumped `helper_version`
  to `1.0.17` (commit `9b07be2d6`), Docker cleanup (`app/Actions/Server/CleanupDocker.php`) removed the old helper,
  and the new one could not be pulled. `webyne-server-1` had pulled `1.0.17` successfully, so it kept working.
- Proof: `DOCKER_CONFIG=$(mktemp -d) docker pull ghcr.io/coollabsio/coolify-helper:1.0.17` (anonymous) succeeded on
  the failing server.

## 1. Server fix (do this on every affected server)

Run as root. Coolify mounts `/root/.docker/config.json`.

- [ ] Check what is stored:
      ```bash
      cp /root/.docker/config.json /root/.docker/config.json.bak
      cat /root/.docker/config.json   # look for "ghcr.io" in auths, credsStore, credHelpers
      ```
- [ ] If the server does not need private ghcr.io images: `docker logout ghcr.io`.
      If `credsStore`/`credHelpers` still supplies a token, remove that entry by hand. Keep the file (at least
      `{"auths":{}}`); Coolify checks that it exists and other registry logins live there.
- [ ] If the server needs private ghcr.io images: log in again with a PAT that has `read:packages`:
      `echo "$GHCR_PAT" | docker login ghcr.io -u <github-user> --password-stdin`. Record the token's expiry date.
- [ ] Verify with a normal pull (no `DOCKER_CONFIG` override):
      `docker pull ghcr.io/coollabsio/coolify-helper:1.0.17` must not print `denied`.

Unblock without fixing credentials (temporary): `DOCKER_CONFIG=$(mktemp -d) docker pull <helper image>`.
If that also fails with `denied`, the server or its network is blocked; copy the image from a working server:
`docker save <helper image> | gzip | ssh root@<server> 'gunzip | docker load'` (same CPU architecture only).

## 2. Code change: anonymous retry in deployments

Status: implemented on this branch.

| File | Change |
|---|---|
| `bootstrap/helpers/constants.php` | `HELPER_IMAGE_PRESENT`, `HELPER_IMAGE_PULLED`, `HELPER_IMAGE_PULLED_ANONYMOUSLY` output markers. |
| `bootstrap/helpers/docker.php` | `ensureHelperImageCommand($image)`: one `sh -c` script that skips the pull when the image exists, pulls with the stored credentials, and on failure pulls again with an empty temporary `DOCKER_CONFIG` (removed afterwards). Prints a marker, or both pull errors and exits 1. |
| `app/Jobs/ApplicationDeploymentJob.php` | `ensure_helper_image()` runs that script in `prepare_builder_image()` before `docker run`. Anonymous pull → a warning in the deployment log telling the operator to fix the server's login. Both pulls fail → `DeploymentException` with both errors. |
| `tests/Unit/EnsureHelperImageTest.php` | Runs the script against a fake `docker` binary (present / pull / stale credentials / denied), checks the temporary config is removed, checks the non-root `sudo` parsing, and checks the job's log and exception paths. |
| `tests/Feature/DeploymentSensitiveCommandFailureTest.php` | Its process fake answers the new pull step. |

Design notes:

- The script is a single `sh -c '...'` line, so for non-root SSH users `parseLineForSudo()` turns it into one
  `sudo sh -c '...'` call (see `isSingleSudoShellScript()`). `DOCKER_CONFIG=... sudo docker pull` would not work,
  because sudo drops the variable.
- The fallback only applies to the helper image. Application image pulls and pushes still use the stored
  credentials, so private registries are unaffected.
- The fallback hides the broken login, so the deployment log always shows the warning. Treat it as a task to do
  step 1 on that server.

Checklist:

- [x] Implement `ensureHelperImageCommand()` and `ensure_helper_image()`.
- [x] Tests: `php artisan test --compact tests/Unit/EnsureHelperImageTest.php tests/Feature/DeploymentSensitiveCommandFailureTest.php tests/Unit/ParseCommandsByLineForSudoTest.php`.
- [ ] Run the full suite: `php artisan test --compact`.
- [ ] Manual check on a test server: add a bogus ghcr.io login
      (`docker login ghcr.io -u x -p invalid` fails, so write a fake entry into `auths` in `config.json`),
      remove the helper image (`docker rmi <helper image>`), deploy, and confirm the warning and a successful deploy.
- [ ] Manual check with a non-root SSH user server.
- [ ] Deploy Coolify, then watch deployment logs for the "pulled without credentials" warning and fix those servers.

## 3. Follow-ups: other helper image users

These paths also run the helper image and fail the same way on a server with a stale login. They are not changed
yet. Reuse `ensureHelperImageCommand()` before the `docker run` in each:

- [ ] `app/Jobs/DatabaseBackupJob.php`
- [ ] `app/Jobs/VolumeBackupJob.php`
- [ ] `app/Actions/Database/StartDatabaseImport.php`
- [ ] `app/Actions/Server/CleanupDocker.php` (the `docker buildx prune` helper run; it ends with `|| true`, so it only
      skips that prune today)

## 4. Prevention

- [ ] Before or right after each Coolify upgrade that changes `helper_version`, pull the new helper on every server:
      `docker pull <helper image>:<version>`.
- [ ] Optional: copy the helper into a registry you control and set `HELPER_IMAGE` (already supported by
      `config/constants.php` and `scripts/coolify-custom.sh`). Copy all architectures:
      `docker buildx imagetools create -t ghcr.io/<org>/coolify-helper:<version> ghcr.io/coollabsio/coolify-helper:<version>`.
      Keep that package public, otherwise every server needs a valid login again.
- [ ] Optional: only build the helper from `docker/coolify-helper/Dockerfile` (workflow `.github/workflows/coolify-helper.yml`)
      if you change the Dockerfile or stop merging upstream.
