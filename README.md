# REST Radar – Endpoint Inspector

**REST Radar** is a WordPress REST API inspection, QA reporting, endpoint protection, and regression review plugin.

It helps WordPress developers, QA testers, and site maintainers inspect registered REST API endpoints, identify risky access patterns, generate QA-ready reports, compare endpoint changes after updates, and apply non-destructive protective rules through Endpoint Shield.

## What it does

REST Radar scans the registered REST API routes of a WordPress site and displays key technical details:

- Route and namespace
- HTTP methods
- Main callback
- Permission callback
- Source detection: WordPress core, plugin, theme, MU plugin, or unknown
- Risk level
- Recommended review action
- Risk explanation and manual QA notes

The goal is to make the WordPress REST API surface visible, reviewable, testable, and easier to document.

## Main use cases

- WordPress REST API review
- Manual QA and API testing
- Plugin/theme update regression testing
- Security-focused endpoint visibility
- QA evidence generation
- Developer handoff documentation
- Temporary endpoint protection without editing third-party plugin or theme files

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
- Dashboard widget with quick endpoint risk summary
- Optional uninstall cleanup
- Safer Shield / Auto Safe Mode confirmation flow

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

## QA workflow support

REST Radar is designed to support practical QA documentation.

For each endpoint, the plugin can generate:

- QA bug report draft
- Developer fix snippet
- Risk explanation
- Suggested manual tests
- False positive notes

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

## Important disclaimer

REST Radar is a review and mitigation tool, not a replacement for a professional security audit. It helps identify and document potentially risky REST API endpoints, but all findings should be manually verified before being treated as confirmed vulnerabilities.

## Version

Current package version: **0.8.0**

## Author

**Szujó János**  
GitHub: [Szujo-Janos](https://github.com/Szujo-Janos)

## License

GPL-2.0-or-later
