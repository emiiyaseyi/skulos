# SKULOS

**The Complete Operating System for Modern Schools**

SKULOS is a cloud-based, multi-tenant Education Operating System (Education ERP) — not just another School Management System. It unifies admissions, academics, finance, communication, learning, and administration into a single platform, giving every school one login, one source of truth, and full control over which modules it uses.

Built as a SaaS platform: one codebase, unlimited schools, each with its own dedicated database, branding, subdomain, and configuration.

---

## Links

| Resource | Description |
|---|---|
| [Business / Landing Page](https://claude.ai/code/artifact/086cc9bb-ea95-4a21-a42e-71357ce5b2fe?via=auto_preview) | Prospective-school-facing marketing page |
| [Development Tracker](./DEVELOPMENT_TRACKER.md) | Master checklist for documentation and build work — the single source of truth for what's shipped and what's left |
| [SKULOS.docx](./SKULOS.docx) | Source product vision & business strategy document (Volume 1, Chapters 1–7) |

### Research & inspiration references
- [schoolcoreos.com](https://schoolcoreos.com/) — primary positioning/UX inspiration
- [school-core.com](https://school-core.com/) — secondary reference
- [safsms.com](https://safsms.com/school-administrative-software/saf-school-management-software/), [safsims.com](https://safsims.com/), [prodigyschoolsuite.com](https://prodigyschoolsuite.com/product/academic) — competitor research
- [LearnGuard Secure Browser](https://github.com/emiiyaseyi/Learn-Proctor-Secure-Browser) — planned CBT/proctoring integration for the Examination module

---

## Features

### Core modules (always available)
- **Student Records** — admission number, bio-data, guardian info, documents, medical, attendance, behaviour, library, payments, exam/CA history, certificates
- **Staff Records** — staff ID, bio-data, teaching load, attendance, leave, payroll, performance, qualifications, promotion history
- **Parent Portal** — one login for multiple children, even across classes or schools under the same ownership group
- **Academic Structure** — sessions, terms/semesters, sections, classes, arms, subjects, subject-teacher mapping
- **School Sections, Classes, Subjects & Houses** — fully configurable and renameable, run Nursery through College concurrently in one school
- **Continuous Assessment (CA)** — configurable per section, no fixed formula
- **Report Card Designer** — drag-and-drop, fully custom per school
- **Examinations & Results** — CA, assignments, practicals, exams, grading, broadsheets, ranking, result approval workflow, unlimited custom grading formulas
- **Timetable Management** — conflict detection, teacher workload balancing
- **Attendance** — student and staff
- **School Calendar & Event Planner**
- **Admissions & Enrollment** — online applications, entrance exams, secure CBT via LearnGuard integration
- **Finance** — fees, discounts, scholarships, installments, auto-invoicing, receipts, expenses, income, payroll, budgets, ledger, payment gateway + bank transfer, refunds, financial reports
- **Communication** — in-app, email, SMS, WhatsApp, push notifications, internal messaging, broadcasts, memos, emergency alerts
- **School Information System** — logo, motto, full address, contact details
- **Visitor Management**
- **School Assets Management**
- **Analytics & Reporting** — student/class/subject/teacher performance, financial, attendance, enrollment, custom report builder

### Optional / toggleable modules (enabled per school)
- Learning Management (lightweight LMS — assignments, quizzes, materials, discussions, certificates)
- Human Resources (leave & document management, beyond core staff records)
- Payroll
- Hostel Management
- Transport Management (+ future GPS bus tracking)
- Inventory Management (assets, consumables, suppliers, purchase orders, stock alerts)
- Medical / Clinic Management (visits, drugs, vaccination, allergies, sick bay)
- Library Management (barcode/QR check-out, fines, reservations, digital books)
- SMS Messaging (credit-based)
- Biometric Attendance (fingerprint, RFID, QR code)
- AI Assistant — report card comments, attendance prediction, at-risk student analysis, performance insights, teacher workload optimization, question/lesson/exam generation, parent summaries

### Platform capabilities (cross-cutting)
- **Hybrid multi-tenant architecture** — shared platform database + dedicated database per school
- **Single Sign-On** — one identity across every module a user has access to
- **Multi-school management** — one owner account, switch between schools without re-login
- **School cloning** — duplicate a school's full setup for a new campus in minutes
- **Guided School Setup Wizard** — school live and operational in under ~15 minutes
- **Role-Based Access Control** — 21+ built-in roles (Platform Super Admin, School Owner, Admin, Principal, Registrar, Bursar, Teacher, Parent, Student, Librarian, Nurse, and more), configurable per school/role/user
- **Feature/module toggles** — Enable / Disable / Hide / Read-only, set per school, per role, or per user
- **Bulk operations everywhere** — create, import, export, update, delete, assign, promote, archive, print, email, SMS, generate IDs/passwords, admit, graduate, suspend, restore
- **Import validation** — row-level error reporting with corrected-file re-upload, not a generic failure message
- **Audit trail** — who, when, IP, device, browser, old value, new value, on every change
- **Custom fields engine** — schools add their own fields to any core entity
- **Workflow engine** — configurable multi-step approval chains
- **Form builder** — drag-and-drop custom forms (visitor, leave, complaint, disciplinary, exit forms, etc.)
- **Automation/rules engine** — trigger-based actions (e.g. fee paid → receipt + notifications; attendance drop → alerts)
- **Document generator** — templated admission letters, ID cards, transcripts, certificates, receipts, employment letters, and more
- **Theme builder** — per-school logo, colours, fonts, login page (foundation for future White Label)
- **Report builder** — drag-and-drop custom reports, exportable to PDF, Excel, CSV, and Word
- **Notification Center** — unified SMS/Email/WhatsApp/Push/In-App view with delivery status and retries
- **Plugin Marketplace** — WordPress-style install/uninstall of optional modules
- **API Center** — every module exposes REST APIs for future mobile apps and integrations

### Business / commercial features
- Termly and annual subscription billing (annual = 3× termly)
- Free trial — one academic term by default, configurable
- Self-registration and manual (Super Admin) school onboarding
- Flexible, combinable pricing — by student count, modules enabled, storage, or flat rate
- White-label ready architecture (disabled at initial launch, future premium tier)
- Storage add-ons independent of subscription tier

### Security
- Two-Factor Authentication (2FA)
- Audit logs & login history
- IP restriction, session timeout, device management
- Configurable password policy & permission matrix
- Encryption at rest and in transit
- Automated, per-school backups

### Integrations
- Payment gateways: Paystack, Flutterwave, and others; bank transfer with proof-of-payment upload
- WhatsApp Business, Google Workspace/Classroom, Microsoft 365, Zoom
- Biometric devices, RFID cards, QR-code attendance
- LearnGuard Secure Browser — secure CBT with webcam monitoring, browser lockdown, screen recording

### Future ecosystem (post-MVP)
- Mobile apps for Parent, Teacher, Student, Admin (API-first backend built to support this from day one)
- SKULOS Sites (school website builder)
- Alumni management/portal
- Digital library / content marketplace
- Government reporting integrations
- Broader third-party API ecosystem & full plugin marketplace monetization

For the complete build status, open questions, and documentation roadmap, see the [Development Tracker](./DEVELOPMENT_TRACKER.md).

---

## Tech Stack

| Layer | Choice |
|---|---|
| Backend | Laravel 12 |
| Frontend | Next.js + React + TypeScript |
| UI | Tailwind CSS, ShadCN UI, Framer Motion |
| Database | MySQL (dedicated per-school databases) |
| Queue / Cache | Redis |
| Storage | S3-compatible + local storage option |
| Search | Laravel Scout + Meilisearch |
| Auth | Laravel Sanctum (SSO); future OAuth, 2FA, passwordless |
| Deployment | Docker, Linux, Nginx, Cloudflare; cPanel-compatible path |

## Status

📋 **Planning & documentation phase.** Product vision and business strategy (Volume 1, Chapters 1–7) are complete. See the [Development Tracker](./DEVELOPMENT_TRACKER.md) for what's documented, what's still being written, and the full build checklist.
