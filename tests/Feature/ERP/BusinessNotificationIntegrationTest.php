<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\BusinessNotificationDelivery;
use App\Models\BusinessNotificationTemplate;
use App\Models\Company;
use App\Models\DealerApplication;
use App\Models\Depot;
use App\Models\DepotApplication;
use App\Models\District;
use App\Models\Division;
use App\Models\Employee;
use App\Models\MailSetting;
use App\Models\Upazila;
use App\Models\User;
use App\Services\DynamicMailConfig;
use App\Services\ERP\CRM\DealerApplicationApprovalService;
use App\Services\ERP\CRM\DepotApplicationApprovalService;
use App\Services\Notifications\BusinessNotificationProcessor;
use Database\Seeders\BusinessNotificationDefaultsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class BusinessNotificationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->company = Company::query()->create(['name' => 'Notification Test', 'code' => 'NOTIFY-A', 'status' => 'active', 'currency_code' => 'BDT', 'timezone' => 'Asia/Dhaka']);
        $this->seed(BusinessNotificationDefaultsSeeder::class);
        BusinessNotificationTemplate::query()->update(['enabled' => true]);
        DB::table('business_notification_settings')->insert(['id' => 1, 'admin_email' => 'admin@example.com', 'admin_phone' => '01800000000', 'automation_enabled' => true]);
        config(['sms-bd.enabled' => true, 'sms-bd.api_key' => 'fake-test-key', 'sms-bd.sender_id' => null]);
        Http::preventStrayRequests();
        Http::fake(['api.sms.net.bd/*' => Http::response(['error' => 0, 'data' => ['request_id' => 123]], 200)]);
        $mail = Mockery::mock(DynamicMailConfig::class);
        $mail->shouldReceive('apply')->andReturn(null);
        $this->app->instance(DynamicMailConfig::class, $mail);
    }

    private function application(array $extra = []): DepotApplication
    {
        return DB::transaction(fn (): DepotApplication => DepotApplication::query()->create([
            'company_id' => $this->company->getKey(), 'business_name' => 'Example business', 'proprietor_name' => 'Alex',
            'phone' => '01700000000', 'email' => 'alex@example.com', 'business_address' => 'Example address',
            'status' => 'pending', ...$extra,
        ]));
    }

    public function test_submission_records_messages_without_sending_during_registration(): void
    {
        $this->application();
        $this->assertDatabaseCount('business_notification_deliveries', 4);
        $this->assertDatabaseHas('business_notification_deliveries', ['event' => 'depot.submitted', 'audience' => 'applicant', 'status' => 'pending']);
        Http::assertNothingSent();
        $row = BusinessNotificationDelivery::query()->where('audience', 'applicant')->where('channel', 'email')->firstOrFail();
        self::assertStringContainsString('Alex', (string) $row->getAttribute('body'));
        $raw = DB::table('business_notification_deliveries')->where('id', $row->getKey())->first();
        self::assertNotNull($raw);
        self::assertStringNotContainsString('alex@example.com', (string) $raw->recipient);
        self::assertStringNotContainsString('Example business', (string) $raw->body);
    }

    public function test_rollback_removes_all_notification_records(): void
    {
        try {
            DB::transaction(function (): void {
                $this->application();
                throw new RuntimeException('Fixture rollback');
            });
        } catch (RuntimeException $exception) {
            self::assertSame('Fixture rollback', $exception->getMessage());
        }
        $this->assertDatabaseCount('business_notification_deliveries', 0);
        $this->assertDatabaseCount('depot_applications', 0);
    }

    public function test_approval_records_once_and_editing_an_approved_record_does_not_repeat(): void
    {
        $source = $this->application();
        $reviewer = User::factory()->create();
        app(DepotApplicationApprovalService::class)->approve($source, $reviewer);
        app(DepotApplicationApprovalService::class)->approve($source, $reviewer);
        $source->refresh();
        DB::transaction(fn () => $source->update(['admin_notes' => 'Updated notes']));
        $this->assertDatabaseCount('business_notification_deliveries', 8);
        self::assertSame(4, BusinessNotificationDelivery::query()->where('event', 'depot.approved')->count());
    }

    public function test_worker_submits_each_sms_once_and_blocks_unconfigured_email(): void
    {
        $this->application();
        app(BusinessNotificationProcessor::class)->process();
        app(BusinessNotificationProcessor::class)->process();
        Http::assertSentCount(2);
        self::assertSame(2, BusinessNotificationDelivery::query()->where('status', 'submitted')->count());
        self::assertSame(2, BusinessNotificationDelivery::query()->where('status', 'blocked')->count());
    }

    public function test_timeout_becomes_uncertain_and_is_never_automatically_retried(): void
    {
        $this->application();
        Http::fake(['api.sms.net.bd/*' => function () {
            throw new ConnectionException('Fake timeout');
        }]);
        app(BusinessNotificationProcessor::class)->process();
        self::assertSame(2, BusinessNotificationDelivery::query()->where('status', 'uncertain')->count());
        self::assertSame(4, (int) BusinessNotificationDelivery::query()->sum('attempts'));
        app(BusinessNotificationProcessor::class)->process();
        self::assertSame(4, (int) BusinessNotificationDelivery::query()->sum('attempts'));
    }

    public function test_changed_contact_is_cancelled_instead_of_sending_to_old_number(): void
    {
        $source = $this->application();
        $source->update(['phone' => '01900000000']);
        app(BusinessNotificationProcessor::class)->process();
        $this->assertDatabaseHas('business_notification_deliveries', ['audience' => 'applicant', 'channel' => 'sms', 'status' => 'cancelled', 'reason' => 'recipient_changed']);
        Http::assertSentCount(1);
    }

    public function test_employee_only_receives_messages_using_work_contact_then_personal_fallback(): void
    {
        $employee = DB::transaction(fn (): Employee => Employee::query()->create([
            'company_id' => $this->company->getKey(), 'employee_number' => 'NOTICE-EMP-1', 'first_name' => 'Taylor', 'last_name' => 'Example',
            'work_email' => 'work@example.com', 'personal_email' => 'personal@example.com', 'work_phone' => null, 'personal_phone' => '01900000000',
        ]));
        $this->assertDatabaseCount('business_notification_deliveries', 2);
        $rows = BusinessNotificationDelivery::query()->where('source_id', $employee->getKey())->get();
        foreach ($rows as $row) {
            self::assertSame('employee', $row->getAttribute('audience'));
            self::assertSame($row->getAttribute('channel') === 'email' ? 'work@example.com' : '8801900000000', $row->getAttribute('recipient'));
        }
    }

    public function test_disabled_automation_does_not_backfill_existing_records(): void
    {
        DB::table('business_notification_settings')->where('id', 1)->update(['automation_enabled' => false]);
        $source = $this->application();
        DB::table('business_notification_settings')->where('id', 1)->update(['automation_enabled' => true]);
        $source->update(['admin_notes' => 'An ordinary edit']);
        $this->assertDatabaseCount('business_notification_deliveries', 0);
    }

    public function test_disabled_template_is_logged_as_skipped(): void
    {
        BusinessNotificationTemplate::query()->where('event', 'depot.submitted')->where('channel', 'sms')->update(['enabled' => false]);
        $this->application();
        self::assertSame(2, BusinessNotificationDelivery::query()->where('status', 'skipped')->count());
    }

    public function test_dealer_submission_and_approval_are_observed(): void
    {
        $division = Division::query()->create(['name' => 'Test division', 'is_active' => true]);
        $district = District::query()->create(['division_id' => $division->getKey(), 'name' => 'Test district', 'is_active' => true]);
        $upazila = Upazila::query()->create(['district_id' => $district->getKey(), 'name' => 'Test upazila', 'is_active' => true]);
        $depot = Depot::query()->create(['company_id' => $this->company->getKey(), 'division_id' => $division->getKey(), 'district_id' => $district->getKey(), 'name' => 'Example depot', 'is_active' => true]);
        $dealer = DB::transaction(fn (): DealerApplication => DealerApplication::query()->create([
            'company_id' => $this->company->getKey(), 'depot_id' => $depot->getKey(), 'upazila_id' => $upazila->getKey(),
            'business_name' => 'Dealer business', 'proprietor_name' => 'Jordan', 'phone' => '01700000000',
            'email' => 'dealer@example.com', 'business_address' => 'Address', 'status' => 'pending',
        ]));
        $reviewer = User::factory()->create();
        foreach (['bank_check', 'trade_license', 'nid'] as $type) {
            $dealer->documents()->create(['document_type' => $type, 'disk' => 'local', 'path' => 'fixture/'.$type.'.pdf',
                'original_name' => $type.'.pdf', 'verified_by_user_id' => $reviewer->getKey(), 'verified_at' => now()]);
        }
        app(DealerApplicationApprovalService::class)->approve($dealer, $reviewer);
        self::assertSame(4, BusinessNotificationDelivery::query()->where('event', 'dealer.submitted')->count());
        self::assertSame(4, BusinessNotificationDelivery::query()->where('event', 'dealer.approved')->count());
    }

    public function test_unknown_send_cannot_be_retried_through_admin_action(): void
    {
        $source = $this->application();
        $delivery = BusinessNotificationDelivery::query()->where('source_id', $source->getKey())->firstOrFail();
        $delivery->update(['status' => 'uncertain']);
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        $this->actingAs($admin)->post('/admin/notification-history/'.$delivery->getKey(), ['action' => 'retry'])->assertSessionHasErrors('delivery');
        self::assertSame('uncertain', $delivery->fresh()->getAttribute('status'));
        Http::assertNothingSent();
    }

    public function test_unprivileged_user_cannot_view_delivery_history(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/notification-history')->assertForbidden();
    }

    public function test_worker_uses_saved_email_text_and_active_smtp_service(): void
    {
        BusinessNotificationTemplate::query()->update(['enabled' => false]);
        BusinessNotificationTemplate::query()->where('event', 'depot.submitted')->where('audience', 'applicant')->where('channel', 'email')
            ->update(['enabled' => true, 'subject' => 'Hello {name}', 'body' => 'Saved text for {business_name}']);
        $this->application();
        // Later template edits cannot rewrite a queued message.
        BusinessNotificationTemplate::query()->where('event', 'depot.submitted')->where('audience', 'applicant')->where('channel', 'email')
            ->update(['body' => 'New text']);
        $mail = Mockery::mock(DynamicMailConfig::class);
        $mail->shouldReceive('apply')->once()->andReturn(new MailSetting);
        $this->app->instance(DynamicMailConfig::class, $mail);
        Mail::shouldReceive('raw')->once()->with('Saved text for Example business', Mockery::type('callable'));
        app(BusinessNotificationProcessor::class)->process();
        $this->assertDatabaseHas('business_notification_deliveries', ['channel' => 'email', 'status' => 'submitted']);
        Http::assertNothingSent();
    }

    public function test_interrupted_worker_is_marked_uncertain_without_sending_again(): void
    {
        $this->application();
        BusinessNotificationDelivery::query()->where('status', 'pending')->update(['status' => 'processing', 'claimed_at' => now()->subMinutes(11)]);
        app(BusinessNotificationProcessor::class)->process();
        self::assertSame(4, BusinessNotificationDelivery::query()->where('status', 'uncertain')->count());
        Http::assertNothingSent();
    }

    public function test_fast_approval_does_not_send_an_outdated_awaiting_review_message(): void
    {
        $source = $this->application();
        DB::transaction(fn () => $source->update(['status' => 'approved']));
        app(BusinessNotificationProcessor::class)->process();
        self::assertSame(4, BusinessNotificationDelivery::query()->where('event', 'depot.submitted')->where('status', 'cancelled')->count());
        Http::assertSentCount(2);
    }
}
