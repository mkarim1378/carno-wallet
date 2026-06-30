---
name: WordPress Plugin Release Checklist
description: "After every code change to a WordPress plugin: bump version, update changelog, write commit message, and optionally update docs."
---

# WordPress Plugin Release Checklist

This skill applies after **every code change** to any WordPress plugin in the Carno ecosystem. It codifies the same workflow that was manually requested across 6+ projects (carno-wallet, carno-plugin, spotplayer-updated, carno-livechat, payamito-schedule, campaign-analytics, carno-KPIs).

## Trigger

Whenever plugin PHP files, assets, or configuration are created or modified, perform the following steps **before finishing your response**. Do not wait for the user to ask.

## Steps

### 1. Bump plugin version

Locate the version constant and/or header in the main plugin file:

| Project | Version location |
|---|---|
| PHP plugins (standard) | `* Version:` header in main `.php` file, and `define('XXX_VERSION', '...')` |
| JS/Composer projects | `"version"` in `package.json` AND `composer.json` |

Choose bump level based on change size:
- **Patch** (x.y.Z+1): bug fixes, small tweaks
- **Minor** (x.Y+1.0): new feature or significant improvement
- **Major** (X+1.0.0): breaking change

### 2. Update CHANGELOG.md

Add a new version block at the top of `CHANGELOG.md` (or `changelog.md`) following Keep a Changelog format:

```
## [X.Y.Z] - YYYY-MM-DD

### Added / Changed / Fixed / Removed
- Description in Persian (Farsi) unless the project uses English
```

Use today's date. Never add an `[Unreleased]` section. Never reference internal phase names — use version numbers as the canonical reference.

### 3. Write a one-line English commit message

Provide a single-line English commit message in imperative style:

```
Add cashback SMS notification on wallet top-up
```

Do **not** auto-commit. Only provide the message text for the user to run themselves.

### 4. Update project-specific docs (if applicable)

| If the project has... | Then update it |
|---|---|
| `README.md` with version field | Bump version number |
| `USER_DOCUMENTATION.md` | Reflect new feature/fix |
| `IDEAS.md` with implementation tracking | Mark completed items with ✅ |
| `languages/*.po` / `.pot` | Add new translatable strings and regenerate `.mo` |

## Why this exists

The same 3-4 step release ritual was explicitly requested as a rule in 6 separate projects. Packaging it as a skill eliminates the need to repeat the instruction in each new project session.

## Source evidence

- `[ses_0e716cf37ffe]` SpotPlayer: release workflow requested and followed across 87 messages
- `[ses_0e716cde5ffe]` Payamito SMS: changelog + version bump pattern
- `[ses_0e716c89bffe]` Carno Wallet: version bump in header + changelog
- Claude memory files: `feedback_after_change_workflow.md`, `feedback_release_workflow.md`, `feedback_plugin_update_workflow.md`, `feedback_post_change_checklist.md`, `feedback_commit_and_changelog.md`, `feedback_after_every_change.md` — all documenting variations of this same workflow
