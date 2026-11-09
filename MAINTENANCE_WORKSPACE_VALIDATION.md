# Maintenance team workspace

Focused update for the existing province/district/town version of CivicCare. No schema migration or new account credentials are included.

## Changes

- Amber setup-incomplete notice, concrete System Admin mapping instructions and locally copied setup details; configured-town empty work is a separate state.
- Read-only province, district, town and supervising officer context, with a compact sidebar role label.
- All-open and five stage summary cards; seven queues, counts, search, priority and date/updated/newest/priority sorting.
- Responsive job cards with exact next actions, priority, town, officer, attention/due date and update timestamp. No one-page pagination on empty/single-page lists.
- Team detail layout with original dispatch instructions preserved from assignment history, current evidence, location copying, latest review feedback and paginated work history.
- Work-photo MIME, size and decoded-dimension checks, preview/removal, stale decode protection, completion confirmation and duplicate-submit prevention. Server-side photo/state validation remains authoritative.
- Explicit Returned stage for new officer/province returns. Resume is required before another progress/completion upload. Existing older In Progress records are not guessed or backfilled.
- Separate Team details, Security and Recovery tabs. Coverage and login email stay read-only; naming examples do not rename existing teams automatically.
- Correct zero/remaining recovery-code text and one-time code display. Password changes return to Security with the current session retained; other sessions are invalidated.
- Sidebar unread notifications and System Admin Configure team town links that preselect the relevant account.
- Existing geography guards, account ownership and final provincial approval remain enforced.

## Verification

**170 HTTP/database checks passed** against a disposable PHP/MariaDB instance. This includes the existing 139 routing checks plus team workspace tests for returned/resumed work, both district/province return cycles, preserved instructions, upload/waiting states, filtered/scoped queues, unconfigured teams, recovery codes, read-only town coverage, password/session behavior and System Admin town-mapping links.

**131 JavaScript checks passed:** prior 110 regression checks plus 21 focused team upload/copy/completion/navigation tests. These include wrong MIME, oversize files and decoded dimensions, decode races, cleanup, cancellation, duplicate submission and resumed-action labels.

Syntax passed for **50 PHP files and 12 JavaScript assets** in the consolidated source. Browser visual/interaction QA, live provider services and the customer's localhost were not tested.

## Installation

Requires the Province/District/Town routing update already installed. Merge/replace the supplied app/assets files; do not delete entire folders. No SQL import is needed. See `MAINTENANCE_WORKSPACE_README_SI.txt`.

A real town still needs to be selected by the System Admin for an unconfigured team. No town, real email, password, existing team name or assignment is guessed or reset by this patch.
