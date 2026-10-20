# al Khair 2.0

A web-based community membership and fee management system built with Laravel, Vue 3, TypeScript, MySQL, and Docker.

## About the Project

al Khair 2.0 is a rebuild of my university Final Year Project (2023), originally developed to support a Muslim community in Ulu Yam Bharu, Selangor, Malaysia.

The project aims to modernize the original system with a maintainable architecture, secure authentication, structured membership workflows, and improved fee management.

This repository represents the second version of the application, developed as a personal software engineering portfolio project.

## Tech Stack

**Backend**
- PHP 8.4
- Laravel 13
- Laravel Sanctum
- MySQL 8.4

**Frontend**
- Vue 3
- TypeScript
- Vue Router
- Pinia
- Vite

**Development Environment**
- Docker Compose
- Nginx
- WSL2 (Ubuntu)

## Planned Core Modules

1. **Identity & Access** — User authentication, authorization, and role-based access.
2. **Registration & Approval** — Membership applications, submission revisions, and committee reviews.
3. **Fees, Invoices & Payments** — Fee configuration, invoice generation, and payment verification.
4. **Membership Lifecycle & Eligibility** — Membership activation, annual renewals, waiting periods, and eligibility determination.

## Project Structure

```text
al-khair-2.0/
├── backend/          # Laravel REST API
├── frontend/         # Vue 3 + TypeScript application
├── docker/
│   ├── php/          # PHP Dockerfile
│   └── nginx/        # Nginx configuration
├── compose.yaml      # Docker Compose services
└── README.md
```

## Development Status

**Status:** M4 and M5 complete; M6 membership lifecycle in progress.

- M1: Project setup, identity and authentication backend.
- M2: Registration and committee approval backend.
- M3: Vue authentication, session restoration and email verification.
- M4: Member application management and committee review interface.

### M4 Features

Members can create and list applications, edit phone/address in DRAFT or CHANGES_REQUESTED, and submit or resubmit after verifying their email. Name and date of birth are read-only. Each submitted revision preserves its snapshot, submission timestamp and committee feedback.

COMMITTEE and ADMIN users can access a paginated pending-review queue, inspect revision history, and approve, reject or request changes. Rejection and change requests require a reason. The interface confirms decisions and prevents repeated clicks while requests are pending.

Laravel remains authoritative for ownership, account status, roles, email verification and self-review restrictions. Application approval does not create or activate membership.

### Verification

Backend tests and frontend tests, lint, type checking and production build passed in the local Docker environment, as reported by the developer on October 20, 2026. Manual browser testing also passed the member submission → committee change request → member resubmission → committee approval workflow.

Run checks from the repository root in Ubuntu:

```bash
docker compose exec app vendor/bin/pint --dirty --format agent
docker compose exec app php artisan test --compact
docker compose exec frontend npm run lint
docker compose exec frontend npm run type-check
docker compose exec frontend npm run test:unit -- --run
docker compose exec frontend npm run build
```

### M5 Billing Foundation

M5 backend and frontend checks, migrations, demonstration seeding, and manual bank transfer/cash payment testing passed as reported by the developer. M5 and the subsequent navigation/dashboard refinements have been committed and pushed by the developer.

Initial invoices are issued atomically with application approval. They belong to the Person and Registration before any membership activation; no member is created. The invoice contains separate registration and annual items, stores their historical amounts, and is due 30 Malaysia calendar days after issuance. Missing or ambiguous rates roll back approval. Initial invoice creation uses row locking and a unique billing key to prevent duplicate billing.

The local-only `DemoFeeSeeder` configures fictional portfolio demonstration rates: registration RM20.00 and annual RM50.00, effective January 1, 2026 with no predetermined end date. It does not overwrite existing rates. Annual fees are charged in full. Rate end dates, when configured, are inclusive; use `FeeRateService` for new rate creation so overlap checks and locking are applied.

After the backend tests pass, install the new tables and seed demonstration rates in local development:

```bash
docker compose exec app php artisan migrate
docker compose exec app php artisan db:seed --class=DemoFeeSeeder
```

No existing approved applications are automatically backfilled. The payment backend supports full bank transfers with private PDF/JPG/PNG receipts up to 2 MB, committee-recorded cash, and independent verification/rejection. PENDING payments block another submission for that invoice. Receipt downloads require owner or committee authorization and use no-store attachment responses. M5 verification updates payment and invoice atomically; the M6 activation integration is described below. Both the submitter and invoice owner are prohibited from reviewing the payment. Submission, review and verification timestamps are server-recorded. Annual-renewal cutoff enforcement remains in the next M6 batch; invoice payment due dates do not implement the renewal deadline.

