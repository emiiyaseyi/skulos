<?php

/*
|--------------------------------------------------------------------------
| SKULOS platform catalog
|--------------------------------------------------------------------------
|
| The module and role catalogs the platform seeds from. This file is the
| single place to add a module, a permission action, or a built-in role
| template — the RbacSeeder syncs it into the database idempotently.
|
| Modules marked "core" default to enabled for every school; optional
| modules default to disabled until the Platform Super Administrator (or
| subscription logic, later) turns them on. Deferred modules exist in the
| catalog so toggles/permissions are stable, but nothing ships UI for them
| yet — see tracker section 5b.
|
*/

return [

    /*
    | Which modules a staff member can be granted extra, per-person access
    | to beyond their base role (user request 2026-07-12: "a staff can be
    | given more roles/features to handle from the list of features... the
    | school has under their plans from billing"), via
    | Rbac::grantModuleOverride(). Deliberately a curated subset of
    | config('skulos.modules'), not every module: granting "full access"
    | to a module with no real screen behind it (Finance, Library, HR,
    | Timetable, Attendance marking — none built yet) would be a toggle
    | that does nothing, so only modules with an actual screen are listed
    | here. Extend this list as more modules get built, not by widening it
    | to the full module catalog.
    */
    'staff_extra_access_modules' => [
        'students', 'staff', 'parents', 'academics', 'examinations',
        'report_cards', 'calendar', 'communication', 'school_info',
    ],

    'modules' => [
        // code => [name, core?]
        'students' => ['name' => 'Student Records', 'core' => true],
        'staff' => ['name' => 'Staff Records', 'core' => true],
        'parents' => ['name' => 'Parent Portal', 'core' => true],
        'academics' => ['name' => 'Academic Structure', 'core' => true],
        'attendance' => ['name' => 'Attendance', 'core' => true],
        'examinations' => ['name' => 'Examinations & Results', 'core' => true],
        'report_cards' => ['name' => 'Report Cards', 'core' => true],
        'timetable' => ['name' => 'Timetable', 'core' => true],
        'calendar' => ['name' => 'School Calendar & Events', 'core' => true],
        'communication' => ['name' => 'Communication', 'core' => true],
        'analytics' => ['name' => 'Analytics & Reporting', 'core' => true],
        'school_info' => ['name' => 'School Information', 'core' => true],

        // Optional / toggleable (tracker section 6)
        'lms' => ['name' => 'Learning Management', 'core' => false],
        'hr' => ['name' => 'Human Resources', 'core' => false],
        'payroll' => ['name' => 'Payroll', 'core' => false],
        'hostel' => ['name' => 'Hostel Management', 'core' => false],
        'transport' => ['name' => 'Transport Management', 'core' => false],
        'inventory' => ['name' => 'Inventory Management', 'core' => false],
        'medical' => ['name' => 'Medical / Clinic', 'core' => false],
        'library' => ['name' => 'Library', 'core' => false],
        'biometric' => ['name' => 'Biometric Attendance', 'core' => false],
        'ai' => ['name' => 'AI Assistant', 'core' => false],

        // Deferred to a later build (tracker section 5b) — cataloged, not built
        'admissions' => ['name' => 'Admissions & Enrollment', 'core' => false],
        'finance' => ['name' => 'Finance', 'core' => false],
        'visitors' => ['name' => 'Visitor Management', 'core' => false],
        'assets' => ['name' => 'School Assets', 'core' => false],
        'discipline' => ['name' => 'Discipline Management', 'core' => false],
    ],

    /*
    | The full commercial feature catalog behind pricing (tracker section 14,
    | sourced from "SKULOS PRICING PLAN AND FEATURES.docx"). This is the
    | admin-editable checklist a Plan's "features" column picks from for
    | marketing/CMS display; it is deliberately broader than the RBAC
    | "modules" catalog above, since many bullets here (e.g. "Standard
    | Support", "Data Migration") are service commitments, not toggleable
    | screens. Grouped for the settings/pricing-page UI. Nothing here is
    | hardcoded per plan; Plan::features stores which keys apply.
    */
    'plan_feature_catalog' => [
        'academic' => ['label' => 'Academic', 'features' => [
            'student_records' => 'Student Records',
            'school_sections' => 'School Sections',
            'classes_arms' => 'Classes & Arms',
            'subject_management' => 'Subject Management',
            'school_houses' => 'School Houses',
            'academic_sessions_terms' => 'Academic Sessions & Terms',
            'attendance_management' => 'Attendance Management',
            'continuous_assessment' => 'Continuous Assessment',
            'result_management' => 'Result Management',
            'basic_report_cards' => 'Basic Report Cards',
            'student_promotion' => 'Student Promotion',
            'academic_calendar' => 'Academic Calendar',
            'timetable_management' => 'Timetable Management',
            'teacher_workload' => 'Teacher Workload',
            'advanced_report_cards' => 'Advanced Report Cards',
            'unlimited_grading_systems' => 'Unlimited Grading Systems',
            'custom_report_card_designer' => 'Custom Report Card Designer',
            'class_position_ranking' => 'Class Position & Ranking',
        ]],
        'admissions' => ['label' => 'Admissions', 'features' => [
            'online_admissions' => 'Online Admissions',
            'admission_workflow' => 'Admission Workflow',
            'applicant_tracking' => 'Applicant Tracking',
            'admission_letters' => 'Admission Letters',
            'online_document_upload' => 'Online Document Upload',
        ]],
        'administration' => ['label' => 'Administration', 'features' => [
            'user_role_management' => 'User & Role Management',
            'staff_records' => 'Staff Records',
            'parent_portal' => 'Parent Portal',
            'student_portal' => 'Student Portal',
            'teacher_portal' => 'Teacher Portal',
            'school_profile' => 'School Profile',
            'school_branding' => 'School Branding',
            'dashboard' => 'Dashboard',
        ]],
        'communication' => ['label' => 'Communication', 'features' => [
            'in_app_notifications' => 'In App Notifications',
            'announcements' => 'Announcements',
            'birthday_anniversary_notifications' => 'Birthday & Anniversary Notifications',
            'email_notifications' => 'Email Notifications',
            'parent_broadcast' => 'Parent Broadcast',
            'communication_history' => 'Communication History',
            'sms_notifications' => 'SMS Notifications',
            'whatsapp_notifications' => 'WhatsApp Notifications',
            'automated_notifications' => 'Automated Notifications',
        ]],
        'finance' => ['label' => 'Finance', 'features' => [
            'bank_transfer_payment_option' => 'Bank Transfer Payment Option',
            'payment_evidence_upload' => 'Payment Evidence Upload',
            'payment_verification' => 'Payment Verification',
            'basic_fee_records' => 'Basic Fee Records',
            'installment_payment_plans' => 'Installment Payment Plans',
            'online_payment_gateway_integration' => 'Online Payment Gateway Integration',
            'school_fees_management' => 'School Fees Management',
            'discounts_scholarships' => 'Discounts & Scholarships',
            'expense_management' => 'Expense Management',
            'income_management' => 'Income Management',
            'payroll' => 'Payroll',
            'financial_reports' => 'Financial Reports',
            'financial_dashboard' => 'Financial Dashboard',
            'automated_receipts' => 'Automated Receipts',
            'automated_invoicing' => 'Automated Invoicing',
            'outstanding_fee_tracking' => 'Outstanding Fee Tracking',
        ]],
        'human_resources' => ['label' => 'Human Resources', 'features' => [
            'hr_management' => 'HR Management',
            'staff_attendance' => 'Staff Attendance',
            'leave_management' => 'Leave Management',
            'performance_appraisal' => 'Performance Appraisal',
            'staff_document_management' => 'Staff Document Management',
        ]],
        'learning' => ['label' => 'Learning', 'features' => [
            'lms' => 'Learning Management System',
            'courses' => 'Courses',
            'assignments' => 'Assignments',
            'quizzes' => 'Quizzes',
            'learning_resources' => 'Learning Resources',
            'discussion_boards' => 'Discussion Boards',
            'certificates' => 'Certificates',
        ]],
        'examination' => ['label' => 'Examination', 'features' => [
            'cbt_examination' => 'CBT Examination',
            'learnguard_secure_browser' => 'LearnGuard Secure Browser',
            'webcam_monitoring' => 'Webcam Monitoring',
            'browser_lockdown' => 'Browser Lockdown',
            'question_bank' => 'Question Bank',
            'random_questions' => 'Random Questions',
            'examination_analytics' => 'Examination Analytics',
        ]],
        'artificial_intelligence' => ['label' => 'Artificial Intelligence', 'features' => [
            'ai_report_comments' => 'AI Report Comments',
            'ai_student_performance_insights' => 'AI Student Performance Insights',
            'ai_lesson_planning' => 'AI Lesson Planning',
            'ai_question_generator' => 'AI Question Generator',
            'ai_risk_analysis' => 'AI Risk Analysis',
        ]],
        'automation' => ['label' => 'Automation', 'features' => [
            'workflow_automation' => 'Workflow Automation',
            'scheduled_reports' => 'Scheduled Reports',
            'approval_workflows' => 'Approval Workflows',
        ]],
        'reports_analytics' => ['label' => 'Reports & Analytics', 'features' => [
            'student_reports' => 'Student Reports',
            'attendance_reports' => 'Attendance Reports',
            'basic_analytics_dashboard' => 'Basic Analytics Dashboard',
            'export_to_pdf' => 'Export to PDF',
            'export_to_excel' => 'Export to Excel',
            'bulk_import' => 'Bulk Import',
            'bulk_export' => 'Bulk Export',
            'custom_report_builder' => 'Custom Report Builder',
            'student_performance_dashboard' => 'Student Performance Dashboard',
            'attendance_dashboard' => 'Attendance Dashboard',
            'academic_insights' => 'Academic Insights',
            'advanced_analytics' => 'Advanced Analytics',
            'executive_dashboard' => 'Executive Dashboard',
            'financial_analytics' => 'Financial Analytics',
            'custom_kpis' => 'Custom KPIs',
        ]],
        'support' => ['label' => 'Support', 'features' => [
            'knowledge_base' => 'Knowledge Base',
            'staff_onboarding_training' => 'Staff Onboarding & Training',
            'standard_support' => 'Standard Support',
            'priority_email_support' => 'Priority Email Support',
            'implementation_assistance' => 'Implementation Assistance',
            'priority_support' => 'Priority Support',
            'dedicated_customer_success' => 'Dedicated Customer Success',
        ]],
        'school_operations' => ['label' => 'School Operations', 'features' => [
            'library_management' => 'Library Management',
            'inventory_management' => 'Inventory Management',
            'asset_management' => 'Asset Management',
            'medical_management' => 'Medical Management',
            'hostel_management' => 'Hostel Management',
            'transport_management' => 'Transport Management',
            'visitor_management' => 'Visitor Management',
            'discipline_management' => 'Discipline Management',
        ]],
        'multi_school_management' => ['label' => 'Multi School Management', 'features' => [
            'multi_campus_management' => 'Multi Campus Management',
            'multi_school_dashboard' => 'Multi School Dashboard',
            'central_administration' => 'Central Administration',
            'school_switching' => 'School Switching',
            'shared_staff' => 'Shared Staff (Optional)',
        ]],
        'enterprise_features' => ['label' => 'Enterprise Features', 'features' => [
            'api_access' => 'API Access',
            'third_party_integrations' => 'Third Party Integrations',
            'plugin_marketplace' => 'Plugin Marketplace',
            'developer_api' => 'Developer API',
            'webhooks' => 'Webhooks',
            'single_sign_on' => 'Single Sign On (SSO)',
            'audit_centre' => 'Audit Centre',
        ]],
        'advanced_intelligence' => ['label' => 'Advanced Intelligence', 'features' => [
            'ai_predictive_analytics' => 'AI Predictive Analytics',
            'school_benchmarking' => 'School Benchmarking',
            'custom_executive_dashboards' => 'Custom Executive Dashboards',
            'data_warehouse' => 'Data Warehouse',
        ]],
        'premium_services' => ['label' => 'Premium Services', 'features' => [
            'dedicated_account_manager' => 'Dedicated Account Manager',
            'data_migration' => 'Data Migration',
            'priority_technical_support' => 'Priority Technical Support',
            'early_access_new_features' => 'Early Access to New Features',
        ]],
        'future_features' => ['label' => 'Future Features', 'features' => [
            'school_website_builder' => 'School Website Builder',
            'alumni_management' => 'Alumni Management',
            'mobile_apps' => 'Mobile Apps',
            'white_label' => 'White Label (Optional)',
            'marketplace_access' => 'Marketplace Access',
            'custom_module_development' => 'Custom Module Development',
            'advanced_security_policies' => 'Advanced Security Policies',
        ]],
    ],

    /*
    | Included with every plan regardless of tier (tracker section 14,
    | the source document's own recommendation for the pricing page).
    | Shown as one shared line beneath every pricing card, not per-plan
    | data, so it lives here rather than on the Plan model.
    */
    'plan_universal_inclusions' => [
        'Free One Term Trial',
        'Free Data Migration (within defined limits)',
        'Free Staff Onboarding & Training',
        'Automatic System Updates',
        'Secure Cloud Backup',
        'SSL Security',
        'Responsive Web Access',
        'Unlimited Administrators (based on roles and permissions)',
        'Regular Feature Updates',
    ],

    /*
    | Add ons purchasable on any plan regardless of tier (tracker section 14).
    | "module" links an add on to an RBAC module code above so granting it
    | can also flip that module on; add ons with "module" => null are
    | recorded as a genuine purchase/entitlement but have no enforcement
    | code yet (no storage metering, no custom domain routing, no live
    | payment gateway) — granting them is honest bookkeeping, not a faked
    | working feature. Extend the relevant service once each is built.
    */
    'add_on_catalog' => [
        'school_operations' => ['label' => 'School Operations', 'items' => [
            'library' => ['name' => 'Library Management', 'module' => 'library'],
            'inventory' => ['name' => 'Inventory Management', 'module' => 'inventory'],
            'medical' => ['name' => 'Medical Management', 'module' => 'medical'],
            'hostel' => ['name' => 'Hostel Management', 'module' => 'hostel'],
            'transport' => ['name' => 'Transport Management', 'module' => 'transport'],
            'visitor_management' => ['name' => 'Visitor Management', 'module' => 'visitors'],
            'discipline' => ['name' => 'Discipline Management', 'module' => 'discipline'],
        ]],
        'finance' => ['label' => 'Finance', 'items' => [
            'additional_payment_gateway' => ['name' => 'Additional Payment Gateway', 'module' => null],
            'accounting_integration' => ['name' => 'Accounting Integration', 'module' => null],
        ]],
        'communication' => ['label' => 'Communication', 'items' => [
            'sms_bundles' => ['name' => 'SMS Bundles', 'module' => null],
            'whatsapp_messaging' => ['name' => 'WhatsApp Messaging', 'module' => null],
            'branded_email_services' => ['name' => 'Branded Email Services', 'module' => null],
        ]],
        'learning' => ['label' => 'Learning', 'items' => [
            'extra_lms_storage' => ['name' => 'Extra LMS Storage', 'module' => null],
            'video_streaming' => ['name' => 'Video Streaming', 'module' => null],
            'additional_ai_credits' => ['name' => 'Additional AI Credits', 'module' => null],
        ]],
        'platform' => ['label' => 'Platform', 'items' => [
            'additional_storage' => ['name' => 'Additional Storage', 'module' => null],
            'additional_student_capacity' => ['name' => 'Additional Student Capacity', 'module' => null],
            'additional_schools' => ['name' => 'Additional Schools', 'module' => null],
            'custom_domain' => ['name' => 'Custom Domain', 'module' => null],
            'premium_themes' => ['name' => 'Premium Themes', 'module' => null],
            'white_label' => ['name' => 'White Label', 'module' => null],
            'api_access' => ['name' => 'API Access', 'module' => null],
        ]],
    ],

    /*
    | Platform support contact, shown on sign in pages so a locked out
    | admin/user has somewhere to turn. Same details published on the
    | marketing site's Privacy/Terms pages; kept here as the single
    | source so both stay in sync (env overridable, never hardcoded
    | into a view).
    */
    'support' => [
        'email' => env('SKULOS_SUPPORT_EMAIL', 'info@skulos.app'),
        'phone' => env('SKULOS_SUPPORT_PHONE', '+234 802 337 3302'),
    ],

    /*
    | Permission actions generated for every module: "{module}.{action}".
    | Modules can add extra actions of their own via "extra_permissions".
    */
    /*
    | Single sign on providers. Each is independently enable-able; a provider
    | is only offered when it is BOTH enabled and configured with credentials.
    | Enable flags and credentials come from env (never hardcoded); once the
    | admin CMS exists these become platform-admin toggles.
    */
    'sso' => [
        'google' => [
            'enabled' => env('SSO_GOOGLE_ENABLED', false),
            'label' => 'Continue with Google',
        ],
        'microsoft' => [
            'enabled' => env('SSO_MICROSOFT_ENABLED', false),
            'label' => 'Continue with Outlook',
        ],
    ],

    /*
    | Attendance status vocabulary. A default set schools can rely on; the
    | admin CMS will let a school edit this per school. Not hardcoded into
    | the model or validation.
    */
    'attendance_statuses' => ['present', 'absent', 'late', 'excused'],

    /*
    | Student record fields a school can mark as required (user request
    | 2026-07-10). admission_number/first_name/last_name are always
    | required (core identity) and email must never be required (a
    | standing confirmed business decision), so neither appear here; every
    | key below defaults to optional until a school's admin turns it on
    | via the Student Settings screen, stored per school in tenant
    | settings under the key "student_required_fields". "class" and
    | "house" are matched against the school's own ClassArm/House names
    | during bulk import, not stored as this literal string.
    */
    'student_optional_fields' => [
        'class' => 'Class',
        'house' => 'House',
        'gender' => 'Gender',
        'date_of_birth' => 'Date of Birth',
        'other_names' => 'Other Names',
        'parent_mobile' => 'Parent Mobile',
        'country' => 'Country',
        'state_of_origin' => 'State of Origin',
        'lga' => 'LGA',
        'address' => 'Address',
    ],

    /*
    | Which contact field a school treats as the unique identifier for
    | linking children to parents and to staff/teachers (user request
    | 2026-07-11: "the admin can determine what the unique ID is, either
    | email address or phone number, and the school must stick to one
    | only"). Stored per school as tenant Setting "parent_identifier" /
    | "staff_identifier", each one of these two keys, default "email" to
    | match the platform's existing email-based parent recognition.
    */
    'identifier_options' => [
        'email' => 'Email address',
        'phone' => 'Phone number',
    ],

    /*
    | Report card review chain (user directive 2026-07-12, "Intelligent
    | Assessment & Report Card Workflow.docx": "Admin configures
    | Workflow... Entirely configurable", worked examples given: Teacher
    | -> Class Teacher -> VP Academic -> Principal at one school, Teacher
    | -> Principal at another, Teacher -> Academic Coordinator ->
    | Principal at a third). "report_card_review_roles" is the picklist
    | an admin builds a chain from on the Report settings page;
    | "default_report_card_review_chain" is what a school starts with
    | (single stage, reproducing the original 2026-07-11 behaviour before
    | this became configurable, so an existing school is unaffected until
    | it deliberately adds a stage).
    */
    'report_card_review_roles' => [
        'class-teacher' => 'Class Teacher',
        'vice-principal' => 'Vice Principal',
        'head-teacher' => 'Head Teacher',
        'examination-officer' => 'Examination Officer',
        'principal' => 'Principal',
        'school-owner' => 'School Owner',
    ],
    'default_report_card_review_chain' => ['class-teacher'],

    /*
    | Picklist of pages an admin can set as a role's default landing page
    | right after login (user request 2026-07-13: "admin-configurable
    | landing page per staff/student/parent (default Dashboard)"). This
    | array is only ever the catalog of *available* choices and each
    | one's real permission code (never read live for the actual chosen
    | value, that is DB-backed via tenant Setting "landing_pages", see
    | LandingPageController). Every entry must be a route needing no
    | parameters beyond {school}, and its permission (null for one every
    | signed-in member can always reach) is re-checked at login time so a
    | misconfigured choice degrades to the Dashboard instead of 403ing a
    | user the moment they sign in.
    */
    'landing_page_options' => [
        'school.dashboard' => ['label' => 'Dashboard', 'permission' => null],
        'school.students' => ['label' => 'Students', 'permission' => 'students.view'],
        'school.staff.index' => ['label' => 'Staff', 'permission' => 'staff.view'],
        'school.houses.index' => ['label' => 'Houses', 'permission' => 'students.view'],
        'school.academics.index' => ['label' => 'Classes and Subjects', 'permission' => 'academics.view'],
        'school.examinations.index' => ['label' => 'Results Entry', 'permission' => 'examinations.view'],
        'school.examinations.grading' => ['label' => 'Grading', 'permission' => 'examinations.view'],
        'school.report-cards.index' => ['label' => 'Report Cards', 'permission' => 'report_cards.view'],
        'school.report-cards.pending-review' => ['label' => 'Pending Report Card Reviews', 'permission' => 'report_cards.assign_review'],
        'school.notifications.index' => ['label' => 'Notifications', 'permission' => null],
        'school.profile.edit' => ['label' => 'My Profile', 'permission' => null],
    ],

    /*
    | Who can see a student's guardian/parent details (name, relationship,
    | contact, parent portal login) on their profile (user directive
    | 2026-07-12: "the admin should be able to set if class teacher only
    | or both subject teachers can also see each child parent details").
    | Only ever narrows visibility for the two teacher_scoped_roles, a
    | class teacher and every unrestricted role can always see it
    | regardless of this setting, it only decides whether a subject
    | teacher additionally can. Tenant Setting "guardian_visibility",
    | default the more restrictive option.
    */
    'guardian_visibility_options' => [
        'class_teacher_only' => 'Class teacher only',
        'both' => 'Class teacher and subject teachers',
    ],

    /*
    | Idle session security timeout (user request 2026-07-09). After no
    | activity on an authenticated page for "idle_seconds", a warning
    | modal counts down "warning_seconds" with a Resume or Log out
    | choice; resuming extends the session, letting the countdown reach
    | zero signs the user out automatically. Values are seconds and are
    | admin editable later via env/config, never hardcoded in the JS.
    */
    'session_timeout' => [
        'idle_seconds' => env('SESSION_IDLE_SECONDS', 30),
        'warning_seconds' => env('SESSION_IDLE_WARNING_SECONDS', 15),
    ],

    /*
    | Default retention windows in days for things that otherwise grow
    | unbounded (Dbase recommendation, standing rule): in app notifications,
    | communication (SMS/email/WhatsApp) logs, and platform login sessions.
    | Admin editable via App\Services\Maintenance\RetentionService, same
    | platform-then-school precedence as idle timeout/mail settings.
    | Audit logs are deliberately NOT in this list: App\Models\AuditLog is
    | immutable by design ("nothing deleted without a trace"), so audit log
    | retention needs an archive-not-delete strategy, not a purge job, and
    | is intentionally left out of this pass rather than guessed at.
    */
    'retention' => [
        'notifications_days' => env('RETENTION_NOTIFICATIONS_DAYS', 90),
        'communication_logs_days' => env('RETENTION_COMMUNICATION_LOGS_DAYS', 180),
        'sessions_days' => env('RETENTION_SESSIONS_DAYS', 30),
    ],

    /*
    | Communication channels. Only enabled channels are attempted. Email is
    | wired now; SMS, WhatsApp and push are placeholders for later providers.
    | A school will toggle these in the admin CMS; not hardcoded in code.
    */
    'communication_channels' => [
        'email' => ['enabled' => true],
        'sms' => ['enabled' => false],
        'whatsapp' => ['enabled' => false],
        'push' => ['enabled' => false],
    ],

    /*
    | Which audiences receive announcement EMAIL by default. Everyone always
    | gets an in-app/web notification regardless of this. The admin can turn
    | any audience's email on or off (stored per school in tenant settings
    | under the same key, which overrides this default). Nothing hardcoded.
    */
    'announcement_email_audiences' => [
        'parents' => true,
        'teachers' => true,
        'staff' => true,
        'students' => true,
    ],

    /*
    | Notification Center taxonomy (user directed backlog item, 2026-07-12,
    | "To Do List 2" doc item 8: categorize into Academic/Finance/Urgent/
    | System, not one flat feed). "notification_types" is the broad bucket
    | shown as filter tabs; "notification_categories" maps each specific
    | free-text category a notification is created with (Notification::
    | create()'s "category" column, e.g. "announcement", "report_card_
    | review") to one of those types plus a display label. A category
    | created without an entry here safely falls back to the "system"
    | type via Notification::typeFor(), it is never a hard error, but
    | every real producer should still get an explicit entry so it lands
    | in the type that actually makes sense for it, not the fallback.
    */
    'notification_types' => [
        'academic' => 'Academic',
        'finance' => 'Finance',
        'urgent' => 'Urgent',
        'system' => 'System',
    ],
    'notification_categories' => [
        'announcement' => ['label' => 'Announcement', 'type' => 'system'],
        'report_card_review' => ['label' => 'Report card review', 'type' => 'academic'],
    ],

    /*
    | Default notable days seeded into a new school's calendar (user request
    | 2026-07-09). These are starting defaults only: the school admin can
    | edit, remove, or add more, and set the actual dates. Category drives
    | display/colour. Nothing here is hardcoded into calendar logic; it is
    | seed data a school owns once provisioned.
    */
    'default_calendar_events' => [
        ['title' => 'Beginning of Term', 'category' => 'term'],
        ['title' => 'Mid Term Break', 'category' => 'break'],
        ['title' => 'End of Term', 'category' => 'term'],
        ['title' => "Children's Day", 'category' => 'celebration'],
        ['title' => 'Art and Culture Day', 'category' => 'celebration'],
        ['title' => 'Professional Day', 'category' => 'staff'],
        ['title' => 'Inter House Sports Day', 'category' => 'sports'],
        ['title' => 'Public Holiday', 'category' => 'holiday'],
    ],

    /*
    | Dashboard look. A school picks one layout at setup and can change it in
    | settings; it can also show or hide individual widgets. Both are stored
    | per school in tenant settings (admin editable, nothing hardcoded); the
    | values here are the catalog + defaults.
    */
    'dashboard_layouts' => [
        'classic' => ['label' => 'Classic School', 'description' => 'Accent bar figure cards above notifications and the calendar.'],
        'vibrant' => ['label' => 'Vibrant', 'description' => 'A colourful welcome banner with rounded figure tiles.'],
        'erp' => ['label' => 'ERP', 'description' => 'Solid colour figure tiles with a notice board, a dense admin look.'],
        'modern' => ['label' => 'Modern', 'description' => 'A welcome banner with ringed figure pills and clean panels.'],
    ],
    'default_dashboard_layout' => 'classic',

    'dashboard_widgets' => [
        'greeting' => ['label' => 'Welcome greeting', 'default' => true],
        'stats' => ['label' => 'Key figures', 'default' => true],
        'notifications' => ['label' => 'Notifications', 'default' => true],
        'calendar' => ['label' => 'Upcoming calendar', 'default' => true],
    ],

    /*
    | Login page designs a school can choose (like dashboard layouts). All
    | render the same form; they differ in arrangement. Admin picks in
    | branding settings; stored on schools.login_layout. Nothing hardcoded.
    */
    'login_layouts' => [
        'centered' => ['label' => 'Centered', 'description' => 'A single clean card in the middle of the page.'],
        'split' => ['label' => 'Split screen', 'description' => 'A branded panel beside the sign in form.'],
        'cover' => ['label' => 'Cover', 'description' => 'A full brand colour background with the form floating on top.'],
        'showcase' => ['label' => 'Showcase', 'description' => 'The form beside a welcome message and highlights.'],
    ],
    'default_login_layout' => 'split',

    /*
    | Quick pick swatches for the brand colour picker (user request
    | 2026-07-11: "swatch grid of basic colours plus a native hex/drag
    | colour picker fallback"). A school can still type or drag pick any
    | hex value; these are just fast, common starting points. 12 colours
    | so the grid divides evenly at every breakpoint (6x2, 4x3, 3x4, 2x6),
    | per the standing "no dangling grid row" design rule.
    */
    'brand_color_presets' => [
        '#E8A33D', '#D64545', '#E5901C', '#2FA84F',
        '#12A97B', '#1B87D6', '#2E5FDB', '#6C4FE0',
        '#B24FD6', '#D6488F', '#5B4636', '#41505E',
    ],

    /*
    | Default class structure a school can load, one tier at a time (user
    | request 2026-07-10). Ticking a tier creates its Section (found or
    | created by name) and each listed class (found or created, each with
    | a default single-stream ClassArm so it is immediately usable), then
    | applies that tier's default_subjects catalog to every class it
    | created. Uses the same 4 keys as default_subjects so the two stay in
    | lockstep; "jss" and "ss" share one "Secondary" section since most
    | schools run junior and senior secondary as one section, not two.
    | Entirely optional and idempotent, same as default_subjects below —
    | a school is never forced into these and can edit anything after.
    |
    | "sequence" (found 2026-07-12, "Class Order & Structure.docx": the
    | real pedagogical order is Nursery, then Primary, then Junior
    | Secondary, then Senior Secondary) and "class_base" are fixed,
    | canonical positions, not creation order. Before this, a Section's
    | sequence was assigned as "whichever tier gets applied first" (an
    | admin ticking "Primary" before "Nursery" on the Academics page
    | permanently put Primary above Nursery everywhere sections are
    | listed), and a class's sequence reset to 1 for every tier sharing a
    | section, so "ss" classes collided with "jss" classes' sequence
    | numbers (both got 1, 2, 3) instead of continuing after them.
    | "class_base" is spaced 10 apart per tier so a tier can grow past 10
    | classes without colliding with the next tier's classes.
    */
    'default_class_structure' => [
        'nursery' => ['section' => 'Nursery', 'sequence' => 1, 'class_base' => 0, 'classes' => ['Creche/Reception', 'Nursery 1', 'Nursery 2']],
        'primary' => ['section' => 'Primary', 'sequence' => 2, 'class_base' => 10, 'classes' => ['Primary 1', 'Primary 2', 'Primary 3', 'Primary 4', 'Primary 5', 'Primary 6']],
        'jss' => ['section' => 'Secondary', 'sequence' => 3, 'class_base' => 20, 'classes' => ['JSS 1', 'JSS 2', 'JSS 3']],
        'ss' => ['section' => 'Secondary', 'sequence' => 3, 'class_base' => 30, 'classes' => ['SSS 1', 'SSS 2', 'SSS 3']],
    ],

    /*
    | Default subject catalogs a school can load per section (user request
    | 2026-07-09, sourced from "List of subjects and Grading system.docx").
    | "match" is a list of lowercase keywords checked against a section's
    | name to suggest which catalog applies to it; a school is never forced
    | into these, they are a one-click starting point (AcademicsController's
    | "apply default subjects" action) that creates/attaches subjects onto a
    | class with the mandatory/examinable flags below. The admin can then
    | add, remove, rename, or flip either flag on any subject at any time —
    | nothing here is hardcoded into behaviour, it is seed data only.
    |
    | "sequence_base" (found 2026-07-12 alongside the class-order bug: a
    | class's subject list was rendering alphabetically, "the list of
    | subject should follow the order and not scatter or arrange
    | alphabetically") spaces each catalog's subjects well apart in the
    | school wide Subject.sequence column, so this catalog's declared
    | order (English/Mathematics first, optional electives last) survives
    | regardless of which catalog a school applies first.
    */
    'default_subjects' => [
        'nursery' => [
            'label' => 'Nursery / Kindergarten',
            'sequence_base' => 0,
            'match' => ['nursery', 'kindergarten', 'kg', 'pre-nursery', 'reception', 'playgroup', 'creche', 'crèche', 'toddler', 'daycare'],
            'subjects' => [
                ['name' => 'Literacy', 'mandatory' => true, 'examinable' => true],
                ['name' => 'Numeracy', 'mandatory' => true, 'examinable' => true],
                ['name' => 'Health and Science', 'mandatory' => true, 'examinable' => true],
                ['name' => 'Creative Arts', 'mandatory' => true, 'examinable' => false],
                ['name' => 'Quantitative Reasoning', 'mandatory' => true, 'examinable' => true],
                ['name' => 'Verbal Reasoning', 'mandatory' => true, 'examinable' => true],
                ['name' => 'Phonics and Reading', 'mandatory' => true, 'examinable' => true],
                ['name' => 'Social Studies', 'mandatory' => true, 'examinable' => true],
            ],
        ],
        'primary' => [
            'label' => 'Primary',
            'sequence_base' => 100,
            'match' => ['primary', 'basic 1', 'basic 2', 'basic 3', 'basic 4', 'basic 5', 'basic 6'],
            'subjects' => [
                ['name' => 'English Studies', 'mandatory' => true, 'examinable' => true],
                ['name' => 'Mathematics', 'mandatory' => true, 'examinable' => true],
                ['name' => 'Nigerian Language', 'mandatory' => true, 'examinable' => true],
                ['name' => 'Basic Science', 'mandatory' => true, 'examinable' => true],
                ['name' => 'Physical and Health Education', 'mandatory' => true, 'examinable' => true],
                ['name' => 'Nigerian History', 'mandatory' => true, 'examinable' => true],
                ['name' => 'Social and Citizenship Studies', 'mandatory' => true, 'examinable' => true],
                ['name' => 'Cultural and Creative Arts', 'mandatory' => true, 'examinable' => false],
                ['name' => 'Religious Studies', 'mandatory' => true, 'examinable' => true],
                ['name' => 'Basic Digital Literacy', 'mandatory' => true, 'examinable' => false],
                ['name' => 'Pre-Vocational Studies', 'mandatory' => true, 'examinable' => false],
            ],
        ],
        'jss' => [
            'label' => 'Junior Secondary (JSS / Basic 7-9)',
            'sequence_base' => 200,
            'match' => ['jss', 'junior secondary', 'basic 7', 'basic 8', 'basic 9', 'grade 7', 'grade 8', 'grade 9'],
            'subjects' => [
                ['name' => 'English Studies', 'mandatory' => true, 'examinable' => true],
                ['name' => 'Mathematics', 'mandatory' => true, 'examinable' => true],
                ['name' => 'Pre-Vocational Studies', 'mandatory' => true, 'examinable' => true],
                ['name' => 'Agricultural Science', 'mandatory' => true, 'examinable' => true],
                ['name' => 'Home Economics', 'mandatory' => true, 'examinable' => true],
                ['name' => 'Basic Science', 'mandatory' => true, 'examinable' => true],
                ['name' => 'Basic Technology', 'mandatory' => true, 'examinable' => true],
                ['name' => 'Information and Communication Technology', 'mandatory' => true, 'examinable' => true],
                ['name' => 'Physical and Health Education', 'mandatory' => true, 'examinable' => true],
                ['name' => 'Civic Education', 'mandatory' => true, 'examinable' => true],
                ['name' => 'Social Studies', 'mandatory' => true, 'examinable' => true],
                ['name' => 'Cultural and Creative Arts', 'mandatory' => true, 'examinable' => false],
                ['name' => 'Nigerian Languages', 'mandatory' => false, 'examinable' => true],
                ['name' => 'Christian Religious Studies', 'mandatory' => false, 'examinable' => true],
                ['name' => 'Islamic Studies', 'mandatory' => false, 'examinable' => true],
                ['name' => 'Business Studies', 'mandatory' => false, 'examinable' => true],
                ['name' => 'French Language', 'mandatory' => false, 'examinable' => true],
                ['name' => 'Arabic Language', 'mandatory' => false, 'examinable' => true],
                ['name' => 'History', 'mandatory' => false, 'examinable' => true],
            ],
        ],
        'ss' => [
            'label' => 'Senior Secondary (SSS 1-3 / Grade 10-12)',
            'sequence_base' => 300,
            'match' => ['sss1', 'sss2', 'sss3', 'sss 1', 'sss 2', 'sss 3', 'ss1', 'ss2', 'ss3', 'ss 1', 'ss 2', 'ss 3', 'senior secondary', 'grade 10', 'grade 11', 'grade 12'],
            'subjects' => [
                ['name' => 'English Language', 'mandatory' => true, 'examinable' => true],
                ['name' => 'Mathematics', 'mandatory' => true, 'examinable' => true],
                ['name' => 'Civic Education', 'mandatory' => true, 'examinable' => true],
                ['name' => 'Digital Technologies', 'mandatory' => true, 'examinable' => true],
                ['name' => 'Trade Subject', 'mandatory' => true, 'examinable' => true],
                ['name' => 'Biology', 'mandatory' => false, 'examinable' => true],
                ['name' => 'Chemistry', 'mandatory' => false, 'examinable' => true],
                ['name' => 'Physics', 'mandatory' => false, 'examinable' => true],
                ['name' => 'Agricultural Science', 'mandatory' => false, 'examinable' => true],
                ['name' => 'Further Mathematics', 'mandatory' => false, 'examinable' => true],
                ['name' => 'Geography', 'mandatory' => false, 'examinable' => true],
                ['name' => 'Government', 'mandatory' => false, 'examinable' => true],
                ['name' => 'Literature in English', 'mandatory' => false, 'examinable' => true],
                ['name' => 'History', 'mandatory' => false, 'examinable' => true],
                ['name' => 'Christian Religious Studies', 'mandatory' => false, 'examinable' => true],
                ['name' => 'Islamic Studies', 'mandatory' => false, 'examinable' => true],
                ['name' => 'Visual Arts', 'mandatory' => false, 'examinable' => true],
                ['name' => 'Music', 'mandatory' => false, 'examinable' => true],
                ['name' => 'French', 'mandatory' => false, 'examinable' => true],
                ['name' => 'Arabic', 'mandatory' => false, 'examinable' => true],
                ['name' => 'Economics', 'mandatory' => false, 'examinable' => true],
                ['name' => 'Commerce', 'mandatory' => false, 'examinable' => true],
                ['name' => 'Financial Accounting', 'mandatory' => false, 'examinable' => true],
                ['name' => 'Marketing', 'mandatory' => false, 'examinable' => true],
            ],
        ],
    ],

    /*
    | Default house names a school can load in one action (user request
    | 2026-07-10): most schools run 4. Colour based and neutral on
    | purpose, not any specific school's real house names, since those
    | would be fabricated. Idempotent by name, same pattern as every
    | other "default_*" catalog here, editable/removable after loading.
    */
    'default_houses' => ['Red House', 'Blue House', 'Green House', 'Yellow House'],

    /*
    | One-click starting assessment structure (part of the guided setup
    | wizard, user request 2026-07-08/2026-07-11): the common Nigerian
    | school split of two continuous assessments plus one exam, summing
    | to 100. A school can add, remove, rename, or reweight any component
    | afterwards, this is only a fast starting point, never forced.
    */
    'default_assessment_components' => [
        ['name' => 'First CA', 'type' => 'ca', 'max_score' => 20],
        ['name' => 'Second CA', 'type' => 'ca', 'max_score' => 20],
        ['name' => 'Exam', 'type' => 'exam', 'max_score' => 60],
    ],

    /*
    | Default grading scales a school can load (same "user request 2026-07-09"
    | source). Loading one creates a GradingScale + its bands only if a scale
    | of that name does not already exist (idempotent); the admin can edit,
    | delete, or add entirely different scales afterwards. Nothing here is
    | hardcoded into ResultService or the model — both already accept any
    | number of school-defined scales.
    */
    'default_grading_scales' => [
        'nursery_primary' => [
            'name' => 'Nursery and Primary Scale',
            'bands' => [
                ['min' => 75, 'max' => 100, 'grade' => 'Excellent', 'remark' => null],
                ['min' => 65, 'max' => 74, 'grade' => 'Very Good', 'remark' => null],
                ['min' => 50, 'max' => 64, 'grade' => 'Good', 'remark' => null],
                ['min' => 40, 'max' => 49, 'grade' => 'Fair', 'remark' => null],
                ['min' => 0, 'max' => 39, 'grade' => 'Fail', 'remark' => null],
            ],
        ],
        'junior' => [
            'name' => 'Junior Secondary Scale',
            'bands' => [
                ['min' => 80, 'max' => 100, 'grade' => 'A', 'remark' => 'Distinction'],
                ['min' => 60, 'max' => 79, 'grade' => 'B', 'remark' => 'Upper Credit / Very Good'],
                ['min' => 50, 'max' => 59, 'grade' => 'C', 'remark' => 'Lower Credit / Good'],
                ['min' => 40, 'max' => 49, 'grade' => 'P', 'remark' => 'Pass'],
                ['min' => 0, 'max' => 39, 'grade' => 'F', 'remark' => 'Fail'],
            ],
        ],
        'senior' => [
            'name' => 'Senior Secondary Scale (WAEC)',
            'bands' => [
                ['min' => 75, 'max' => 100, 'grade' => 'A1', 'remark' => 'Excellent'],
                ['min' => 70, 'max' => 74, 'grade' => 'B2', 'remark' => 'Very Good'],
                ['min' => 65, 'max' => 69, 'grade' => 'B3', 'remark' => 'Good'],
                ['min' => 60, 'max' => 64, 'grade' => 'C4', 'remark' => 'Credit'],
                ['min' => 55, 'max' => 59, 'grade' => 'C5', 'remark' => 'Credit'],
                ['min' => 50, 'max' => 54, 'grade' => 'C6', 'remark' => 'Credit'],
                ['min' => 45, 'max' => 49, 'grade' => 'D7', 'remark' => 'Pass'],
                ['min' => 40, 'max' => 44, 'grade' => 'E8', 'remark' => 'Pass'],
                ['min' => 0, 'max' => 39, 'grade' => 'F9', 'remark' => 'Fail'],
            ],
        ],
    ],

    /*
    | Score-range comment bank (user directive 2026-07-12, sourced verbatim
    | from "Intelligent Assessment & Report Card Workflow.docx": "this is
    | not really an AI engine, just use the bank against the score range
    | and the flow in the document"). App\Services\Results\CommentBank
    | picks a random comment from whichever band a subject's calculated
    | total (already a 0-100 percentage, the same assumption
    | GradingScale::bandFor() already makes) falls into, so a teacher gets
    | a full suggested remark pre-filled rather than a blank box, and
    | students stop getting identical report cards for the same score.
    | Every comment here is copied verbatim from the docx, not
    | regenerated, do the same if this bank is ever extended.
    */
    'default_comment_bank' => [
        ['min' => 90, 'max' => 100, 'comments' => [
            'Your performance this term has been exceptional and reflects remarkable dedication to your studies. Keep raising the standard.',
            'You have demonstrated excellent mastery of the subject with consistent outstanding performance throughout the term.',
            'Your commitment to learning is evident in your excellent results. Continue to aim for even greater accomplishments.',
            'An outstanding performance that deserves recognition. Your consistency and determination are highly commendable.',
            'You continue to set an excellent example through your academic discipline and commitment to excellence.',
            'Your enthusiasm for learning has translated into remarkable academic success. Keep it up.',
            'You have displayed excellent analytical and problem solving abilities throughout this term.',
            'Your exceptional performance is the result of hard work and perseverance. Continue striving for greatness.',
            'You have shown impressive confidence and accuracy in your academic work. Well done.',
            'Outstanding achievement. Maintain your focus and continue inspiring those around you.',
            'Your academic performance reflects maturity, discipline, and a genuine desire to succeed.',
            'You consistently demonstrate a high level of understanding in every assessment.',
            'Your dedication has produced excellent results. Continue challenging yourself academically.',
            'You have maintained an exceptional standard throughout the term. Congratulations.',
            'Your ability to grasp complex concepts quickly is highly commendable.',
            'Excellent work. Continue nurturing your talents and exploring new learning opportunities.',
            'Your commitment to excellence has been evident in every task completed this term.',
            'You have proven yourself to be an exceptional learner. Keep aiming even higher.',
            'Congratulations on an excellent performance. Continue maintaining this admirable standard.',
            'Your outstanding achievement is a reflection of your discipline and determination. Well done.',
        ]],
        ['min' => 80, 'max' => 89, 'comments' => [
            'You have performed very well this term. Continue working towards even greater success.',
            'Your performance has been consistently impressive. Maintain your determination and focus.',
            'You have demonstrated very good understanding of the subject. Keep aiming for excellence.',
            'A commendable effort throughout the term. Continue improving your study habits.',
            'Your performance shows strong potential for even greater achievement.',
            'You are making steady academic progress. Continue giving your best.',
            'Very good work. Increased confidence will help you perform even better.',
            'Your dedication is yielding positive results. Continue striving for excellence.',
            'A very encouraging performance that reflects commitment and discipline.',
            'You have shown good understanding of the learning objectives. Keep improving.',
            'Continue maintaining your positive attitude towards learning.',
            'You have demonstrated strong academic ability throughout the term.',
            'Keep building on your strengths to reach the highest level of achievement.',
            'Your performance continues to improve steadily. Well done.',
            'A very pleasing result. Continue to remain focused and determined.',
            'Your consistent effort has produced commendable results.',
            'Keep challenging yourself to reach the next level of academic excellence.',
            'You have demonstrated impressive learning ability. Continue reading beyond the classroom.',
            'A strong performance that can become outstanding with a little extra effort.',
            'Well done. Continue maintaining your positive learning attitude.',
        ]],
        ['min' => 70, 'max' => 79, 'comments' => [
            'You have achieved a good result this term. Continue working towards greater excellence.',
            'Your effort has been commendable. Greater consistency will improve your performance further.',
            'You have shown good understanding of the subject matter. Keep practicing.',
            'A good performance. Continue asking questions whenever necessary.',
            'Your academic progress is encouraging. Stay committed to your studies.',
            'You are developing well academically. Continue putting in consistent effort.',
            'Continue to participate actively during lessons to strengthen your understanding.',
            'Your performance reflects good potential. Keep working hard.',
            'A positive result. Continue developing your confidence and study habits.',
            'You are progressing steadily. More revision will improve your performance.',
            'Continue to complete assignments promptly to maximize your potential.',
            'Your understanding continues to improve. Keep up the good work.',
            'More independent study will help you achieve higher results.',
            'You have shown determination throughout the term. Continue improving.',
            'Good performance. Aim to become more consistent across all assessments.',
            'Continue developing your problem solving skills through regular practice.',
            'Keep believing in yourself and remain committed to academic success.',
            'Your hard work is producing encouraging results. Continue striving higher.',
            'A good foundation has been established. Continue building upon it.',
            'You have the ability to perform even better with greater concentration.',
        ]],
        ['min' => 60, 'max' => 69, 'comments' => [
            'Your performance is satisfactory but there is considerable room for improvement.',
            'Continue working harder and your performance will improve significantly.',
            'Greater commitment to your studies will produce better results.',
            'You have demonstrated potential but need to be more consistent.',
            'More classroom participation will strengthen your understanding.',
            'You need to revise more regularly outside the classroom.',
            'Continue making steady effort and your performance will improve.',
            'You are capable of much better results with increased determination.',
            'Your progress is encouraging. Continue working harder.',
            'More attention during lessons will improve your academic performance.',
            'Continue seeking help whenever concepts are not fully understood.',
            'Better time management will contribute positively to your studies.',
            'You should devote more attention to completing assignments thoroughly.',
            'Continue developing better reading habits.',
            'Stay focused and avoid unnecessary distractions during lessons.',
            'Improvement is possible if you remain committed and disciplined.',
            'Your ability is evident. More effort is required to maximize it.',
            'Continue practicing regularly to improve your confidence.',
            'Make better use of classroom activities to improve understanding.',
            'Remain determined and your academic performance will improve steadily.',
        ]],
        ['min' => 0, 'max' => 59, 'comments' => [
            'Greater dedication to your studies is required to improve your academic performance.',
            'Your current performance does not reflect your true potential.',
            'More serious attention should be given to your studies next term.',
            'You need to participate more actively during lessons.',
            'Greater commitment to homework and revision is necessary.',
            'You are encouraged to seek academic support whenever necessary.',
            'Improvement is possible if you develop more consistent study habits.',
            'Regular practice and determination will help improve your results.',
            'More concentration during lessons is strongly recommended.',
            'You should take greater responsibility for your academic progress.',
            'Additional effort is required to meet expected learning standards.',
            'Develop a personal study timetable and follow it consistently.',
            'Avoid distractions and focus more on your academic responsibilities.',
            'Improvement begins with consistent effort and positive attitude.',
            'More classroom participation will help strengthen your understanding.',
            'You have the ability to improve significantly if you remain focused.',
            'Continue working closely with your teachers for additional guidance.',
            'Academic improvement requires commitment, perseverance, and discipline.',
            'Make better use of available learning resources and support.',
            'I encourage you to approach next term with renewed determination and confidence.',
        ]],
    ],

    /*
    | Report card looks a school can choose (same pattern as dashboard and
    | login layouts): one active template + per-field visibility, both
    | admin editable and stored in tenant settings. See ReportCardService /
    | ReportCardPreferences.
    */
    'report_card_templates' => [
        'classic' => ['label' => 'Classic', 'description' => 'A simple bordered results table, one row per subject.'],
        'modern' => ['label' => 'Modern', 'description' => 'A card layout with a student photo, subject position, class average, highest and lowest.'],
        'formal' => ['label' => 'Formal', 'description' => 'A dense formal sheet with a grade distribution summary and term by term cumulative columns.'],
        'compact' => ['label' => 'Compact', 'description' => 'A short, simple sheet suited to Nursery and Primary classes.'],
    ],
    'default_report_card_template' => 'classic',

    /*
    | Fields a report card can show. Grouped for the settings screen. A
    | school can hide any of these; "custom_fields" (free text label/value
    | pairs an admin can add per template) covers anything not listed here
    | so nothing is a hard ceiling on what a report card can contain.
    */
    'report_card_fields' => [
        'admission_number' => ['label' => 'Admission number', 'group' => 'identity', 'default' => true],
        'gender' => ['label' => 'Gender', 'group' => 'identity', 'default' => true],
        'date_of_birth' => ['label' => 'Date of birth', 'group' => 'identity', 'default' => true],
        'age' => ['label' => 'Age', 'group' => 'identity', 'default' => false],
        'class' => ['label' => 'Class', 'group' => 'identity', 'default' => true],
        'house' => ['label' => 'House', 'group' => 'identity', 'default' => true],
        'class_teacher' => ['label' => 'Class teacher name', 'group' => 'identity', 'default' => true],
        'attendance' => ['label' => 'Attendance summary', 'group' => 'academic', 'default' => true],
        'subject_position' => ['label' => 'Position in subject', 'group' => 'academic', 'default' => false],
        'class_average' => ['label' => 'Class average per subject', 'group' => 'academic', 'default' => false],
        'subject_highest' => ['label' => 'Subject highest score', 'group' => 'academic', 'default' => false],
        'subject_lowest' => ['label' => 'Subject lowest score', 'group' => 'academic', 'default' => false],
        'cumulative_terms' => ['label' => 'Previous terms as columns, with a cumulative average', 'group' => 'academic', 'default' => true],
        'overall_position' => ['label' => 'Overall position in class', 'group' => 'academic', 'default' => true],
        'class_teacher_remark' => ['label' => 'Class teacher remark', 'group' => 'remarks', 'default' => true],
        'principal_remark' => ['label' => 'Head of school remark', 'group' => 'remarks', 'default' => true],
        'next_term_begins' => ['label' => 'Next term begins on', 'group' => 'remarks', 'default' => true],
    ],

    'permission_actions' => ['view', 'create', 'update', 'delete', 'export', 'bulk'],

    /*
    | Roles restricted to only their own assigned classes/students rather
    | than the whole school (user directive 2026-07-12, live QA on the
    | subject-teacher role: "should only see the class assigned to him...
    | except a new feature was given to them"). Enforced by
    | App\Services\Rbac\TeacherScope, not Rbac::check() itself, since this
    | is a data-level ("which rows") restriction, not an action-level
    | ("can they at all") one. A scoped role that is explicitly granted
    | the "students" module via Staff Extra Access is lifted back to
    | unrestricted, that override always wins.
    */
    'teacher_scoped_roles' => ['class-teacher', 'subject-teacher', 'class-assistant'],

    /*
    | Which RBAC permission code gates a nav item being shown at all (user
    | directive 2026-07-12: a class/subject teacher should not even see a
    | link to Houses, Classes & Subjects, Sessions & Terms, Assessment
    | Structure, Grading, Campuses, or Branding in the sidebar). Keyed by
    | the same plan_feature_catalog feature key nav-school.blade.php
    | already loops over. A feature key with no entry here falls back to
    | "hidden for config('skulos.teacher_scoped_roles') roles, shown for
    | everyone else" rather than blocking every other role too, since only
    | those two roles were reported seeing things they should not.
    */
    'nav_permission_map' => [
        'student_records' => 'students.view',
        'staff_records' => 'staff.view',
        'school_houses' => 'students.view',
        'school_sections' => 'academics.view',
        'classes_arms' => 'academics.view',
        'subject_management' => 'academics.view',
        'academic_sessions_terms' => 'academics.view',
        'continuous_assessment' => 'examinations.view',
        'result_management' => 'examinations.view',
        'basic_report_cards' => 'report_cards.view',
        'unlimited_grading_systems' => 'examinations.view',
        'student_promotion' => 'academics.view',
        'school_profile' => 'school_info.view',
        'school_branding' => 'school_info.update',
    ],

    'extra_permissions' => [
        'examinations' => ['approve_results', 'publish_results'],
        'communication' => ['broadcast'],
        'school_info' => ['manage_settings'],
        'students' => ['manage_settings'],
        // Assigning a report card for review and releasing it to students/
        // parents is an institution wide decision (user request
        // 2026-07-11: "the admin, head teacher, principal or school
        // owner"), not something a plain class or subject teacher does,
        // matching the same tier as examinations.approve_results.
        'report_cards' => ['assign_review', 'release'],
    ],

    /*
    | Built-in role templates (tracker section 4.2) seeded with school_id = null.
    | Every school can clone/customize these. "grants" gives the default
    | permission set: "*" = everything, "module.*" = whole module,
    | otherwise explicit codes. Schools adjust from there — defaults are a
    | starting point, not a policy statement.
    */
    'roles' => [
        'school-owner' => ['name' => 'School Owner', 'grants' => ['*']],
        'school-admin' => ['name' => 'School Admin', 'grants' => ['*']],
        'principal' => ['name' => 'Principal', 'grants' => ['*']],
        'head-teacher' => ['name' => 'Head Teacher', 'grants' => ['*']],
        'vice-principal' => ['name' => 'Vice Principal', 'grants' => [
            'students.*', 'staff.*', 'academics.*', 'attendance.*',
            'examinations.*', 'report_cards.*', 'timetable.*', 'calendar.*',
            'communication.*', 'analytics.*',
        ]],
        'registrar' => ['name' => 'Registrar', 'grants' => [
            'students.*', 'parents.*', 'academics.view', 'analytics.view',
        ]],
        'examination-officer' => ['name' => 'Examination Officer', 'grants' => [
            'examinations.*', 'report_cards.*', 'students.view', 'academics.view', 'analytics.view',
        ]],
        'bursar' => ['name' => 'Bursar', 'grants' => [
            'students.view', 'parents.view', 'analytics.view',
        ]],
        'ict-officer' => ['name' => 'ICT Officer', 'grants' => [
            'students.*', 'staff.*', 'parents.*', 'academics.*', 'school_info.*',
        ]],
        'secretary' => ['name' => 'Secretary', 'grants' => [
            'students.view', 'staff.view', 'calendar.*', 'communication.view', 'communication.create',
        ]],
        'teacher' => ['name' => 'Teacher', 'grants' => [
            'students.view', 'attendance.view', 'attendance.create', 'attendance.update',
            'examinations.view', 'examinations.create', 'examinations.update',
            'timetable.view', 'calendar.view', 'communication.view', 'communication.create',
        ]],
        'class-teacher' => ['name' => 'Class Teacher', 'grants' => [
            'students.view', 'students.update', 'attendance.*',
            'examinations.view', 'examinations.create', 'examinations.update',
            'report_cards.view', 'report_cards.create', 'report_cards.update',
            'timetable.view', 'calendar.view', 'communication.view', 'communication.create',
        ]],
        'subject-teacher' => ['name' => 'Subject Teacher', 'grants' => [
            'students.view', 'attendance.view',
            'examinations.view', 'examinations.create', 'examinations.update',
            'timetable.view', 'calendar.view', 'communication.view',
        ]],
        'librarian' => ['name' => 'Librarian', 'grants' => ['library.*', 'students.view', 'staff.view']],
        'nurse' => ['name' => 'Nurse', 'grants' => ['medical.*', 'students.view']],
        'store-keeper' => ['name' => 'Store Keeper', 'grants' => ['inventory.*']],
        'accountant' => ['name' => 'Accountant', 'grants' => ['students.view', 'parents.view', 'analytics.view']],
        'class-assistant' => ['name' => 'Class Assistant', 'grants' => [
            'students.view', 'attendance.view', 'attendance.create',
            'timetable.view', 'calendar.view', 'communication.view',
        ]],
        'parent' => ['name' => 'Parent', 'grants' => [
            'calendar.view', 'communication.view', 'timetable.view',
        ]],
        'student' => ['name' => 'Student', 'grants' => [
            'calendar.view', 'communication.view', 'timetable.view',
        ]],
        'security' => ['name' => 'Security', 'grants' => []],
        'hostel-master' => ['name' => 'Hostel Master', 'grants' => ['hostel.*', 'students.view']],
        'transport-officer' => ['name' => 'Transport Officer', 'grants' => ['transport.*', 'students.view']],
    ],
];
