# REST Radar - Endpoint Inspector

REST Radar is a WordPress admin tool for inspecting registered REST API endpoints, flagging risky permission patterns, generating QA-ready reports, and applying non-destructive endpoint protection rules.

## Version 0.7.0

### Scanner and QA features

- Lists registered WordPress REST API routes.
- Shows route, namespace, methods, source, callbacks, and permission callback metadata.
- Flags missing `permission_callback` values.
- Flags public write-capable endpoints.
- Highlights sensitive-looking route keywords such as user, auth, settings, file, payment, license, and webhook.
- Provides Details view for each endpoint.
- Offers safe anonymous GET probe only for simple non-parameterized GET routes.
- Generates copy-ready QA bug report drafts.
- Renders generated snippets as stable dark `<pre><code>` blocks with Copy and Expand controls.
- Explains why an endpoint received its risk level with evidence, suggested tests, and false-positive notes.
- Saves REST scan snapshots and compares before/after endpoint changes for regression QA.
- Exports CSV and QA Markdown reports.

### Snapshot compare

Use snapshots to save the current REST API inventory before a plugin/theme/core update, then compare it with a later scan.

REST Radar can highlight:

- new endpoints
- removed endpoints
- risk level changes
- permission callback changes
- source changes

This is designed for manual QA and regression evidence, not automated destructive testing.


### Endpoint Shield

Endpoint Shield is a non-destructive mitigation layer. It does not edit third-party plugin or theme files. It blocks matching REST API requests before the endpoint callback runs.

Manual rule modes:

- Block guests only.
- Require logged-in user.
- Allow administrators only.
- Require selected capability.
- Disable route completely.

Additional protection:

- One-click Add shield rule from endpoint Details.
- Auto Safe Mode for critical/high custom endpoints.
- Optional inclusion of WordPress core routes in Auto Safe Mode.
- Recent blocked request log.
- Clear logs button.

### Developer fix snippets

Endpoint Details now includes a developer-oriented code snippet suggesting how to adjust the original route registration, especially around `permission_callback` and `current_user_can()`.

## Safety notes

- REST Radar does not execute write methods.
- Endpoint Shield is disabled until enabled by an administrator.
- Auto Safe Mode can break public custom integrations if enabled too aggressively. Use it on staging first.
- Long-term fixes should still be applied in the source plugin/theme.

## Location

WordPress Admin → Tools → REST Radar


## 0.5.1 - Dashboard widget

Adds a WordPress Dashboard widget with a compact REST Radar summary: total routes, critical/high/review counts, Shield status, rule/log counts, latest blocked route, and quick links to the full scanner.


## 0.5.2 - Professional UI/UX polish

- Added a cleaner product-style admin hero header with version and export actions.
- Improved scan summary cards, toolbar, filter layout, table container, badges, details panel, sidebar boxes, and dashboard widget styling.
- Added a compact table shell for better horizontal scrolling on dense REST inventories.
- Added Endpoint Shield status badge styling in the sidebar.
- No destructive behaviour changes; this is a cumulative UI/UX refinement release.


Current version: 0.8.0


## 0.8.0 Stabilization

This release adds safer production behavior:

- Optional uninstall cleanup setting
- `uninstall.php` support
- Explicit confirmation required for Auto Safe Mode
- Separate confirmation required for WordPress core route protection
- Admin warnings when Shield or Auto Safe Mode is active
- Safe defaults remain enforced
