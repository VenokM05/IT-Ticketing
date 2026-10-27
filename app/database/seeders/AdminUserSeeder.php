<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * The single IT Administrator for the dashboard. Admin-only pages (Companies,
 * Employees, Approvals, Reports, WhatsApp settings) require this role. Idempotent —
 * matched by email. Change the password after first sign-in.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'IT Administrator',
                'password' => 'password',
                'role' => UserRole::Admin,
                'company_id' => null,
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );
    }
}
