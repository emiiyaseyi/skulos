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
        // SKULOS Assessment Hub, Phase 2 (2026-07-31): a shared question
        // bank + timed test-taking engine, distinct from the older
        // AssessmentComponent/AssessmentController concept under
        // Examinations (CA-vs-Exam weighting for report cards, not this).
        'assessment_hub' => ['name' => 'Assessment Hub', 'core' => false],
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
    | Bridges the two independent ways a school ever gets an optional
    | module: a full Plan change (Plan::features, checked by School::
    | hasFeature()/hasAnyFeatureInCategory(), what the nav below was
    | originally built against), and a direct per-school module toggle
    | (BillingController::toggleFeature() -> Rbac::setModuleState(),
    | Billing's "Toggle feature" action on one school, no plan change at
    | all). These are genuinely different mechanisms with no shared
    | column, so a module enabled only the second way used to never
    | appear in nav at all, regardless of built screens behind it (found
    | live 2026-07-31: the user toggled 'admissions' this way on a real
    | school and it stayed invisible). Only optional (non-core) plan_
    | feature_catalog categories that map to exactly one RBAC module need
    | an entry here; core categories bundle several always-on modules and
    | have no single toggle to fall back to.
    */
    'feature_category_module_map' => [
        'admissions' => 'admissions',
        'finance' => 'finance',
        'learning' => 'lms',
        'human_resources' => 'hr',
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
        // For Parents (2026-07-26, user report: "Help & Knowledge Base
        // for parent is totally wrong... update it to show items related
        // to parents"). Every article here is something a parent
        // genuinely does on SKULOS, none of the school administration
        // content above ever applied to them in the first place.
        // hidden_for_teacher_scoped on every one (2026-07-28, same user
        // report as "For Teachers" above: this category was confirmed
        // leaking the OTHER way too, showing "Viewing your child's
        // academic report" etc. to a real class teacher, since a
        // teacher also genuinely holds students.view/communication.view
        // for their own unrelated reason).
        'For Parents' => [
            [
                'title' => "Viewing your child's academic report",
                'body' => "Each child's profile shows their current term scores, remarks, and attendance for that term.",
                'steps' => [
                    'Go to Students.',
                    'Open your child\'s profile (if you have more than one child, pick which one first).',
                    "The Academic Report card shows this term's subject scores, remarks, and attendance.",
                    'A full Report Card is also available from the same profile once the school has released it.',
                ],
                'start_route' => 'school.students',
                'hidden_for_teacher_scoped' => true,
                // family_only (2026-08-05, real bug found live: a bursar,
                // who genuinely holds students.view for a completely
                // unrelated reason, was seeing this "For Parents" article
                // — hidden_for_teacher_scoped/hidden_for_family_scoped
                // above can only ever HIDE from those two specific
                // scopes, they cannot RESTRICT an article to being shown
                // ONLY to one. Every non-generic "For Parents"/"For
                // Teachers" article needs this positive flag, not just
                // the negative exclusions.
                'family_only' => true,
            ],
            [
                'title' => "Paying your child's fees",
                'body' => 'Payment is by bank transfer: send the money, then upload your evidence here for the school to verify.',
                'steps' => [
                    'Go to Finance > Payments > Submit Payment.',
                    "Send the transfer to one of the school's bank accounts shown on the page.",
                    'Choose which child (or children, if you are covering more than one) and what the payment is for, or use one of the All Fees options to pay everything at once.',
                    'Upload a screenshot or PDF of your transfer as evidence, then submit. You will be notified once the school verifies it.',
                ],
                'start_route' => 'school.finance.payments.create',
                'hidden_for_teacher_scoped' => true,
                'family_only' => true,
            ],
            [
                'title' => 'Checking your payment history',
                'body' => 'Every payment you have submitted, and whether it is still pending, verified, or was rejected.',
                'steps' => [
                    'Go to Finance > Payments.',
                    'Each row shows the child, amount, and current status.',
                    'Open any payment to see its full detail, including your uploaded evidence.',
                ],
                'start_route' => 'school.finance.payments.index',
                'hidden_for_teacher_scoped' => true,
                'family_only' => true,
            ],
            [
                // Deliberately NOT hidden_for_teacher_scoped: unlike the
                // 3 above (worded "your child", genuinely parent
                // exclusive), this content is generic and equally true
                // for a teacher, hiding it would remove real content
                // rather than fix a leak.
                'title' => 'Reading school announcements',
                'body' => 'Every announcement sent to you, most recent first.',
                'steps' => [
                    'Go to Announcements.',
                    'Tap any row to read the full announcement.',
                    'A new announcement also arrives as a notification.',
                ],
                'start_route' => 'school.announcements.index',
            ],
            [
                // Same reasoning: generic, universal content, not
                // hidden_for_teacher_scoped.
                'title' => 'Understanding your notifications',
                'body' => 'The bell icon at the top of every screen, and the full Notification Center for everything at once.',
                'steps' => [
                    'Tap the bell icon at the top of any screen for a quick preview of your recent notifications.',
                    'Go to Notifications for the full list, including read and archived ones.',
                    'Pin an important notification so it always stays at the top.',
                ],
                'start_route' => 'school.notifications.index',
            ],
        ],
        // For Teachers (2026-07-28, user request: "Help & Knowledge Base
        // role-relevance for OTHER roles" — the same shape of fix "For
        // Parents" above already got: a class/subject teacher's Help
        // list was showing the same admin only articles above, now
        // hidden via hidden_for_teacher_scoped, with genuinely
        // teacher-relevant content here instead. "Taking attendance" is
        // deliberately NOT duplicated here, the existing "Attendance &
        // Timetable" category's own article already covers it and was
        // never hidden from a teacher in the first place.
        'For Teachers' => [
            [
                'title' => 'Entering scores for your subject',
                'body' => 'Enter each student\'s score per assessment component for a class and subject.',
                'steps' => [
                    'Go to Enter Scores.',
                    'Pick the class, subject, and term.',
                    'Enter each student\'s score per component. The total, grade, and a suggested remark (if turned on) update live as you type.',
                    'Scores save automatically a few seconds after you stop typing, or use the Save button.',
                ],
                'start_route' => 'school.examinations.index',
                // hidden_for_family_scoped (2026-07-28, user report: "why
                // are parents seeing for teachers knowledge under their
                // own help base"): a route-level hide can't be used here,
                // school.timetable.mine/school.chat.index are ALSO
                // legitimately reachable by a parent for their own
                // reason, so the route itself must stay unhidden while
                // this specific, teacher-voiced ARTICLE is hidden from a
                // family-scoped viewer regardless.
                'hidden_for_family_scoped' => true,
                // teacher_only (2026-08-05, real bug found live: a bursar,
                // who holds no permission this route even checks, was
                // still NOT the actual leak vector here — but a
                // registrar/secretary/ict-officer role sharing
                // examinations.view for a completely different reason
                // would have been. This positive restriction closes that
                // for every current and future non-teacher role, the
                // same reasoning as family_only above.
                'teacher_only' => true,
            ],
            [
                'title' => 'Viewing your timetable',
                'body' => 'Your own weekly teaching schedule, or your class\'s full grid if you are a class teacher.',
                'steps' => [
                    'Go to My Timetable.',
                    'A class teacher can switch between their own teaching schedule and their class\'s full grid.',
                    'Export or print the grid from the same page.',
                ],
                'start_route' => 'school.timetable.mine',
                'hidden_for_family_scoped' => true,
                'teacher_only' => true,
            ],
            [
                'title' => "Messaging a student's guardian",
                'body' => "A direct message to a guardian of one of your students, or a guardian messaging you back.",
                'steps' => [
                    'Go to Messages.',
                    "Pick a guardian from one of your students, or open an existing conversation.",
                    'Send your message. The guardian is notified.',
                ],
                'start_route' => 'school.chat.index',
                'hidden_for_family_scoped' => true,
                'teacher_only' => true,
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
        'For Parents' => 'parents',
        'For Teachers' => 'staff',
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
    |
    | "hidden_for_family_scoped" (added 2026-07-26, user report: "Help &
    | Knowledge Base for parent is totally wrong, why is it showing how
    | to set up house, communication... update it to show items related
    | to parents") catches every article below, all of them genuinely
    | staff/admin workflows, none meant for a parent or student, that the
    | permission check alone lets through anyway because a parent/student
    | happens to hold the same broad permission for a completely
    | different, narrower reason (their own child only, via FamilyScope),
    | the exact same "shares students.view but isn't really for them"
    | shape nav-school.blade.php's own $alwaysHiddenForScoped list
    | already handles for the sidebar. Mirrors that same convention here
    | rather than inventing a new one.
    */
    'knowledge_base_start_routes' => [
        // hidden_for_teacher_scoped (added 2026-07-28, user request:
        // "Help & Knowledge Base role-relevance for OTHER roles" —
        // teacher_scoped_roles, config('skulos.teacher_scoped_roles'),
        // share the same broad permission codes these admin/leadership
        // screens check for a completely different, narrower reason
        // (their own class/subject only), the identical shape
        // hidden_for_family_scoped already fixed for parents/students
        // above. 'school.attendance.index' is deliberately the one
        // exception left visible to teachers: taking attendance IS a
        // genuine class teacher task, not an admin-only workflow.
        'school.roles.index' => ['label' => 'User & Role Management', 'permission' => 'staff.update', 'hidden_for_family_scoped' => true, 'hidden_for_teacher_scoped' => true],
        'school.students.create' => ['label' => 'Add a Student', 'permission' => 'students.create', 'hidden_for_family_scoped' => true, 'hidden_for_teacher_scoped' => true],
        'school.students.import' => ['label' => 'Import Students', 'permission' => 'students.bulk', 'hidden_for_family_scoped' => true, 'hidden_for_teacher_scoped' => true],
        'school.students.settings.edit' => ['label' => 'Student Field Settings', 'permission' => 'students.manage_settings', 'hidden_for_family_scoped' => true, 'hidden_for_teacher_scoped' => true],
        'school.houses.index' => ['label' => 'Houses', 'permission' => 'students.view', 'hidden_for_family_scoped' => true, 'hidden_for_teacher_scoped' => true],
        'school.settings.custom-fields.index' => ['label' => 'Custom Fields', 'permission' => 'school_info.manage_settings', 'hidden_for_family_scoped' => true, 'hidden_for_teacher_scoped' => true],
        'school.attendance.index' => ['label' => 'Take Attendance', 'permission' => 'attendance.view', 'hidden_for_family_scoped' => true],
        'school.timetable.periods' => ['label' => 'Timetable Periods', 'permission' => 'timetable.update', 'hidden_for_family_scoped' => true, 'hidden_for_teacher_scoped' => true],
        'school.timetable.exam.index' => ['label' => 'Examination Timetable', 'permission' => 'timetable.update', 'hidden_for_family_scoped' => true, 'hidden_for_teacher_scoped' => true],
        'school.examinations.structure' => ['label' => 'Assessment Structure', 'permission' => 'examinations.view', 'hidden_for_family_scoped' => true, 'hidden_for_teacher_scoped' => true],
        'school.examinations.grading' => ['label' => 'Grading', 'permission' => 'examinations.view', 'hidden_for_family_scoped' => true, 'hidden_for_teacher_scoped' => true],
        'school.report-cards.layout.edit' => ['label' => 'Report Card Layout Designer', 'permission' => 'report_cards.view', 'hidden_for_family_scoped' => true, 'hidden_for_teacher_scoped' => true],
        'school.report-cards.pending-review' => ['label' => 'Report Cards Pending Review', 'permission' => 'report_cards.assign_review', 'hidden_for_family_scoped' => true, 'hidden_for_teacher_scoped' => true],
        'school.announcements.create' => ['label' => 'Compose an Announcement', 'permission' => 'communication.broadcast', 'hidden_for_family_scoped' => true, 'hidden_for_teacher_scoped' => true],
        'school.announcements.history' => ['label' => 'Communication History', 'permission' => 'communication.view', 'hidden_for_family_scoped' => true, 'hidden_for_teacher_scoped' => true],
        'school.reports.builder.index' => ['label' => 'Report Builder', 'permission' => 'analytics.view', 'hidden_for_family_scoped' => true, 'hidden_for_teacher_scoped' => true],
        'school.examinations.academic-insights' => ['label' => 'Academic Insights', 'permission' => 'examinations.approve_results', 'hidden_for_family_scoped' => true, 'hidden_for_teacher_scoped' => true],
        // Parent/student relevant destinations (2026-07-26), every one
        // reachable with only the permissions the parent/student role
        // already holds, no {studentId}-style extra route parameter
        // (the "Start here" button only ever supplies {school}).
        'school.students' => ['label' => 'Students', 'permission' => 'students.view'],
        'school.finance.payments.create' => ['label' => 'Submit Payment', 'permission' => 'finance.create'],
        'school.finance.payments.index' => ['label' => 'Payments', 'permission' => 'finance.view'],
        'school.announcements.index' => ['label' => 'Announcements', 'permission' => 'communication.view'],
        // Notifications are personal, signed-in membership is the only
        // gate (NotificationController's own doc comment), no permission
        // to check.
        'school.notifications.index' => ['label' => 'Notifications'],
        // Teacher relevant destinations (2026-07-28), same shape as the
        // parent/student block above: no {classId}/{studentId}-style
        // extra route parameter, so the "Start here" button always
        // resolves with just {school}.
        'school.examinations.index' => ['label' => 'Enter Scores', 'permission' => 'examinations.view'],
        'school.timetable.mine' => ['label' => 'My Timetable', 'permission' => 'timetable.view'],
        'school.chat.index' => ['label' => 'Messages', 'permission' => 'communication.view'],
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
    /*
    | Demo school shown to a school approved for a trial (2026-08-16).
    | Config rather than baked into the email template so the accounts
    | can be rotated, or pointed at a different demo school, without a
    | code change to the mail itself. Passwords here are deliberately
    | the shared, read-only demo logins -- these accounts cannot save,
    | edit, or change their own password (BlockDemoAccountMutations),
    | so they are safe to put in an email.
    */
    /*
    | Every email the platform sends, and the placeholders each one can
    | use (2026-08-16). Drives the Mail Templates editor: the catalog
    | says what exists and what may be substituted, the mail_templates
    | table holds whatever the owner has edited, and the Mailable's own
    | code remains the fallback when nothing has been edited.
    |
    | "seed" is the starting copy an editor sees, matching what that
    | mail says today. Editing one row never affects any other mail.
    */
    'mail_templates' => [
        'TrialRequestStatusMail' => [
            'label' => 'Trial approved',
            'description' => "Sent when a school's trial request is approved, with demo access and the setup checklist.",
            'placeholders' => ['school_name', 'contact_name', 'portal_url', 'login_email', 'temporary_password', 'demo_url'],
            'seed_subject' => 'SKULOS Trial Access and Setup Requirements',
            // The FULL letter, matching resources/views/emails/trial-approved.blade.php
            // line for line (2026-08-16: the seed shipped as an
            // abbreviated summary at first, which meant an admin editing
            // this template was editing a shorter letter than the one
            // schools actually receive). "## " is a heading, "- " is a
            // bullet list item -- see MailTemplate::renderBody().
            'seed_body' => "Dear {school_name},

Thank you for your interest in SKULOS.

We are pleased to approve your school for the SKULOS trial. The trial will give your team the opportunity to explore the platform, test the different workflows, and provide feedback that will help us tailor the solution to your school's needs.

## Demo Access
Demo School: {demo_url}

You may explore the different user roles and workflows available on the platform.

## Information to Prepare for Your School Setup
To make the setup process smooth and allow us to configure SKULOS around your school's structure, kindly have the following information ready:

## School Information
- School name and logo
- School motto or slogan
- Full school address
- Official email address
- Phone numbers
- School website, if applicable

## Academic Structure
- Academic sections operated by the school
- Classes and arms
- Subjects offered under each class
- School calendar and important dates
- School houses and clubs, where applicable

## Student Information
- Student admission or serial number
- Firstname
- Lastname
- Gender
- Date of birth
- Class and arm
- Session of admission
- Parent or guardian details
- House
- Other relevant student information maintained by the school

## Staff Information
- Staff ID
- Firstname
- Lastname
- Gender
- Role or position
- Subjects taught
- Classes assigned
- Class teacher or subject teacher
- Email
- Phone number

## Assessment and Results
- Current assessment structure
- Continuous Assessment format
- Examination structure
- Grading system
- Report card or sample result template
- Existing teacher or class teacher comment formats, if available

## Parents and Communication
- Parent or guardian contact information
- Preferred communication channels
- Any specific communication or approval workflows

## Other Information
- Admission requirements and process
- Fee structure, if you would like to test the finance module
- Timetable structure
- School policies or workflows you would like SKULOS to accommodate

You do not need to prepare everything before the trial begins. We can work through the requirements together and prioritise the information needed for the initial setup.

We can also schedule a session to present SKULOS to your team, walk through the major features, answer your questions, and discuss how the platform can be configured to suit your school's operations.

## Scheduling Your Demo
To arrange the session, please reply with:
- A preferred date and time, and an alternative if possible
- The full address of the school, if the session is to hold on site
- Roughly how many people will attend, and their roles
- Whether you would prefer to meet in person or online

We look forward to working with you and receiving your feedback during the trial.

Kind regards,
SKUL Operating System",
        ],
        'SchoolWelcomeMail' => [
            'label' => 'School welcome',
            'description' => 'Sent when the platform owner creates a school directly, rather than approving a trial.',
            'placeholders' => ['school_name', 'portal_url', 'login_email', 'temporary_password', 'plan_name', 'demo_url'],
            'seed_subject' => 'Welcome to SKULOS: Your School Access and Setup',
            // Reuses the same full onboarding letter TrialRequestStatusMail
            // sends (2026-08-16, same reason: an admin editing this one
            // was seeing a much shorter letter than schools actually get),
            // with only the opening line and the plan line differing --
            // this school did not ask for a trial, someone set it up
            // for them.
            'seed_body' => "Dear {school_name},

Your school has been set up on SKULOS and is ready to use.

Your plan: {plan_name}

## Demo Access
Demo School: {demo_url}

You may explore the different user roles and workflows available on the platform.

## Information to Prepare for Your School Setup
To make the setup process smooth and allow us to configure SKULOS around your school's structure, kindly have the following information ready:

## School Information
- School name and logo
- School motto or slogan
- Full school address
- Official email address
- Phone numbers
- School website, if applicable

## Academic Structure
- Academic sections operated by the school
- Classes and arms
- Subjects offered under each class
- School calendar and important dates
- School houses and clubs, where applicable

## Student Information
- Student admission or serial number
- Firstname
- Lastname
- Gender
- Date of birth
- Class and arm
- Session of admission
- Parent or guardian details
- House
- Other relevant student information maintained by the school

## Staff Information
- Staff ID
- Firstname
- Lastname
- Gender
- Role or position
- Subjects taught
- Classes assigned
- Class teacher or subject teacher
- Email
- Phone number

## Assessment and Results
- Current assessment structure
- Continuous Assessment format
- Examination structure
- Grading system
- Report card or sample result template
- Existing teacher or class teacher comment formats, if available

## Parents and Communication
- Parent or guardian contact information
- Preferred communication channels
- Any specific communication or approval workflows

## Other Information
- Admission requirements and process
- Fee structure, if you would like to test the finance module
- Timetable structure
- School policies or workflows you would like SKULOS to accommodate

You do not need to prepare everything before the trial begins. We can work through the requirements together and prioritise the information needed for the initial setup.

Kind regards,
SKUL Operating System",
        ],
        'PasswordResetLinkMail' => [
            'label' => 'Password reset link',
            'description' => 'Sent when a user asks to reset their own password.',
            'placeholders' => ['name', 'reset_url', 'school_name'],
            'seed_subject' => 'Reset your SKULOS password',
            'seed_body' => "Hello {name},

We received a request to reset your SKULOS password. Use the button below to choose a new one.

If you did not ask for this, you can ignore this email and your password will stay as it is.",
        ],
        'PasswordResetByAdminMail' => [
            'label' => 'Password reset by an administrator',
            'description' => "Sent when an administrator resets someone's password on their behalf.",
            'placeholders' => ['name', 'school_name', 'temporary_password', 'portal_url'],
            'seed_subject' => 'Your SKULOS password has been reset',
            'seed_body' => "Hello {name},

An administrator at {school_name} has reset your password. Sign in with the temporary password below, and you will be asked to choose a new one.",
        ],
        'AccountCreatedMail' => [
            'label' => 'Account created',
            'description' => 'Sent to a parent, student or staff member when their login is created.',
            'placeholders' => ['name', 'school_name', 'login_email', 'temporary_password', 'portal_url'],
            'seed_subject' => 'Your SKULOS account is ready',
            'seed_body' => "Hello {name},

An account has been created for you at {school_name}.

Sign in at {portal_url} using {login_email} and the temporary password below. You will be asked to change it the first time you sign in.",
        ],
        'AdmissionOfferMail' => [
            'label' => 'Admission offer',
            'description' => 'Sent to an applicant when a school offers them admission.',
            'placeholders' => ['applicant_name', 'school_name', 'class_name', 'portal_url'],
            'seed_subject' => 'Admission offer from {school_name}',
            'seed_body' => "Dear Parent/Guardian,

We are pleased to offer {applicant_name} admission to {school_name}.

Sign in to the admissions portal to read the offer letter, accept the offer, and complete the next steps.",
        ],
        'AdmissionCredentialsMail' => [
            'label' => 'Admission portal credentials',
            'description' => 'Sent to an applicant once their application fee is verified.',
            'placeholders' => ['applicant_name', 'school_name', 'reference_number', 'temporary_password', 'portal_url'],
            'seed_subject' => 'Your application reference for {school_name}',
            'seed_body' => "Dear Parent/Guardian,

Your application for {applicant_name} at {school_name} has been received.

Use the reference number and password below to sign in and continue the application.",
        ],
        'ContactFormMail' => [
            'label' => 'Contact form enquiry',
            'description' => 'Sent to the platform support address when someone submits the website contact form.',
            'placeholders' => ['sender_name', 'sender_school', 'sender_email', 'sender_message'],
            'seed_subject' => 'New contact form message from {sender_name}',
            'seed_body' => "Name: {sender_name}
School: {sender_school}
Email: {sender_email}

Message:
{sender_message}",
        ],
        'SchoolFollowUpNudgeMail' => [
            'label' => 'Follow-up nudge',
            'description' => 'Sent manually from Trial Requests or the Schools list, checking in on a school that has not been heard from since its welcome mail.',
            'placeholders' => ['school_name', 'contact_name', 'portal_url'],
            'seed_subject' => "Checking in on {school_name}'s SKULOS setup",
            'seed_body' => "Hello {contact_name},

We wanted to check in on {school_name}'s SKULOS setup. We sent you an email earlier with your sign in details and the information we would need to get everything ready.

If you have run into any issue at all, or have any questions, just reply to this email and we will help you sort it out. We would love to get {school_name} fully set up and ready to go ahead of the new term.",
        ],
    ],

    'demo' => [
        'url' => env('SKULOS_DEMO_URL', 'https://greenfield.skulosapp.com'),
        'accounts' => [
            ['role' => 'School Admin', 'email' => env('SKULOS_DEMO_ADMIN', 'demo.admin@skulosapp.com'), 'password' => env('SKULOS_DEMO_PASSWORD', 'password')],
            ['role' => 'Class Teacher', 'email' => 'uju.balogun@outlook.com', 'password' => env('SKULOS_DEMO_PASSWORD', 'password')],
            ['role' => 'Subject Teacher', 'email' => 'chidinma.akintola@yahoo.com', 'password' => env('SKULOS_DEMO_PASSWORD', 'password')],
            ['role' => 'Parent', 'email' => 'tunde.yusuf@gmail.com', 'password' => env('SKULOS_DEMO_PASSWORD', 'password')],
            ['role' => 'Student', 'email' => env('SKULOS_DEMO_STUDENT', 'demo.student@skulosapp.com'), 'password' => env('SKULOS_DEMO_PASSWORD', 'password')],
        ],
    ],

    'support' => [
        'email' => env('SKULOS_SUPPORT_EMAIL', 'info@skulosapp.com'),
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
    | Admission pipeline (Admissions module, Phase 1, user directive
    | 2026-07-30: "Admission Pipeline... think like a CRM", an ordered
    | sequence an applicant moves through, same configurable-chain shape
    | as the report card review chain above (Applicant::pipelineChain()).
    | "admission_pipeline_stages" is the picklist an admin builds a chain
    | from; "default_admission_pipeline_chain" is what a school starts
    | with (every stage, in the natural order). A school can trim optional
    | stages (e.g. skip Interview) but not reorder around the ones later
    | sub-phases attach real behaviour to (fee payment, document upload,
    | assessment), so unlike the report card chain this one is a
    | toggleable subset of a fixed order, not a free reorder.
    */
    'admission_pipeline_stages' => [
        'application_started' => 'Application Started',
        'application_submitted' => 'Application Submitted',
        'documents_uploaded' => 'Documents Uploaded',
        // SKULOS Assessment Hub, Phase 2 (live user report: "the
        // admission assessment is missing from the admission
        // pipeline"). Not yet auto-triggered — the stage exists as a
        // real, reorderable pipeline entry now; actually starting a
        // CbtSession for an Applicant when they reach it is flagged as
        // separate, later Admissions-side wiring (see the Phase 2
        // plan's own "explicitly still deferred" section).
        'admission_assessment' => 'Admission Assessment',
        'interview_scheduled' => 'Interview Scheduled',
        'interview_completed' => 'Interview Completed',
        'admission_offered' => 'Admission Offered',
        'acceptance_fee_paid' => 'Acceptance Fee Paid',
        'enrolled' => 'Enrolled',
    ],
    'default_admission_pipeline_chain' => [
        'application_started', 'application_submitted', 'documents_uploaded',
        'admission_assessment', 'interview_scheduled', 'interview_completed', 'admission_offered',
        'acceptance_fee_paid', 'enrolled',
    ],

    /*
    | Seeded once into a real tenant table (AdmissionFormSection::
    | ensureSeeded(), same "config feeds a one-time DB seed" pattern as
    | grading_scales/comment_bank) the first time any school's Admission
    | Form Builder or applicant-facing form is touched, so a freshly
    | provisioned school has a real, sensible starter form instead of a
    | blank one (user directive 2026-07-31: "the form builder should be
    | pre loaded with current form currently used by all schools"). Every
    | field here is deliberately optional — a starting point an admin is
    | free to edit or remove, not a new hard requirement on applicants.
    | Core identity fields (name, DOB, phone, email) are already
    | collected on the apply form itself (Phase 1c), not duplicated here.
    */
    'default_admission_form_sections' => [
        ['label' => 'Learner Details', 'step' => 1, 'fields' => [
            ['label' => 'Previous School', 'type' => 'text'],
            // 'geo': the same reusable Country/State/LGA cascading picker
            // (with search) Student/Staff forms already use, not a plain
            // text field — see AdmissionFormField::TYPES's own docblock.
            ['label' => 'State of Origin', 'type' => 'geo'],
            ['label' => 'Religion', 'type' => 'select', 'options' => ['Christianity', 'Islam', 'Other']],
            ['label' => 'Blood Group', 'type' => 'select', 'options' => ['A', 'B', 'AB', 'O']],
        ]],
        ['label' => 'Guardian Details', 'step' => 2, 'fields' => [
            ['label' => 'Guardian Role', 'type' => 'select', 'options' => ['Father', 'Mother', 'Sponsor']],
            // Phone required (user directive 2026-07-31: "phone number is
            // mandatory for each one entered") — the primary guardian is
            // never optional, unlike the second guardian slot below,
            // whose own fields all stay optional since the whole section
            // is: conditional-required (only if a second guardian is
            // actually being filled in) is a real rules engine, already
            // out of scope for this EAV shape, see AdmissionFormSection's
            // own docblock precedent.
            ['label' => 'Phone Number', 'type' => 'text', 'required' => true],
            ['label' => 'Email Address', 'type' => 'text'],
            ['label' => "Guardian's Occupation", 'type' => 'text'],
            ['label' => 'Home Address', 'type' => 'textarea'],
        ]],
        // A bounded second slot, not a free-repeat "add guardian" button
        // (user directive 2026-07-31: "applicant can add another guardian
        // if needed") — a real dynamic repeater is a bigger structural
        // change to this EAV shape (one value per applicant+field, no
        // group/instance concept) than a default-seed content fix
        // warrants; this covers the common two-guardian case plainly.
        ['label' => 'Second Guardian (optional)', 'step' => 2, 'fields' => [
            ['label' => "Guardian's Full Name", 'type' => 'text'],
            ['label' => 'Guardian Role', 'type' => 'select', 'options' => ['Father', 'Mother', 'Sponsor']],
            ['label' => 'Phone Number', 'type' => 'text'],
            ['label' => 'Email Address', 'type' => 'text'],
            ['label' => "Guardian's Occupation", 'type' => 'text'],
        ]],
    ],

    /*
    | How long an applicant's account stays usable after credentials are
    | issued (user directive 2026-07-30: "APPLICANT WILL HAVE A TEMPORARY
    | WINDOW... BY DEFAULT 1 MONTH AND USER IS LOCK OUT AND PROFILE IS
    | ARCHIVE"), admin-editable per school via the Admissions settings
    | screen (Setting key "admission_account_ttl_days"), this is only the
    | platform default a school starts with.
    */
    'default_admission_account_ttl_days' => 30,

    /*
    | Length of the randomly generated password issued once an
    | applicant's application fee clears (user directive 2026-07-31:
    | "admin determines how the password is generated automatically"),
    | admin-editable per school via the Admissions settings screen
    | (Setting key "admission_password_length"), clamped to 6-24 by
    | Applicant::issueCredentials() regardless of what a school sets.
    */
    'default_admission_password_length' => 10,

    /*
    | Not just how long the generated password is but what characters it
    | actually draws from (user directive 2026-07-31, after the length
    | setting alone wasn't enough: "admin still cant configure it (NOT
    | ONLY THE LENGTH BUT HOW IT LOOKS LIKE)"). "random" (the default,
    | reproducing this module's original Str::password() shape byte for
    | byte) mixes letters/numbers/symbols; "alphanumeric" drops symbols,
    | easier to read aloud/retype; "numeric" is digits only, a PIN a
    | young learner's parent can dictate over the phone without spelling
    | anything out.
    */
    'default_admission_password_style' => 'random',

    /*
    | How an applicant's admission_number/reference is built (user
    | directive 2026-07-31: "school abbreviation, class applied to and
    | autogenerated serial numbers OR admin enter a default text and the
    | system add autogenerated number to it"), admin-editable per school
    | via the Admissions settings screen (Setting keys
    | "admission_reference_style" ["custom"|"structured"] and
    | "admission_reference_prefix"). Defaults to "custom" with the exact
    | prefix this module always used before this setting existed
    | (APP/0001, APP/0002, ...), so an already-provisioned school's
    | reference format never silently changes underneath it.
    */
    'default_admission_reference_style' => 'custom',
    'default_admission_reference_prefix' => 'APP',

    /*
    | Admission/offer letter body copy (2026-08-06, user directive: "the
    | school admin can change the content of the admission or offer
    | letter"), admin-editable per school via the Admissions settings
    | screen (Setting keys "admission_letter_intro"/"admission_letter_
    | next_steps"). Support the placeholders AdmissionLetterContentService
    | replaces at render time — {applicant_name}/{school_name}/{class}/
    | {session} — so an admin can personalize wording without touching
    | code. Defaults reproduce the original fixed copy this module always
    | used before this setting existed, so an already-provisioned school's
    | letter never silently changes underneath it.
    */
    'default_admission_letter_intro' => 'We are pleased to inform you that, following review of your application, you have been offered admission to {school_name} for the {class} class, {session}.',
    'default_admission_letter_next_steps' => 'To secure this place, please sign in to your application on the school\'s admissions portal and formally accept this offer. Once accepted, you will be asked to pay the applicable school fees for {class}; your child\'s admission and class placement will be finalized once this is complete.',

    /*
    | Who can approve a new fee item before parents ever see it (user
    | request 2026-07-25: "admin can set an approval level for school
    | fees which the school owner, principal, vice principal, head
    | teacher, assistant head teacher or any other name from the staff
    | list"). No "assistant-head-teacher" role exists in role_catalog
    | below, a school that has one names that specific person instead via
    | the staff search the approval chain screen also offers, the same
    | escape hatch the user's own "or any other name from the staff
    | list" already asked for. Off by default (fee_approval_enabled
    | tenant Setting), a school that never turns it on keeps every fee
    | item auto-approved exactly as it always was.
    */
    'fee_approval_roles' => [
        'school-owner' => 'School Owner',
        'principal' => 'Principal',
        'vice-principal' => 'Vice Principal',
        'head-teacher' => 'Head Teacher',
    ],

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
        // Audit logs (2026-08-15). The "archive-not-delete" strategy the
        // comment above called for now exists, so these CAN finally be
        // pruned: AuditLogsExport already ships every row to durable
        // NDJSON hourly and records a watermark, and the monthly
        // archive mail sends the school its own CSV copy. Nothing is
        // ever deleted unless it is already past BOTH gates (exported,
        // and older than this window), so the trail is never silently
        // lost. Admin editable 7-30 days; a busy school picks a shorter
        // window, a quiet one keeps a month.
        'audit_logs_days' => env('RETENTION_AUDIT_LOGS_DAYS', 30),
    ],

    /*
    | Monthly audit trail archive mail. attachment_max_mb is capped by
    | what a RECIPIENT will accept, not what this server will send
    | (Gmail 25MB, Outlook 20MB), so anything larger is linked rather
    | than attached — a school with a heavy month still gets its data
    | instead of a silently bounced mail.
    */
    'audit_archive' => [
        // attach_csv defaults FALSE on purpose: the monthly mail sends a
        // download link behind the school's own login instead. The data
        // is never at risk either way (it is already on the server, both
        // as the hourly NDJSON export and as the archived CSV the link
        // points at), but a link is revocable, access controlled and
        // itself auditable, where an emailed attachment is permanent,
        // forwardable and sits on mail servers nobody here controls --
        // and an audit trail names who viewed which child's record,
        // exactly the kind of personal data NDPR/GDPR expects not to be
        // mailed around in the clear. A school that deliberately wants
        // attachments can turn this on and owns that decision.
        'attach_csv' => env('AUDIT_ARCHIVE_ATTACH_CSV', false),
        // Capped by what a RECIPIENT accepts (Gmail 25MB, Outlook 20MB),
        // not by what this server will send. Anything larger is linked
        // even when attach_csv is on, so a heavy month still gets
        // delivered instead of silently bouncing.
        'attachment_max_mb' => env('AUDIT_ARCHIVE_ATTACHMENT_MAX_MB', 15),
        'min_days' => 7,
        'max_days' => 30,
    ],

    /*
    | Backup retention (2026-08-12, "the single highest-value addition":
    | unlike everything else above, backups were never pruned at all —
    | every backup, per school and the platform's own, accumulated
    | forever until an admin manually deleted one). A backup is kept if
    | it satisfies EITHER rule (the more forgiving of the two, per
    | direct user choice): among the most recent keep_count backups, OR
    | newer than keep_days. Only deleted once it fails BOTH. Whether a
    | manually-triggered backup is exempt from this or pruned the same
    | as a scheduled one is itself admin-editable (prune_manual), not a
    | hardcoded choice either way, per direct user instruction ("make it
    | both, admin can decide").
    */
    'backup_retention' => [
        'keep_count' => env('BACKUP_RETENTION_KEEP_COUNT', 10),
        'keep_days' => env('BACKUP_RETENTION_KEEP_DAYS', 90),
        'prune_manual' => env('BACKUP_RETENTION_PRUNE_MANUAL', false),
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
        'report_card_feedback' => ['label' => 'Report card feedback', 'type' => 'academic'],
        'report_card_released' => ['label' => 'Report card released', 'type' => 'academic'],
        'birthday' => ['label' => 'Birthday', 'type' => 'academic'],
        'anniversary' => ['label' => 'Work anniversary', 'type' => 'academic'],
        'fee_item_pending_approval' => ['label' => 'Fee item awaiting approval', 'type' => 'finance'],
        'payment_reminder' => ['label' => 'Outstanding fees reminder', 'type' => 'finance'],
        'payment_submitted' => ['label' => 'Payment submitted', 'type' => 'finance'],
        'payment_verified' => ['label' => 'Payment verified', 'type' => 'finance'],
        'payment_rejected' => ['label' => 'Payment rejected', 'type' => 'finance'],
        'subscription_reminder' => ['label' => 'Subscription reminder', 'type' => 'finance'],
        'subscription_extended' => ['label' => 'Subscription extended', 'type' => 'finance'],
        'subscription_locked' => ['label' => 'Subscription overdue', 'type' => 'urgent'],
        'subscription_reinstated' => ['label' => 'Subscription reinstated', 'type' => 'finance'],
        'subscription_payment_verified' => ['label' => 'Subscription payment verified', 'type' => 'finance'],
        'subscription_payment_rejected' => ['label' => 'Subscription payment rejected', 'type' => 'finance'],
        // A user's own profile edit or password reset request, both
        // held for admin sign off (found live 2026-07-24, real user
        // report: an approver had no way to know a request existed at
        // all without proactively opening the approvals page).
        'profile_approval' => ['label' => 'Profile approval needed', 'type' => 'urgent'],
        // The other side of profile_approval above: told to the person who
        // ASKED for the change once an admin decides it (found live
        // 2026-07-25: an applicant had no way to know their own request
        // had been approved besides re-opening their profile page and
        // noticing the fields had quietly changed).
        'profile_change_approved' => ['label' => 'Your profile change was approved', 'type' => 'system'],
        'profile_change_rejected' => ['label' => 'Your profile change was rejected', 'type' => 'system'],
        // A parent's requested change to their child's profile (user
        // request 2026-07-26), the two review stages plus the two
        // decisions told back to the parent who asked.
        'student_profile_change_pending_class_teacher' => ['label' => 'Student profile change awaiting your review', 'type' => 'urgent'],
        'student_profile_change_pending_admin' => ['label' => 'Student profile change awaiting your review', 'type' => 'urgent'],
        'student_profile_change_approved' => ['label' => 'Your requested change was approved', 'type' => 'system'],
        'student_profile_change_rejected' => ['label' => 'Your requested change was rejected', 'type' => 'system'],
        // Automated admissions pipeline (2026-08-06): offer -> accept ->
        // pay -> class-teacher onboard. The offer itself is Mail-only
        // (AdmissionOfferMail, no guardian User exists yet at that point)
        // so it has no category here; these three cover every stage from
        // acceptance onward, once a real guardian/class-teacher User
        // exists to notify in-app.
        'admission_fees_cleared' => ['label' => 'Admission fees cleared', 'type' => 'finance'],
        'admission_onboarding_pending' => ['label' => 'A new student is awaiting onboarding', 'type' => 'academic'],
        'admission_onboarded' => ['label' => 'Enrollment complete', 'type' => 'academic'],
    ],

    /*
    | Where clicking a notification actually takes the recipient (user
    | report 2026-07-25: a notification could be read but never clicked
    | through to the screen where the thing it describes gets acted on).
    | Deliberately just a category -> route map, not a specific record
    | (e.g. one exact payment or fee item): recipients are always
    | pre-filtered to whoever is actually eligible to act before a
    | Notification row is ever written for them (an approver, the
    | assigned reviewer, the owing guardian), so no extra permission
    | check is layered on here, the destination route's own gate is
    | the only one that matters. A category with no entry (birthday,
    | anniversary) has nothing to act on, so it stays a plain, unlinked
    | notification, same as before this existed.
    */
    'notification_deep_links' => [
        'announcement' => 'school.announcements.index',
        'broadcast' => 'school.announcements.index',
        'report_card_review' => 'school.report-cards.pending-review',
        'fee_item_pending_approval' => 'school.finance.fee-items.index',
        'payment_reminder' => 'school.finance.payments.create',
        'payment_submitted' => 'school.finance.payments.index',
        'payment_verified' => 'school.finance.payments.index',
        'payment_rejected' => 'school.finance.payments.index',
        'subscription_reminder' => 'school.subscription.index',
        'subscription_extended' => 'school.subscription.index',
        'subscription_locked' => 'school.subscription.index',
        'subscription_reinstated' => 'school.subscription.index',
        'subscription_payment_verified' => 'school.subscription.index',
        'subscription_payment_rejected' => 'school.subscription.index',
        'profile_approval' => 'school.profile.approvals',
        'profile_change_approved' => 'school.profile.edit',
        'profile_change_rejected' => 'school.profile.edit',
        'admission_fees_cleared' => 'school.finance.payments.index',
        'admission_onboarding_pending' => 'school.admissions.onboarding.index',
        'admission_onboarded' => 'school.students.index',
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
    | Welcome email role briefs (2026-07-26). Seed-only catalog, same
    | "config feeds a one-time seed, live reads go through a real tenant
    | Setting" shape grading_scales/comments already use: an admin can
    | override any of these from Email Settings, App\Services\Accounts\
    | WelcomeEmailService::briefFor() falls back to this only for a role
    | the school has never customized. One short sentence per role slug,
    | what that role can actually do on first sign in.
    */
    'default_role_welcome_briefs' => [
        'school-owner' => 'You have full control of your school\'s SKULOS portal: admissions, academics, finance, staff, and communication, all in one place.',
        'school-admin' => 'You can manage students, staff, academics, and day to day school administration on SKULOS.',
        'principal' => 'You have an overview of academics, staff, and student performance across the school.',
        'head-teacher' => 'You have an overview of academics, staff, and student performance for your section of the school.',
        'vice-principal' => 'You support the principal with academics, staff, and student performance across the school.',
        'registrar' => 'You manage student admissions, records, and enrollment on SKULOS.',
        'examination-officer' => 'You manage examinations, results, and report cards on SKULOS.',
        'bursar' => 'You manage fees, payments, and the school\'s financial records on SKULOS.',
        'ict-officer' => 'You support the school\'s SKULOS setup and help other staff with their accounts.',
        'secretary' => 'You handle correspondence, records, and day to day administrative support on SKULOS.',
        'teacher' => 'You can mark attendance, enter scores, post assignments, and message parents from your dashboard.',
        'class-teacher' => 'You manage your class\'s attendance, report cards, and parent communication from your dashboard.',
        'subject-teacher' => 'You can mark attendance, enter scores, and post assignments for the subjects you teach.',
        'librarian' => 'You manage the school library on SKULOS.',
        'nurse' => 'You manage student health records on SKULOS.',
        'store-keeper' => 'You manage school inventory and supplies on SKULOS.',
        'accountant' => 'You manage the school\'s financial records and reporting on SKULOS.',
        'class-assistant' => 'You can take attendance and support your class teacher from your dashboard.',
        'parent' => 'You can track your child\'s attendance, results, fees, and school announcements in one place.',
        'student' => 'You can view your timetable, assignments, results, and school announcements any time.',
        'security' => 'You manage visitor and gate records on SKULOS.',
        'hostel-master' => 'You manage boarding house records on SKULOS.',
        'transport-officer' => 'You manage school transport records on SKULOS.',
    ],

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
        // A stream's own subject MENU, what it offers — not what any one
        // student ends up taking. Christian Religious Studies/Islamic
        // Studies and Yoruba/Hausa/Igbo are each a real, mutually
        // exclusive choice (user directive 2026-07-24: "no student can
        // take both Christian Religious Studies and Islamic Studies, they
        // have to select one. Same as the languages, student must only
        // have one of Yoruba, Hausa or Igbo and in SSS these languages
        // are only offered in Arts & Humanities"), see
        // sss_elective_groups below for how that is actually enforced
        // when scores are populated. A student's REAL total stays at the
        // 11 subject cap: 5 shared + Government/Literature in English/
        // History/Visual Arts (4, always) + one religious study + one
        // language = 11, even though the menu here lists 9 options.
        'Science & Technology' => ['Biology', 'Chemistry', 'Physics', 'Agricultural Science', 'Further Mathematics', 'Geography'],
        'Arts & Humanities' => [
            'Government', 'Literature in English', 'History', 'Visual Arts',
            'Christian Religious Studies', 'Islamic Studies',
            'Yoruba', 'Hausa', 'Igbo',
        ],
        'Commercial' => ['Economics', 'Commerce', 'Financial Accounting', 'Marketing'],
    ],

    /*
    | Subjects that are real alternatives, never taken together by the same
    | student, school wide, not only in SSS (user directive 2026-07-24: "no
    | student can take both Christian Religious Studies and Islamic
    | Studies, they have to select one. Same as the languages, student
    | must only have one of Yoruba, Hausa or Igbo... update the seeded
    | data for all students across the school with this too"). A class's
    | own subject catalog can still legitimately offer every member (a
    | school's real menu of choices for its students), this only
    | constrains what any ONE student is ever actually scored on.
    | Christian Religious Studies/Islamic Studies exists as a choice on
    | both the 'jss' and 'ss' catalogs; Yoruba/Hausa/Igbo only exist as
    | separate subjects in SSS's Arts & Humanities stream (JSS instead
    | uses one single "Nigerian Languages" subject, no conflict there).
    | Consumed by schools:populate-scores (both to skip creating a second
    | score for an already-satisfied group, and to clean up any student
    | already scored on more than one member from before this rule
    | existed): a student is only ever scored on one member per group,
    | chosen deterministically from their own student id so a re-run
    | always picks the same one rather than reshuffling on every run.
    */
    'mutually_exclusive_subjects' => [
        ['Christian Religious Studies', 'Islamic Studies'],
        ['Yoruba', 'Hausa', 'Igbo'],
    ],

    /*
    | The 'ss' catalog subjects every SSS stream shares (Mathematics/
    | English Language/Civic Education are mandatory there already;
    | Digital Technologies/Trade Subject are offered but optional), kept
    | as its own explicit list rather than "whatever the catalog's 24
    | subjects minus whatever a stream claims happens to leave over" —
    | that implicit-leftover approach silently promoted Music/French/
    | Arabic to shared-by-every-stream the moment they were trimmed off
    | Arts & Humanities to fit the 11 subject cap (found testing
    | sss:setup-streams before it ever touched real data). A subject in
    | neither this list nor any one stream's own list is simply not
    | offered by that class at all, not absorbed anywhere by default.
    */
    'sss_shared_subjects' => ['Mathematics', 'English Language', 'Civic Education', 'Digital Technologies', 'Trade Subject'],

    /*
    | Default house names a school can load in one action (user request
    | 2026-07-10): most schools run 4. Colour based and neutral on
    | purpose, not any specific school's real house names, since those
    | would be fabricated. Idempotent by name, same pattern as every
    | other "default_*" catalog here, editable/removable after loading.
    */
    'default_houses' => ['Red House', 'Blue House', 'Green House', 'Yellow House'],

    /*
    | The starter club catalog a school can load in one click, or add to
    | (2026-08-16, supplied by the platform owner). Seeds a real tenant
    | table on demand -- nothing reads this list at runtime once a
    | school has its own clubs, so a school is free to rename, retire or
    | replace any of them.
    |
    | Clubs are optional: a school that never enables them sees no club
    | field on a student profile or report card at all (the
    | 'clubs_enabled' tenant setting, default off).
    */
    'default_clubs' => [
        'JETS Club',
        'Mathematics Club',
        'AI / ICT / Coding Club',
        'Language Clubs',
        'Literary and Debating Society',
        'Press Club',
        'Drama & Cultural Troupe',
        'Fine Arts & Craft',
        'Young Farmers Club',
        'Homemakers / Home Economics Club',
        'Business & Enterprise Club',
        'Boy Scout & Girl Guide',
        'Red Cross Society',
    ],

    /*
    | Extracurricular clubs schools:populate-scores picks from when
    | backfilling a demo student's "club" field on the report card's
    | Physical & Attendance section (user request 2026-07-24: "populate
    | 'Physical & Attendance' data too for all learners"). Generic,
    | common to most Nigerian schools, not any specific school's real
    | club roster.
    */
    'demo_clubs' => ['Debate Club', 'Football Club', 'Drama Club', 'Chess Club', 'Reading Club', 'Science Club', 'Music Club', 'Press Club'],

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
    | A second, genuinely distinct comment bank in a school leadership
    | voice (user report 2026-07-27, real report card seen live: "why is
    | class teacher and head teacher comment the same thing... update
    | your comment bank to have a distinct comment"). The class teacher
    | bank above speaks day to day, classroom level; this one speaks the
    | way a Principal/Head of School addresses a whole term's standing,
    | never reused word for word. Same 5 score bands, seeded into the
    | same tenant `comments` table tagged `audience = 'principal'`
    | (CommentBank::suggestionFor() filters on it), so a class teacher
    | remark and a principal/head of school remark for the identical
    | score can never land on the same sentence.
    */
    'default_principal_comment_bank' => [
        ['min' => 90, 'max' => 100, 'comments' => [
            'The school is proud of this outstanding result and encourages you to continue setting the standard for your peers.',
            'An excellent term overall. The school has every confidence you will continue to excel.',
            'Your results this term reflect real credit on you and your family. Well done.',
            'The school commends this exceptional achievement and looks forward to even greater things from you.',
            'You have set a fine example this term. Keep up this excellent standard.',
            'A truly commendable result. The school is confident you will continue to reach for excellence.',
            'This is an excellent report, and the school is pleased to note your consistent hard work.',
            'The school recognises your outstanding effort this term and encourages you to aim even higher.',
        ]],
        ['min' => 80, 'max' => 89, 'comments' => [
            'A very good term\'s work. The school encourages you to keep striving for the highest standard.',
            'This is a pleasing result and the school is confident of further improvement.',
            'Well done on a strong term. The school looks forward to seeing continued progress.',
            'A commendable report overall. Keep up the good work and aim higher next term.',
            'The school is pleased with this result and encourages continued effort.',
            'A good term\'s work. Continue to build on this solid foundation.',
            'The school notes your steady progress this term with satisfaction.',
            'A very good result. The school expects even better in the terms ahead.',
        ]],
        ['min' => 70, 'max' => 79, 'comments' => [
            'A satisfactory term overall. The school encourages greater consistency going forward.',
            'This is a fair result with clear room for improvement. The school expects more focused effort next term.',
            'The school notes reasonable progress this term and encourages continued commitment.',
            'A fairly good report. The school urges greater dedication in the coming term.',
            'This result shows promise. The school encourages you to work towards your full potential.',
            'The school is satisfied with this term\'s effort but expects further improvement.',
            'A decent term overall. Greater focus will bring better results next term.',
            'The school encourages you to build on this term\'s progress with renewed effort.',
        ]],
        ['min' => 60, 'max' => 69, 'comments' => [
            'This term\'s result requires improvement. The school expects greater commitment going forward.',
            'The school is concerned about this result and urges more serious attention to studies.',
            'There is a need for greater effort next term. The school encourages you to seek support where needed.',
            'This result falls below expectation. The school urges renewed focus and discipline.',
            'The school notes the need for significant improvement and encourages closer attention to studies.',
            'More is expected of you. The school urges greater commitment in the coming term.',
            'This result is a concern. The school encourages you to work more closely with your teachers.',
            'The school expects a marked improvement in effort and result next term.',
        ]],
        ['min' => 0, 'max' => 59, 'comments' => [
            'This result is unsatisfactory. The school requires immediate and sustained improvement.',
            'The school is deeply concerned about this result and expects urgent attention to studies.',
            'Serious effort is required next term. The school urges full commitment from you and your family.',
            'This performance falls well short of expectation. The school insists on greater seriousness with studies.',
            'The school requires a marked change in attitude and effort going forward.',
            'Urgent improvement is needed. The school encourages close collaboration between home and school.',
            'This result cannot continue. The school expects full commitment and discipline next term.',
            'The school urges you to take your studies far more seriously in the term ahead.',
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
        // Templates 7 and 8 (2026-07-28, user-supplied reference images
        // under "Templates/Report Card templates"): two more genuinely
        // distinct visual styles, on top of the same configurable body
        // sections every other template already shares.
        'bordered' => ['label' => 'Bordered', 'description' => 'A bold accent colour frame around the whole sheet, with an accent coloured results table header, for a school that wants a strong branded look.'],
        'vibrant' => ['label' => 'Vibrant', 'description' => 'A colourful, rounded, friendly sheet with a tinted header and softly striped results table, suited to Nursery and Primary classes.'],
    ],
    'default_report_card_template' => 'classic',

    /*
    | How each term's report card treats the terms before it in the same
    | session (user request 2026-07-24: "let's add a settings under
    | examination on the termly report card calculation settings...
    | for third term - the school can decide to add first term total and
    | second term total plus the third term total then find the average
    | for each subject... admin can toggle it off if the school decide
    | otherwise... alternatively, the school can decide to add the total
    | from previous terms but it wont find the average, it just display
    | it for comparison purpose only"). A school configurable choice per
    | term SEQUENCE (not per literal "first/second/third", so this still
    | makes sense for a school running more or fewer than 3 terms, or a
    | custom mid session term), seeded once into
    | App\Services\ReportCards\ReportCardCumulativePolicy's own
    | tenant Setting, never read live from here past that seed, the same
    | shape as grading_scales/comments.
    */
    'report_card_cumulative_modes' => [
        'off' => ['label' => 'This term only', 'description' => 'No earlier terms are shown or counted, this report reflects this term alone.'],
        'average' => ['label' => 'Average with earlier terms', 'description' => 'Adds this term\'s subject total to every earlier term in the same session and shows the average.'],
        'comparison' => ['label' => 'Show earlier terms for comparison, no average', 'description' => 'Shows each earlier term\'s total alongside this one so a parent can see the trend, without averaging them together.'],
    ],
    // Applied to any term whose sequence has no explicit choice yet (a
    // school that has never opened the new settings screen at all keeps
    // exactly the one behaviour the product always had before this
    // screen existed). A term's own first (lowest sequence) term in a
    // session always effectively behaves as 'off' regardless of this
    // default, there is nothing earlier to add or compare against.
    'default_report_card_cumulative_mode' => 'average',

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
        // Not in default_report_card_layout below (2026-07-23, user
        // request: "as part of report card builder, the option to add
        // student picture to the top of the report card"): a student
        // photo is opt in, dragged into the header from the designer's
        // own "Available blocks" tray, the same not-mandatory-by-default
        // treatment score_trend/subject_chart already got.
        'student_photo' => 'Student Photo',
        'school_name' => 'School Name',
        'address' => 'School Address',
        // Same row as the address by default (user request 2026-07-24:
        // "beside the school address, the same row let's have the
        // school website, email address and all phone numbers"), also
        // draggable on their own here in the custom Layout Designer.
        'website' => 'School Website',
        'school_email' => 'School Email Address',
        'school_phone' => 'School Phone Number',
        'school_alternate_phone' => 'School Alternate Phone Number',
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
        // Two standalone sections, not one merged "Behavioural Traits &
        // Physical Stats" block (user correction 2026-07-24: "Help me
        // separate this... in the report card designer to standalone
        // each"). Keys match their own report_card_fields toggle name
        // directly, the same dual purpose naming subject_comments
        // already uses.
        'behavioural_traits' => 'Behavioural / Psychomotor Traits',
        // Class Teacher Contact renders inside this same section's own
        // partial, directly under the Physical & Attendance table, not
        // as its own body_sections row (user correction 2026-07-24:
        // "it should be placed directly under the Physical & Attendance
        // so that its length/height can match the Behavioural /
        // Psychomotor Traits container on the left"), so it has no
        // entry of its own here; its report_card_fields toggle below
        // still controls it independently.
        'physical_stats' => 'Physical & Attendance',
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
        'subjects_table', 'subject_comments', 'summary', 'behavioural_traits', 'physical_stats', 'grade_key', 'remarks', 'signoff',
    ],

    /*
    | Which sections share a row by default (user request 2026-07-24:
    | "let the report designer in view match the current live report
    | card view showing some sections side by side and not all in linear
    | method"). Behavioural Traits and Physical & Attendance always sat
    | side by side before they were split into two standalone sections
    | the same day, this default keeps that look without forcing every
    | future section into a fixed pairing, an admin can change or remove
    | it from the Layout Designer like anything else there.
    */
    'default_report_card_body_columns' => [
        'behavioural_traits' => 2,
        'physical_stats' => 2,
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
        // Shown in the header, under the school name (user request
        // 2026-07-24: "let's add the school address as a default thing
        // to also appear on the report card header too, admin can
        // decide to remove it from the report builder page"), was
        // previously unconditional with no way to turn it off at all.
        'school_address' => ['label' => "School address (header)", 'group' => 'identity', 'default' => true],
        // Same row as the address (user request 2026-07-24: "beside the
        // school address, the same row let's have the school website,
        // email address and all phone numbers"), each independently
        // toggleable and also available as its own block in the Layout
        // Designer, same "field toggle + matching designer block" shape
        // as everything else in this header.
        'school_website' => ['label' => 'School website (header)', 'group' => 'identity', 'default' => true],
        'school_email' => ['label' => 'School email address (header)', 'group' => 'identity', 'default' => true],
        'school_phone' => ['label' => 'School phone number (header)', 'group' => 'identity', 'default' => true],
        'school_alternate_phone' => ['label' => 'School alternate phone number (header)', 'group' => 'identity', 'default' => true],
        // Top right of the header, same size as the school logo (user
        // request 2026-07-24, then again 2026-07-27 once it was noticed
        // still missing on the non layout-designer templates): shows
        // whichever photo the student's own profile has uploaded. A
        // school not wanting a photo section at all turns this whole
        // thing off; one still wanting the space to always show a face
        // (even a generic one) for an unphotographed student turns the
        // avatar fallback on too, a separate choice from the header
        // toggle itself.
        'student_photo_header' => ['label' => 'Student photo (header)', 'group' => 'identity', 'default' => true],
        'student_photo_avatar_fallback' => ['label' => 'Use a generic avatar when no student photo is uploaded', 'group' => 'identity', 'default' => true],
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
        // Default changed to off (user directive 2026-07-27: "that
        // remark should not be seen only if admin want it in settings,
        // so it should not be seen under the subject scores entry,
        // parent report and under report cards by default"), an admin
        // opts it on from Report Settings rather than opting it off.
        // The score-entry gating itself was only actually wired 2026-08-05
        // (a real gap: the toggle existed and defaulted off, but the
        // remark box on the score entry screen kept rendering regardless,
        // and a typed remark was silently discarded on save either way).
        'subject_remark' => ['label' => 'Subject remark (shown at score entry, on the report card, and to parents)', 'group' => 'academic', 'default' => false],
        // Superseded 2026-07-24 by the dedicated Term Calculation
        // settings screen (Examinations > Term Calculation,
        // ReportCardCumulativePolicy): a single on/off checkbox here
        // could not express "average for third term, comparison only
        // for second term" (user request), a real per term choice, not
        // a report card field toggle.
        // The 5 summary boxes (Total Score, Average, Grade, Position,
        // Class Size), each independently toggleable (user request
        // 2026-07-24: "let have the admin report setting to include
        // this section where admin can toggle any of the 5 items under
        // summary on or off"). Total Score/Average were previously
        // unconditional (no toggle existed at all); Position and Class
        // Size previously shared the one 'overall_position' toggle.
        'summary_total' => ['label' => 'Summary: Total Score', 'group' => 'academic', 'default' => true],
        'summary_average' => ['label' => 'Summary: Average', 'group' => 'academic', 'default' => true],
        'summary_grade' => ['label' => 'Summary: Grade', 'group' => 'academic', 'default' => true],
        'overall_position' => ['label' => 'Summary: Position', 'group' => 'academic', 'default' => true],
        'summary_class_size' => ['label' => 'Summary: Class Size', 'group' => 'academic', 'default' => true],
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
        // Rendered as fixed content inside the Physical & Attendance
        // column itself (user directive 2026-07-24: "you are not to add
        // the toggle there but add the class teacher name and phone
        // number there. the toggle will be on the report card setting
        // page"), this key is only the on/off switch for it, distinct
        // from 'class_teacher' above (an identity block, name only, no
        // phone, used by the header/info zone instead).
        'class_teacher_contact' => ['label' => 'Class teacher name and phone number', 'group' => 'evaluation', 'default' => false],
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
        // Seed default only, the live value is
        // Setting::grab('student_photo_max_kb'), admin editable from
        // Student, Parent & Staff Field Settings (user request
        // 2026-07-23: "max size of image should be 100kb, admin can
        // reduce or increase it under settings").
        'student_photo_kb' => 100,
        // Admissions module (2026-07-30): same ceiling as payment
        // evidence, a bank receipt/proof-of-payment screenshot or PDF.
        'admission_fee_evidence_kb' => 3072,
        // Admissions module, Phase 1f (2026-07-31): a scanned birth
        // certificate/report card/testimonial, same ceiling as fee
        // evidence — these are the same kind of file (a phone photo or
        // scanned PDF), no reason for a different limit.
        'admission_document_kb' => 3072,
        // Assessment Hub, Phase 2d: the pre-exam wizard's photo ID upload
        // (same ceiling as every other phone-photo/scan upload in this
        // app) and its short environment-scan webcam clip (video, so a
        // higher ceiling — capped client-side to 15 seconds already).
        'cbt_photo_id_kb' => 3072,
        'cbt_environment_video_kb' => 15360,
    ],

    /*
    | Which supporting documents an applicant can attach (user directive
    | 2026-07-31: "filling candidate birth certificate, last report card
    | if available etc, all these are optional by default"). Every type
    | is optional — an applicant uploads whichever they actually have,
    | not a hard gate on completing the application. Not admin-editable
    | yet (no settings screen wires this catalog to a tenant table the
    | way admission_pipeline_stages should eventually move to too); flag
    | this the same way if that becomes a real ask.
    */
    'admission_document_types' => [
        'birth_certificate' => 'Birth Certificate',
        'last_report_card' => 'Last Report Card / Testimonial',
        'passport_photograph' => 'Passport Photograph',
        'immunization_record' => 'Immunization Record',
    ],

    /*
    | SKULOS Assessment Hub, Phase 2a: seeded once into a real tenant
    | table (CbtSecurityProfile::ensureSeeded(), same "config seeds a
    | tenant table once" pattern as AdmissionPipelineStage/grading
    | scales) — the four-tier concept (Basic/Medium/High/Maximum) is
    | fixed by the original spec, only each tier's individual flags are
    | admin-editable from there. Maximum is the only tier with camera
    | flags on; those flags do nothing until Phase 2e (AI proctoring)
    | actually reads them.
    */
    // event_scores/risk_medium_threshold/risk_high_threshold (2026-08-12,
    // feedback list 8/7/2026 item 18, in line with the LearnGuard
    // reference plugin's own per-profile risk weights/thresholds): every
    // tier starts from the same values 'cbt_security_event_scores'/
    // 'cbt_risk_thresholds' below already used globally, a school can
    // diverge per-tier later from the real Security Profiles screen.
    // autosuspend_score/break_max_minutes are real NEW capabilities
    // (LearnGuard's own real defaults, 80/10) given only to Maximum,
    // matching this block's existing "only Maximum enables the strict
    // stuff" pattern for the camera/identity flags below.
    'default_cbt_security_profiles' => (function () {
        // Same 10 event-type weights every profile starts from — see
        // 'cbt_security_event_scores' further below, the values a school
        // can then diverge per-tier from the real Security Profiles
        // screen once seeded into a real row.
        $defaultEventScores = [
            'tab_switch' => 10, 'fullscreen_exit' => 15, 'copy_attempt' => 5, 'paste_attempt' => 5,
            'right_click' => 2, 'window_blur' => 5, 'face_not_detected' => 8, 'face_mismatch' => 20,
            'object_detected' => 25, 'noise_threshold' => 5,
        ];

        return [
            ['name' => 'Basic', 'require_fullscreen' => false, 'block_copy_paste' => false, 'block_right_click' => false, 'tab_switch_action' => 'none', 'tab_switch_limit' => null, 'require_camera_check' => false, 'require_object_detection' => false, 'require_audio_check' => false, 'require_photo_id' => false, 'require_face_match' => false, 'require_screen_share' => false, 'require_environment_scan' => false, 'snapshot_interval_seconds' => null, 'event_scores' => $defaultEventScores, 'risk_medium_threshold' => 30, 'risk_high_threshold' => 60, 'autosuspend_score' => null, 'break_min_minutes' => null, 'break_max_minutes' => null],
            ['name' => 'Medium', 'require_fullscreen' => false, 'block_copy_paste' => true, 'block_right_click' => true, 'tab_switch_action' => 'warn', 'tab_switch_limit' => null, 'require_camera_check' => false, 'require_object_detection' => false, 'require_audio_check' => false, 'require_photo_id' => false, 'require_face_match' => false, 'require_screen_share' => false, 'require_environment_scan' => false, 'snapshot_interval_seconds' => null, 'event_scores' => $defaultEventScores, 'risk_medium_threshold' => 30, 'risk_high_threshold' => 60, 'autosuspend_score' => null, 'break_min_minutes' => null, 'break_max_minutes' => null],
            ['name' => 'High', 'require_fullscreen' => true, 'block_copy_paste' => true, 'block_right_click' => true, 'tab_switch_action' => 'lock', 'tab_switch_limit' => 3, 'require_camera_check' => false, 'require_object_detection' => false, 'require_audio_check' => false, 'require_photo_id' => false, 'require_face_match' => false, 'require_screen_share' => false, 'require_environment_scan' => false, 'snapshot_interval_seconds' => null, 'event_scores' => $defaultEventScores, 'risk_medium_threshold' => 30, 'risk_high_threshold' => 60, 'autosuspend_score' => null, 'break_min_minutes' => null, 'break_max_minutes' => null],
            // Maximum is the only tier requiring the full identity/environment
            // wizard steps (photo ID, face match, audio, screen share, a 360
            // environment scan) as well as periodic in-exam AI checks
            // (Phase 2e/2g, snapshot_interval_seconds) — matches "Maximum is
            // the only camera tier" already locked in by CbtPaperBuilderTest.
            // autosuspend_score/break_max_minutes are LearnGuard's own real
            // defaults (80/10), given only to Maximum, matching this
            // block's "only Maximum enables the strict stuff" pattern.
            ['name' => 'Maximum', 'require_fullscreen' => true, 'block_copy_paste' => true, 'block_right_click' => true, 'tab_switch_action' => 'lock', 'tab_switch_limit' => 1, 'require_camera_check' => true, 'require_object_detection' => true, 'require_audio_check' => true, 'require_photo_id' => true, 'require_face_match' => true, 'require_screen_share' => true, 'require_environment_scan' => true, 'snapshot_interval_seconds' => 15, 'event_scores' => $defaultEventScores, 'risk_medium_threshold' => 30, 'risk_high_threshold' => 60, 'autosuspend_score' => 80, 'break_min_minutes' => null, 'break_max_minutes' => 10],
        ];
    })(),

    /*
    | SKULOS Assessment Hub, Phase 2d: the pre-exam wizard's fixed step
    | order (mirrors the reference Learn-Proctor-Secure-Browser repo's own
    | 15-step flow). Every taker sees welcome/closetabs/browser/internet/
    | privacy/fullscreen/ready; the rest are shown only if the paper's own
    | CbtSecurityProfile requires that specific signal — see
    | App\Models\Tenant\CbtPrecheckSession::activeSteps().
    */
    'precheck_wizard_steps' => [
        'welcome' => 'Welcome',
        'closetabs' => 'Close Other Tabs & Apps',
        'browser' => 'Browser Check',
        'internet' => 'Internet Check',
        'camera' => 'Camera Check',
        'audio' => 'Audio Check',
        'photoid' => 'Photo ID',
        'face' => 'Face Verification',
        'screenshare' => 'Screen Share',
        'keystroke' => 'Keystroke Baseline',
        'monitor' => 'Monitor Check',
        'environment' => 'Environment Scan',
        'privacy' => 'Privacy Consent',
        'fullscreen' => 'Fullscreen Mode',
        'ready' => 'Ready',
    ],

    /*
    | SKULOS Assessment Hub, Phase 2c: how much each proctoring signal
    | adds to a session's risk_score, and the score thresholds that flip
    | risk_level. Matches the reference (Learn-Proctor-Secure-Browser)
    | repo's own medium=30/high=60 reference lines. As of 2026-08-12
    | (feedback list 8/7/2026 item 18) these are real, per-profile,
    | admin-editable columns on CbtSecurityProfile (event_scores/
    | risk_medium_threshold/risk_high_threshold) — RiskScoringService now
    | reads a session's own profile first. This block is now only the
    | seed source for a brand new profile's own event_scores (see
    | 'default_cbt_security_profiles' above) and a defensive fallback for
    | the rare case a session's assessment has no profile at all.
    */
    'cbt_security_event_scores' => [
        'tab_switch' => 10,
        'fullscreen_exit' => 15,
        'copy_attempt' => 5,
        'paste_attempt' => 5,
        'right_click' => 2,
        'window_blur' => 5,
        'face_not_detected' => 8,
        'face_mismatch' => 20,
        'object_detected' => 25,
        'noise_threshold' => 5,
    ],
    'cbt_risk_thresholds' => ['medium' => 30, 'high' => 60],

    /*
    | LIGHT MODE (user directive 2026-08-01: "I WANT TO KEEP EVERY OTHER
    | THING DEFERRED SO WE CAN BUILD A LIGHTER VERSION SCHOOLS CAN USE
    | FOR NOW ... NO NEED FOR PROCTOR AND INVIGILATION FOR NOW. JUST
    | TIMER, ATTEMPTS, QUESTION BANK, RANDOMISATION").
    |
    | Everything proctoring-related is built and tested, but a school
    | launching now does not want it. Rather than delete that work, it
    | sits behind this one flag, OFF by default. Turning it on restores
    | the pre-check wizard, AI face/object monitoring, the live monitor,
    | proctor and face-approval screens, incidents and captured media —
    | no rebuild required.
    |
    | What stays on in light mode: the timed attempt, attempt limits,
    | the question bank, randomisation, grading and reporting.
    */
    'cbt_proctoring_enabled' => env('SKULOS_CBT_PROCTORING', false),

    // Phase 2d: the pre-exam wizard's 'keystroke' step asks a Maximum-tier
    // taker to type this exact sentence, establishing a typing-pattern
    // baseline (matches the reference repo's own fixed-sentence approach,
    // not something a school would plausibly want to customise).
    'cbt_keystroke_baseline_sentence' => 'The quick brown fox jumps over the lazy dog.',

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

    // Which teacher_scoped_roles actually manage a class and so can have
    // a parent's profile change request routed to them (2026-08-05,
    // "student profile change request... meant for only class teachers
    // and admin"). A subject/plain teacher only ever gets this via an
    // explicit per-staff 'students' extra-access override, never by role.
    'class_review_role_slugs' => ['class-teacher', 'class-assistant'],

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
        // Admissions module (2026-07-30/31 audit follow-up: newly enabled
        // modules must show in nav automatically, this entry was simply
        // missing when the screens were built — the automatic-appear
        // mechanism itself already existed and needed no new code).
        'online_admissions' => 'admissions.manage_settings',
        'admission_workflow' => 'admissions.manage_settings',
        'applicant_tracking' => 'admissions.decide',
        // 'admission_letters' repointed again 2026-08-06 to the real
        // pipeline board, once real offer letter generation actually
        // landed (see nav-school.blade.php's own comment on this key) —
        // same admissions.decide gate the board itself already checks.
        'admission_letters' => 'admissions.decide',
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
        // A distinct permission from plain examinations.view (2026-08-05,
        // user directive: "admin can toggle if subject teachers should
        // have access to class boradhsheets or not"), so revoking a
        // subject teacher's broadsheet access from Role Management never
        // also takes away their ordinary score-entry screen.
        'student_reports' => 'examinations.view_broadsheet',
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
        // Found live 2026-07-25 (user report: "finance menu is missing
        // from parent view"): the parent and student roles both already
        // hold finance.view/finance.create (config('skulos.role_catalog')
        // above), so PaymentController/FeeItemController already let them
        // in, but with no entry here at all every Finance nav link was
        // unconditionally hidden for every restricted-nav role regardless
        // of that grant, the map lookup returning null reads as "no
        // permission required, so hide it" for a restricted role. Mapped
        // to finance.view, the exact permission each of these screens'
        // own index() action already checks.
        'bank_transfer_payment_option' => 'finance.view',
        'payment_evidence_upload' => 'finance.view',
        'payment_verification' => 'finance.view',
        'basic_fee_records' => 'finance.view',
        'installment_payment_plans' => 'finance.view',
    ],

    'extra_permissions' => [
        // view_broadsheet (2026-08-05, user directive: "admin can toggle
        // if subject teachers should have access to class boradhsheets
        // or not"): a distinct permission from plain examinations.view
        // so it can be revoked/granted per role from Role Management
        // without touching ordinary score entry. Granted by default to
        // class-teacher (their own class's broadsheet, already
        // TeacherScope-restricted) but deliberately NOT to subject-teacher/
        // teacher — an admin opts a subject teacher in from Role
        // Management, the same generic mechanism already used for
        // report_cards.bulk_generate_all above.
        // promote_students (2026-08-11, feedback list 8/7/2026 item 9):
        // a real, admin-triggered "move a class up" action is a bigger,
        // riskier action than everyday score entry or even the settings
        // above, same reasoning report_cards.bulk_generate_all already
        // documents for its own institution-wide action — its own
        // permission an admin can extend to another role via Roles &
        // Permissions, not bundled in by default.
        'examinations' => ['approve_results', 'publish_results', 'view_broadsheet', 'promote_students'],
        'communication' => ['broadcast'],
        'school_info' => ['manage_settings'],
        'students' => ['manage_settings'],
        // Assessment Hub, Phase 2: grading a subjective response is a
        // real, distinct action from plain "update" (the standard CRUD
        // set already covers building the question bank/papers).
        'assessment_hub' => ['grade'],
        // Assigning a report card for review and releasing it to students/
        // parents is an institution wide decision (user request
        // 2026-07-11: "the admin, head teacher, principal or school
        // owner"), not something a plain class or subject teacher does,
        // matching the same tier as examinations.approve_results.
        // bulk_generate_all (2026-07-23, user request: "add a bulk way for
        // the admin to trigger bulk generate report card for all classes
        // so that it can go to the reviewers for review... should be a
        // submenu under examination only for the school admin who can
        // decide to give it to other users"): deliberately its own
        // permission, not reusing report_cards.create (which a class
        // teacher already holds for their own single class) — generating
        // for the WHOLE SCHOOL at once is a bigger, institution wide
        // action a school admin should explicitly opt other roles into via
        // Roles & Permissions, not something that comes bundled with
        // everyday per-class report card creation.
        'report_cards' => ['assign_review', 'release', 'bulk_generate_all'],
        // Admissions module (2026-07-30): configuring the pipeline chain,
        // fee amounts, and which class/session cycles are open is an
        // institution wide decision, same tier as school_info/students
        // manage_settings above, not bundled with everyday
        // review/decide/document-approve actions (admissions.update
        // already covers those via the standard action set).
        // 'onboard' (2026-08-06): the receiving class teacher's own final
        // confirm step in the automated offer -> accept -> pay -> onboard
        // pipeline, granted by default only to class-teacher below — a
        // distinct action from 'decide'/'review_documents' above, which
        // stay admin/registrar tier.
        'admissions' => ['manage_settings', 'decide', 'review_documents', 'onboard'],
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
        'endpoint' => env('SKULOS_LICENSE_ENDPOINT', 'https://skulosapp.com/api/license/verify'),
        // Release management (2026-08-07): the sibling endpoints
        // ReleaseDownloadController exposes, each independently
        // env-overridable rather than derived by string-manipulating
        // 'endpoint' above, so customizing one alone never silently
        // breaks the others.
        'latest_release_endpoint' => env('SKULOS_LICENSE_LATEST_RELEASE_ENDPOINT', 'https://skulosapp.com/api/license/latest-release'),
        'download_endpoint' => env('SKULOS_LICENSE_DOWNLOAD_ENDPOINT', 'https://skulosapp.com/api/license/download'),
        'grace_days' => (int) env('SKULOS_LICENSE_GRACE_DAYS', 14),
    ],

    /*
    | This installation's own current release version (2026-08-07,
    | release management), e.g. "2026.08.07" — only meaningful on a
    | self-hosted install, set/updated by the operator in their own .env
    | as the last step of applying an update (see DEPLOYMENT.md's
    | "Updating an existing self-hosted install" section). Compared
    | against School::latest_available_version (set by this install's
    | own hourly check-in) to decide whether to show an "update
    | available" banner on the self-hosted license settings screen. Null
    | on the main platform itself and on a self-hosted install that has
    | never set it — both correctly show no banner rather than a false
    | "update available" from comparing against nothing.
    */
    'version' => env('SKULOS_VERSION'),

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
            // admissions.* added 2026-08-10 (UX audit finding): a role
            // literally described as "You manage student admissions,
            // records, and enrollment" had zero admissions.* grant at
            // all, a real gap not a deliberate scoping choice — every
            // other role's grants line up with its own description.
            'students.*', 'parents.*', 'academics.view', 'analytics.view', 'admissions.*',
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
            // Marking written answers is ordinary teacher work (the
            // reference plugin puts :grade in the teacher archetype
            // too). This permission alone does NOT let them mark
            // anything: they must also be assigned as a CbtGrader on
            // the assessment, which is what decides which attempts
            // they see and whether they mark blind.
            'assessment_hub.view', 'assessment_hub.grade',
        ]],
        'class-teacher' => ['name' => 'Class Teacher', 'grants' => [
            'students.view', 'students.update', 'attendance.*',
            'examinations.view', 'examinations.create', 'examinations.update', 'examinations.view_broadsheet',
            'report_cards.view', 'report_cards.create', 'report_cards.update',
            'timetable.view', 'assignments.view', 'assignments.create', 'assignments.update', 'assignments.delete',
            'calendar.view', 'communication.view', 'communication.create',
            // create/update/delete/bulk added 2026-08-02 (user directive:
            // "class teacher and subject teacher can set assessment for
            // the classes assigned to them"), matching subject-teacher's
            // own tier below — a class teacher builds assessments for
            // their own class, scoped server-side to their own
            // TeachingAssignment rows (CbtAssessmentController::
            // allowedPairs()), never the whole school. Deliberately NOT
            // granted assessment_hub.export — that stays the "blanket
            // access" signal a scoped role never holds, mirroring
            // ExaminationController::hasBlanketAccess()'s own choice of
            // an unused-by-scoped-roles permission as its marker.
            'assessment_hub.view', 'assessment_hub.create', 'assessment_hub.update', 'assessment_hub.delete', 'assessment_hub.bulk', 'assessment_hub.grade',
            // Automated admissions pipeline (2026-08-06): the receiving
            // class teacher's own final onboarding step for a dormant
            // pending_enrollment student assigned to their class.
            'admissions.onboard',
        ]],
        'subject-teacher' => ['name' => 'Subject Teacher', 'grants' => [
            'students.view', 'attendance.view',
            'examinations.view', 'examinations.create', 'examinations.update',
            'timetable.view', 'assignments.view', 'assignments.create', 'assignments.update', 'assignments.delete',
            'calendar.view', 'communication.view',
            // Assessment Hub, Phase 2: a teacher builds the question
            // bank/papers for their own subjects, same view/create/
            // update/delete/bulk tier as assignments above; grading
            // (Phase 2c) is a separate, distinct permission.
            'assessment_hub.view', 'assessment_hub.create', 'assessment_hub.update', 'assessment_hub.delete', 'assessment_hub.bulk', 'assessment_hub.grade',
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
            // Assessment Hub, Phase 2: view-only, same tier as
            // assignments.view above — a parent can see (not take) a
            // child's assessment results.
            'assessment_hub.view',
        ]],
        'student' => ['name' => 'Student', 'grants' => [
            'calendar.view', 'communication.view', 'timetable.view', 'assignments.view', 'students.view',
            'finance.view', 'finance.create',
            // Assessment Hub, Phase 2: lets a student see and take their
            // own assigned assessments (CbtTakeController), same
            // view-tier as assignments.view above — never the admin
            // question-bank/paper-builder screens, those additionally
            // require create/update, which students are never granted.
            'assessment_hub.view',
        ]],
        'security' => ['name' => 'Security', 'grants' => []],
        'hostel-master' => ['name' => 'Hostel Master', 'grants' => ['hostel.*', 'students.view']],
        'transport-officer' => ['name' => 'Transport Officer', 'grants' => ['transport.*', 'students.view']],
    ],
];
