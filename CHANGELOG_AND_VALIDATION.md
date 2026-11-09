# Province, District and Town dispatch

This cumulative update changes CivicCare's dispatch boundary from unrestricted officer coverage to a configured province/district/town hierarchy. It includes the preceding professional workspace and inline map improvements.

| Stage | Responsible account | Enforced boundary |
| --- | --- | --- |
| Report submission | Citizen | A valid Province → District → Town path is required. |
| Review / officer assignment | Province Admin | Reports in its own province; only the configured officer for that report's district. |
| Maintenance assignment | District Officer | Its assigned district reports; only active teams registered in the report's town. |
| Work / evidence | Town Maintenance Team | Reports assigned to that team, in its configured town. |
| Completion review | District Officer | Review the town team's evidence and submit for provincial approval. |
| Final approval | Province Admin | Its province's completed work. |
| Configuration / migration | System Admin | Geography, responsibility mapping, legacy classification, global settings and backup. |

## Configuration and migration

The additive migration seeds 9 provinces, 25 districts and 43 starter towns. This is an operational starter town list, not a complete official list of towns. Add actual maintenance towns through Province routing. Province/district sources: [Department of Census and Statistics](https://www.statistics.gov.lk/Resource/en/Population/CPH_2024/Preliminary_Report.pdf), [Ministry of Provincial Councils and Local Government](https://mpclg.gov.lk/web/index.php?Itemid=106&id=55&lang=en&option=com_content&view=article).

No real account emails, passwords or default logins are generated. Each province has one responsible Province Admin slot, each district one District Officer slot; a town may have multiple teams. System Admin or the relevant Province Admin creates the district/town accounts with actual credentials. Existing accounts can be mapped without discarding their credentials.

Existing administrators initially retain their previous global rights as System Admins. Mapping another existing global administrator to a province requires the current System Admin password, removes the target's global rights and invalidates its sessions. The current System Admin cannot convert itself. Configured Province Admins cannot create global administrators or access global backups, system tools or audit exports.

Old reports are retained with empty geographical fields, rather than guessing a province from their address/pin. Only System Admin can classify them. Explicit classification/correction resets active work to Submitted and clears prior assignments, retaining evidence and history. Closed reports remain closed. New routing fields do not automatically follow map-pin movement.

District responsibility replacement with open work requires explicit handover confirmation and a reason. It transfers the district's current open work to the new officer while retaining status, town team and evidence. Earlier forms become stale, and the old officer immediately loses write access.

A report submitted before a province administrator is configured stays in its province queue. The readiness screen flags missing/disabled ownership; a configured administrator then sees the existing queue. Notifications are sent to the active configured Province Admin at submission.

## Read and write protection

Issue reads are scoped centrally in the query helper for non-System staff, including totals, lists, maps, CSV exports and media lookups. Locking mutation queries retain explicit geographical guards under the existing application write lock. Server checks reject mismatched parents, cross-province administration, another district's officer, another town's team, invalid account conversion and stale forms. Progress and completion media use the same issue scope as original report photos.

The Citizen community browser remains shared; My reports stays limited to the reporting citizen. Staff geography restricts operational access, while the System Admin retains central configuration responsibility.

## Validation

**139 HTTP/database checks** ran against a disposable MariaDB/PHP instance, including:

- Creation of all nine Province Admins and all 25 District Officers, plus town team accounts.
- Province-specific notifications, queues, lists, maps, legacy/current CSV and direct report URLs.
- Wrong province/district/town inputs and forged review/assignment/approval requests.
- Original photos, progress evidence and completion photos under province/town permissions.
- Full team-start/completion → district review → province final approval.
- Explicit legacy classification, stale correction rejection and central-only geographical corrections.
- Existing global-admin conversion and immediate old-session revocation; existing Officer/Team mapping.
- Confirmed district handover retaining pending review evidence and invalidating stale forms.
- Idempotent migration preserving reports and never re-elevating converted administrators.
- Complete geography-aware backup/restore and rejection of mixed-hierarchy backup data before replacing records.

**110 JavaScript checks**: prior citizen/draft/photo, staff lookup, place search, map grouping, Street View lifecycle and keyboard menu tests (100), plus dependent geography selectors/account requirements/draft selection ordering (10).

Syntax checks passed for **48 PHP files and 11 JavaScript assets**. Real customer credentials, the user's localhost, browser visual QA, live Google Street View and SMTP delivery were not tested. All test accounts/data were isolated fixtures.

## Installation and compatibility

Follow `UPDATE_README_SI.txt`. Existing installations with the latest professional update need `database/province_routing_upgrade.sql`, followed by merging the supplied files. Earlier additive migrations are included for older installations; import them in the documented order. Configuration files and uploaded photos are excluded from the ZIP.

Routing-aware backups include all geography and account responsibility tables. Pre-routing application backup ZIPs are not directly compatible with this expanded schema; restore them in their matching version before migrating. Preserve a complete folder backup and phpMyAdmin SQL export before installation.
