<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Point tickets at the new DB-managed lookups (PRD Phase 9). Adds nullable
     * category_id / priority_id foreign keys, back-fills them from the legacy
     * string columns, then drops the string columns (and their indexes) so the
     * Ticket model can expose category()/priority() relations without a name clash.
     */
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->unsignedBigInteger('category_id')->nullable()->after('description');
            $table->unsignedBigInteger('priority_id')->nullable()->after('category_id');
        });

        // Back-fill from the legacy enum strings, most-common path in PHP so the
        // mapping is identical on sqlite (tests/CI) and MySQL (production).
        foreach (DB::table('categories')->get() as $category) {
            DB::table('tickets')
                ->where('category', $category->key)
                ->update(['category_id' => $category->id]);
        }

        foreach (DB::table('priorities')->get() as $priority) {
            DB::table('tickets')
                ->where('priority', $priority->key)
                ->update(['priority_id' => $priority->id]);
        }

        // Any straggler string value with no matching lookup keeps a null FK; add
        // the constraints now that no orphan rows can violate them.
        Schema::table('tickets', function (Blueprint $table) {
            $table->foreign('category_id')->references('id')->on('categories')->nullOnDelete();
            $table->foreign('priority_id')->references('id')->on('priorities')->nullOnDelete();
            $table->index('category_id');
            $table->index('priority_id');
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex('tickets_category_index');
            $table->dropIndex('tickets_priority_index');
            $table->dropColumn(['category', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
            $table->dropForeign(['priority_id']);
            $table->dropIndex(['category_id']);
            $table->dropIndex(['priority_id']);
            $table->dropColumn(['category_id', 'priority_id']);
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->string('category')->default('general')->after('description');
            $table->string('priority')->default('medium')->after('category');
            $table->index('category');
            $table->index('priority');
        });
    }
};
