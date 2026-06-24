# Changelog

## 0.9.1

- Redesigned the main admin dashboard summary area into an operational overview.
- Added a Priority queue card for critical, high, fix-required, and retest-required endpoints.
- Added a Review progress card with triage percentage and direct review links.
- Added a System state card for Shield status, active rules, snapshots, latest snapshot, and filtered row count.
- Added a compact scan scope metric strip for lower-priority route categories.
- Improved dashboard spacing, hierarchy, visual grouping, and responsive layout.

## 0.9.0

- Added Endpoint Review Status & Finding Triage Workflow.
- Added `rest_radar_endpoint_reviews` storage for endpoint review decisions.
- Added review statuses: New, Needs review, Accepted public, False positive, Fix required, Shielded, Retest required.
- Added reviewer notes and severity override support. Severity override requires a note.
- Added Review column and review filters to the endpoint inventory.
- Added review summary cards and dashboard metrics.
- Added Shield rule state detection per endpoint.
- Expanded CSV and QA Markdown exports with review evidence fields.
- Added review decision data to QA ticket drafts.
- Added automatic Retest required marking when accepted/false-positive/shielded endpoint fingerprints change.
- Added public callback filter `rest_radar_public_permission_callbacks`.
- Added 60-second Auto Safe Mode scan transient cache.
- Added optional Shield log IP anonymization, enabled by default.
- Added snapshot storage guards for `wp_options`.
- Updated documentation with scanner limitations and privacy notes.

## 0.8.0

- Added `uninstall.php` with optional cleanup behavior.
- Added an admin setting to remove REST Radar settings, Shield rules/logs, and snapshots when the plugin is deleted.
- Added safer Endpoint Shield controls.
- Auto Safe Mode now requires explicit confirmation before it can be enabled.
- WordPress core route protection now requires a separate confirmation.
- Added persistent admin warnings when Endpoint Shield, Auto Safe Mode, or core route protection is active.
- Enforced safe defaults: Shield OFF, Auto Safe Mode OFF, core route protection OFF.
- Kept all previous endpoint scanning, QA report, Shield, dashboard, snapshot/compare, export, and UI features.

## 0.8.0

- Added `uninstall.php` with optional cleanup behavior.
- Added an admin setting to remove REST Radar settings, Shield rules/logs, and snapshots when the plugin is deleted.
- Added safer Endpoint Shield controls.
- Auto Safe Mode now requires explicit confirmation before it can be enabled.
- WordPress core route protection now requires a separate confirmation.
- Added persistent admin warnings when Endpoint Shield, Auto Safe Mode, or core route protection is active.
- Enforced safe defaults: Shield OFF, Auto Safe Mode OFF, core route protection OFF.
- Kept all previous endpoint scanning, QA report, Shield, dashboard, snapshot/compare, export, and UI features.

## 0.7.0

- Replaced snippet/draft textareas with stable dark `<pre><code>` blocks.
- Kept Copy and Expand/Collapse controls for Developer fix snippet and QA bug report draft.
- Added Risk explanation panel with evidence, suggested manual tests, and false-positive notes.
- Added Snapshot & Compare mode for before/after REST API regression QA.
- Snapshot compare detects new endpoints, removed endpoints, risk changes, permission callback changes, and source changes.
- Added snapshot create/delete/clear actions with nonce and admin capability checks.
- Kept all previous cumulative scanner, QA export, Dashboard, Endpoint Shield, duplicate rule guard, and UI polish features.

## 0.5.8

- Fixed the snippet and QA textarea contrast with forced high-specificity textarea styling.
- Added !important dark background and light text rules so WordPress/browser defaults cannot wash out the code blocks.
- Keeps the snippet toolbar, dark cards, duplicate Shield rule protection, and all earlier cumulative features.

## 0.5.7

- Added toolbar controls to the Developer fix snippet and QA bug report draft panels.
- Added one-click Copy buttons for both generated text blocks.
- Added Expand / Collapse controls for longer code and QA drafts.
- Added a small admin JavaScript asset for clipboard and textarea expansion behavior.

## 0.5.6

- Updated the Developer fix snippet and QA bug report draft sections to use a dark visual theme, not just a dark textarea.
- Improved contrast for the full snippet/QA cards so code and report content are clearly readable.
- Keeps cumulative Shield duplicate-rule protection and all previous REST Radar features.

## 0.5.5

- Added Endpoint Shield duplicate rule protection.
- REST Radar now treats the same route pattern + methods + protection mode + capability as one rule.
- Existing duplicate manual shield rules are deduplicated during sanitization.
- Re-clicking Add shield rule now shows a warning instead of creating another identical rule.

## 0.5.4

- Fixed textarea readability in the Developer fix snippet and QA bug report draft panels.
- Switched those code/draft textareas to a dark code-style surface with high-contrast light text.
- Added explicit readonly text color handling and improved focus styling.

## 0.5.3

- Changed the filter Reset control from a link-style control to a secondary WordPress button.
- Added a reset icon and dedicated styling for better UI consistency.
- Kept all 0.1.0-0.5.2 scanner, QA, Shield, dashboard, export, and mitigation features.

# Changelog

## 0.5.2

- Added professional admin UI/UX polish.
- Added product-style hero header with version badge and export actions.
- Improved summary cards, filter toolbar, table shell, endpoint details, sidebar panels, dashboard widget, form controls, and risk/status badges.
- Added Endpoint Shield active/inactive status badge in the sidebar.
- Kept all 0.1.0-0.5.1 scanner, QA, Shield, dashboard, export, and mitigation features.

## 0.5.1

- Added WordPress Dashboard widget for REST Radar.
- Shows total endpoint count, critical/high/review counts, Shield ON/OFF status, rule count, recent block log count, and latest blocked route.
- Added quick links to the full scanner and critical-only view.
- Dashboard widget is admin-only and read-only.

## 0.5.0

- Added Endpoint Shield runtime protection layer.
- Added manual shield rules with wildcard route patterns.
- Added protection modes:
  - block guests only
  - require logged-in user
  - administrators only
  - selected capability
  - disable route completely
- Added one-click shield rule creation from endpoint Details.
- Added Auto Safe Mode for critical/high custom endpoints.
- Added optional Auto Safe Mode coverage for WordPress core routes.
- Added recent block log and clear logs action.
- Added developer fix snippet in endpoint Details.
- Updated plugin description and version metadata.
- Kept all previous 0.1.0–0.4.0 scanner, filtering, probe, CSV, and QA Markdown features.

## 0.4.0

- Added QA bug report draft to endpoint details.
- Added QA Markdown export.
- Added manual QA focus and finding sections.

## 0.3.1

- Fixed Details / GET probe button visibility by adding stable row keys to scanner output.

## 0.3.0

- Added endpoint detail panel.
- Added safe GET probe for simple public GET routes.

## 0.2.0

- Added source detection, tags, recommendations, ignore patterns, and source filters.

## 0.1.0

- Initial REST endpoint scanner and CSV export.
