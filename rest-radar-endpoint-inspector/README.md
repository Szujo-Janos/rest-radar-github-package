# REST Radar - Endpoint Inspector

REST Radar is a WordPress admin tool for inspecting registered REST API endpoints, flagging risky permission patterns, generating QA-ready reports, saving regression snapshots, and applying non-destructive endpoint protection rules.

## Version 0.9.1

### Dashboard Layout & Visual Optimization

REST Radar 0.9.1 redesigns the admin dashboard area so the most important operational decisions are visible before the full endpoint table.

Dashboard improvements:

- Replaced the flat nine-card summary row with an operational overview panel.
- Added a dedicated Priority queue card for critical, high, fix-required, and retest-required work.
- Added a Review progress card with triage percentage and direct links to unreviewed, needs-review, and shielded decisions.
- Added a System state card for Shield status, rule count, snapshots, latest snapshot, and filtered row count.
- Added a compact scan scope metric strip for total routes, review risk, public, low, and ignored rows.
- Added clearer primary actions for Review queue, CSV export, and QA Markdown export.
- Improved responsive behavior for medium and narrow admin screens.

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

### Endpoint Review Status & Finding Triage Workflow

REST Radar 0.9.0 adds a human review layer so scanner findings can become reusable QA/security evidence.

Review statuses:

- New
- Needs review
- Accepted public
- False positive
- Fix required
- Shielded
- Retest required

Review features:

- Endpoint Details includes an Endpoint review decision card.
- Each endpoint can store a reviewer note.
- Each endpoint can store a manual severity override.
- Severity override requires a reviewer note.
- Endpoint inventory includes a Review column.
- Review filters include unreviewed only, critical/high + unreviewed, has Shield rule, and all individual review statuses.
- Dashboard and admin summary cards include review workflow counts.
- CSV export includes effective risk, scanner risk, review status, reviewer note, severity override, reviewed date, reviewer, and Shield rule state.
- QA Markdown export includes review evidence.
- QA ticket drafts include review decision data.
- If a previously accepted/false-positive/shielded endpoint changes its technical fingerprint, REST Radar marks it as Retest required in the current scan.

### Snapshot compare

Use snapshots to save the current REST API inventory before a plugin/theme/core update, then compare it with a later scan.

REST Radar can highlight:

- new endpoints
- removed endpoints
- risk level changes
- permission callback changes
- source changes

Snapshot storage is limited to protect `wp_options`:

- maximum 8 snapshots
- maximum 1000 endpoint rows per snapshot
- soft serialized payload guard of about 750 KB

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
- Auto Safe Mode scan result is cached for 60 seconds to reduce REST request overhead.
- Optional inclusion of WordPress core routes in Auto Safe Mode.
- Recent blocked request log.
- Optional IP anonymization for Shield logs, enabled by default.
- Clear logs button.

### Technical limitations and privacy notes

- `__return_true` is detected as a public permission callback.
- Custom public wrapper callbacks can be declared through the `rest_radar_public_permission_callbacks` filter.
- REST Radar cannot statically inspect the runtime body of a `Closure` such as `fn() => true`, so closure-based allow-all logic may require manual review.
- Auto Safe Mode scans REST routes during REST request handling, but the scan output is cached for 60 seconds.
- Shield logs can store request IP addresses. IP anonymization is enabled by default and should stay enabled for EU production sites unless full IP evidence is intentionally required.
- Callback source paths are intended for administrators only. Do not expose scanner output publicly without removing internal source paths.
- Wildcard Shield route matching is case-insensitive. For example, `/Wp/v2/*` can match `/wp/v2/posts`.

### Developer fix snippets

Endpoint Details includes a developer-oriented code snippet suggesting how to adjust the original route registration, especially around `permission_callback` and `current_user_can()`.

## Safety notes

- REST Radar does not execute write methods.
- Endpoint Shield is disabled until enabled by an administrator.
- Auto Safe Mode can break public custom integrations if enabled too aggressively. Use it on staging first.
- Long-term fixes should still be applied in the source plugin/theme.

## Location

WordPress Admin → Tools → REST Radar
