<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BusinessNotificationSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_settings(): void
    {
        $this->get('/admin/business-notifications')->assertRedirect('/login');
    }

    public function test_user_without_permissions_cannot_view_or_write_settings(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get('/admin/business-notifications')->assertForbidden();
        $this->put('/admin/business-notifications/recipients', [])->assertForbidden();
    }

    public function test_employee_admin_template_is_rejected(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('super-admin');
        $this->actingAs($user)->put('/admin/business-notifications/template', [
            'event' => 'employee.created', 'audience' => 'admin', 'channel' => 'sms',
            'subject' => null, 'body' => 'Employee created', 'enabled' => false,
        ])->assertSessionHasErrors('audience');
        $this->assertDatabaseCount('business_notification_templates', 0);
    }

    public function test_authorized_user_can_save_and_update_a_template_and_contacts(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('super-admin');
        $this->actingAs($user);
        $payload = ['event' => 'depot.submitted', 'audience' => 'applicant', 'channel' => 'email',
            'subject' => 'Received', 'body' => 'Your application is received.', 'enabled' => false];
        $this->put('/admin/business-notifications/template', $payload)->assertSessionHasNoErrors();
        $this->put('/admin/business-notifications/template', [...$payload, 'body' => 'Updated text'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('business_notification_templates', 1);
        $this->assertDatabaseHas('business_notification_templates', ['event' => 'depot.submitted', 'body' => 'Updated text']);
        $this->put('/admin/business-notifications/recipients', ['admin_email' => 'admin@example.com', 'admin_phone' => '01700000000'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('business_notification_settings', ['id' => 1, 'admin_email' => 'admin@example.com']);
    }
}
