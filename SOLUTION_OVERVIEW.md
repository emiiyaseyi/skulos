# SKULOS — Solution Structure Overview

## What it is

An Africa-first, multi-tenant Education Operating System (not a generic "School Management System") — unifies admissions, academics, finance, communication, and learning into one platform. One login, one source of truth, modules enabled per school via subscription plans.

## Architecture

| Layer | Choice |
|---|---|
| Backend | Laravel 12 (PHP 8.2+), server-rendered Blade views (no separate SPA frontend) |
| Multi-tenancy | Hybrid: one shared platform database (schools, billing, plans) plus one dedicated database per school (student/academic data) |
| Database | MySQL in production (SQLite as a local dev stand-in) |
| Queue/Cache | Redis (queued jobs for bulk email/notifications) |
| Storage | S3-compatible or local disk. Images are auto-converted to WebP at upload time; nothing regenerable (report cards, receipts) is stored permanently |
| Auth | Laravel Sanctum; separate guards per audience (staff/parent/student, plus a standalone applicant guard for admissions) |
| Deployment targets | cPanel/shared hosting and more modern VPS/container hosting, both stay viable |

## Core feature modules

- **Admissions** — public apply flow, application and acceptance fee collection, custom form builder, document upload and review, a configurable pipeline board, automatic offer to accept to pay to onboard to enroll flow, self-service credential recovery
- **Academics** — students, staff, classes/sections/arms, subjects, timetabling, attendance, houses, promotion (automatic at term-end plus admin-triggered on demand)
- **Examinations and Results** — configurable grading scales, assessment structure (mid-term/full-term), report cards (8 visual templates), bulk Excel score workbooks, narrative evaluation for early years
- **Assessment Hub (CBT)** — question bank, timed online assessments, 4-tier proctoring security profiles (lockdown, face/object detection, risk scoring, auto-suspend), a 15-step pre-exam wizard, live invigilator monitoring. Reused by admissions entrance tests, academic CBTs, and staff assessments
- **Finance** — fee schedules, bank-transfer payment evidence and verification, pooled family balances, billing and subscriptions
- **Communication** — announcements, in-app chat, notifications, a Knowledge Base scoped per role
- **RBAC** — deny-by-default permissions, per-school role cloning from templates, module-level and permission-level toggles
- **Platform Admin** — school provisioning/cloning/archiving, billing and plan management, backups (with corruption-safe restore), storage-usage dashboards, audited support impersonation, trial-request pipeline

## Deployment model

- **Multi-tenant provisioning** — creating a school runs a full migration set against a freshly created tenant database, the same code path for organic signups, admin-created schools, and school cloning
- **Self-hosted option** — license-key-gated release distribution (a git archive snapshot plus checksum verification), for schools that want to run their own instance rather than the SaaS
- **Backups** — per-school, scheduled or on-demand, write-verify-swap restore (never overwrites a live database until the incoming backup is confirmed intact)
- **Background jobs** — bulk email/notifications run queued, not inline, to avoid timeouts on large "all audience" sends
- **Maintenance** — automated retention/cleanup, database optimization, a real health-check score per school
