<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * The 4 confirmed pricing tiers (tracker section 14, sourced from
 * "SKULOS PRICING PLAN AND FEATURES.docx"): Foundation (was Starter),
 * Advance (was Growth), Professional, Enterprise. Each is cumulative,
 * "everything in the tier before, plus" — matching how the source
 * document itself presents them. "features" is the full customer-facing
 * checklist for the marketing/admin pricing display (keys into
 * config('skulos.plan_feature_catalog')); "modules" is the narrower RBAC
 * toggle set that actually gates screens (config('skulos.modules')).
 * Foundation and Advance are launch blocking and purchasable today;
 * Professional and Enterprise are seeded "is_purchasable" false, shown
 * "Coming soon" until their feature sets are actually built.
 *
 * Idempotent: only fills in plans that don't exist yet, by slug — safe to
 * run again after the admin has customised a plan. Nothing here is a
 * literal in code once seeded; the platform admin can edit every field
 * from Admin\BillingController afterwards.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $foundationFeatures = [
            'student_records', 'staff_records', 'school_sections', 'classes_arms', 'subject_management',
            'school_houses', 'academic_sessions_terms', 'attendance_management', 'continuous_assessment',
            'result_management', 'basic_report_cards', 'student_promotion', 'academic_calendar',
            'user_role_management', 'parent_portal', 'student_portal', 'teacher_portal', 'school_profile',
            'school_branding', 'dashboard',
            'in_app_notifications', 'announcements', 'birthday_anniversary_notifications',
            'bank_transfer_payment_option', 'payment_evidence_upload', 'payment_verification', 'basic_fee_records',
            'student_reports', 'attendance_reports', 'basic_analytics_dashboard', 'export_to_pdf', 'subject_insights',
            'export_to_excel', 'bulk_import', 'bulk_export',
            'knowledge_base', 'staff_onboarding_training', 'standard_support',
            // Assessment Hub (cbt_examination/question_bank/random_
            // questions/examination_analytics) added 2026-08-02: the
            // module is on every plan tier with no restriction (user
            // directive) and the light-mode engine — timed sessions,
            // question bank with import/export, randomised pools,
            // scoring/reporting — is genuinely built and live. The
            // catalog's other 3 examination features (learnguard_
            // secure_browser/webcam_monitoring/browser_lockdown) stay
            // Professional-only below: proctoring is explicitly
            // deferred scope, not yet built at all, so claiming it here
            // would be exactly the false-marketing-claim mistake this
            // pass was meant to fix.
            'cbt_examination', 'question_bank', 'random_questions', 'examination_analytics',
        ];

        $advanceFeatures = array_merge($foundationFeatures, [
            // Admissions (online_admissions/admission_workflow/
            // applicant_tracking/online_document_upload) added
            // 2026-08-02: the whole module was built across an earlier
            // session (public apply, fee review, form builder, document
            // upload/approval, the pipeline board, applicant conversion
            // to a real enrolled student) but the feature catalog was
            // never updated to say so — the marketing site's own static
            // snapshot already claimed these features live, while the
            // actual Plan row behind the admin billing screens still
            // excluded them, a real drift this fixes. admission_letters
            // added 2026-08-06, now that real offer letter generation
            // (the automated offer -> accept -> pay -> class-teacher
            // onboarding pipeline) actually exists — previously excluded
            // deliberately while that catalog key was only ever a nav
            // housekeeping repoint with nothing real behind it.
            'online_admissions', 'admission_workflow', 'applicant_tracking', 'online_document_upload', 'admission_letters',
            'timetable_management', 'teacher_workload', 'advanced_report_cards', 'unlimited_grading_systems',
            'custom_report_card_designer', 'class_position_ranking',
            'email_notifications', 'parent_broadcast', 'communication_history',
            'installment_payment_plans',
            'custom_report_builder', 'student_performance_dashboard', 'attendance_dashboard', 'academic_insights',
            'priority_email_support', 'implementation_assistance',
        ]);

        $professionalFeatures = array_merge($advanceFeatures, [
            'online_payment_gateway_integration', 'school_fees_management', 'discounts_scholarships',
            'expense_management', 'income_management', 'payroll', 'financial_reports', 'financial_dashboard',
            'automated_receipts', 'automated_invoicing', 'outstanding_fee_tracking',
            'hr_management', 'staff_attendance', 'leave_management', 'performance_appraisal', 'staff_document_management',
            'lms', 'courses', 'assignments', 'quizzes', 'learning_resources', 'discussion_boards', 'certificates',
            // cbt_examination/question_bank/random_questions/
            // examination_analytics moved to Foundation above (already
            // inherited here via array_merge) — only the genuinely
            // unbuilt proctoring features stay Professional-exclusive.
            'learnguard_secure_browser', 'webcam_monitoring', 'browser_lockdown',
            'ai_report_comments', 'ai_student_performance_insights', 'ai_lesson_planning', 'ai_question_generator', 'ai_risk_analysis',
            'sms_notifications', 'whatsapp_notifications', 'automated_notifications',
            'workflow_automation', 'scheduled_reports', 'approval_workflows',
            'advanced_analytics', 'executive_dashboard', 'financial_analytics', 'custom_kpis',
            'priority_support', 'dedicated_customer_success',
        ]);

        $enterpriseFeatures = array_merge($professionalFeatures, [
            'library_management', 'inventory_management', 'asset_management', 'medical_management',
            'hostel_management', 'transport_management', 'visitor_management', 'discipline_management',
            'multi_campus_management', 'multi_school_dashboard', 'central_administration', 'school_switching', 'shared_staff',
            'api_access', 'third_party_integrations', 'plugin_marketplace', 'developer_api', 'webhooks',
            'single_sign_on', 'audit_centre',
            'ai_predictive_analytics', 'school_benchmarking', 'custom_executive_dashboards', 'data_warehouse',
            'dedicated_account_manager', 'data_migration', 'priority_technical_support', 'early_access_new_features',
            'school_website_builder', 'alumni_management', 'mobile_apps', 'white_label', 'marketplace_access',
            'custom_module_development', 'advanced_security_policies',
        ]);

        $plans = [
            [
                'name' => 'Foundation', 'slug' => 'foundation', 'price' => 500,
                // 'finance' module toggled on here (2026-07-17), not because
                // the full deferred Finance module is in scope, but because
                // the Foundation Finance slice's own catalog features
                // (bank_transfer_payment_option/payment_evidence_upload/
                // payment_verification/basic_fee_records, see the plan
                // feature list below) live behind finance.* permissions,
                // and Rbac::check() auto-denies any module.* permission
                // whose module defaults to disabled (every non-core module
                // does). Without this, a Foundation school would see the
                // nav link (hasFeature() says yes) but 403 the moment they
                // click it (moduleState() says no), a real conflict caught
                // before writing any Finance code.
                // 'assessment_hub' added 2026-08-01, user directive: every
                // tier gets the Assessment Hub (question bank, timed
                // sessions, admission/class/teacher assessments), no
                // tier restriction — same reasoning as 'finance' above,
                // real screens exist now so the module must be claimed
                // by a plan or a school assigned one loses access to it.
                'modules' => ['finance', 'assessment_hub'], 'features' => $foundationFeatures,
                'default' => true, 'purchasable' => true, 'order' => 1,
            ],
            [
                'name' => 'Advance', 'slug' => 'advance', 'price' => 800,
                // 'admissions' module toggle removed alongside the feature
                // keys above (2026-07-18, suspended pending the module
                // actually being built).
                'modules' => ['finance', 'assessment_hub'], 'features' => $advanceFeatures,
                'default' => false, 'purchasable' => true, 'order' => 2,
            ],
            [
                'name' => 'Professional', 'slug' => 'professional', 'price' => 1200,
                'modules' => ['admissions', 'lms', 'hr', 'payroll', 'ai', 'finance', 'assessment_hub'], 'features' => $professionalFeatures,
                'default' => false, 'purchasable' => false, 'order' => 3,
            ],
            [
                'name' => 'Enterprise', 'slug' => 'enterprise', 'price' => 1500,
                'modules' => ['admissions', 'lms', 'hr', 'payroll', 'ai', 'finance', 'assessment_hub', 'hostel', 'transport', 'inventory', 'medical', 'library', 'visitors', 'assets', 'discipline'],
                'features' => $enterpriseFeatures,
                'default' => false, 'purchasable' => false, 'order' => 4,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::firstOrCreate(
                ['slug' => $plan['slug']],
                [
                    'name' => $plan['name'],
                    'price_per_student_per_term' => $plan['price'],
                    'modules' => $plan['modules'],
                    'features' => $plan['features'],
                    'is_default' => $plan['default'],
                    'is_active' => true,
                    'is_purchasable' => $plan['purchasable'],
                    'sort_order' => $plan['order'],
                ]
            );
        }
    }
}
