<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\User;
use App\Notifications\InventoryLowStockNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class NotificationSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function storeNotification(User $user, string $type, array $data): string
    {
        $id = (string) Str::uuid();

        DB::table('notifications')->insert([
            'id' => $id,
            'type' => $type,
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->getKey(),
            'data' => json_encode($data, JSON_THROW_ON_ERROR),
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    public function test_guest_cannot_read_unread_count(): void
    {
        $this->get(route('erp.notifications.unread-count'))
            ->assertRedirect();
    }

    public function test_unread_count_only_includes_owned_visible_notifications(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->storeNotification($user, 'crm.follow_up', ['title' => 'CRM']);
        $this->storeNotification($other, 'crm.follow_up', ['title' => 'Other']);
        $this->storeNotification($user, InventoryLowStockNotification::class, [
            'company_id' => 999999,
            'alert_id' => 123,
        ]);

        $this->actingAs($user)
            ->getJson(route('erp.notifications.unread-count'))
            ->assertOk()
            ->assertJsonPath('unread_count', 1);
    }

    public function test_user_cannot_mark_another_users_notification_read(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $id = $this->storeNotification($other, 'crm.follow_up', ['title' => 'Private']);

        $this->actingAs($user)
            ->post(route('erp.notifications.read', ['notification' => $id]))
            ->assertNotFound();

        $this->assertDatabaseHas('notifications', ['id' => $id, 'read_at' => null]);
    }

    public function test_user_cannot_mark_inaccessible_inventory_notification_read(): void
    {
        $user = User::factory()->create();
        $id = $this->storeNotification($user, InventoryLowStockNotification::class, [
            'company_id' => 999999,
            'alert_id' => 123,
        ]);

        $this->actingAs($user)
            ->post(route('erp.notifications.read', ['notification' => $id]))
            ->assertNotFound();

        $this->assertDatabaseHas('notifications', ['id' => $id, 'read_at' => null]);
    }

    public function test_mark_all_only_marks_owned_visible_notifications(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $crmId = $this->storeNotification($user, 'crm.follow_up', ['title' => 'CRM']);
        $hiddenId = $this->storeNotification($user, InventoryLowStockNotification::class, [
            'company_id' => 999999,
            'alert_id' => 123,
        ]);
        $otherId = $this->storeNotification($other, 'crm.follow_up', ['title' => 'Other']);

        $this->actingAs($user)
            ->post(route('erp.notifications.read-all'))
            ->assertRedirect();

        $this->assertDatabaseMissing('notifications', ['id' => $crmId, 'read_at' => null]);
        $this->assertDatabaseHas('notifications', ['id' => $hiddenId, 'read_at' => null]);
        $this->assertDatabaseHas('notifications', ['id' => $otherId, 'read_at' => null]);
    }
}
