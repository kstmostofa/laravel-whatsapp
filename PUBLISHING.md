# Publishing `kstmostofa/laravel-whatsapp`

End-to-end guide for cutting a release on Packagist + GitHub. Step-by-step,
nothing to guess.

---

## 0. One-time setup

Do this the **first time** you publish the package. Skip on subsequent releases.

### 0.1 Create the GitHub repo

```bash
gh repo create kstmostofa/laravel-whatsapp \
    --public \
    --description "Dual-backend Laravel package for WhatsApp: Cloud API + whatsapp-web.js sidecar with Livewire admin UI." \
    --homepage "https://kstmostofa.github.io/laravel-whatsapp/" \
    --source . \
    --remote origin \
    --push
```

If `gh` isn't installed, create the repo manually at <https://github.com/new>,
then:
```bash
git remote add origin git@github.com:kstmostofa/laravel-whatsapp.git
git branch -M main
git push -u origin main
```

### 0.2 Enable GitHub Pages for the docs site

Repo → **Settings** → **Pages** → Source = **GitHub Actions**.

The first push to `main` will trigger [`.github/workflows/docs.yml`](.github/workflows/docs.yml)
and publish the site at `https://kstmostofa.github.io/laravel-whatsapp/`.

### 0.3 Create a Packagist account

1. Visit <https://packagist.org/register/> and sign up.
2. Confirm your email.

### 0.4 Submit the package to Packagist

1. Visit <https://packagist.org/packages/submit>.
2. Paste `https://github.com/kstmostofa/laravel-whatsapp` and click **Check**.
3. Packagist reads your `composer.json` and shows a preview.
4. Click **Submit**.

The package is now live at `https://packagist.org/packages/kstmostofa/laravel-whatsapp`.

### 0.5 Wire the GitHub → Packagist auto-update webhook

Without this, every new tag requires a manual "Update" click on Packagist.

1. Packagist → profile dropdown → **Your API Token** → copy.
2. GitHub repo → **Settings** → **Webhooks** → **Add webhook**
   - **Payload URL**: `https://packagist.org/api/github?username=<your-packagist-username>`
   - **Content type**: `application/json`
   - **Secret**: paste your Packagist API token
   - **Events**: just `pushes`
   - **Active**: checked
3. Save. GitHub sends a ping immediately — should return **200**.

From now on, every `git push --tags` auto-bumps Packagist within seconds.

---

## 1. Pre-flight checklist

Run through this **every release**. Skip none of these.

### 1.1 Code state

- [ ] On `main` branch, working tree clean (`git status`)
- [ ] `composer validate --strict` returns no errors
- [ ] `vendor/bin/phpunit` passes (46/46 tests green)
- [ ] No `dd()` / `var_dump()` / `console.log` leftovers — `grep -rn 'dd(\|var_dump(' src/`
- [ ] No `TODO`/`FIXME` blocking release — `grep -rn 'TODO\|FIXME' src/`
- [ ] All `WhatsApp` casing is consistent (namespace, class, file names)

### 1.2 Metadata

- [ ] [composer.json](composer.json) — version constraints are sane (no `*`)
- [ ] [composer.json](composer.json) — `keywords`, `description`, `homepage`, `support.*` are current
- [ ] [README.md](README.md) — install instructions match this version
- [ ] [CHANGELOG.md](CHANGELOG.md) — `[Unreleased]` section reflects what changed; about to be promoted to a numbered version
- [ ] [LICENSE](LICENSE) — exists, says MIT, year up to date

### 1.3 Docs

- [ ] `npm run docs:build` succeeds
- [ ] Any new public APIs are documented in `docs/api/`
- [ ] Breaking changes are flagged in the changelog with **BREAKING:**

### 1.4 Distribution hygiene

- [ ] [.gitattributes](.gitattributes) — excludes `tests/`, `docs/`, CI, etc. from dist
- [ ] Run a dry-run dist build to confirm:
  ```bash
  composer archive --format=tar
  tar -tzf kstmostofa-laravel-whatsapp-*.tar | head -20
  ```
  Should NOT include `tests/`, `docs/`, `.github/`, `.vitepress/dist/`, `node_modules/`.

---

## 2. Cut the release

### 2.1 Decide the version

