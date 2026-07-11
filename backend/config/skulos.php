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
            'staff_records' => 'Staff Records',
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
    */
    'default_class_structure' => [
        'nursery' => ['section' => 'Nursery', 'classes' => ['Creche/Toddler', 'Reception 1', 'Reception 2', 'Nursery 1', 'Nursery 2']],
        'primary' => ['section' => 'Primary', 'classes' => ['Primary 1', 'Primary 2', 'Primary 3', 'Primary 4', 'Primary 5', 'Primary 6']],
        'jss' => ['section' => 'Secondary', 'classes' => ['JSS 1', 'JSS 2', 'JSS 3']],
        'ss' => ['section' => 'Secondary', 'classes' => ['SS 1', 'SS 2', 'SS 3']],
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
    */
    'default_subjects' => [
        'nursery' => [
            'label' => 'Nursery / Kindergarten',
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
            'label' => 'Senior Secondary (SS 1-3 / Grade 10-12)',
            'match' => ['ss1', 'ss2', 'ss3', 'ss 1', 'ss 2', 'ss 3', 'senior secondary', 'grade 10', 'grade 11', 'grade 12'],
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

    'extra_permissions' => [
        'examinations' => ['approve_results', 'publish_results'],
        'communication' => ['broadcast'],
        'school_info' => ['manage_settings'],
        'students' => ['manage_settings'],
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
