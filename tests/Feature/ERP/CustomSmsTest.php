<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class CustomSmsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_and_unprivileged_user_cannot_send(): void
    {
        Http::fake();
        $this->post('/admin/custom-sms', [])->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->post('/admin/custom-sms', [])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_authorized_send_is_one_provider_request(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('super-admin');
        config(['sms-bd.enabled' => true, 'sms-bd.api_key' => 'test-only', 'sms-bd.sender_id' => null]);
        Http::preventStrayRequests();
        Http::fake(['api.sms.net.bd/*' => Http::response(['error' => 0, 'data' => ['request_id' => 123]], 200)]);
        $this->actingAs($user)->post('/admin/custom-sms', ['phone' => '01700000000', 'message' => 'Hello'])->assertSessionHasNoErrors();
        Http::assertSentCount(1);
    }
}
