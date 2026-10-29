<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\User;
use App\Services\Notifications\SmsBdGateway;
use App\Services\Notifications\SmsBdSettings;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class SmsBdSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function administrator(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('super-admin');

        return $user;
    }

    public function test_guest_and_unprivileged_user_cannot_change_settings(): void
    {
        $this->put('/admin/sms-bd-settings', [])->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->put('/admin/sms-bd-settings', [])->assertForbidden();
    }

    public function test_api_key_is_encrypted_hidden_and_preserved_when_blank(): void
    {
        $this->actingAs($this->administrator());
        $this->put('/admin/sms-bd-settings', ['api_key' => 'private-test-key', 'sender_id' => 'TEST', 'enabled' => true])->assertSessionHasNoErrors();
        $stored = DB::table('sms_bd_settings')->where('id', 1)->value('api_key');
        self::assertIsString($stored);
        self::assertStringNotContainsString('private-test-key', $stored);
        $this->get('/admin/sms-bd-settings')->assertOk()->assertDontSee('private-test-key');
        $this->put('/admin/sms-bd-settings', ['api_key' => '', 'sender_id' => '', 'enabled' => true])->assertSessionHasNoErrors();
        self::assertSame('private-test-key', app(SmsBdSettings::class)->current()['api_key']);
    }

    public function test_enable_requires_an_admin_key(): void
    {
        $this->actingAs($this->administrator())->put('/admin/sms-bd-settings', ['api_key' => '', 'sender_id' => '', 'enabled' => true])->assertSessionHasErrors('api_key');
        $this->assertDatabaseCount('sms_bd_settings', 0);
    }

    public function test_invalid_submission_does_not_flash_api_key_to_old_input(): void
    {
        $this->actingAs($this->administrator())->put('/admin/sms-bd-settings', [
            'api_key' => 'private-test-key', 'sender_id' => str_repeat('a', 81), 'enabled' => true,
        ])->assertSessionHasErrors('sender_id')->assertSessionMissing('_old_input.api_key');
    }

    public function test_gateway_uses_the_saved_admin_key_without_a_real_request(): void
    {
        $this->actingAs($this->administrator())->put('/admin/sms-bd-settings', ['api_key' => 'stored-test-key', 'sender_id' => '', 'enabled' => true])->assertSessionHasNoErrors();
        Http::preventStrayRequests();
        Http::fake(['api.sms.net.bd/*' => Http::response(['error' => 0, 'data' => ['request_id' => 42]], 200)]);
        $result = app(SmsBdGateway::class)->send('01700000000', 'Test');
        self::assertSame('submitted', $result['status']);
        Http::assertSent(fn ($request): bool => $request['api_key'] === 'stored-test-key');
    }
}
