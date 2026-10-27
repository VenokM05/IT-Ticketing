<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public, no-login ticket submission. A visitor is not an enrolled employee, so a
 * ticket no longer requires an employee_id; instead it can carry a self-declared
 * requester snapshot. The company stays a tag: if the typed company matches an
 * existing one it is stamped, otherwise the ticket is filed as "Unassigned".
 */
return new class extends Migration
{
    public function up(): void
    {
        // A visitor is not an enrolled employee, so employee_id becomes optional.
        // change() keeps the existing foreign key on both MySQL and sqlite.
        Schema::table('tickets', function (Blueprint $table) {
            $table->foreignId('employee_id')->nullable()->change();
        });

        // Self-declared requester snapshot, used when there is no enrolled employee.
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('requester_name')->nullable()->after('employee_id');
            $table->string('requester_contact')->nullable()->after('requester_name');
            $table->string('requester_company')->nullable()->after('requester_contact');
            $table->string('requester_department')->nullable()->after('requester_company');
            $table->string('requester_position')->nullable()->after('requester_department');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn([
                'requester_name',
                'requester_contact',
                'requester_company',
                'requester_department',
                'requester_position',
            ]);
        });

        // Rollback only valid once no ticket is left without an enrolled employee.
        Schema::table('tickets', function (Blueprint $table) {
            $table->foreignId('employee_id')->change();
        });
    }
};
