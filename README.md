# REST Radar – Endpoint Inspector

**REST Radar** is a WordPress REST API inspection, QA reporting, endpoint protection, regression review, and finding triage plugin.

It helps WordPress developers, QA testers, site maintainers, and security-minded reviewers inspect registered REST API endpoints, identify risky access patterns, document findings, compare endpoint changes after updates, and apply non-destructive protective rules through Endpoint Shield.

## What it does

REST Radar scans the registered REST API routes of a WordPress site and displays key technical and review details:

- Route and namespace
- HTTP methods
- Main callback
- Permission callback
- Source detection: WordPress core, plugin, theme, MU plugin, or unknown
- Scanner risk level
- Effective risk after reviewer override
- Review status and reviewer note
- Shield rule state
- Recommended review action
- Risk explanation and manual QA notes

The goal is to make the WordPress REST API surface visible, reviewable, testable, documentable, and easier to protect during maintenance or QA review.

## Main use cases

- WordPress REST API review
- Manual QA and API testing
- Plugin/theme update regression testing
- Security-focused endpoint visibility
- QA evidence generation
- Finding triage and review workflow
- Developer handoff documentation
- Temporary endpoint protection without editing third-party plugin or theme files
- Portfolio project for Manual QA / API QA / Security Review roles

## Key features

- Scan registered WordPress REST API endpoints
- Display route, namespace, methods, callbacks, and permission callbacks
- Detect endpoint source: WordPress core, plugin, theme, MU plugin, or unknown
- Highlight risky routes with Critical, High, Review, Public, and Low risk levels
- Explain why an endpoint received a specific risk rating
- Generate QA bug report drafts for individual findings
- Generate developer fix snippets for permission callback issues
- Run safe GET probes for simple read-only endpoints
- Export findings as CSV
- Export QA-ready Markdown reports
- Add non-destructive Endpoint Shield rules
- Block guest access, require login, allow admins only, require capability, or disable routes
- Save REST API snapshots
- Compare snapshots before and after plugin/theme updates
- Detect new, removed, or changed endpoints
- Operational dashboard with priority queue, review progress, and system state
- Optional uninstall cleanup
- Safer Shield / Auto Safe Mode confirmation flow
- Shield log IP anonymization option
- Snapshot size guards for safer `wp_options` storage

## Endpoint Review Status & Finding Triage Workflow

REST Radar 0.9.x adds a human review layer on top of scanner output.

Supported review statuses:

- New
- Needs review
- Accepted public
- False positive
- Fix required
- Shielded
- Retest required

Each endpoint can store:

- Review status
- Reviewer note
- Manual severity override
- Reviewed date
- Reviewer identity
- Technical fingerprint for retest detection

Severity override requires a reviewer note, so review decisions stay auditable.

If an endpoint that was previously accepted, marked as false positive, or marked as shielded later changes its technical fingerprint, REST Radar marks it as **Retest required**.

## Dashboard

Version 0.9.1 replaces the old flat metric-card layout with an operational dashboard:

- **Priority queue**: Critical, High, Fix required, and Retest required work
- **Review progress**: triage percentage and direct review links
- **System state**: Shield status, active rule count, snapshots, latest snapshot, and filtered rows
- **Metric strip**: total routes, review risk, public, low, and ignored routes

The dashboard is designed to answer the practical question: **what should I review first?**

## Endpoint Shield

Endpoint Shield allows REST Radar to apply a protective layer in front of selected REST endpoints without modifying third-party plugin or theme files.

Supported protection modes:

- Block guests only
- Require logged-in user
- Allow administrators only
- Require selected capability
- Disable route completely

This is intended as a temporary mitigation or review aid, not as a replacement for fixing the source code.

## Safe defaults

REST Radar uses safe defaults:

- Endpoint Shield is OFF by default
- Auto Safe Mode is OFF by default
- WordPress core route protection is OFF by default
- Auto Safe Mode requires explicit confirmation
- WordPress core route protection requires separate confirmation
- Admin warnings appear when Shield, Auto Safe Mode, or core route protection is active
- Shield log IP anonymization is enabled by default

## Snapshot / Compare mode

REST Radar can save the current REST API state as a snapshot and compare it with another snapshot later.

This is useful before and after:

- Plugin updates
- Theme updates
- WordPress core updates
- Security hardening changes
- Client site maintenance

The comparison highlights:

- New endpoints
- Removed endpoints
- Changed risk levels
- Changed permission callbacks
- Changed endpoint sources

Snapshot storage is guarded to avoid uncontrolled growth inside `wp_options`.

## QA workflow support

REST Radar is designed to support practical QA documentation.

For each endpoint, the plugin can generate:

- QA bug report draft
- Developer fix snippet
- Risk explanation
- Suggested manual tests
- False positive notes
- Review status evidence
- Severity override evidence
- Shield rule state

The output can be copied into GitHub Issues, Jira, Trello, Upwork reports, internal QA documentation, or client-facing review notes.

## Installation

### From the release ZIP

1. Download the plugin ZIP from the `release/` folder.
2. Open your WordPress admin.
3. Go to **Plugins → Add New → Upload Plugin**.
4. Upload the ZIP file.
5. Activate the plugin.
6. Open **Tools → REST Radar**.

### From source

Copy the `rest-radar-endpoint-inspector/` folder into:

```text
wp-content/plugins/
```

Then activate it from the WordPress admin.

## Admin location

```text
Tools → REST Radar
```

A dashboard summary box is also available on the main WordPress Dashboard.

## Technical limitations and privacy notes

- REST Radar can detect `__return_true` as a public permission callback.
- Custom public wrapper callbacks can be declared with the `rest_radar_public_permission_callbacks` filter.
- The scanner cannot statically inspect the body of runtime closures such as `fn() => true`; these require manual review.
- Auto Safe Mode scan output is cached for 60 seconds to reduce REST request overhead.
- Shield logs can store request IP addresses. IP anonymization is enabled by default and should usually stay enabled on EU production sites.
- Callback source paths are intended for administrators only. Do not expose scanner output publicly without removing internal source paths.
- Wildcard Shield route matching is case-insensitive.

## Important disclaimer

REST Radar is a review, QA, and mitigation tool, not a replacement for a professional security audit. It helps identify and document potentially risky REST API endpoints, but all findings should be manually verified before being treated as confirmed vulnerabilities.

## Version

Current package version: **0.9.1**

## Author

**Szujó János**  
GitHub: [Szujo-Janos](https://github.com/Szujo-Janos)

## License

GPL-2.0-or-later
