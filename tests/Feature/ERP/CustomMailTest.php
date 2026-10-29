<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\MailSetting;
use App\Models\User;
use App\Services\DynamicMailConfig;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

final class CustomMailTest extends TestCase
{
    use RefreshDatabase;

    private function administrator(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('super-admin');

        return $user;
    }

    public function test_guest_and_unprivileged_user_cannot_send(): void
    {
        $this->post('/admin/custom-mail/send', [])->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->post('/admin/custom-mail/send', [])->assertForbidden();
    }

    public function test_authorized_user_can_save_and_update_template_without_a_recipient(): void
    {
        $this->actingAs($this->administrator());
        $this->put('/admin/custom-mail/template', ['subject' => 'Hello', 'message' => 'Welcome'])->assertSessionHasNoErrors();
        $this->put('/admin/custom-mail/template', ['subject' => 'Updated', 'message' => 'New text'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('business_notification_templates', 1);
        $this->assertDatabaseHas('business_notification_templates', ['event' => 'custom.email', 'subject' => 'Updated', 'body' => 'New text']);
    }

    public function test_missing_smtp_configuration_blocks_send(): void
    {
        $this->actingAs($this->administrator());
        $config = Mockery::mock(DynamicMailConfig::class);
        $config->shouldReceive('apply')->once()->andReturn(null);
        $this->app->instance(DynamicMailConfig::class, $config);
        Mail::shouldReceive('raw')->never();
        $this->post('/admin/custom-mail/send', ['email' => 'example@example.com', 'subject' => 'Hello', 'message' => 'Message'])
            ->assertSessionHasErrors('mail');
    }

    public function test_send_uses_the_configured_mail_service(): void
    {
        $this->actingAs($this->administrator());
        $config = Mockery::mock(DynamicMailConfig::class);
        $config->shouldReceive('apply')->once()->andReturn(new MailSetting);
        $this->app->instance(DynamicMailConfig::class, $config);
        Mail::shouldReceive('raw')->once()->with('Message', Mockery::type('callable'));
        $this->post('/admin/custom-mail/send', ['email' => 'example@example.com', 'subject' => 'Hello', 'message' => 'Message'])
            ->assertSessionHasNoErrors();
    }
}
