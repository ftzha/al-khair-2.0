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

**Status:** Initial development

The development environment has been configured, and the initial architecture and business workflows have been planned.

Application features will be implemented incrementally, with an emphasis on maintainability, validation, authorization, and automated testing.

## Development Approach

- RESTful API architecture
- Separation of responsibilities using controllers, form requests, policies, and services
- Database integrity and transactional business operations
- Automated testing of critical workflows
- Git version control with GitHub and GitLab mirrors

## Disclaimer

This is an independent portfolio project. Membership policies, fee amounts, and eligibility rules are subject to confirmation before any real-world deployment.