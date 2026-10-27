<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountGroup;
use App\Models\Accounting\AccountingCashAccount;
use App\Models\Accounting\AccountingExpense;
use App\Models\Accounting\AccountingExpenseAttachment;
use App\Models\Accounting\AccountingExpenseAttachmentDownload;
use App\Models\Accounting\AccountingExpenseAttachmentEvent;
use App\Models\Accounting\AccountingExpenseCategory;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingAccountService;
use App\Services\ERP\Accounting\AccountingSetupService;
use App\Services\ERP\Accounting\Expenses\AccountingExpenseAttachmentFiles;
use App\Services\ERP\Accounting\Expenses\AccountingExpenseAttachmentLedger;
use App\Services\ERP\Accounting\Expenses\AccountingExpenseAttachmentService;
use App\Services\ERP\Accounting\Expenses\AccountingExpenseGuard;
use App\Services\ERP\Accounting\Expenses\AccountingExpenseHistory;
use App\Services\ERP\Accounting\Expenses\AccountingExpenseWorkflow;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingExpenseAttachmentPermissionSeeder;
use Database\Seeders\AccountingExpensePermissionSeeder;
use Database\Seeders\AccountingPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AccountingExpenseAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $maker;

    private User $reviewer;

    private Account $gl;

    private AccountingExpenseCategory $category;

    private AccountingExpenseWorkflow $workflow;

    private AccountingExpenseAttachmentService $attachments;

    private AccountingExpense $expense;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->seed(AccountingPermissionSeeder::class);

        $this->seed(AccountingExpensePermissionSeeder::class);

        $this->seed(AccountingExpenseAttachmentPermissionSeeder::class);

        config(['accounting-expenses.private_root' => Storage::fake('expense_evidence_test')->path('')]);

        $this->travelTo(CarbonImmutable::parse('2026-10-26 12:00:00', 'Asia/Dhaka'));

        $this->company = $this->newCompany('EXP-A');

        $this->maker = User::factory()->create();

        $this->maker->assignRole('super-admin');

        $this->reviewer = User::factory()->create();

        $this->reviewer->assignRole('super-admin');

        app(AccountingSetupService::class)->initialize($this->maker, (int) $this->company->getKey());

        app(AccountingAccountService::class)->initializeGroups($this->maker, (int) $this->company->getKey());

        $this->gl = $this->account('expense', '6000');

        $this->workflow = app(AccountingExpenseWorkflow::class);

        $this->category = $this->workflow->saveCategory($this->maker, $this->categoryData());

        $this->expense = $this->workflow->save($this->maker, $this->data());

        $this->attachments = app(AccountingExpenseAttachmentService::class);
    }

    private function newCompany(string $code): Company
    {
        return Company::query()->create(['code' => $code, 'name' => $code, 'currency_code' => 'BDT', 'timezone' => 'Asia/Dhaka', 'status' => 'active']);
    }

    private function account(string $category, string $code, ?string $cashFlow = null): Account
    {
        $group = AccountGroup::query()->where('company_id', $this->company->getKey())->where('category', $category)->firstOrFail();

        return Account::query()->create(['company_id' => $this->company->getKey(), 'account_group_id' => $group->getKey(), 'code' => $code, 'name' => $code, 'normal_side' => 'debit', 'is_postable' => true, 'status' => 'active', 'revision' => 1, 'statement_role' => $category === 'expense' ? 'operating_expense' : 'current_asset', 'cash_flow_role' => $cashFlow ?? ($category === 'expense' ? 'operating' : null)]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function categoryData(array $overrides = []): array
    {
        return ['company_id' => (int) $this->company->getKey(), 'code' => 'OFFICE', 'name' => 'Office expenses', 'account_id' => (int) $this->gl->getKey(), 'status' => 'active', 'reason' => 'Reviewed category mapping', 'key' => (string) Str::uuid(), ...$overrides];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function data(array $overrides = []): array
    {
        return ['company_id' => (int) $this->company->getKey(), 'category_id' => (int) $this->category->getKey(), 'document_date' => '2026-10-26', 'accounting_date' => '2026-10-26', 'document_reference' => 'RECEIPT-1', 'payee_type' => 'other', 'payee_label' => 'Office Vendor', 'settlement_mode' => 'unpaid', 'net_amount' => '100.25', 'tax_amount' => '0.00', 'tax_treatment' => 'none', 'description' => 'Office stationery', 'key' => (string) Str::uuid(), ...$overrides];
    }

    private function move(AccountingExpense $row, string $action, ?User $actor = null): AccountingExpense
    {
        return $this->workflow->change($actor ?? $this->maker, (int) $row->getKey(), (int) $row->getAttribute('revision'), $action, 'Reviewed '.$action);
    }

    private function invalid(callable $action): void
    {
        try {
            $action();

            $this->fail('Expected validation failure.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
    }

    /** @param list<string> $permissions */
    private function staff(array $permissions): User
    {
        $user = User::factory()->create();

        Employee::query()->create(['company_id' => $this->company->getKey(), 'user_id' => $user->getKey(), 'employee_number' => 'EXP-'.$user->getKey(), 'first_name' => 'Expense', 'last_name' => 'User', 'employment_status' => 'active']);

        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }

        return $user;
    }

    private function supplier(): Supplier
    {
        return Supplier::query()->create(['company_id' => $this->company->getKey(), 'code' => 'EXP-S', 'name' => 'Supplier', 'currency_code' => 'BDT', 'status' => 'active']);
    }

    private function cash(): AccountingCashAccount
    {
        $gl = $this->account('asset', '1000', 'cash');

        return AccountingCashAccount::query()->create(['company_id' => $this->company->getKey(), 'account_id' => $gl->getKey(), 'role' => 'cash:EXPENSE', 'kind' => 'cash', 'name' => 'Expense cash', 'currency_code' => 'BDT', 'status' => 'active', 'revision' => 1]);
    }

    private function bytes(string $label = 'evidence'): string
    {
        return "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n% ".$label."\n%%EOF\n";
    }

    private function uploadFile(string $label = 'evidence', string $name = 'receipt.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $this->bytes($label));
    }

    private function attach(string $label = 'evidence', ?User $actor = null, ?string $key = null, ?int $revision = null): AccountingExpenseAttachment
    {
        return $this->attachments->upload($actor ?? $this->maker, (int) $this->expense->getKey(), $revision ?? (int) $this->expense->refresh()->getAttribute('revision'), $key ?? (string) Str::uuid(), 'Supporting document', $this->uploadFile($label));
    }

    private function base(): string
    {
        return '/erp/accounting/expenses/'.$this->expense->getKey().'/attachments';
    }

    private function path(AccountingExpenseAttachment $file): string
    {
        return Storage::disk('expense_evidence_test')->path((string) $file->getAttribute('storage_key'));
    }

    /** @return list<string> */
    private function filePermissions(): array
    {
        return ['erp.expenses.view', 'erp.expenses.edit', ...AccountingExpenseAttachmentLedger::PERMISSIONS];
    }

    public function test_guest_redirect_and_native_file_view_required(): void
    {
        $this->get($this->base())->assertRedirect('/login');

        $staff = $this->staff(['erp.expenses.view']);
        $this->actingAs($staff)->getJson($this->base())->assertForbidden();
    }

    public function test_upload_persists_private_bytes_and_advances_expense_intent(): void
    {
        $before = app(AccountingExpenseGuard::class)->intent($this->expense);
        $file = $this->attach();
        $row = $this->expense->refresh();

        $this->assertSame($this->bytes(), file_get_contents($this->path($file)));
        $this->assertSame(2, (int) $row->getAttribute('revision'));
        $this->assertSame(1, (int) $row->getAttribute('attachment_revision'));
        $this->assertSame('draft', $row->getAttribute('status'));

        $this->assertNotSame($before, app(AccountingExpenseGuard::class)->intent($row));
        $this->assertDatabaseCount('accounting_expense_attachment_events', 1);
        $this->assertDatabaseCount('journal_entries', 0);
        app(AccountingExpenseHistory::class)->verify($row);
    }

    public function test_upload_endpoint_and_safe_list_do_not_expose_storage_metadata(): void
    {
        $this->actingAs($this->maker)->postJson($this->base(), ['revision' => 1, 'key' => (string) Str::uuid(), 'reason' => 'Receipt evidence', 'file' => $this->uploadFile()])->assertOk()->assertJsonPath('revision', 2)->assertJsonCount(1, 'data')->assertJsonPath('data.0.active', true)->assertJsonMissingPath('data.0.storage_key')->assertJsonMissingPath('data.0.sha256')->assertJsonMissingPath('data.0.request_hash');
    }

    public function test_pdf_png_and_jpeg_are_accepted_from_actual_contents(): void
    {
        $this->attach();

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jB1kAAAAASUVORK5CYII=', true);

        $this->attachments->upload($this->maker, (int) $this->expense->getKey(), 2, (string) Str::uuid(), 'Image evidence', UploadedFile::fake()->createWithContent('receipt.png', $png));

        $jpeg = base64_decode(self::JPEG, true);

        $file = $this->attachments->upload($this->maker, (int) $this->expense->getKey(), 3, (string) Str::uuid(), 'JPEG evidence', UploadedFile::fake()->createWithContent('receipt.JPEG', $jpeg));

        $this->assertSame('jpg', $file->getAttribute('extension'));
        $this->assertDatabaseCount('accounting_expense_attachments', 3);
    }

    public function test_extension_mime_mismatch_and_executable_types_rejected(): void
    {
        foreach (['receipt.pdf' => '<?php echo "unsafe";', 'receipt.jpg' => $this->bytes(), 'receipt.svg' => '<svg/>', 'receipt.zip' => 'PK arbitrary archive', 'receipt.html' => '<html>untrusted</html>'] as $name => $bytes) {
            $this->invalid(fn () => $this->attachments->upload($this->maker, (int) $this->expense->getKey(), 1, (string) Str::uuid(), 'Invalid document', UploadedFile::fake()->createWithContent($name, $bytes)));
        }

        $this->assertDatabaseCount('accounting_expense_attachments', 0);
        $this->assertCount(0, Storage::disk('expense_evidence_test')->allFiles('company'));
    }

    public function test_invalid_pdf_and_truncated_image_rejected(): void
    {
        foreach (['bad.pdf' => '%PDF-1.4 no end marker', 'bad.png' => substr(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jB1kAAAAASUVORK5CYII='), 0, -12)] as $name => $bytes) {
            $this->invalid(fn () => $this->attachments->upload($this->maker, (int) $this->expense->getKey(), 1, (string) Str::uuid(), 'Invalid document', UploadedFile::fake()->createWithContent($name, $bytes)));
        }

        $this->assertDatabaseCount('accounting_expense_attachments', 0);
    }

    public function test_empty_and_over_limit_actual_bytes_are_rejected(): void
    {
        foreach (['', '%PDF-1.4'.str_repeat(' ', AccountingExpenseAttachmentFiles::MAX_BYTES).'%%EOF'] as $bytes) {
            $this->invalid(fn () => $this->attachments->upload($this->maker, (int) $this->expense->getKey(), 1, (string) Str::uuid(), 'Invalid document', UploadedFile::fake()->createWithContent('receipt.pdf', $bytes)));
        }

        $this->assertDatabaseCount('accounting_expense_attachments', 0);
    }

    public function test_control_characters_in_display_filename_are_rejected(): void
    {
        $this->invalid(fn () => $this->attachments->upload($this->maker, (int) $this->expense->getKey(), 1, (string) Str::uuid(), 'Invalid name', $this->uploadFile('evidence', "receipt\r\nInjected.pdf")));
        $this->assertDatabaseCount('accounting_expense_attachments', 0);
    }

    public function test_storage_paths_urls_and_ownership_cannot_be_supplied(): void
    {
        $this->actingAs($this->maker)->postJson($this->base(), ['revision' => 1, 'key' => (string) Str::uuid(), 'reason' => 'Receipt', 'file' => $this->uploadFile(), 'storage_key' => '../../public/payload.php'])->assertUnprocessable();
        $this->assertDatabaseCount('accounting_expense_attachments', 0);
    }

    public function test_upload_requires_both_file_upload_and_native_edit(): void
    {
        $staff = $this->staff(['erp.expenses.view', 'erp.expense-attachments.view', 'erp.expense-attachments.upload']);

        $this->actingAs($staff)->postJson($this->base(), ['revision' => 1, 'key' => (string) Str::uuid(), 'reason' => 'Receipt', 'file' => $this->uploadFile()])->assertForbidden();
        $this->assertDatabaseCount('accounting_expense_attachments', 0);
    }

    public function test_view_alone_does_not_authorize_download(): void
    {
        $file = $this->attach();
        $staff = $this->staff(['erp.expenses.view', 'erp.expense-attachments.view']);
        $this->actingAs($staff)->getJson($this->base())->assertOk()->assertJsonPath('data.0.download_url', null);

        $this->getJson($this->base().'/'.$file->getKey().'/download')->assertForbidden();
        $this->assertDatabaseCount('accounting_expense_attachment_downloads', 0);
    }

    public function test_authorized_download_is_verified_forced_private_and_audited(): void
    {
        $file = $this->attach();
        $response = $this->actingAs($this->maker)->get($this->base().'/'.$file->getKey().'/download')->assertOk()->assertHeader('Content-Type', 'application/octet-stream')->assertHeader('X-Content-Type-Options', 'nosniff')->assertDownload('expense-'.$this->expense->getKey().'-attachment-'.$file->getKey().'.pdf');

        $this->assertSame($this->bytes(), $response->streamedContent());
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertDatabaseCount('accounting_expense_attachment_downloads', 1);
        $this->assertSame(2, (int) $this->expense->refresh()->getAttribute('revision'));
    }

    public function test_cross_company_user_cannot_list_or_download(): void
    {
        $file = $this->attach();
        $foreign = $this->newCompany('EXP-B');
        $user = User::factory()->create();
        Employee::query()->create(['company_id' => $foreign->getKey(), 'user_id' => $user->getKey(), 'employee_number' => 'FOREIGN-1', 'first_name' => 'Foreign', 'last_name' => 'Staff']);
        $user->givePermissionTo($this->filePermissions());

        $this->actingAs($user)->getJson($this->base())->assertNotFound();
        $this->getJson($this->base().'/'.$file->getKey().'/download')->assertNotFound();
        $this->assertDatabaseCount('accounting_expense_attachment_downloads', 0);
    }

    public function test_other_expense_attachment_id_is_not_accepted_even_in_same_company(): void
    {
        $file = $this->attach();
        $other = $this->workflow->save($this->maker, $this->data(['document_reference' => 'OTHER-EXPENSE']));

        $this->actingAs($this->maker)->getJson('/erp/accounting/expenses/'.$other->getKey().'/attachments/'.$file->getKey().'/download')->assertNotFound();
    }

    public function test_same_upload_key_replays_without_new_bytes_or_history(): void
    {
        $key = (string) Str::uuid();
        $first = $this->attach('evidence', null, $key, 1);
        $again = $this->attach('evidence', null, $key, 1);

        $this->assertSame($first->getKey(), $again->getKey());
        $this->assertCount(1, Storage::disk('expense_evidence_test')->allFiles('company'));
        $this->assertDatabaseCount('accounting_expense_attachment_events', 1);
        $this->assertSame(2, (int) $this->expense->refresh()->getAttribute('revision'));
    }

    public function test_upload_key_conflict_is_rejected_without_overwrite(): void
    {
        $key = (string) Str::uuid();
        $file = $this->attach('evidence', null, $key, 1);
        $this->invalid(fn () => $this->attach('changed', null, $key, 1));
        $this->assertSame($this->bytes(), file_get_contents($this->path($file)));
        $this->assertDatabaseCount('accounting_expense_attachments', 1);
    }

    public function test_stale_upload_revision_is_rejected(): void
    {
        $this->attach();
        $this->invalid(fn () => $this->attach('new document', null, null, 1));
        $this->assertDatabaseCount('accounting_expense_attachments', 1);
    }

    public function test_duplicate_active_bytes_do_not_create_second_file(): void
    {
        $this->attach();
        $this->invalid(fn () => $this->attach());
        $this->assertCount(1, Storage::disk('expense_evidence_test')->allFiles('company'));
    }

    public function test_withdrawal_retains_original_bytes_and_audited_record(): void
    {
        $file = $this->attach();
        $this->attachments->withdraw($this->maker, (int) $this->expense->getKey(), (int) $file->getKey(), 2, 'Wrong receipt selected');

        $this->assertFileExists($this->path($file));
        $this->assertSame(3, (int) $this->expense->refresh()->getAttribute('revision'));
        $this->assertDatabaseCount('accounting_expense_attachments', 1);
        $this->assertDatabaseCount('accounting_expense_attachment_events', 2);

        $this->actingAs($this->maker)->getJson($this->base())->assertOk()->assertJsonPath('data.0.active', false)->assertJsonPath('history.1.action', 'withdraw');
    }

    public function test_withdrawal_endpoint_requires_current_revision_and_reason(): void
    {
        $file = $this->attach();
        $this->actingAs($this->maker)->postJson($this->base().'/'.$file->getKey().'/withdraw', ['revision' => 2, 'reason' => 'Wrong attachment'])->assertOk()->assertJsonPath('revision', 3);

        $this->postJson($this->base().'/'.$file->getKey().'/withdraw', ['revision' => 3, 'reason' => 'Again'])->assertUnprocessable();
    }

    public function test_submitted_evidence_cannot_be_added_or_withdrawn(): void
    {
        $file = $this->attach();
        $this->expense = $this->move($this->expense->refresh(), 'submit');

        $this->invalid(fn () => $this->attach('new document'));
        $this->invalid(fn () => $this->attachments->withdraw($this->maker, (int) $this->expense->getKey(), (int) $file->getKey(), 3, 'Too late'));
        $this->assertFileExists($this->path($file));
    }

    public function test_approved_snapshot_stays_unchanged_by_download_or_blocked_edits(): void
    {
        $file = $this->attach();
        $this->expense = $this->move($this->move($this->expense->refresh(), 'submit'), 'approve', $this->reviewer);
        $hash = $this->expense->getAttribute('approved_hash');

        $this->invalid(fn () => $this->attach('late document'));
        $response = $this->actingAs($this->reviewer)->get($this->base().'/'.$file->getKey().'/download')->assertOk();
        $this->assertSame($this->bytes(), $response->streamedContent());

        $this->assertSame($hash, $this->expense->refresh()->getAttribute('approved_hash'));
        $this->assertSame(1, (int) $this->expense->getAttribute('attachment_revision'));
        app(AccountingExpenseHistory::class)->verify($this->expense);
    }

    public function test_upload_retry_after_approval_replays_original_operation_only(): void
    {
        $key = (string) Str::uuid();
        $first = $this->attach('evidence', null, $key, 1);
        $this->expense = $this->move($this->move($this->expense->refresh(), 'submit'), 'approve', $this->reviewer);
        $again = $this->attach('evidence', null, $key, 1);

        $this->assertSame($first->getKey(), $again->getKey());
        $this->assertSame(4, (int) $this->expense->refresh()->getAttribute('revision'));
        $this->assertDatabaseCount('accounting_expense_attachment_events', 1);
    }

    public function test_rejection_allows_new_draft_evidence_then_fresh_approval(): void
    {
        $this->attach();
        $this->expense = $this->move($this->move($this->expense->refresh(), 'submit'), 'reject', $this->reviewer);
        $this->attach('new document');

        $this->expense = $this->move($this->move($this->expense->refresh(), 'submit'), 'approve', $this->reviewer);
        $this->assertSame('approved', $this->expense->getAttribute('status'));
        $this->assertSame(2, (int) $this->expense->getAttribute('attachment_revision'));
    }

    public function test_cancelled_expense_retains_downloads_and_blocks_new_upload(): void
    {
        $file = $this->attach();
        $this->expense = $this->move($this->expense->refresh(), 'cancel');
        $this->invalid(fn () => $this->attach('late file'));

        $this->actingAs($this->maker)->get($this->base().'/'.$file->getKey().'/download')->assertOk();
        $this->assertDatabaseCount('accounting_expense_attachments', 1);
    }

    public function test_missing_bytes_block_download_and_submission_without_repair(): void
    {
        $file = $this->attach();
        unlink($this->path($file));
        $this->actingAs($this->maker)->getJson($this->base().'/'.$file->getKey().'/download')->assertUnprocessable();

        $this->invalid(fn () => $this->move($this->expense->refresh(), 'submit'));
        $this->assertFileDoesNotExist($this->path($file));
        $this->assertDatabaseCount('accounting_expense_attachment_downloads', 0);
        $this->assertSame('draft', $this->expense->refresh()->getAttribute('status'));
    }

    public function test_same_size_modified_bytes_block_approval(): void
    {
        $file = $this->attach();
        $this->expense = $this->move($this->expense->refresh(), 'submit');
        file_put_contents($this->path($file), str_replace('evidence', 'modified', $this->bytes()));

        $this->invalid(fn () => $this->move($this->expense, 'approve', $this->reviewer));
        $this->assertSame('submitted', $this->expense->refresh()->getAttribute('status'));
        $this->assertDatabaseCount('accounting_expense_attachment_downloads', 0);
    }

    public function test_retry_does_not_recreate_missing_bytes(): void
    {
        $key = (string) Str::uuid();
        $file = $this->attach('evidence', null, $key, 1);
        unlink($this->path($file));
        $this->invalid(fn () => $this->attach('evidence', null, $key, 1));
        $this->assertCount(0, Storage::disk('expense_evidence_test')->allFiles('company'));
        $this->assertDatabaseCount('accounting_expense_attachments', 1);
    }

    public function test_changed_metadata_blocks_native_expense_reads(): void
    {
        $file = $this->attach();
        DB::table('accounting_expense_attachments')->where('id', $file->getKey())->update(['original_name' => 'tampered.pdf']);

        $this->actingAs($this->maker)->getJson('/erp/accounting/expenses/'.$this->expense->getKey())->assertUnprocessable()->assertJsonValidationErrors('attachment_integrity');
    }

    public function test_missing_history_is_not_repaired_on_upload_retry(): void
    {
        $key = (string) Str::uuid();
        $this->attach('evidence', null, $key, 1);
        DB::table('accounting_expenses')->where('id', $this->expense->getKey())->update(['attachment_head_id' => null]);
        DB::table('accounting_expense_attachment_events')->where('expense_id', $this->expense->getKey())->delete();

        $this->invalid(fn () => $this->attach('evidence', null, $key, 1));
        $this->assertDatabaseCount('accounting_expense_attachment_events', 0);
        $this->assertDatabaseCount('accounting_expense_attachments', 1);
    }

    public function test_attachment_audit_failure_rolls_back_header_and_compensates_bytes(): void
    {
        $active = true;
        AccountingExpenseAttachmentEvent::creating(static function () use (&$active): void {
            if ($active) {
                throw new \RuntimeException('Injected evidence audit failure');
            }
        });

        try {
            $this->attach();
            $this->fail('Expected failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Injected evidence audit failure', $e->getMessage());
        } finally {
            $active = false;
        }

        $this->assertDatabaseCount('accounting_expense_attachments', 0);
        $this->assertDatabaseCount('accounting_expense_attachment_events', 0);
        $this->assertCount(0, Storage::disk('expense_evidence_test')->allFiles('company'));
        $this->assertSame(1, (int) $this->expense->refresh()->getAttribute('revision'));
    }

    public function test_download_audit_failure_prevents_binary_response(): void
    {
        $file = $this->attach();
        $active = true;
        AccountingExpenseAttachmentDownload::creating(static function () use (&$active): void {
            if ($active) {
                throw new \RuntimeException('Injected download audit failure');
            }
        });

        try {
            $this->attachments->download($this->maker, (int) $this->expense->getKey(), (int) $file->getKey());
            $this->fail('Expected failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Injected download audit failure', $e->getMessage());
        } finally {
            $active = false;
        }

        $this->assertDatabaseCount('accounting_expense_attachment_downloads', 0);
        $this->assertFileExists($this->path($file));
    }

    public function test_evidence_contributor_cannot_approve_as_last_editor(): void
    {
        $editor = $this->staff([...$this->filePermissions(), 'erp.expenses.approve']);
        $this->attach('evidence', $editor);
        $this->expense = $this->move($this->expense->refresh(), 'submit');

        $this->expectException(AuthorizationException::class);
        $this->move($this->expense, 'approve', $editor);
    }

    public function test_reviewer_needs_permission_to_read_active_files(): void
    {
        $this->attach();
        $this->expense = $this->move($this->expense->refresh(), 'submit');
        $reviewer = $this->staff(['erp.expenses.view', 'erp.expenses.approve']);

        $this->expectException(AuthorizationException::class);
        $this->move($this->expense, 'approve', $reviewer);
    }

    public function test_active_file_limit_and_retained_withdrawal_limit(): void
    {
        for ($i = 0;
            $i < 10;
            $i++) {
            $this->attach('document '.$i);
        }

        $this->invalid(fn () => $this->attach('document 11'));
        $files = AccountingExpenseAttachment::query()->where('expense_id', $this->expense->getKey())->get();

        foreach ($files as $file) {
            $this->attachments->withdraw($this->maker, (int) $this->expense->getKey(), (int) $file->getKey(), (int) $this->expense->refresh()->getAttribute('revision'), 'Replace wrong file');
        }

        for ($i = 10;
            $i < 20;
            $i++) {
            $this->attach('document '.$i);
        }

        $first = AccountingExpenseAttachment::query()->where('expense_id', $this->expense->getKey())->orderByDesc('id')->firstOrFail();
        $this->attachments->withdraw($this->maker, (int) $this->expense->getKey(), (int) $first->getKey(), (int) $this->expense->refresh()->getAttribute('revision'), 'Withdraw latest');

        $this->invalid(fn () => $this->attach('document 21'));
        $this->assertDatabaseCount('accounting_expense_attachments', 20);
    }

    public function test_private_root_cannot_be_configured_inside_public_storage(): void
    {
        config(['accounting-expenses.private_root' => public_path('expense-evidence')]);
        $this->expectException(\LogicException::class);
        $this->attach();
    }

    public function test_metadata_and_download_logs_are_immutable_through_models(): void
    {
        $file = $this->attach();
        $this->expectException(\LogicException::class);
        $file->update(['original_name' => 'changed.pdf']);
    }

    public function test_attachment_events_are_immutable_through_models(): void
    {
        $this->attach();
        $event = AccountingExpenseAttachmentEvent::query()->firstOrFail();
        $this->expectException(\LogicException::class);
        $event->delete();
    }

    public function test_attachment_free_expense_hash_remains_compatible_and_approvable(): void
    {
        $this->assertSame(0, (int) $this->expense->getAttribute('attachment_revision'));
        $this->expense = $this->move($this->move($this->expense, 'submit'), 'approve', $this->reviewer);
        $this->assertSame('approved', $this->expense->getAttribute('status'));
        $this->assertDatabaseCount('accounting_expense_attachment_events', 0);
        app(AccountingExpenseHistory::class)->verify($this->expense);
    }

    public function test_total_byte_limit_includes_withdrawn_retained_files(): void
    {
        $first = null;

        for ($i = 0;
            $i < 5;
            $i++) {
            $prefix = "%PDF-1.4\n% receipt ".$i."\n";
            $bytes = $prefix.str_repeat(' ', 9 * 1024 * 1024 - strlen($prefix) - 6)."%%EOF\n";

            $file = $this->attachments->upload($this->maker, (int) $this->expense->getKey(), (int) $this->expense->refresh()->getAttribute('revision'), (string) Str::uuid(), 'Large receipt', UploadedFile::fake()->createWithContent('large.pdf', $bytes));
            $first ??= $file;
        }

        $this->attachments->withdraw($this->maker, (int) $this->expense->getKey(), (int) $first->getKey(), (int) $this->expense->refresh()->getAttribute('revision'), 'Retain withdrawn evidence');

        $prefix = "%PDF-1.4\n% receipt six\n";
        $bytes = $prefix.str_repeat(' ', 9 * 1024 * 1024 - strlen($prefix) - 6)."%%EOF\n";

        $this->invalid(fn () => $this->attachments->upload($this->maker, (int) $this->expense->getKey(), (int) $this->expense->refresh()->getAttribute('revision'), (string) Str::uuid(), 'Over aggregate limit', UploadedFile::fake()->createWithContent('large.pdf', $bytes)));
        $this->assertDatabaseCount('accounting_expense_attachments', 5);
    }

    public function test_download_uses_the_verified_copy_even_if_original_changes_after_preparation(): void
    {
        $file = $this->attach();
        $response = $this->attachments->download($this->maker, (int) $this->expense->getKey(), (int) $file->getKey());
        file_put_contents($this->path($file), 'Changed after download prepared');

        ob_start();
        try {
            $response->sendContent();
            $bytes = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        $this->assertSame($this->bytes(), $bytes);
        $this->assertDatabaseCount('accounting_expense_attachment_downloads', 1);
    }

    public function test_download_log_is_append_only_through_models(): void
    {
        $file = $this->attach();
        $response = $this->attachments->download($this->maker, (int) $this->expense->getKey(), (int) $file->getKey());
        unset($response);

        $log = AccountingExpenseAttachmentDownload::query()->firstOrFail();
        $this->expectException(\LogicException::class);
        $log->delete();
    }

    public function test_same_name_different_bytes_create_distinct_objects_without_overwrite(): void
    {
        $first = $this->attach('original');
        $second = $this->attach('replacement');

        $this->assertNotSame($first->getAttribute('storage_key'), $second->getAttribute('storage_key'));
        $this->assertSame($this->bytes('original'), file_get_contents($this->path($first)));
        $this->assertSame($this->bytes('replacement'), file_get_contents($this->path($second)));
    }

    public function test_raw_event_key_reordering_preserves_canonical_verification(): void
    {
        $this->attach();
        $event = AccountingExpenseAttachmentEvent::query()->firstOrFail();
        $payload = $event->getAttribute('payload');
        $payload['file'] = array_reverse($payload['file'], true);

        DB::table('accounting_expense_attachment_events')->where('id', $event->getKey())->update(['payload' => json_encode(array_reverse($payload, true), JSON_THROW_ON_ERROR)]);

        $this->actingAs($this->maker)->getJson($this->base())->assertOk()->assertJsonCount(1, 'data');
    }

    private const JPEG = '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwD3+iiigD//2Q==';
}