### M6 — Activation foundation

The first M6 backend batch adds memberships, calendar-year membership periods and append-only lifecycle events. Initial-payment verification now creates membership atomically with marking the payment VERIFIED and invoice PAID. Activation rechecks the approved registration and independent verification; row locks and unique constraints prevent duplicate activation. Membership numbers are generated once and existing numbers are preserved.

The activation date is the verified payment timestamp's date in Asia/Kuala_Lumpur. The initial waiting period ends at 00:00 Malaysia time on that date plus 90 calendar days. Eligibility is calculated as of a requested instant using lifecycle events, paid periods and verification timestamps, rather than stored as a boolean.

An initial invoice retains its original billing year when verification crosses New Year. Its period remains January 1–December 31 of that billing year, and activation still starts on the verification date. A separate annual-only renewal is required for the new year; the initial invoice is not reassigned. Renewal generation, January grace-period enforcement and membership UI are the next M6 batch.

These changes do not automatically backfill previously verified M5 payments. No existing invoices or fee configurations are rewritten. The activation backend tests and migrations passed as reported by the developer.

### M6 — Renewal backend checkpoint

The activation checkpoint passed backend checks and migrations, as reported by the developer. The renewal checkpoint adds annual-only invoices using the rate effective on issuance, one invoice per member and billing year, and one paid period per membership/year. Initial invoice due dates and billing years remain unchanged. Renewals are issued during January 1–30 for active memberships with the previous year paid; gap-year arrears/reactivation remains outside v1.0.

Renewal bank transfers must be submitted, and cash must be recorded, before January 31 at 00:00 Asia/Kuala_Lumpur time. The server records this timestamp; no user-supplied payment date is accepted. Timely pending payments can be verified later. A rejected payment cannot be replaced after the cutoff. Verified renewals take effect prospectively from verification and preserve the original initial waiting-period boundary.

Unpaid members are ineligible during January's grace period. Status synchronization records LAPSED from the January 31 boundary (never before activation or the preceding paid period became effective). Valid delayed verification records RENEWED and restores status prospectively; it also records a missing lapse if the scheduler did not run. Historical events are retained. A past-year payment never pays the current year.

Operator commands:

```bash
docker compose exec app php artisan memberships:generate-renewals
docker compose exec app php artisan memberships:sync-statuses
```

The renewal command uses the current Malaysia calendar year and does nothing outside January 1–30. Both commands tolerate retries and are scheduled hourly with overlap protection. The Laravel scheduler runner must be started separately (for example, `docker compose exec app php artisan schedule:work` during development); adding schedule entries does not start a background process. Renewals for a membership first activated in January can be generated on the next run or by running the renewal command directly before the cutoff.

The renewal backend checkpoint passed its tests and full backend suite as reported by the developer. It was committed and pushed separately.

### M6 — Membership dashboard checkpoint

Authenticated active accounts can read only their own membership summary through `GET /api/membership`. The response includes the member number, current lifecycle status, activation and waiting dates, remaining calendar days, paid current-year period, eligibility explanation and renewal state. Personal identity numbers, private receipts and verification actors are not included. Summary reads do not generate invoices or write membership events.

During January 1–30, eligible members can request their own annual invoice through `POST /api/membership/renewal-invoice`. Ownership, year, rate, cutoff and duplicate protection are enforced on the backend; the client supplies none of those financial values.

The dashboard displays this summary with Malaysia date formatting, invoice links, a refresh action and an available-renewal action. It keeps loading, failed requests and absent membership records distinct. Existing verified M5 payments still require controlled activation; this checkpoint does not backfill them automatically.

The renewal backend checkpoint passed checks and was committed/pushed as reported by the developer. The membership dashboard checkpoint's backend and frontend checks await execution in Docker.

### Next Milestones

- M5: Fees, invoices and payments.
- M6: Membership lifecycle and eligibility.
- M7: Integration, security and v1.0 release preparation.

## Development Approach

- RESTful API architecture
- Separation of responsibilities using controllers, form requests, policies, and services
- Database integrity and transactional business operations
- Automated testing of critical workflows
- Git version control with GitHub and GitLab mirrors

## Disclaimer

This is an independent portfolio project. Membership policies, fee amounts, and eligibility rules are subject to confirmation before any real-world deployment.