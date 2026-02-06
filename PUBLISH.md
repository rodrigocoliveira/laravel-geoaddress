# Publishing Guide

## First-Time Setup (Packagist)

1. Ensure the GitHub repo is **public**
2. Go to [packagist.org](https://packagist.org) and sign in with GitHub
3. Click **Submit**, paste: `https://github.com/rodrigocoliveira/laravel-geoaddress`
4. Set up the **auto-update webhook** in GitHub repo Settings > Webhooks using the URL and token Packagist provides

## Releasing a New Version

### 1. Decide the version bump

Follow [Semantic Versioning](https://semver.org/) — format: `vMAJOR.MINOR.PATCH`

| Type      | When to use                                                        | Example           |
|-----------|--------------------------------------------------------------------|--------------------|
| **Patch** | Bug fixes, typos, internal changes (no breaking, no new features)  | `v1.0.0` → `v1.0.1` |
| **Minor** | New features that are backwards-compatible                         | `v1.0.0` → `v1.1.0` |
| **Major** | Breaking changes (renamed methods, removed features, changed API)  | `v1.0.0` → `v2.0.0` |

### 2. Update CHANGELOG (optional but recommended)

Document what changed in this release.

### 3. Commit any pending changes

```bash
git add -A
git commit -m "Prepare release vX.Y.Z"
```

### 4. Create and push the tag

```bash
# Patch release
git tag v1.0.1

# Minor release
git tag v1.1.0

# Major release
git tag v2.0.0

# Push the tag
git push origin --tags
```

### 5. Verify

- Check [packagist.org/packages/multek/laravel-geoaddress](https://packagist.org/packages/multek/laravel-geoaddress) — the new version should appear within a few minutes (instant if webhook is configured).

## Installing in Laravel Apps

```bash
# Latest version
composer require multek/laravel-geoaddress

# Specific version
composer require multek/laravel-geoaddress:^1.0

# Update to latest
composer update multek/laravel-geoaddress
```

## Quick Reference

```bash
# See all existing tags
git tag

# Delete a tag locally + remote (if you tagged wrong)
git tag -d vX.Y.Z
git push origin :refs/tags/vX.Y.Z

# See latest tag
git describe --tags --abbrev=0
```
