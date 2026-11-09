# CivicCare custom popup messages

Replaces app-owned native browser confirmations and alerts with one dependency-free, branded modal system. Includes the previous maintenance workspace patch for installations already using province/district/town routing.

## Behavior

- One dialog style for assignment, maintenance dispatch/completion, draft actions, deletion, duplicate linking, account/category changes, recovery codes, regional responsibility/routing, backup restore and logout.
- Geolocation error alerts and server success/error/warning/info flash messages use the same system.
- Context-specific titles/action labels, danger styling, Cancel-first focus for confirmations, acknowledgment-only alerts and serialized messages.
- Accessible dialog roles, labeled/described content, focus trap/return, inert background, scroll locking, Escape/backdrop cancellation, responsive layout and reduced motion.
- Messages are inserted as text, not HTML.
- No external service, modal library or JavaScript package is needed in the browser.

## Submission integrity

A document capture handler pauses `data-confirm` forms before their other submit listeners run. On approval it revalidates and replays the original form with its original submitter. Normal listeners, permissions, CSRF, file inputs and downstream cancellation remain effective. Pending/already-sent forms reject duplicate submits; action changes require new confirmation. Validation-aborted replays do not leave a stale bypass token.

Draft operations explicitly await the custom confirmation; repeated clicks are suppressed and focus is restored. Completion sets dynamic form confirmation metadata and relies on the shared gate before setting its busy state. Logout has both static HTML metadata and the common service, with no native fallback.

Server flash text remains escaped and is consumed from the session once. Success banners are represented by the popup; error summaries also remain inline for form context. Persistent setup warnings and ordinary field help remain in the page. Browser permission/file dialogs and standard input validation are browser-managed.

## Checks

- **173 HTTP/database checks** passed: full routing/team regression, public/authenticated dialog asset loading/order, logout consolidation and server error-popup metadata.
- **167 JavaScript checks** passed, including **33 dialog checks**, **23 citizen checks** and the prior geography, map, search, staff, team upload and keyboard menu suites.
- Dialog checks cover cancellation, queued messages, safe text, focus/keyboard behavior, background restoration, duplicate submits, photo/form preservation, validation, changed semantics, downstream listeners, older-form fallback, removed forms, navigation and one-time flash handling.
- PHP flash escaping and session consumption verified independently.
- Syntax passed for **50 PHP files and 13 JavaScript assets**. Static scan found **zero native app-owned alert/confirm/prompt calls** in production assets.
- Browser visual/interaction QA and the customer's localhost were not tested. DOM unit tests are simulations rather than a claim of real browser verification.

## Installation

Follow `POPUP_MESSAGES_README_SI.txt`; merge/replace supplied files and hard-refresh. Requires the province/district/town routing version. No SQL import, configuration overwrite, account creation or password reset is performed. Existing reports, assignments, town mappings and uploaded photos remain intact.