Follow [SemVer](https://semver.org/):

| Change type | Bump |
|---|---|
| Breaking API change (method removed/renamed/signature changed) | **MAJOR** (`1.0.0` → `2.0.0`) |
| New feature, backwards-compatible | **MINOR** (`1.0.0` → `1.1.0`) |
| Bug fix only, no API change | **PATCH** (`1.0.0` → `1.0.1`) |

For the first public release, use `0.1.0` if API is still in flux (Composer
treats `0.x.0` bumps as potentially breaking — gives you room to iterate),
or `1.0.0` if the API is stable.

### 2.2 Update the changelog

Open [CHANGELOG.md](CHANGELOG.md) and:

1. Rename `## [Unreleased]` → `## [X.Y.Z] - YYYY-MM-DD`
2. Add a fresh empty `## [Unreleased]` section at the top
3. Update the link references at the bottom:
   ```markdown
   [Unreleased]: https://github.com/kstmostofa/laravel-whatsapp/compare/vX.Y.Z...HEAD
   [X.Y.Z]: https://github.com/kstmostofa/laravel-whatsapp/releases/tag/vX.Y.Z
   ```

### 2.3 Commit and tag

```bash
git add CHANGELOG.md
git commit -m "Release vX.Y.Z"

# Annotated tag — shows the release notes when `git show vX.Y.Z`
git tag -a vX.Y.Z -m "Release vX.Y.Z"

git push origin main
git push origin vX.Y.Z
```

### 2.4 Verify Packagist picked up the tag

Within 30 seconds:

```bash
curl -s https://repo.packagist.org/p2/kstmostofa/laravel-whatsapp.json | \
    jq '.packages."kstmostofa/laravel-whatsapp"[].version' | head -5
```

The new version should be at the top.

If it's missing:
1. Visit <https://packagist.org/packages/kstmostofa/laravel-whatsapp>
2. Click **Update** (top-right) — forces a re-sync
3. Check the webhook in GitHub → Settings → Webhooks → recent deliveries; fix any 4xx/5xx

### 2.5 Create the GitHub release

```bash
gh release create vX.Y.Z \
    --title "vX.Y.Z" \
    --notes-from-tag
```

Or paste the changelog entry manually at <https://github.com/kstmostofa/laravel-whatsapp/releases/new>.

---

## 3. Post-publish verification

Install the new version into a fresh Laravel app to confirm it actually works.

```bash
cd /tmp
laravel new wa-smoke-test
cd wa-smoke-test
composer require kstmostofa/laravel-whatsapp:^X.Y.Z

# Make sure the service provider auto-registered
php artisan list | grep whatsapp     # should list 6 commands
php artisan route:list | grep whatsapp  # should list ~9 routes

# Publish config + migrations
php artisan vendor:publish --tag=laravel-whatsapp-config
php artisan vendor:publish --tag=laravel-whatsapp-migrations
php artisan migrate

# Sanity-check the UI loads
php artisan serve &
curl -sI http://127.0.0.1:8000/whatsapp | head -1   # HTTP/1.1 200
```

If anything fails — `composer require` errors, missing autoload, broken
publish — yank the release:

```bash
git tag -d vX.Y.Z
git push origin :refs/tags/vX.Y.Z
# Packagist auto-removes the version within a minute
```

Then fix forward and re-tag.

---

## 4. Common gotchas

### Tag pushed but Packagist doesn't show it

1. Was the tag `vX.Y.Z` or `X.Y.Z`? Packagist accepts both, but Composer
   prefers `vX.Y.Z`. Stay consistent.
2. Webhook delivery failed — check GitHub → Settings → Webhooks → recent
   deliveries. A `401` means the API token is wrong; a `404` means the
   URL has a typo.
3. Manual fallback: hit the **Update** button on Packagist.

### Dist tarball is huge

`composer archive --format=tar` shows what gets shipped. If it's >1 MB:
1. Check [.gitattributes](.gitattributes) — is `export-ignore` set on the
   bloat directories (`docs/`, `tests/`, `node_modules/`)?
2. Don't commit `vendor/`, `node_modules/`, build artifacts.

### "Could not detect the root package version"

`composer validate` warning. Normal in a local clone — gets resolved by
Packagist via the git tags. Ignore.

### A user reports "Class not found: WhatsApp"

The namespace is `Kstmostofa\LaravelWhatsApp` (capital A in `App`). Users
who copied old code may have `Kstmostofa\LaravelWhatsapp`. Direct them to
the README install snippet.

---

## 5. Maintenance cadence

| Activity | Frequency |
|---|---|
| Patch release for bug fixes | As needed, ideally within a week of a bug report |
| Minor release for new features | Monthly batch, or sooner if a feature is impactful |
| Major release for breaking changes | Rare — every 6-12 months max |
| Update Laravel version compatibility | Within 2 weeks of a new Laravel major |
| Security advisories | Same-day if exploitable; tag a patch + post to GitHub Security Advisories |
| Dependency bumps (Guzzle, Symfony Process) | Quarterly review |

---

## 6. Quick reference — commands cheat sheet

```bash
# Pre-flight
composer validate --strict
vendor/bin/phpunit
npm run docs:build
composer archive --format=tar     # inspect dist contents

# Release
git commit -m "Release vX.Y.Z"
git tag -a vX.Y.Z -m "Release vX.Y.Z"
git push origin main
git push origin vX.Y.Z
gh release create vX.Y.Z --title "vX.Y.Z" --notes-from-tag

# Verify
curl -s https://repo.packagist.org/p2/kstmostofa/laravel-whatsapp.json | jq '.packages."kstmostofa/laravel-whatsapp"[0].version'

# Smoke-test
cd /tmp && laravel new wa-smoke-$(date +%s) && cd $_ && composer require kstmostofa/laravel-whatsapp:^X.Y.Z

# Emergency yank
git tag -d vX.Y.Z && git push origin :refs/tags/vX.Y.Z
```

---

## Resources

- Packagist publishing docs: <https://packagist.org/about>
- Composer manual: <https://getcomposer.org/doc/02-libraries.md>
- SemVer spec: <https://semver.org/>
- Keep a Changelog: <https://keepachangelog.com/>
- This package on Packagist: <https://packagist.org/packages/kstmostofa/laravel-whatsapp>
