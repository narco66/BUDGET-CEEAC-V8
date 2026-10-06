<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->unique();
            $table->string('matricule', 32)->nullable()->unique();
            $table->string('phone', 32)->nullable();
            $table->string('account_status', 32)->default('actif');
            $table->string('locale', 8)->default('fr');
            $table->string('timezone', 64)->default('Africa/Libreville');
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('password_changed_at')->nullable();
            $table->boolean('mfa_required')->default(false);
            $table->string('identity_source', 32)->default('local');
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('deactivated_at')->nullable();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('label');
            $table->string('description')->nullable();
            $table->boolean('sensitive')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 120)->unique();
            $table->string('module', 64);
            $table->string('label');
            $table->string('kind', 32);
            $table->timestamps();
        });

        Schema::create('role_permission', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });

        Schema::create('user_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->unique(['user_id', 'role_id']);
            $table->timestamps();
        });

        Schema::create('sod_rules', function (Blueprint $table) {
            $table->id();
            $table->string('role_a', 64);
            $table->string('role_b', 64);
            $table->boolean('blocking')->default(true);
            $table->string('label');
            $table->timestamps();
        });

        Schema::create('institutional_functions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('label');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('function_code', 64);
            $table->foreignId('organization_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('access_scopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('scope_type', 32);
            $table->string('scope_value', 64);
            $table->timestamps();
        });

        Schema::create('admin_delegations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delegant_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('delegataire_id')->constrained('users')->cascadeOnDelete();
            $table->string('fonction');
            $table->string('perimetre')->nullable();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('motif');
            $table->string('document')->nullable();
            $table->string('status', 32)->default('active');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('substitutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('titulaire_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('interim_id')->constrained('users')->cascadeOnDelete();
            $table->string('fonction');
            $table->foreignId('organization_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('document')->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamps();
        });

        Schema::create('fiscal_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exercice_id')->constrained('exercices')->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('label');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 32)->default('ouvert');
            $table->timestamps();
            $table->unique(['exercice_id', 'code']);
        });

        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->date('holiday_on')->unique();
            $table->string('label');
            $table->timestamps();
        });

        Schema::create('reference_values', function (Blueprint $table) {
            $table->id();
            $table->string('set_code', 64);
            $table->string('code', 64);
            $table->string('label');
            $table->string('status', 32)->default('actif');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['set_code', 'code']);
        });

        Schema::create('workflow_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('module', 64);
            $table->string('label');
            $table->timestamps();
        });

        Schema::create('workflow_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_definition_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('version');
            $table->string('status', 32)->default('brouillon');
            $table->date('effective_on')->nullable();
            $table->timestamps();
            $table->unique(['workflow_definition_id', 'version']);
        });

        Schema::create('workflow_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_version_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('ordre');
            $table->string('code', 64);
            $table->string('label');
            $table->string('actor_role', 64)->nullable();
            $table->timestamps();
        });

        Schema::create('authorization_thresholds', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('operation', 64);
            $table->unsignedBigInteger('min_amount');
            $table->unsignedBigInteger('max_amount')->nullable();
            $table->string('actor_role', 64);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('document')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('business_rules', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('label');
            $table->string('value');
            $table->string('unit', 32)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('label');
            $table->string('operation', 64);
            $table->boolean('required')->default(false);
            $table->unsignedSmallInteger('min_count')->default(0);
            $table->unsignedInteger('max_size_kb')->default(10240);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('document_templates', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('module', 64);
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('status', 32)->default('actif');
            $table->string('header')->nullable();
            $table->string('footer')->nullable();
            $table->string('mentions')->nullable();
            $table->timestamps();
        });

        Schema::create('number_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('prefix', 16);
            $table->unsignedSmallInteger('padding')->default(6);
            $table->string('separator', 4)->default('-');
            $table->unsignedInteger('last_value')->default(0);
            $table->unsignedSmallInteger('exercise_year');
            $table->string('reset_policy', 32)->default('annuel');
            $table->timestamps();
        });

        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('subject');
            $table->text('body');
            $table->string('channel', 32)->default('database');
            $table->string('locale', 8)->default('fr');
            $table->string('status', 32)->default('brouillon');
            $table->timestamps();
        });

        Schema::create('sla_rules', function (Blueprint $table) {
            $table->id();
            $table->string('module', 64);
            $table->string('step', 64);
            $table->unsignedSmallInteger('target_hours');
            $table->unsignedSmallInteger('alert_hours');
            $table->unsignedSmallInteger('max_hours');
            $table->string('escalate_role', 64);
            $table->timestamps();
        });

        Schema::create('security_policies', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('min_length')->default(8);
            $table->unsignedSmallInteger('max_failures')->default(5);
            $table->unsignedSmallInteger('lock_minutes')->default(15);
            $table->json('mfa_roles');
            $table->timestamps();
        });

        Schema::create('user_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('ip', 64)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('last_seen')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('system', 64);
            $table->string('environment', 32)->default('recette');
            $table->string('endpoint');
            $table->string('auth_mode', 32);
            $table->string('status', 32)->default('inactive');
            $table->text('secret_encrypted')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->timestamps();
        });

        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->boolean('critical')->default(false);
            $table->timestamps();
        });

        Schema::create('setting_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('system_setting_id')->constrained()->cascadeOnDelete();
            $table->text('before')->nullable();
            $table->text('after')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('motif')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('role', 64)->nullable();
            $table->string('action', 64);
            $table->string('object_type', 64);
            $table->string('object_id', 64)->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('motif')->nullable();
            $table->string('result', 32)->default('succes');
            $table->string('ip', 64)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('setting_versions');
        Schema::dropIfExists('system_settings');
        Schema::dropIfExists('integrations');
        Schema::dropIfExists('user_sessions');
        Schema::dropIfExists('security_policies');
        Schema::dropIfExists('sla_rules');
        Schema::dropIfExists('notification_templates');
        Schema::dropIfExists('number_sequences');
        Schema::dropIfExists('document_templates');
        Schema::dropIfExists('document_types');
        Schema::dropIfExists('business_rules');
        Schema::dropIfExists('authorization_thresholds');
        Schema::dropIfExists('workflow_steps');
        Schema::dropIfExists('workflow_versions');
        Schema::dropIfExists('workflow_definitions');
        Schema::dropIfExists('reference_values');
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('fiscal_periods');
        Schema::dropIfExists('substitutions');
        Schema::dropIfExists('admin_delegations');
        Schema::dropIfExists('access_scopes');
        Schema::dropIfExists('assignments');
        Schema::dropIfExists('institutional_functions');
        Schema::dropIfExists('sod_rules');
        Schema::dropIfExists('user_roles');
        Schema::dropIfExists('role_permission');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'uuid', 'matricule', 'phone', 'account_status', 'locale', 'timezone',
                'last_login_at', 'password_changed_at', 'mfa_required', 'identity_source',
                'locked_until', 'deactivated_at',
            ]);
        });
    }
};
