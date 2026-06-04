# QA Checklist

## Installation

- [ ] Plugin uploads successfully from ZIP
- [ ] Plugin activates without fatal error
- [ ] Admin menu appears under Tools → REST Radar
- [ ] Dashboard widget appears for administrators

## Scanner

- [ ] Endpoint table loads
- [ ] Risk badges are visible
- [ ] Source column is populated
- [ ] Risk filter works
- [ ] Source filter works
- [ ] Search works
- [ ] Reset button clears filters

## Details

- [ ] Details button opens endpoint detail panel
- [ ] Risk explanation is visible
- [ ] Developer fix snippet renders in dark code block
- [ ] QA bug report draft renders in dark code block
- [ ] Copy buttons work
- [ ] Expand/collapse works

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
- [ ] Delete shield rule works
- [ ] Clear logs works
- [ ] Auto Safe Mode requires confirmation
- [ ] Core route protection requires separate confirmation

## Snapshot / Compare

- [ ] Save current scan creates a snapshot
- [ ] Multiple snapshots can be saved
- [ ] Compare snapshots works
- [ ] New endpoints are detected
- [ ] Removed endpoints are detected
- [ ] Risk changes are detected
- [ ] Permission callback changes are detected
- [ ] Snapshot delete works
- [ ] Clear all snapshots works

## Uninstall

- [ ] Cleanup option is visible
- [ ] Cleanup option remains OFF unless manually enabled
- [ ] With cleanup OFF, audit data is preserved after plugin deletion
- [ ] With cleanup ON, plugin options/snapshots/logs are removed during uninstall

## Exports

- [ ] CSV export downloads
- [ ] QA Markdown export downloads
