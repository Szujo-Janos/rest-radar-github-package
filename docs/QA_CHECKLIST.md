# QA Checklist

## Installation

- [ ] Plugin uploads successfully from ZIP
- [ ] Plugin activates without fatal error
- [ ] Admin menu appears under Tools → REST Radar
- [ ] Dashboard widget appears for administrators
- [ ] Plugin version shows 0.9.1

## Dashboard

- [ ] Main REST Radar admin screen loads
- [ ] Operational dashboard appears above the endpoint table
- [ ] Priority queue card is visible
- [ ] Review progress card is visible
- [ ] System state card is visible
- [ ] Metric strip is visible
- [ ] Priority queue links filter the endpoint list correctly
- [ ] Review queue link opens unreviewed endpoints
- [ ] Dashboard remains readable on medium/narrow admin widths

## Scanner

- [ ] Endpoint table loads
- [ ] Risk badges are visible
- [ ] Source column is populated
- [ ] Review column is visible
- [ ] Risk filter works
- [ ] Source filter works
- [ ] Review filter works
- [ ] Search works
- [ ] Reset button clears filters

## Details

- [ ] Details button opens endpoint detail panel
- [ ] Risk explanation is visible
- [ ] Endpoint review decision card is visible
- [ ] Developer fix snippet renders in dark code block
- [ ] QA bug report draft renders in dark code block
- [ ] Copy buttons work
- [ ] Expand/collapse works

## Review / Triage

- [ ] Review status can be saved
- [ ] Reviewer note can be saved
- [ ] Severity override without note is rejected
- [ ] Severity override with note is saved
- [ ] Effective risk reflects the override
- [ ] Review status remains after page refresh
- [ ] Review filters work: Unreviewed only, Critical/High + unreviewed, Has Shield rule, Fix required, Retest required
- [ ] QA ticket draft includes review decision data

## Probe

- [ ] GET probe is only available for simple GET endpoints
- [ ] GET probe never appears for POST/PUT/PATCH/DELETE-only endpoints
- [ ] Probe result shows status, content type, size, time, and preview

## Shield

- [ ] Shield is OFF by default
- [ ] Auto Safe Mode is OFF by default
- [ ] Core route protection is OFF by default
- [ ] Add shield rule works
- [ ] Duplicate shield rules are not created
- [ ] Shield can block selected endpoint
- [ ] Block log appears
- [ ] IP anonymization option is visible
- [ ] Anonymized IPv4 log masks the last octet
- [ ] Delete shield rule works
- [ ] Clear logs works
- [ ] Auto Safe Mode requires confirmation
- [ ] Core route protection requires separate confirmation

## Snapshot / Compare

- [ ] Save current scan creates a snapshot
- [ ] Multiple snapshots can be saved
- [ ] Snapshot list is capped safely
- [ ] Compare snapshots works
- [ ] New endpoints are detected
- [ ] Removed endpoints are detected
- [ ] Risk changes are detected
- [ ] Permission callback changes are detected
- [ ] Snapshot delete works
- [ ] Clear all snapshots works
- [ ] Previously accepted/false-positive/shielded endpoint changing fingerprint becomes Retest required

## Exports

- [ ] CSV export downloads
- [ ] CSV contains review fields
- [ ] QA Markdown export downloads
- [ ] QA Markdown contains review evidence block

## Uninstall

- [ ] Cleanup option is visible
- [ ] Cleanup option remains OFF unless manually enabled
- [ ] With cleanup OFF, audit data is preserved after plugin deletion
- [ ] With cleanup ON, plugin options/snapshots/logs/review decisions are removed during uninstall
