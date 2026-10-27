<?php

declare(strict_types=1);

use App\Services\ERP\Accounting\AccountingFoundationRecovery;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['fiscal_years', 'accounting_periods', 'account_groups', 'accounts', 'accounting_mapping_versions', 'accounting_account_mappings', 'journal_entries', 'journal_entry_lines', 'accounting_source_effects', 'accounting_document_sequences', 'accounting_audit_events', 'accounting_cutovers', 'accounting_cutover_sources', 'accounting_company_settings'];

    /** @var list<string> */
    private array $resumeTables = [];

    public function up(): void
    {
        $collisions = array_values(array_filter(self::TABLES, static fn (string $table): bool => Schema::hasTable($table)));
        if ($collisions !== []) {
            if (config('accounting.recover_empty_foundation', false) !== true) {
                throw new RuntimeException('Accounting foundation collision; inspect existing tables before migrating: '.implode(', ', $collisions));
            }
            $verified = app(AccountingFoundationRecovery::class)->inspect();
            if ($verified !== $collisions) {
                throw new RuntimeException('Recovery preflight and actual collisions disagree.');
            }
            $this->resumeTables = $verified;
        }
        $this->createTable('fiscal_years', function (Blueprint $t): void {
            $this->base($t, 'af0');
            $t->string('code', 30);
            $t->string('name', 120);
            $t->date('starts_on');
            $t->date('ends_on');
            $t->string('state', 20)->default('open');
            $this->actor($t, 'closed_by', true);
            $t->timestamp('closed_at')->nullable();
            $this->revision($t);
            $t->unique(['company_id', 'code'], 'afy_code');
        });
        $this->createTable('accounting_periods', function (Blueprint $t): void {
            $this->base($t, 'af1');
            $t->unsignedBigInteger('fiscal_year_id');
            $t->string('code', 30);
            $t->date('starts_on');
            $t->date('ends_on');
            $t->string('state', 20)->default('open');
            $this->actor($t, 'closed_by', true);
            $t->timestamp('closed_at')->nullable();
            $this->revision($t);
            $t->unique(['company_id', 'code'], 'ap_code');
            $this->owned($t, 'fiscal_year_id', 'fiscal_years', 'ap_fy');
        });
        $this->createTable('account_groups', function (Blueprint $t): void {
            $this->base($t, 'af2');
            $t->string('code', 30);
            $t->string('name', 120);
            $t->string('category', 20);
            $t->string('normal_side', 10);
            $this->revision($t);
            $t->unique(['company_id', 'code'], 'ag_code');
            $t->unique(['company_id', 'category'], 'ag_category');
        });
        $this->createTable('accounts', function (Blueprint $t): void {
            $this->base($t, 'af3');
            $t->unsignedBigInteger('account_group_id');
            $t->unsignedBigInteger('parent_id')->nullable();
            $t->string('code', 40);
            $t->string('name', 160);
            $t->string('normal_side', 10);
            $t->boolean('is_postable')->default(true);
            $t->string('status', 20)->default('active');
            $t->string('control_role', 40)->nullable();
            $t->string('statement_role', 40);
            $t->string('cash_flow_role', 40)->nullable();
            $this->revision($t);
            $t->unique(['company_id', 'code'], 'ac_code');
            $this->owned($t, 'account_group_id', 'account_groups', 'ac_group');
            $this->owned($t, 'parent_id', 'accounts', 'ac_parent');
        });
        $this->createTable('accounting_mapping_versions', function (Blueprint $t): void {
            $this->base($t, 'af4');
            $t->unsignedInteger('version');
            $t->string('state', 20)->default('draft');
            $t->date('effective_from');
            $t->char('payload_hash', 64)->nullable();
            $this->actor($t, 'published_by', true);
            $t->timestamp('published_at')->nullable();
            $this->revision($t);
            $t->unique(['company_id', 'version'], 'amv_version');
        });
        $this->createTable('accounting_account_mappings', function (Blueprint $t): void {
            $this->base($t, 'af5');
            $t->unsignedBigInteger('mapping_version_id');
            $t->string('role', 40);
            $t->unsignedBigInteger('account_id');
            $t->unique(['mapping_version_id', 'role'], 'aam_role');
            $this->owned($t, 'mapping_version_id', 'accounting_mapping_versions', 'aam_version');
            $this->owned($t, 'account_id', 'accounts', 'aam_account');
        });
        $this->createTable('journal_entries', function (Blueprint $t): void {
            $this->base($t, 'af6');
            $t->unsignedBigInteger('accounting_period_id');
            $t->unsignedBigInteger('mapping_version_id')->nullable();
            $t->string('number', 60);
            $t->string('entry_kind', 30)->default('manual');
            $t->string('status', 20)->default('draft');
            $t->date('accounting_date');
            $t->date('document_date')->nullable();
            $t->char('currency_code', 3);
            $t->string('description', 500);
            $this->revision($t);
            $this->actor($t, 'created_by');
            $this->actor($t, 'updated_by');
            foreach (['submitted', 'approved', 'posted'] as $action) {
                $this->actor($t, $action.'_by', true);
                $t->timestamp($action.'_at')->nullable();
            } $t->unsignedBigInteger('reverses_journal_id')->nullable()->unique('je_reversal');
            $t->unsignedBigInteger('correction_of_journal_id')->nullable();
            $t->text('reason')->nullable();
            $t->char('payload_hash', 64)->nullable();
            $t->string('contract_version', 20)->default('1.0.0');
            $t->unique(['company_id', 'number'], 'je_number');
            $t->index(['company_id', 'accounting_date', 'status'], 'je_date');
            $this->owned($t, 'accounting_period_id', 'accounting_periods', 'je_period');
            $this->owned($t, 'mapping_version_id', 'accounting_mapping_versions', 'je_map');
            $this->owned($t, 'reverses_journal_id', 'journal_entries', 'je_reverse');
            $this->owned($t, 'correction_of_journal_id', 'journal_entries', 'je_correct');
        });
        $this->createTable('journal_entry_lines', function (Blueprint $t): void {
            $this->base($t, 'af7');
            $t->unsignedBigInteger('journal_entry_id');
            $t->unsignedInteger('line_number');
            $t->unsignedBigInteger('account_id');
            $t->decimal('debit', 20, 2)->default(0);
            $t->decimal('credit', 20, 2)->default(0);
            $t->string('party_type', 30)->nullable();
            $t->unsignedBigInteger('party_id')->nullable();
            $t->string('source_line_type', 40)->nullable();
            $t->unsignedBigInteger('source_line_id')->nullable();
            $t->unsignedBigInteger('cash_account_id')->nullable();
            $t->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $t->foreignId('department_id')->nullable()->constrained('departments')->restrictOnDelete();
            $t->string('description', 500)->nullable();
            $t->unique(['journal_entry_id', 'line_number'], 'jel_line');
            $t->index(['company_id', 'account_id'], 'jel_account');
            $this->owned($t, 'journal_entry_id', 'journal_entries', 'jel_header');
            $this->owned($t, 'account_id', 'accounts', 'jel_account_fk');
        });
        $this->createTable('accounting_source_effects', function (Blueprint $t): void {
            $this->base($t, 'af8');
            $t->string('source_kind', 40);
            $t->unsignedBigInteger('source_id');
            $t->string('event_kind', 40);
            $t->string('event_token', 64);
            $t->string('effect_kind', 40);
            $t->char('source_payload_hash', 64);
            $t->string('contract_version', 20)->default('1.0.0');
            $t->unsignedBigInteger('mapping_version_id')->nullable();
            $t->unsignedBigInteger('journal_entry_id')->nullable();
            $t->string('outcome', 30);
            $t->string('no_journal_reason', 60)->nullable();
            $t->date('source_document_date')->nullable();
            $t->date('accounting_date');
            $t->json('source_snapshot');
            $t->unique(['company_id', 'source_kind', 'source_id', 'event_kind', 'event_token', 'effect_kind'], 'ase_event');
            $this->owned($t, 'mapping_version_id', 'accounting_mapping_versions', 'ase_map');
            $this->owned($t, 'journal_entry_id', 'journal_entries', 'ase_journal');
        });
        $this->createTable('accounting_document_sequences', function (Blueprint $t): void {
            $this->base($t, 'af9');
            $t->unsignedBigInteger('fiscal_year_id');
            $t->string('document_kind', 30);
            $t->unsignedBigInteger('last_number')->default(0);
            $t->unique(['company_id', 'fiscal_year_id', 'document_kind'], 'ads_scope');
            $this->owned($t, 'fiscal_year_id', 'fiscal_years', 'ads_year');
        });
        $this->createTable('accounting_audit_events', function (Blueprint $t): void {
            $this->base($t, 'af10');
            $t->string('entity_kind', 40);
            $t->unsignedBigInteger('entity_id');
            $t->string('action', 40);
            $this->actor($t, 'actor_id');
            $t->json('actor_snapshot');
            $t->text('reason')->nullable();
            $t->json('before_payload')->nullable();
            $t->json('after_payload');
            $t->dateTime('occurred_at');
            $t->index(['company_id', 'entity_kind', 'entity_id', 'occurred_at'], 'aae_entity');
        });
        $this->createTable('accounting_cutovers', function (Blueprint $t): void {
            $this->base($t, 'af11');
            $t->date('cutover_date');
            $t->string('strategy', 20)->default('snapshot');
            $t->string('status', 20)->default('draft');
            $this->actor($t, 'created_by');
            foreach (['approved', 'activated'] as $action) {
                $this->actor($t, $action.'_by', true);
                $t->timestamp($action.'_at')->nullable();
            } $t->char('manifest_hash', 64)->nullable();
            $t->char('reconciliation_hash', 64)->nullable();
            $this->revision($t);
            $t->unique(['company_id', 'cutover_date'], 'aco_date');
        });
        $this->createTable('accounting_cutover_sources', function (Blueprint $t): void {
            $this->base($t, 'af12');
            $t->unsignedBigInteger('cutover_id');
            $t->string('source_kind', 40);
            $t->unsignedBigInteger('source_id');
            $t->string('coverage_kind', 40);
            $t->string('covered_through_event', 64)->nullable();
            $t->char('source_payload_hash', 64);
            $t->unsignedBigInteger('opening_journal_entry_id')->nullable();
            $t->json('opening_state');
            $t->unique(['cutover_id', 'source_kind', 'source_id', 'coverage_kind'], 'acs_source');
            $this->owned($t, 'cutover_id', 'accounting_cutovers', 'acs_cutover');
            $this->owned($t, 'opening_journal_entry_id', 'journal_entries', 'acs_opening');
        });
        $this->createTable('accounting_company_settings', function (Blueprint $t): void {
            $this->base($t, 'af13');
            $t->string('state', 20)->default('disabled');
            $t->char('functional_currency', 3);
            $t->unsignedTinyInteger('currency_precision')->default(2);
            $t->string('contract_version', 20)->default('1.0.0');
            $t->string('recognition_policy', 30)->default('invoice_cogs');
            $t->date('activation_date')->nullable();
            $t->timestamp('cutover_locked_at')->nullable();
            $t->unsignedBigInteger('active_mapping_version_id')->nullable();
            $this->actor($t, 'configured_by');
            $this->actor($t, 'activated_by', true);
            $t->timestamp('activated_at')->nullable();
            $this->revision($t);
            $t->unique('company_id', 'acs_company');
            $this->owned($t, 'active_mapping_version_id', 'accounting_mapping_versions', 'acs_map');
        });
    }

    public function down(): void
    {
        foreach (['journal_entries', 'accounting_source_effects'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Accounting rollback refused: '.$table.' contains history. Use a reviewed forward migration.');
            }
        }
        if (Schema::hasTable('accounting_cutovers') && DB::table('accounting_cutovers')->where('status', 'activated')->exists()) {
            throw new RuntimeException('Accounting rollback refused: an activated cutover exists.');
        }
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function createTable(string $table, Closure $definition): void
    {
        if (in_array($table, $this->resumeTables, true)) {
            return;
        }
        Schema::create($table, $definition);
    }

    private function base(Blueprint $t, string $index): void
    {
        $t->id();
        $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
        $t->unique(['id', 'company_id'], $index.'_owner');
        $t->timestamps();
    }

    private function revision(Blueprint $t): void
    {
        $t->unsignedInteger('revision')->default(1);
    }

    private function actor(Blueprint $t, string $column, bool $nullable = false): void
    {
        $field = $t->foreignId($column);
        if ($nullable) {
            $field->nullable();
        }
        $field->constrained('users')->restrictOnDelete();
    }

    private function owned(Blueprint $t, string $column, string $table, string $name): void
    {
        $t->foreign([$column, 'company_id'], $name)->references(['id', 'company_id'])->on($table)->restrictOnDelete()->restrictOnUpdate();
    }
};
