<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Provisions the live client companies and one IT technician dashboard account
 * per company (the target of ticket auto-assignment). Idempotent — safe to re-run:
 * companies and accounts are matched by name / email and only created when missing.
 *
 * All seeded logins use the demo default password "password"; change them after
 * first sign-in.
 */
class CompanyProvisionerSeeder extends Seeder
{
    /**
     * name, code, company email, technician display name, technician login.
     *
     * @var array<int, array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    private const COMPANIES = [
        ['One Source Facility Services Corporation', 'OSFSC', 'it@onesourcefacility.example', 'One Source IT', 'onesource.it@example.com'],
        ['Staunch Workforce Corporation', 'SWC', 'it@staunchworkforce.example', 'Staunch IT', 'staunch.it@example.com'],
        ['Sendiva Logistics Corporation', 'SLC', 'it@sendivalogistics.example', 'Sendiva IT', 'sendiva.it@example.com'],
    ];

    public function run(): void
    {
        foreach (self::COMPANIES as [$name, $code, $companyEmail, $techName, $techEmail]) {
            $company = Company::firstOrCreate(
                ['name' => $name],
                ['code' => $code, 'email' => $companyEmail, 'location' => 'Cebu City', 'is_active' => true],
            );

            // Keep an existing (previously name-matched) row up to date.
            if ($company->code === null) {
                $company->update(['code' => $code, 'email' => $companyEmail]);
            }

            $tech = User::firstOrCreate(
                ['email' => $techEmail],
                [
                    'name' => $techName,
                    'password' => 'password',
                    'role' => UserRole::Technician,
                    'company_id' => $company->id,
                    'is_active' => true,
                    'email_verified_at' => now(),
                ],
            );

            if ($tech->company_id === null) {
                $tech->update(['company_id' => $company->id]);
            }
        }
    }
}
