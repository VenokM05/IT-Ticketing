<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company tagging across the intake chain. Every column is nullable so an
 * existing (already-seeded) database migrates without data loss; tickets
 * carry a denormalized copy of the requester's company so lists and reports
 * filter without joins.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('department_id')
                ->constrained('companies')->nullOnDelete();
            $table->string('position')->nullable()->after('company_id');
        });

        Schema::table('departments', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('name')
                ->constrained('companies')->nullOnDelete();
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('employee_id')
                ->constrained('companies')->nullOnDelete();
            $table->index('company_id');
        });

        Schema::table('users', function (Blueprint $table) {
            // Home company of a company admin/technician: used only to pre-filter
            // views, never to restrict access.
            $table->foreignId('company_id')->nullable()->after('role')
                ->constrained('companies')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn('position');
        });
        Schema::table('departments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
        });
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex(['company_id']);
            $table->dropConstrainedForeignId('company_id');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
        });
    }
};
