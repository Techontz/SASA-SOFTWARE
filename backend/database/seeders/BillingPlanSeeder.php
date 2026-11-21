<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * The launch plans. Idempotent by code, and it never overwrites a plan that
 * already exists: once an administrator has edited a price, re-seeding must
 * not put the old one back.
 */
class BillingPlanSeeder extends Seeder
{
    public function run(): void
    {
        $starter = [
            'Stakeholder register',
            'Engagements and concerns',
            'Grievance management with SLA',
            'Commitments register',
            'Offline field app',
            'Standard reports',
            'Email support',
        ];

        $professional = [
            'Everything in Starter',
            'PAP register',
            'ESHS contractor assurance',
            'Incidents',
            'Analytics dashboards',
            'AI voice intake',
            'Audit trail export',
            'Priority support',
        ];

        $enterprise = [
            'Everything in Professional',
            'Multiple organisations',
            'Custom configuration and onboarding',
            'Single sign-on',
            'Dedicated support and SLA',
        ];

        $plans = [
            [
                'code' => 'starter',
                'name' => 'Starter',
                'tagline' => 'For a single project getting started',
                'price_usd' => 149,
                'price_tzs' => 380000,
                'billing_period' => 'monthly',
                'features' => $starter,
                'limits' => ['projects' => 1, 'users' => 10],
                'is_featured' => false,
                'is_custom_quote' => false,
                'sort_order' => 10,
            ],
            [
                'code' => 'professional',
                'name' => 'Professional',
                'tagline' => 'For active projects with field teams',
                'price_usd' => 449,
                'price_tzs' => 1150000,
                'billing_period' => 'monthly',
                'features' => $professional,
                'limits' => ['projects' => 3, 'users' => 50],
                'is_featured' => true,
                'is_custom_quote' => false,
                'sort_order' => 20,
            ],
            [
                'code' => 'enterprise',
                'name' => 'Enterprise',
                'tagline' => "For portfolios and lenders' requirements",
                'price_usd' => null,
                'price_tzs' => null,
                'billing_period' => 'yearly',
                'features' => $enterprise,
                'limits' => ['projects' => null, 'users' => null],
                'is_featured' => false,
                'is_custom_quote' => true,
                'sort_order' => 30,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::firstOrCreate(['code' => $plan['code']], $plan);
        }

        $this->command?->info('Plans: '.Plan::count().' ('.Plan::whereNull('archived_at')->count().' offered).');
    }
}
