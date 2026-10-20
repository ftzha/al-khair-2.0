# al Khair 2.0 Frontend

Vue 3 and TypeScript application using Pinia, Vue Router, Axios and Vite.

## Local Development

The frontend runs in the Docker Compose `frontend` service. From the repository root in Ubuntu:

```bash
docker compose up -d
```

Open `http://localhost:5173`. Vite forwards `/api` requests to the Compose `web` service. Authentication uses Sanctum bearer tokens stored in session storage; the server validates permissions for every protected API action.

## Application Routes

| Route | Access | Purpose |
| --- | --- | --- |
| `/register` | Guest | Create an account |
| `/login` | Guest | Sign in |
| `/dashboard` | Authenticated | Account and email verification controls |
| `/applications` | Authenticated owner | List applications and create a draft |
| `/applications/:id` | Authenticated owner | Edit contact details, submit and view revision feedback |
| `/committee/applications` | COMMITTEE or ADMIN | Paginated pending-review queue |
| `/committee/applications/:id` | COMMITTEE or ADMIN | Inspect snapshots and record review decisions |

Frontend role guards guide navigation. Laravel enforces access independently, including ownership and the prohibition on reviewing your own submission.

## Payment Pages

Members submit a full bank-transfer amount from invoice details, attaching a PDF/JPG/PNG receipt up to 2 MB and a transfer reference. Payments stay pending until independent committee verification. Receipt downloads use authenticated blob requests, without placing bearer tokens in URLs.

Committee and Admin users open Verify Payments from the dashboard. The queue filters pending, verified and rejected payments. Reviewers inspect invoice items and receipts, then confirm verification or rejection. Rejection requires a reason. Cash payments are recorded against eligible unpaid invoices from the Record cash payment page and require a different verifier. Submitters and invoice owners cannot review their own payments. No membership activation is performed by these screens.

## Invoice Pages

Members can open My Invoices from the dashboard, browse paginated invoices, and inspect the saved fee items, MYR total, status, billing year, Malaysia-formatted issuance time and due date. The API restricts both list and detail access to the invoice owner, independently of frontend routing. Unpaid invoice details now include bank-transfer receipt submission, payment history and authenticated receipt downloads. Pending payments block another submission until reviewed.

## Identity Display

Member and committee detail pages display only a masked identity number by default. COMMITTEE and ADMIN users can explicitly reveal the number through a separate authenticated, rate-limited endpoint. Reveal responses use no-store caching headers; access logs contain reviewer and registration IDs, never the number. The frontend retains the revealed value only in component memory and hides it after 60 seconds, when the tab is hidden, on reload/navigation, or when a review is recorded. Database encryption and lookup hashing are unchanged; full identity numbers remain absent from submission snapshots.

## Date Display

Dates are displayed as DD/MM/YYYY. Timestamps are displayed as DD/MM/YYYY, HH:mm MYT in Asia/Kuala_Lumpur, regardless of browser locale. DOB entry uses a native date picker whose presentation follows browser regional settings; API calendar dates remain YYYY-MM-DD. Reuse the shared date formatting functions for new screens.

## Account Registration

Date of birth is required when creating an account. A complete Malaysian NRIC pattern can suggest a DOB; the user must check the full year because the century is ambiguous. The field stays editable before account creation, and manual corrections are preserved. Other identity documents require manual DOB entry. Laravel rejects missing, invalid, today or future birth dates. Existing accounts and historical submission snapshots are not backfilled.

## Member Workflow

1. Create a membership application draft from the dashboard's My Applications link.
2. Edit phone number and address; name and date of birth are read-only.
3. Verify the account email. Verification can be resent or refreshed from the application page.
4. Confirm submission. The interface saves current contact details before submitting.
5. View revision snapshots, review reasons and timestamps in Submission history.
6. If changes are requested, update the contact details and confirm resubmission.

Only DRAFT and CHANGES_REQUESTED applications are editable. An existing draft, pending application, change request or approved application prevents another application from being created.

## Committee Workflow

Sign in with a separate COMMITTEE or ADMIN account and choose Review Applications. Open an application, inspect its submitted revisions, choose a decision and confirm it. Reject and Request changes require a nonblank reason. Successful decisions disable review actions; uncertain write results trigger a state refresh rather than automatic retries.

Approval completes application review; membership activation belongs to a later milestone. Local test accounts are created in the development database and credentials are not committed.

## Checks

Run from the repository root:

```bash
docker compose exec frontend npm run lint
docker compose exec frontend npm run type-check
docker compose exec frontend npm run test:unit -- --run
docker compose exec frontend npm run build
```

`npm run lint` applies fixes. Tests cover application editing and submission, verification, history, committee reviews, pagination, API response handling and role-based route access.

## Membership Dashboard

The dashboard reads the authenticated account's membership summary from `GET /api/membership`. It displays membership status and number, activation and initial waiting-period dates, current-year paid coverage, server-calculated eligibility, and renewal information. A failed request is shown separately from having no membership record.

Eligible members can create an annual renewal invoice during January 1–30 and open its details. The server determines ownership, billing year, fee amount, deadlines and eligibility. The frontend prevents duplicate clicks and refreshes server state after an uncertain creation failure. It does not calculate eligibility locally or activate historical payments.

## Committee Memberships

COMMITTEE and ADMIN accounts can open Memberships from the shared navigation, search by name/member number, and inspect eligibility, paid periods and the membership timeline. The ADMIN-only closure form requires a reason and an explicit permanent-closure confirmation. Pending payments block the form; the backend rechecks authorization and pending payments on submission. A failed or uncertain closure request refreshes server state without automatically retrying the action.

Closure preserves the member's login and financial/history records. Closed memberships cannot be reopened in v1.0. Member identity numbers and private receipt paths are not included in the membership API.
