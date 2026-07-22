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
        ];

        $advanceFeatures = array_merge($foundationFeatures, [
            // Admissions (online_admissions/admission_workflow/
            // applicant_tracking/admission_letters/online_document_upload)
            // deliberately excluded (user directive 2026-07-18: "SUSPEND"
            // building it, after initially confirming it should be built
            // now): keeping it out of Advance's feature list is what
            // actually stops it from showing at all (the nav's own
            // standing rule is "a feature not included is not shown at
            // all", never a "Soon" placeholder for something with no
            // screen). Add back in once the module is actually built.
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
            'cbt_examination', 'learnguard_secure_browser', 'webcam_monitoring', 'browser_lockdown',
            'question_bank', 'random_questions', 'examination_analytics',
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
                'modules' => ['finance'], 'features' => $foundationFeatures,
                'default' => true, 'purchasable' => true, 'order' => 1,
            ],
            [
                'name' => 'Advance', 'slug' => 'advance', 'price' => 800,
                // 'admissions' module toggle removed alongside the feature
                // keys above (2026-07-18, suspended pending the module
                // actually being built).
                'modules' => ['finance'], 'features' => $advanceFeatures,
                'default' => false, 'purchasable' => true, 'order' => 2,
            ],
            [
                'name' => 'Professional', 'slug' => 'professional', 'price' => 1200,
                'modules' => ['admissions', 'lms', 'hr', 'payroll', 'ai', 'finance'], 'features' => $professionalFeatures,
                'default' => false, 'purchasable' => false, 'order' => 3,
            ],
            [
                'name' => 'Enterprise', 'slug' => 'enterprise', 'price' => 1500,
                'modules' => ['admissions', 'lms', 'hr', 'payroll', 'ai', 'finance', 'hostel', 'transport', 'inventory', 'medical', 'library', 'visitors', 'assets', 'discipline'],
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
