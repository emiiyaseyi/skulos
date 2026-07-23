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
        'report_cards', 'calendar', 'communication', 'school_info', 'attendance',
        // Added 2026-07-15 ("MORE FEATURES TO BE GIVEN TO A USER"): the
        // first module built since this list was curated that has a real
        // screen behind it (increments batch, see tracker), extend this
        // list as more modules get real screens, not by widening it to
        // the full module catalog (see the comment on this key's own
        // definition below for why).
        'assignments',
        // Added 2026-07-17: Foundation Finance slice gave 'finance' a real
        // screen behind it (FeeItemController/PaymentController), same
        // reasoning as 'assignments' above.
        'finance',
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
        'assignments' => ['name' => 'Assignments', 'core' => true],
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
            'subject_insights' => 'Subject Insights',
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
    | Knowledge Base seed catalog (2026-07-21): the initial real, accurate
    | article set for the in-app help screen, describing features that
    | genuinely exist and work exactly as described here. Seeded once via
    | KnowledgeBaseSeeder into the real knowledge_base_categories/articles
    | tables (the "config feeds a one-time seed into a DB table, every
    | runtime read goes through the table" pattern already established by
    | grading_scales/comments/report card traits/admission document
    | requirements) — a platform admin edits/adds from there afterward,
    | this array is never read directly at request time.
    */
    'default_knowledge_base' => [
        'Getting Started' => [
            [
                'title' => 'Finding your way around',
                'body' => 'Every screen lives behind the menu on the left (tap the menu icon on a phone to open it).',
                'steps' => [
                    'Tap or click the menu icon to open the sidebar, then tap it again to collapse it back down.',
                    'Use the search box above the menu to jump straight to a feature by name instead of hunting through the list.',
                    'Your dashboard is the home screen after signing in: it shows figures relevant to your role (students, attendance, results, and so on).',
                    'A set of quick action buttons on the dashboard covers the things your role does most often, so you rarely need to hunt for the first click.',
                ],
            ],
            [
                'title' => 'Who can see and do what',
                'body' => 'Every person who signs in has a role, and each role grants access to specific screens and actions.',
                'steps' => [
                    'Go to Settings > User & Role Management.',
                    'Pick an existing role, or create a new one and name it.',
                    'Open each section and tick every permission that role should have.',
                    'Save. The change takes effect immediately for everyone currently holding that role.',
                ],
                'start_route' => 'school.roles.index',
            ],
        ],
        'Students & Staff' => [
            [
                'title' => 'Adding a student',
                'body' => "A student's Admission Number is their permanent identifier here, set by the school and used everywhere a student is referenced. Email is optional and never required.",
                'steps' => [
                    'Go to Students > Add Student for a single entry.',
                    'For many students at once, use Students > Import instead: download the template first, it lists every column the importer understands.',
                    "Fill in the student's Admission Number, name, class, and any other details your school requires.",
                    'Double check the Admission Number before saving, it cannot be changed as easily once other records start referencing it.',
                    'Save. A login is provisioned automatically using the admission number and your school\'s configured default password.',
                ],
                'start_route' => 'school.students.create',
            ],
            [
                'title' => "Setting a student or staff member's unique sign-in identifier",
                'body' => 'An administrator chooses whether email address or phone number is the required unique identifier for parents and, separately, for staff.',
                'steps' => [
                    'Go to Settings > Student Field Settings.',
                    'Under Parent identifier, choose email address or phone number.',
                    'Under Staff identifier, choose email address or phone number (independently of the parent choice).',
                    'Save. Sign in itself still accepts either an email or a phone number in the same field either way.',
                ],
                'start_route' => 'school.students.settings.edit',
            ],
            [
                'title' => 'Setting up school houses',
                'body' => 'Houses group students for inter house competitions and events, independent of class.',
                'steps' => [
                    'Go to Houses.',
                    'Load the default 4-house catalog in one click, or add your own houses one at a time.',
                    "Open a house to see its member list, assign students to it, or download the member list as a CSV.",
                    'Use the bulk assign screen to place many students into houses at once, with a "show only unassigned" filter.',
                ],
                'start_route' => 'school.houses.index',
            ],
            [
                'title' => 'Adding a custom field for students, staff, or parents',
                'body' => 'A genuinely new field your school needs, not already covered by the standard record.',
                'steps' => [
                    'Go to Settings > Custom Fields.',
                    'Add a field: give it a label, a type (text, number, date, dropdown, checkbox, and so on), and mark it required if every record must have a value.',
                    'Choose which of Student, Staff, or Parent it applies to.',
                    'It now appears on that entity\'s create/edit form and profile page automatically.',
                ],
                'start_route' => 'school.settings.custom-fields.index',
            ],
        ],
        'Attendance & Timetable' => [
            [
                'title' => 'Taking attendance',
                'body' => 'Attendance cannot be recorded for a weekend or a day marked as a holiday or break on the school calendar, those are not real school days.',
                'steps' => [
                    'Go to Attendance > Take Attendance.',
                    'Pick a class and a date.',
                    'Mark each student present, absent, late, or excused.',
                    'Save. Re-opening the same class and date later lets you correct any mark rather than creating a duplicate.',
                ],
                'start_route' => 'school.attendance.index',
            ],
            [
                'title' => 'Building a class timetable',
                'body' => 'Timetable Management is available on the Advance plan and above.',
                'steps' => [
                    'Go to Timetable > Periods and set up the school\'s period slots once, or load the suggested default day.',
                    'Go to Timetable, pick a class, and assign a subject and teacher to each period across the week.',
                    'A conflicting cell (the same teacher already booked in another class at that day and period) is skipped and reported, never silently double booked.',
                    'Use Master Timetable to see every class\'s weekly grid on one screen at a glance.',
                ],
                'start_route' => 'school.timetable.periods',
            ],
            [
                'title' => 'Building the examination timetable',
                'body' => 'Dated across a real calendar window, distinct from the recurring weekly class timetable above.',
                'steps' => [
                    'Go to Timetable > Examination Timetable, then Exam day structure first.',
                    'Enter each paper (Paper 1, Break, Paper 2, and so on), its duration, and when the first paper starts; every other paper\'s time is computed automatically.',
                    'Back on the Examination Timetable screen, pick a section or one specific class, and a date range.',
                    'Assign a subject to each paper on each date, or download the blank template, fill it in, and upload it back.',
                    'Export the finished timetable as CSV, or use Print / Save as PDF.',
                ],
                'start_route' => 'school.timetable.exam.index',
            ],
        ],
        'Results & Report Cards' => [
            [
                'title' => 'Setting up how results are scored',
                'body' => 'Both can be loaded from a sensible default and adjusted afterward.',
                'steps' => [
                    'Go to Results > Assessment Structure.',
                    'Define the components scores are made of, for example two Continuous Assessments plus one Examination, and how many marks each is out of.',
                    'Go to Results > Grading.',
                    'Define the grade bands (A, B, C, and so on) and the score range each one covers.',
                ],
                'start_route' => 'school.examinations.structure',
            ],
            [
                'title' => 'Designing a report card',
                'body' => 'Every school starts on a sensible default layout even before anyone opens the designer.',
                'steps' => [
                    'Go to Report Cards > Report settings > open the Layout Designer.',
                    'Drag sections and blocks around a live preview of the real report card.',
                    'Add custom text blocks or charts if your school wants something beyond the fixed catalog.',
                    'Save. The new layout applies to every report card generated afterward.',
                ],
                'start_route' => 'school.report-cards.layout.edit',
            ],
            [
                'title' => 'Reviewing and releasing report cards',
                'body' => 'A report card generated for a class is automatically assigned to that class\'s reviewer.',
                'steps' => [
                    'Go to Report Cards > Pending review.',
                    'Open a report card to check it, then Approve, or Reject with a comment explaining what needs correcting.',
                    'Tick several at once and use the bulk Approve, Reject, or Nudge actions to act on many together.',
                    'Once approved through every stage of the review chain, release it so the parent or student can see it.',
                ],
                'start_route' => 'school.report-cards.pending-review',
            ],
        ],
        'Communication' => [
            [
                'title' => 'Sending an announcement',
                'body' => 'Everyone the announcement is sent to always gets an in-app notification.',
                'steps' => [
                    'Go to Communication > Announcements > Compose.',
                    'Write the message and choose who receives it: parents, teachers, staff, students, or a mix.',
                    'Publish. Email is a separate, optional toggle per audience, set under Settings > Communication.',
                ],
                'start_route' => 'school.announcements.create',
            ],
            [
                'title' => 'Seeing who has acknowledged an announcement',
                'body' => 'Every announcement and message shares one chronological ledger.',
                'steps' => [
                    'Go to Communication > Communication History.',
                    'Open any item to see exactly who has acknowledged it and who is still pending.',
                    'Use Nudge everyone pending to send a fresh in-app reminder to only the ones who have not yet acknowledged it.',
                ],
                'start_route' => 'school.announcements.history',
            ],
        ],
        'Reports & Analytics' => [
            [
                'title' => 'Building a custom report',
                'body' => 'Pick a data source, choose your columns, filter, then preview or export.',
                'steps' => [
                    'Go to Reports > Report Builder.',
                    'Pick a data source (Students or Staff).',
                    'Tick which columns to include, for example attendance rate, average score, or behavioural traits alongside the usual student details.',
                    'Filter by class or status if needed, preview the table, then export it as CSV.',
                ],
                'start_route' => 'school.reports.builder.index',
            ],
            [
                'title' => 'Seeing whole school academic insights',
                'body' => 'Subject strength, weak spots, and grade spread across the whole school, one term at a time.',
                'steps' => [
                    'Go to Reports > Academic Insights.',
                    'Pick a session and term, and optionally scope it down to one section or one class.',
                    'Read the subject performance table (strongest first) and the grade distribution below it.',
                    'Use the filter and sort controls on the subject table to find a specific subject quickly.',
                ],
                'start_route' => 'school.examinations.academic-insights',
            ],
        ],
    ],

    /*
    | Which sidebar icon (see layouts/partials/icon.blade.php's catalog)
    | represents each Knowledge Base category on the redesigned Help
    | index (user request 2026-07-22: "let's make this section much more
    | presentable and great"). Falls back to the generic 'support' icon
    | for any category name not listed here (e.g. an admin-added one).
    */
    'knowledge_base_category_icons' => [
        'Getting Started' => 'dashboard',
        'Students & Staff' => 'students',
        'Attendance & Timetable' => 'attendance',
        'Results & Report Cards' => 'results',
        'Communication' => 'comms',
        'Reports & Analytics' => 'trend',
    ],

    /*
    | Curated dropdown of "start here" destinations a Knowledge Base
    | article can link to (user request 2026-07-22: "add a button that
    | takes them to where to start the process or knowledge under help").
    | A route NAME, not admin-typed freeform text or a raw URL, so a typo
    | cannot silently produce a broken link and the destination correctly
    | resolves to whichever school subdomain is currently viewing the
    | article. Extend this list whenever a new article needs to point
    | somewhere not yet covered here.
    |
    | "permission" (added 2026-07-22, user report: "Knowdlge base showing
    | under other staff should be related to the things the user can do
    | only") is the exact same permission the destination screen's own
    | controller checks (kept in sync by hand, not derived automatically,
    | since Laravel has no reliable way to introspect a route's
    | authorization from config alone) — School\KnowledgeBaseController
    | filters an article out of a user's own Help list when they lack it.
    | An article with no start_route (general orientation, "Finding your
    | way around") has no permission to check and always shows.
    */
    'knowledge_base_start_routes' => [
        'school.roles.index' => ['label' => 'User & Role Management', 'permission' => 'staff.update'],
        'school.students.create' => ['label' => 'Add a Student', 'permission' => 'students.create'],
        'school.students.import' => ['label' => 'Import Students', 'permission' => 'students.bulk'],
        'school.students.settings.edit' => ['label' => 'Student Field Settings', 'permission' => 'students.manage_settings'],
        'school.houses.index' => ['label' => 'Houses', 'permission' => 'students.view'],
        'school.settings.custom-fields.index' => ['label' => 'Custom Fields', 'permission' => 'school_info.manage_settings'],
        'school.attendance.index' => ['label' => 'Take Attendance', 'permission' => 'attendance.view'],
        'school.timetable.periods' => ['label' => 'Timetable Periods', 'permission' => 'timetable.update'],
        'school.timetable.exam.index' => ['label' => 'Examination Timetable', 'permission' => 'timetable.update'],
        'school.examinations.structure' => ['label' => 'Assessment Structure', 'permission' => 'examinations.view'],
        'school.examinations.grading' => ['label' => 'Grading', 'permission' => 'examinations.view'],
        'school.report-cards.layout.edit' => ['label' => 'Report Card Layout Designer', 'permission' => 'report_cards.view'],
        'school.report-cards.pending-review' => ['label' => 'Report Cards Pending Review', 'permission' => 'report_cards.assign_review'],
        'school.announcements.create' => ['label' => 'Compose an Announcement', 'permission' => 'communication.broadcast'],
        'school.announcements.history' => ['label' => 'Communication History', 'permission' => 'communication.view'],
        'school.reports.builder.index' => ['label' => 'Report Builder', 'permission' => 'analytics.view'],
        'school.examinations.academic-insights' => ['label' => 'Academic Insights', 'permission' => 'examinations.approve_results'],
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
    | admin/user has somewhere to turn. This is only the seed/fallback
    | value now (no-hardcoding audit, 2026-07-13): the live value a view
    | actually renders comes from App\Services\Platform\SupportContactService,
    | which reads a PlatformSetting override if a super admin has set one
    | under Admin > Settings > Support Contact, falling back to this array
    | (still env overridable) only until they do.
    */
    'support' => [
        'email' => env('SKULOS_SUPPORT_EMAIL', 'info@skulos.app'),
        'phone' => env('SKULOS_SUPPORT_PHONE', '+234 802 337 3302'),
    ],

    /*
    | Seed default for the bank account a school transfers its SKULOS
    | subscription payment into. A platform admin can override this from
    | Admin > Settings > Subscription Bank without a code deploy
    | (SubscriptionBankService, same PlatformSetting-override pattern as
    | 'support' above). The real account details live in .env only
    | (SKULOS_SUBSCRIPTION_*), never as a committed default here: this is
    | a real bank account, not public contact info like 'support' above,
    | and a leaked real account name/number in version control is
    | directly usable for a fraudulent "updated bank details" scam
    | against paying schools (audit finding M-3, 2026-07-17).
    */
    'subscription_bank' => [
        'bank_name' => env('SKULOS_SUBSCRIPTION_BANK_NAME', ''),
        'account_name' => env('SKULOS_SUBSCRIPTION_ACCOUNT_NAME', ''),
        'account_number' => env('SKULOS_SUBSCRIPTION_ACCOUNT_NUMBER', ''),
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
    | Timetable Management (Advance plan feature, built 2026-07-18). The
    | school week itself, same lightweight "configurable catalog, not a
    | hardcoded enum" treatment attendance_statuses above already gets,
    | not the heavier seed-into-a-DB-table shape (there is nothing here
    | for a school to add/remove/reorder beyond which weekdays exist).
    | `default_timetable_periods` IS seeded into the real
    | `timetable_periods` tenant table (TimetableController::
    | applyDefaultPeriods()), the same seed-then-DB-table shape
    | grading_scales/comments already use, since a school genuinely edits
    | its own period times/names/count afterward.
    */
    'school_week_days' => [
        'monday' => 'Monday', 'tuesday' => 'Tuesday', 'wednesday' => 'Wednesday',
        'thursday' => 'Thursday', 'friday' => 'Friday',
    ],
    'default_timetable_periods' => [
        ['name' => 'Period 1', 'starts_at' => '08:00', 'ends_at' => '08:40', 'is_teaching_period' => true],
        ['name' => 'Period 2', 'starts_at' => '08:40', 'ends_at' => '09:20', 'is_teaching_period' => true],
        ['name' => 'Period 3', 'starts_at' => '09:20', 'ends_at' => '10:00', 'is_teaching_period' => true],
        ['name' => 'Break', 'starts_at' => '10:00', 'ends_at' => '10:20', 'is_teaching_period' => false],
        ['name' => 'Period 4', 'starts_at' => '10:20', 'ends_at' => '11:00', 'is_teaching_period' => true],
        ['name' => 'Period 5', 'starts_at' => '11:00', 'ends_at' => '11:40', 'is_teaching_period' => true],
        ['name' => 'Period 6', 'starts_at' => '11:40', 'ends_at' => '12:20', 'is_teaching_period' => true],
        ['name' => 'Lunch', 'starts_at' => '12:20', 'ends_at' => '13:00', 'is_teaching_period' => false],
        ['name' => 'Period 7', 'starts_at' => '13:00', 'ends_at' => '13:40', 'is_teaching_period' => true],
        ['name' => 'Period 8', 'starts_at' => '13:40', 'ends_at' => '14:20', 'is_teaching_period' => true],
    ],

    /*
    | Students At Risk widget bands (Dashboard Design Cross-Check): a
    | student flags as at risk if their current term attendance rate or
    | raw average score falls below these percentages. Config-level today
    | (same effort as the existing Health Score 80/50 bands), not yet a
    | per-school editable Setting; move to the Setting-backed pattern
    | already used for dashboard_widgets/plan features if a school ever
    | needs to tune it.
    */
    'at_risk_thresholds' => ['attendance_pct' => 75, 'score_pct' => 40],

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
        // Added 2026-07-22 (user request: "I want the option of having
        // both as the unique identifier too, both email and phone
        // number"), a reversal of the original "the school must stick
        // to one only" decision: a school choosing "both" accepts
        // either contact field as satisfying the requirement (at least
        // one of the two present), rather than forcing a single specific
        // one. See StudentController::storeGuardian()/updateGuardian()
        // and StaffController::staffValidationRules() for the
        // required_without enforcement this adds.
        'both' => 'Both (either one)',
    ],

    /*
    | Auto-provisioned login defaults (user request 2026-07-21: "Why do we
    | have to manually create login for students and parents... students
    | can login with their admission number and the default password
    | which must be change upon first login. Parents can login with
    | either email or phone number"). Seeds tenant Settings
    | "default_student_password" / "default_parent_password", each
    | school-editable from Student Field Settings — a known, told-to-them
    | password rather than a random one, since a student has no
    | guaranteed email/phone to receive a random one at, and it must be
    | changed at first sign in regardless (AccountProvisioningService::
    | provisionAuto() always sets must_change_password).
    |
    | Both fixed to "password123" (corrected 2026-07-22, user report
    | "part 3" item 142/143: "students are to use their admission numbers
    | as username and the default pass is password123... for parents, can
    | use email or phone and the default pass of password123"). Was
    | "Student@123"/"Parent@123", never actually matching the convention
    | stated here and in schools:seed-demo-data's own DEMO_PASSWORD, a
    | real gap for the first login any school creates via the actual
    | Create login button (every already-provisioned school's demo data
    | already used "password123", confirmed by audit, so no existing
    | account needed a reset, only this default going forward).
    */
    'default_student_password' => 'password123',
    'default_parent_password' => 'password123',

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
    | Audit log durable export (2026-07-20 audit log hardening): the
    | archive-not-delete strategy the comment above pointed at. Batches of
    | new audit_logs rows are periodically written as append-only, never
    | overwritten NDJSON files to this filesystem disk (see
    | config/filesystems.php), which any external log shipper / SIEM can
    | tail. Defaults to the 'local' private disk, which is durable but not
    | truly immutable; pointing AUDIT_LOG_EXPORT_DISK at 's3' with the
    | bucket's Object Lock / versioning enabled in production is what
    | makes this genuinely write-once-read-many, that bucket policy is an
    | infrastructure setting outside this codebase, not something this app
    | can turn on for you. See DEVELOPMENT_TRACKER.md's audit log
    | hardening entry for the full picture, including the pieces (direct
    | database access restriction) that are ops policy, not application
    | code, and so aren't implemented here.
    */
    'audit_export_disk' => env('AUDIT_LOG_EXPORT_DISK', 'local'),

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
        'broadcast' => ['label' => 'Broadcast', 'type' => 'urgent'],
        'report_card_review' => ['label' => 'Report card review', 'type' => 'academic'],
        'birthday' => ['label' => 'Birthday', 'type' => 'academic'],
        'anniversary' => ['label' => 'Work anniversary', 'type' => 'academic'],
        'payment_submitted' => ['label' => 'Payment submitted', 'type' => 'finance'],
        'payment_verified' => ['label' => 'Payment verified', 'type' => 'finance'],
        'payment_rejected' => ['label' => 'Payment rejected', 'type' => 'finance'],
        'subscription_reminder' => ['label' => 'Subscription reminder', 'type' => 'finance'],
        'subscription_extended' => ['label' => 'Subscription extended', 'type' => 'finance'],
        'subscription_locked' => ['label' => 'Subscription overdue', 'type' => 'urgent'],
        'subscription_reinstated' => ['label' => 'Subscription reinstated', 'type' => 'finance'],
        'subscription_payment_verified' => ['label' => 'Subscription payment verified', 'type' => 'finance'],
        'subscription_payment_rejected' => ['label' => 'Subscription payment rejected', 'type' => 'finance'],
    ],

    /*
    | Default notable days seeded into a new school's calendar (user request
    | 2026-07-09). These are starting defaults only: the school admin can
    | edit, remove, or add more, and set the actual dates. Category drives
    | display/colour. Nothing here is hardcoded into calendar logic; it is
    | seed data a school owns once provisioned.
    */
    /*
    | Category vocabulary a school picks from when adding its own calendar
    | event (drives the same colour coding the seeded defaults already use
    | via their own 'category' value). A closed technical vocabulary, not
    | a business catalog a school customizes, same shape as
    | attendance_statuses.
    */
    'calendar_event_categories' => ['term', 'break', 'celebration', 'staff', 'sports', 'holiday', 'exam', 'meeting', 'event'],

    /*
    | Messaging (2026-07-15). A school owner/admin composes a broadcast
    | choosing WHO (a message_audience: a role group, or everyone) and the
    | SCOPE (message_scope: the whole school, one section, or one class).
    | Both are config catalogs, not literals in the controller/view, so the
    | compose form and its validation read from here (standing "nothing
    | hardcoded" rule). Delivery mode (one_way / two_way) is likewise a
    | catalog; one_way broadcasts pop up on sign-in and are acknowledged,
    | two_way opens the chat widget.
    */
    'message_audiences' => [
        'everyone' => 'Everyone',
        'parents' => 'Parents',
        'students' => 'Students',
        'teachers' => 'Teachers',
    ],
    'message_scopes' => [
        'all' => 'Whole school',
        'section' => 'A section',
        'class' => 'A class',
    ],
    'message_modes' => [
        'one_way' => 'One way (broadcast, they acknowledge)',
        'two_way' => 'Two way (they can reply, chat)',
    ],

    /*
    | Seeded per school, per year, by CalendarService::seedDefaults() (user
    | directive 2026-07-15: "CALENDAT SHOULD HAVE DATE POPULATED ALREADY
    | AND SOME DAYS ARE FIXED ALREADY - CHECK THE PUBLIC HOLIDAY CALENDAR
    | FOR THE YEAR IN QUESTION AND POPULATE", followed by "ADD ALL PUBLIC
    | HOLIDAYES IN NIGERIA TOO AND ALWAYS DO IT FOR EVERY YEAR GOING
    | FORWARD TOO. ITS DEFAULT"). Three shapes for a real date:
    |   - 'month'/'day': a fixed-date Nigerian public holiday or
    |     observance, the same Gregorian date every year.
    |   - 'easter_offset': days from Easter Sunday (moves every year), see
    |     CalendarService::easterDate().
    |   - 'hijri_month'/'hijri_day': an Islamic holiday, an ESTIMATED
    |     Gregorian date via the tabular Hijri calendar (see
    |     CalendarService::islamicHolidayDate()'s own docblock for why this
    |     is an estimate, not an official confirmed date, its title says
    |     "(estimated)" for the same reason). $hijriMonth is 0-indexed
    |     (0=Muharram ... 9=Shawwal ... 11=Dhu al-Hijjah), matching ICU.
    |   - none of the above: no real-world fixed date exists (a school's
    |     own term dates, or an internal school-culture day each school
    |     schedules itself), starts_on is left null, an admin sets it by
    |     editing the seeded row ("EACH CALENDAR DATE CAN BE CLICKED AND
    |     EDITED"), seeded once ever, not re-added every year.
    | Every dated entry is re-seeded automatically each year by the
    | scheduled `skulos:seed-calendar-defaults` command (routes/console.php),
    | see App\Console\Commands\SeedCalendarDefaults.
    */
    'default_calendar_events' => [
        ['title' => "New Year's Day", 'category' => 'holiday', 'month' => 1, 'day' => 1],
        ['title' => 'Good Friday', 'category' => 'holiday', 'easter_offset' => -2],
        ['title' => 'Easter Monday', 'category' => 'holiday', 'easter_offset' => 1],
        ['title' => "Workers' Day", 'category' => 'holiday', 'month' => 5, 'day' => 1],
        ['title' => "Children's Day", 'category' => 'celebration', 'month' => 5, 'day' => 27],
        ['title' => 'Democracy Day', 'category' => 'holiday', 'month' => 6, 'day' => 12],
        ['title' => 'Independence Day', 'category' => 'holiday', 'month' => 10, 'day' => 1],
        ['title' => 'Christmas Day', 'category' => 'holiday', 'month' => 12, 'day' => 25],
        ['title' => 'Boxing Day', 'category' => 'holiday', 'month' => 12, 'day' => 26],
        ['title' => 'Eid el-Fitr (estimated)', 'category' => 'holiday', 'hijri_month' => 9, 'hijri_day' => 1],
        ['title' => 'Eid el-Kabir (estimated)', 'category' => 'holiday', 'hijri_month' => 11, 'hijri_day' => 10],
        ['title' => 'Eid el-Mawlid (estimated)', 'category' => 'holiday', 'hijri_month' => 2, 'hijri_day' => 12],
        ['title' => 'Beginning of Term', 'category' => 'term'],
        ['title' => 'Mid Term Break', 'category' => 'break'],
        ['title' => 'End of Term', 'category' => 'term'],
        ['title' => 'Art and Culture Day', 'category' => 'celebration'],
        ['title' => 'Professional Day', 'category' => 'staff'],
        ['title' => 'Inter House Sports Day', 'category' => 'sports'],
    ],

    /*
    | Official Lagos State Ministry of Basic & Secondary Education (OEQA)
    | harmonised academic calendar term dates (user request 2026-07-16:
    | "CHECK FOR LAGOS STATE OR OGUN STATE ACADEMIC CALENDAR FOR THE
    | SESSION OF 2024/2025 AND 2025/2026 TO POPULATE THE RESPECTIVE AND
    | ACTIVE TERMS"). Sourced from the approved PDFs published at
    | oeqalagos.com for each session. `ends_on` is each term's last actual
    | teaching day (the Friday before vacation begins / the calendar's own
    | "END OF SESSION" date for the third term), not the vacation start
    | date. This is a one time seed catalog only, per the project's
    | "nothing hardcoded" rule: `skulos:seed-official-term-dates` reads it
    | once to correct/fill real Term and AcademicSession rows in each
    | school's own database, and every runtime read already goes through
    | those tables (the existing Calendar screens), never through this
    | config directly.
    */
    'official_term_dates' => [
        '2024/2025' => [
            'session' => ['starts_on' => '2024-09-09', 'ends_on' => '2025-07-25'],
            'terms' => [
                1 => ['name' => 'First Term', 'starts_on' => '2024-09-09', 'ends_on' => '2024-12-13', 'mid_term_break' => ['2024-10-28', '2024-11-01']],
                2 => ['name' => 'Second Term', 'starts_on' => '2025-01-06', 'ends_on' => '2025-04-11', 'mid_term_break' => ['2025-02-17', '2025-02-21']],
                3 => ['name' => 'Third Term', 'starts_on' => '2025-04-28', 'ends_on' => '2025-07-25', 'mid_term_break' => ['2025-06-09', '2025-06-13']],
            ],
        ],
        '2025/2026' => [
            'session' => ['starts_on' => '2025-09-15', 'ends_on' => '2026-07-24'],
            'terms' => [
                1 => ['name' => 'First Term', 'starts_on' => '2025-09-15', 'ends_on' => '2025-12-19', 'mid_term_break' => ['2025-11-03', '2025-11-07']],
                2 => ['name' => 'Second Term', 'starts_on' => '2026-01-12', 'ends_on' => '2026-04-17', 'mid_term_break' => ['2026-02-23', '2026-02-27']],
                3 => ['name' => 'Third Term', 'starts_on' => '2026-05-04', 'ends_on' => '2026-07-24', 'mid_term_break' => ['2026-06-15', '2026-06-19']],
            ],
        ],
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
        // Split out of "greeting" (user report 2026-07-16: "THERE SHOULD
        // BE MORE ITEMS UNDER THE PANEL CHECKLIST"), the announcements
        // ticker used to be bundled inside the greeting toggle with no
        // way to turn it off on its own.
        'announcements' => ['label' => 'Announcements', 'default' => true],
        'stats' => ['label' => 'Key figures', 'default' => true],
        // The role specific panel (Students at risk, Result performance,
        // birthdays, upcoming exams/assignments, pending reviews, ...)
        // had no toggle at all until now, same 2026-07-16 request.
        'role_panel' => ['label' => 'Role specific panel', 'default' => true],
        'notifications' => ['label' => 'Notifications', 'default' => true],
        'calendar' => ['label' => 'Upcoming calendar', 'default' => true],
        'holidays' => ['label' => "This term's key dates", 'default' => true],
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
    | Chart style a school can choose for every trend chart platform wide
    | (user request 2026-07-22: "let's have alterative style ot charts to
    | select for this current ones, apply to all areas that the chart
    | is"). Stored as tenant Setting "chart_style" (not a schools column,
    | same reasoning as guardian_visibility: a small, rarely-changed
    | display preference, not something every request needs eager loaded
    | on the School model), read by the shared _trend-line partial and
    | the report card Score Trend chart alike. Scoped to trend (time
    | series) charts only, since a magnitude chart like the report card's
    | own Subject Breakdown is already a bar chart by necessity (per
    | subject categories, not a series over time) and has no "line"
    | equivalent that would make sense.
    */
    'chart_styles' => [
        'line' => ['label' => 'Line', 'description' => 'A connected line through each point, axis zoomed to the data.'],
        'bar' => ['label' => 'Bar', 'description' => 'A bar per point, axis anchored at zero.'],
    ],
    'default_chart_style' => 'line',

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
                // Corrected 2026-07-23, real user directive: "the only
                // mandatory subjects are Maths, English and Civic... after
                // the mandatory subjects, others are not mandatory" — was
                // 5 mandatory subjects (this list plus Digital
                // Technologies/Trade Subject), narrower than the source
                // doc's own fuller WAEC compulsory-core listing, per this
                // school's explicit correction. Digital Technologies and
                // Trade Subject stay offered, at the whole class level
                // (every stream sees them, not stream specific), just no
                // longer required.
                ['name' => 'English Language', 'mandatory' => true, 'examinable' => true],
                ['name' => 'Mathematics', 'mandatory' => true, 'examinable' => true],
                ['name' => 'Civic Education', 'mandatory' => true, 'examinable' => true],
                ['name' => 'Digital Technologies', 'mandatory' => false, 'examinable' => true],
                ['name' => 'Trade Subject', 'mandatory' => false, 'examinable' => true],
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
    | Which of the 'ss' catalog's own subjects belong to which Senior
    | Secondary stream (user directive 2026-07-23: "update the classes
    | streams in Greenfield for SSS to have the three streams... after
    | the mandatory subjects, others are not mandatory", cross checked
    | against "List of subjects and Grading system.docx"'s WAEC
    | departmental breakdown). Seed data only, consumed once by
    | `php artisan sss:setup-streams {school}` to create real
    | class_arm_subjects rows an admin can then edit freely from the
    | Classes & Subjects screen's own per stream tabs — never read live
    | at request time, the same "config feeds a one time seed action"
    | shape as default_subjects/default_grading_scales.
    */
    'sss_stream_subjects' => [
        'Science & Technology' => ['Biology', 'Chemistry', 'Physics', 'Agricultural Science', 'Further Mathematics', 'Geography'],
        'Arts & Humanities' => ['Government', 'Literature in English', 'History', 'Christian Religious Studies', 'Islamic Studies', 'Visual Arts', 'Music', 'French', 'Arabic'],
        'Commercial' => ['Economics', 'Commerce', 'Financial Accounting', 'Marketing'],
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
        // Templates 5 and 6 (user-supplied reference, 2026-07-18, "HEYDAY
        // PRIVATE SCHOOL" nursery evaluation sheets): a scored evaluation
        // sheet and a comments-only one, both with a behavioural traits/
        // physical stats block, sized for Nursery/Early Years.
        'evaluation' => ['label' => 'Evaluation Sheet', 'description' => 'A scored nursery/primary evaluation sheet with a behavioural traits and physical stats block, plus separate class teacher and head teacher remarks.'],
        'narrative' => ['label' => 'Narrative Evaluation', 'description' => 'A comments-only evaluation sheet (no scores): a written comment per subject instead, for Nursery/Early Years classes that are not scored numerically.'],
    ],
    'default_report_card_template' => 'classic',

    /*
    | The header and identity strip, as movable blocks a school can drag
    | between two zones (Header, Info Strip) or leave unused (user request
    | 2026-07-18: "schools should be able to select where the logo should
    | be, what should come next, name of school, address, term, session,
    | class, name of learner, date of next term, position, etc"). Opt in
    | per school via ReportCardLayout::enabled(); off by default so every
    | existing template's fixed header/id-strip markup is unaffected until
    | a school deliberately turns this on. Applies on top of whichever
    | template is active, replacing only the header/identity area, not the
    | subject table, remarks, traits, or stamp/signature blocks (those stay
    | governed by report_card_fields above).
    */
    'report_card_layout_blocks' => [
        'logo' => 'School Logo',
        'school_name' => 'School Name',
        'address' => 'School Address',
        'term_session' => 'Term and Session',
        'student_name' => 'Name of Learner',
        'admission_number' => 'Admission Number',
        'class' => 'Class',
        'gender' => 'Gender',
        'date_of_birth' => 'Date of Birth',
        'age' => 'Age',
        'house' => 'House',
        'class_teacher' => 'Class Teacher',
        'attendance' => 'Attendance',
        'next_term_begins' => 'Date of Next Term',
        'overall_position' => 'Position in Class',
    ],
    'default_report_card_layout' => [
        'header' => ['logo', 'school_name', 'address', 'term_session'],
        'info' => [
            'student_name', 'class', 'admission_number', 'gender', 'date_of_birth',
            'age', 'house', 'class_teacher', 'attendance', 'next_term_begins', 'overall_position',
        ],
    ],

    /*
    | Report card body sections (2026-07-18, Layout Designer "make it
    | much more holistic": "so many blocks are missing... where are the
    | footer blocks like class teacher remark, headteacher/principal,
    | stamp and signature"). Each key has a matching partial
    | resources/views/school/report-cards/_body-{key}.blade.php,
    | extracted verbatim from the report card's own fixed rendering so
    | the designer's live preview is always the real markup. Order here
    | is the render order when a school has not customized it.
    */
    'report_card_body_sections' => [
        'subjects_table' => 'Scores Table',
        'subject_comments' => 'Subject Comments',
        'summary' => 'Summary Boxes',
        'traits_physical' => 'Behavioural Traits & Physical Stats',
        'grade_key' => 'Grade Key',
        'remarks' => 'Remarks (Class Teacher / Head of School)',
        'signoff' => 'Stamp & Signature',
        // Charts (user request 2026-07-19: "add option to add text,
        // charts etc to the report card"). Print safe inline SVG, no JS,
        // so they render identically on screen and in a printed/PDF copy.
        // Not in 'report_card_default_body_sections' below (2026-07-23,
        // real user report: a 24 subject SSS report's Subject Breakdown
        // chart rendered as an unreadable wall of overlapping bars/labels)
        // — neither chart is a mandatory report card element, so both
        // stay opt-in, available to drag in from the layout designer's
        // "Available sections" tray, not shown until a school actually
        // adds one.
        'score_trend' => 'Score Trend Chart',
        'subject_chart' => 'Subject Breakdown Chart',
    ],

    /*
    | The body sections a report card shows before any school has ever
    | touched the Layout Designer, and what a school's own custom layout
    | starts pre-filled with the first time they turn it on. Deliberately
    | a curated subset of report_card_body_sections above, not every key
    | in that catalog — see the score_trend/subject_chart comment there.
    */
    'report_card_default_body_sections' => [
        'subjects_table', 'subject_comments', 'summary', 'traits_physical', 'grade_key', 'remarks', 'signoff',
    ],

    /*
    | Custom Report Builder (Advance plan feature 'custom_report_builder',
    | built 2026-07-18): which data sources and columns an admin can pick
    | from to assemble their own report, scoped down from the full
    | "drag-and-drop custom report creation across all modules" ambition
    | in the docx to a real, buildable "pick a source, pick columns, pick
    | filters, preview, export CSV" first cut. A fixed code-level catalog
    | (not a school-editable table, unlike report_card_fields): these are
    | real column definitions tied to real model attributes/relations, not
    | something a school customizes further, the "admin editable" part of
    | this feature is which columns/filters they pick per report, not the
    | catalog of what is available to pick from.
    */
    'report_builder_sources' => [
        'students' => [
            'label' => 'Students',
            'permission' => 'students.view',
            'columns' => [
                'admission_number' => 'Admission Number',
                'first_name' => 'First Name',
                'last_name' => 'Last Name',
                'gender' => 'Gender',
                'date_of_birth' => 'Date of Birth',
                'email' => 'Email',
                'phone' => 'Phone',
                'class' => 'Class',
                'house' => 'House',
                'status' => 'Status',
                'admitted_on' => 'Admission Date',
                // Added 2026-07-22 (user request: "report builder should
                // have much more items to combine into a report. Check
                // others things like, attendance, performance,
                // behavioural, etc"). Kept as extra columns on the
                // existing Students source, not new standalone sources:
                // each is naturally a per-student, per-current-term fact
                // meant to sit alongside a student's own record in one
                // combined report row, not a separate table a school
                // would browse on its own.
                'attendance_rate' => 'Attendance Rate (Current Term)',
                'average_score' => 'Average Score (Current Term)',
                'behavioural_traits' => 'Behavioural Traits (Current Term)',
            ],
        ],
        'staff' => [
            'label' => 'Staff',
            'permission' => 'staff.view',
            'columns' => [
                'staff_id' => 'Staff ID',
                'first_name' => 'First Name',
                'last_name' => 'Last Name',
                'gender' => 'Gender',
                'position' => 'Position',
                'email' => 'Email',
                'phone' => 'Phone',
                'employed_on' => 'Employment Date',
                'status' => 'Status',
            ],
        ],
    ],

    /*
    | Seed default for the behavioural/psychomotor trait catalog, copied
    | verbatim from the user-supplied real report card ("HEYDAY PRIVATE
    | SCHOOL"). Only ever a seed: App\Services\Results\BehaviouralTraitCatalog
    | seeds this into the real behavioural_traits table the first time a
    | school needs it, then that table is the only thing ever read, an
    | admin can add/edit/retire/reorder freely from there (the standing
    | "DB-backed, never read live from config" rule).
    */
    'default_behavioural_traits' => [
        ['name' => 'Class Participation', 'type' => 'remark'],
        ['name' => 'Punctuality', 'type' => 'remark'],
        ['name' => 'Attendance', 'type' => 'remark'],
        ['name' => 'Neatness', 'type' => 'remark'],
        ['name' => 'Homework/Assignment', 'type' => 'remark'],
        ['name' => "Attitude to School's Property", 'type' => 'remark'],
        ['name' => 'Attitude to Personal Property', 'type' => 'remark'],
        ['name' => 'Obedience', 'type' => 'remark'],
    ],

    /*
    | The remark-word scale a "type=remark" behavioural trait picks from
    | (matches the reference sheet's own wording exactly: "Excellent",
    | "V.Good", "Good", ...). A "type=score" trait uses a plain number
    | (0 to its own max_score) instead, no fixed scale needed for that.
    */
    'behavioural_trait_remark_scale' => ['Excellent', 'Very Good', 'Good', 'Fair', 'Weak'],

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
        // Added 2026-07-18 alongside Templates 5/6 (Evaluation Sheet /
        // Narrative Evaluation), but usable on any template, not only
        // those two: a school can turn on a behavioural traits block,
        // physical stats, subject-by-subject written comments, the stamp
        // and signature images, or a grade key legend on whichever
        // template it prefers.
        'behavioural_traits' => ['label' => 'Behavioural / psychomotor traits', 'group' => 'evaluation', 'default' => false],
        'physical_stats' => ['label' => 'Physical stats (height, weight, club, days present)', 'group' => 'evaluation', 'default' => false],
        'subject_comments' => ['label' => 'Written comment per subject (instead of, or alongside, scores)', 'group' => 'evaluation', 'default' => false],
        'stamp_signature' => ['label' => 'School stamp and signature', 'group' => 'evaluation', 'default' => false],
        'grade_legend' => ['label' => 'Grade key legend', 'group' => 'evaluation', 'default' => false],
    ],

    'permission_actions' => ['view', 'create', 'update', 'delete', 'export', 'bulk'],

    /*
    | Per file type upload size limits, in kilobytes (Dbase recommendation
    | standing rule: "bake these limits into config, admin adjustable, not
    | hardcoded, when the first upload feature is built"). Payment evidence
    | (Foundation Finance slice, 2026-07-17) is the first real upload
    | feature in the product, so this is the first entry; add a new key
    | here rather than a magic number in a validation rule whenever a
    | future upload feature (student/staff photos, documents) lands.
    */
    'upload_limits' => [
        'payment_evidence_kb' => 3072,
    ],

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
    |
    | 'teacher' (the plain "Teacher" role, distinct from "Class Teacher"/
    | "Subject Teacher") added 2026-07-18: a real school's teaching staff
    | most naturally end up on this generic role rather than the two more
    | specific ones, and were seeing every class/subject in the school
    | under Assignments as a result, since this list previously only
    | covered the two named roles a 2026-07-12 report happened to call
    | out. Scoped the same way: their own class (class_teacher_staff_id/
    | assistant_teacher_staff_id) plus whatever they hold a
    | TeachingAssignment for.
    */
    'teacher_scoped_roles' => ['class-teacher', 'subject-teacher', 'class-assistant', 'teacher'],

    /*
    | Parent and student are restricted to their own family's data only
    | (user request 2026-07-13: "BUILD THE FULL STUDENT AND PARENT
    | PORTAL"). Enforced by App\Services\Rbac\FamilyScope, the same
    | data-level ("which rows") vs action-level split TeacherScope
    | already established: `students.view` in their role grants above
    | is real, but every student it actually resolves to is either the
    | student's own record or a parent's own linked children, never the
    | whole school. Kept as its own list, not folded into
    | "teacher_scoped_roles" above, since neither role has a Staff
    | record for TeacherScope's class-arm based logic to key off.
    */
    'family_scoped_roles' => ['parent', 'student'],

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
        // Every scoped role already holds calendar.view (parent, student,
        // every teacher tier, ...), used here purely as an "any signed in
        // member" proxy — Knowledge Base needs no real permission of its
        // own, it is a help resource, not a management screen.
        'knowledge_base' => 'calendar.view',
        'student_records' => 'students.view',
        'staff_records' => 'staff.view',
        'attendance_management' => 'attendance.view',
        'announcements' => 'communication.view',
        'parent_broadcast' => 'communication.view',
        'school_houses' => 'students.view',
        'school_sections' => 'academics.view',
        'classes_arms' => 'academics.view',
        'subject_management' => 'academics.view',
        'academic_sessions_terms' => 'academics.view',
        'continuous_assessment' => 'examinations.view',
        // Was 'examinations.view' (found live 2026-07-22: a subject
        // teacher, who holds examinations.view for entering scores but
        // no report_cards.* grant at all, still saw "Report Cards" in
        // their nav via this looser mapping, landing on a screen with
        // nothing they could actually open, "it ought not to be a menu
        // option for subject teachers at all"). result_management and
        // basic_report_cards both point at the exact same
        // school.report-cards.index screen, so they must require the
        // same real permission that screen itself checks.
        'result_management' => 'report_cards.view',
        'basic_report_cards' => 'report_cards.view',
        'advanced_report_cards' => 'report_cards.view',
        'student_reports' => 'examinations.view',
        'unlimited_grading_systems' => 'examinations.view',
        'student_promotion' => 'academics.view',
        'school_profile' => 'school_info.view',
        'school_branding' => 'school_info.update',
        'academic_insights' => 'examinations.approve_results',
        // Subject Insights (2026-07-22 user request: "report and analytics
        // should have subject insights for class teacher and subject
        // teachers"): scoped to a teacher's own TeachingAssignment pairs,
        // so the gate is the same examinations.view every class/subject
        // teacher already holds, distinct from Academic Insights above
        // which is the school wide, leadership only view.
        'subject_insights' => 'examinations.view',
        'teacher_workload' => 'staff.view',
        'timetable_management' => 'timetable.view',
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
    | Role slugs that get the business wide "leadership" dashboard view
    | (RoleDashboard::admin()) rather than a narrower operational one.
    | School Admin is deliberately excluded here (it gets its own
    | day to day operational dashboard, RoleDashboard::schoolAdministrator())
    | but IS still an admin tier role for platform wide counts like
    | "total administrators" (PlatformDashboardController), which adds it
    | back on top of this list rather than duplicating the base set.
    | No-hardcoding audit, 2026-07-13: this single source replaced two
    | independently hardcoded literal arrays that had drifted apart once
    | already (see the "how can i have 449 users and 447 administrators"
    | incident referenced in PlatformDashboardController).
    */
    'leadership_role_slugs' => ['school-owner', 'principal', 'head-teacher', 'vice-principal'],

    /*
    | Who sees the subscription countdown/popup and its notifications
    | (user directive 2026-07-17: "school owners, head teacher, asst
    | headteacher, principal and vice principal and school admins").
    | Deliberately its own list, not leadership_role_slugs above: School
    | Owner and School Administrator are two different real roles (see
    | RoleDashboard's own dispatch comment), and School Administrator is
    | explicitly named here even though it is excluded from the
    | "leadership" dashboard bucket. No separate "Assistant Head
    | Teacher" role exists in config('skulos.roles'), Vice Principal
    | already IS that role under its secondary school naming (the same
    | pairing RoleDashboard's leadership bucket already treats
    | Principal/Head Teacher and Vice Principal as parallel, not
    | additional, titles).
    */
    'subscription_visible_role_slugs' => ['school-owner', 'principal', 'head-teacher', 'vice-principal', 'school-admin'],

    /*
    | Self-hosted licensing (user request 2026-07-18): whether THIS
    | deployment is a school's own self-hosted copy of the codebase,
    | rather than a tenant on the main SKULOS platform. Set only via
    | env on a self-hosted install's own server, never toggled at
    | runtime, since it decides whether App\Console\Commands\
    | CheckSelfHostedLicense actually does anything (see
    | App\Services\Licensing\LicenseService::checkSelfHosted()).
    | `endpoint` is the main platform's public license-verify API
    | (App\Http\Controllers\LicenseVerifyController); `grace_days` is
    | how long a failed/unreachable check-in is tolerated with a
    | warning banner (Layouts\app.blade.php) before full lockout via
    | School::SUSPENSION_REASON_LICENSE_EXPIRED.
    */
    'self_hosted' => env('SKULOS_SELF_HOSTED', false),
    'self_hosted_license' => [
        'endpoint' => env('SKULOS_LICENSE_ENDPOINT', 'https://skulos.app/api/license/verify'),
        'grace_days' => (int) env('SKULOS_LICENSE_GRACE_DAYS', 14),
    ],

    /*
    | Who a platform-owner broadcast reaches within each matched school
    | (messaging increment 3, 2026-07-15, user directive: "THE PLATFORM
    | OWNER CAN SEND BROADCAST OR MESSAGE TO ALL SCHOOLS OWNSERS, ADMIN OR
    | ALL USERS, THIS WILL BE SELECTED"). "All users" (every teacher,
    | parent, student across every school) was explicitly deferred by the
    | user's own follow-up answer ("School owners + admins first") so is
    | not a selectable option yet. "leadership" preserves the original
    | Send Broadcast tool's reach (built 2026-07-13, before this option
    | existed) so it stays available and is the default when a caller
    | omits the field entirely (BroadcastController::send()).
    */
    'platform_message_audiences' => [
        'owners' => 'School Owners',
        'admins' => 'School Admins',
        'owners_and_admins' => 'School Owners & Admins',
        'leadership' => 'Leadership tier & Admin (Owner, Principal, Head Teacher, Vice Principal, Admin)',
    ],
    'platform_message_audience_roles' => [
        'owners' => ['school-owner'],
        'admins' => ['school-admin'],
        'owners_and_admins' => ['school-owner', 'school-admin'],
        'leadership' => ['school-owner', 'principal', 'head-teacher', 'vice-principal', 'school-admin'],
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
            'examinations.*', 'report_cards.*', 'timetable.*', 'assignments.*', 'calendar.*',
            'communication.*', 'analytics.*',
        ]],
        'registrar' => ['name' => 'Registrar', 'grants' => [
            'students.*', 'parents.*', 'academics.view', 'analytics.view',
        ]],
        'examination-officer' => ['name' => 'Examination Officer', 'grants' => [
            'examinations.*', 'report_cards.*', 'students.view', 'academics.view', 'analytics.view',
        ]],
        'bursar' => ['name' => 'Bursar', 'grants' => [
            'students.view', 'parents.view', 'analytics.view', 'finance.*',
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
            'timetable.view', 'assignments.view', 'assignments.create', 'assignments.update', 'assignments.delete',
            'calendar.view', 'communication.view', 'communication.create',
        ]],
        'class-teacher' => ['name' => 'Class Teacher', 'grants' => [
            'students.view', 'students.update', 'attendance.*',
            'examinations.view', 'examinations.create', 'examinations.update',
            'report_cards.view', 'report_cards.create', 'report_cards.update',
            'timetable.view', 'assignments.view', 'assignments.create', 'assignments.update', 'assignments.delete',
            'calendar.view', 'communication.view', 'communication.create',
        ]],
        'subject-teacher' => ['name' => 'Subject Teacher', 'grants' => [
            'students.view', 'attendance.view',
            'examinations.view', 'examinations.create', 'examinations.update',
            'timetable.view', 'assignments.view', 'assignments.create', 'assignments.update', 'assignments.delete',
            'calendar.view', 'communication.view',
        ]],
        'librarian' => ['name' => 'Librarian', 'grants' => ['library.*', 'students.view', 'staff.view']],
        'nurse' => ['name' => 'Nurse', 'grants' => ['medical.*', 'students.view']],
        'store-keeper' => ['name' => 'Store Keeper', 'grants' => ['inventory.*']],
        'accountant' => ['name' => 'Accountant', 'grants' => ['students.view', 'parents.view', 'analytics.view', 'finance.*']],
        'class-assistant' => ['name' => 'Class Assistant', 'grants' => [
            'students.view', 'attendance.view', 'attendance.create',
            'timetable.view', 'assignments.view', 'calendar.view', 'communication.view',
        ]],
        'parent' => ['name' => 'Parent', 'grants' => [
            'calendar.view', 'communication.view', 'timetable.view', 'assignments.view', 'students.view',
            'finance.view', 'finance.create',
        ]],
        'student' => ['name' => 'Student', 'grants' => [
            'calendar.view', 'communication.view', 'timetable.view', 'assignments.view', 'students.view',
            'finance.view', 'finance.create',
        ]],
        'security' => ['name' => 'Security', 'grants' => []],
        'hostel-master' => ['name' => 'Hostel Master', 'grants' => ['hostel.*', 'students.view']],
        'transport-officer' => ['name' => 'Transport Officer', 'grants' => ['transport.*', 'students.view']],
    ],
];
