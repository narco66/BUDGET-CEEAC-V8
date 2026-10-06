<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('role_permission', function (Blueprint $table) {
            if (! Schema::hasColumn('role_permission', 'level')) {
                $table->string('level', 16)->default('scoped');
            }
        });

        Schema::table('user_roles', function (Blueprint $table) {
            if (! Schema::hasColumn('user_roles', 'plafond_fcfa')) {
                $table->unsignedBigInteger('plafond_fcfa')->nullable();
            }
            if (! Schema::hasColumn('user_roles', 'origine')) {
                $table->string('origine', 32)->nullable();
            }
            if (! Schema::hasColumn('user_roles', 'scope_unit_id')) {
                $table->foreignId('scope_unit_id')->nullable()->constrained('organization_units')->nullOnDelete();
            }
            if (! Schema::hasColumn('user_roles', 'derogation')) {
                $table->boolean('derogation')->default(false);
            }
        });

        Schema::table('user_roles', function (Blueprint $table) {
            $table->index(['status', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::table('user_roles', function (Blueprint $table) {
            $table->dropIndex(['status', 'ends_on']);
            if (Schema::hasColumn('user_roles', 'scope_unit_id')) {
                $table->dropConstrainedForeignId('scope_unit_id');
            }
            $table->dropColumn(['plafond_fcfa', 'origine', 'derogation']);
        });

        Schema::table('role_permission', function (Blueprint $table) {
            $table->dropColumn('level');
        });
    }
};
