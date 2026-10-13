<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('redirects guests away from protected ERP pages', function (): void {
    $this->get(route('erp.dashboard'))
        ->assertRedirect(route('login'));

    $this->get(route('erp.depots.index'))
        ->assertRedirect(route('login'));

    $this->get(route('erp.notifications.index'))
        ->assertRedirect(route('login'));
});

it('forbids authenticated users without ERP permissions', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('erp.dashboard'))
        ->assertForbidden();

    $this->actingAs($user)
        ->get(route('erp.depots.index'))
        ->assertForbidden();
});

it('allows users who have the required ERP permissions', function (): void {
    $user = User::factory()->create();

    $permission = Permission::findOrCreate(
        'erp.dashboard.view',
        'web'
    );

    $user->givePermissionTo($permission);

    $this->actingAs($user)
        ->get(route('erp.dashboard'))
        ->assertOk();

    $this->actingAs($user)
        ->get(route('erp.depots.index'))
        ->assertForbidden();
});

it('allows super admins through the global Gate override', function (): void {
    $user = User::factory()->create();

    $role = Role::findOrCreate(
        'super-admin',
        'web'
    );

    $user->assignRole($role);

    $this->actingAs($user)
        ->get(route('erp.dashboard'))
        ->assertOk();
});

it('does not let a user mark another users notification as read', function (): void {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();

    $notificationId = (string) Str::uuid();

    DB::table('notifications')->insert([
        'id' => $notificationId,
        'type' => 'Tests\\Notifications\\ExampleNotification',
        'notifiable_type' => $owner->getMorphClass(),
        'notifiable_id' => $owner->getKey(),
        'data' => json_encode(
            ['title' => 'Private notification'],
            JSON_THROW_ON_ERROR
        ),
        'read_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($otherUser)
        ->post(route('erp.notifications.read', [
            'notification' => $notificationId,
        ]))
        ->assertNotFound();

    expect(
        DB::table('notifications')
            ->where('id', $notificationId)
            ->value('read_at')
    )->toBeNull();

    $this->actingAs($owner)
        ->post(route('erp.notifications.read', [
            'notification' => $notificationId,
        ]))
        ->assertRedirect();

    expect(
        DB::table('notifications')
            ->where('id', $notificationId)
            ->value('read_at')
    )->not->toBeNull();
});

it('marks only the current users notifications as read in bulk', function (): void {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();

    $ownerId = (string) Str::uuid();
    $otherId = (string) Str::uuid();

    foreach (
        [
            [$owner, $ownerId],
            [$otherUser, $otherId],
        ] as [$user, $id]
    ) {
        DB::table('notifications')->insert([
            'id' => $id,
            'type' => 'Tests\\Notifications\\ExampleNotification',
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->getKey(),
            'data' => '{}',
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $this->actingAs($owner)
        ->post(route('erp.notifications.read-all'))
        ->assertRedirect();

    expect(
        DB::table('notifications')
            ->where('id', $ownerId)
            ->value('read_at')
    )->not->toBeNull();

    expect(
        DB::table('notifications')
            ->where('id', $otherId)
            ->value('read_at')
    )->toBeNull();
});

it('registers one correctly handled ERP proprietor photo route per resource', function (): void {
    $expected = [
        'erp.depots.proprietor-photo' => [
            'uri' => 'erp/crm/depots/{depot}/proprietor-photo',
            'action' => 'App\\Http\\Controllers\\ERP\\CRM\\DepotAdminEditController@proprietorPhoto',
        ],
        'erp.dealers.proprietor-photo' => [
            'uri' => 'erp/crm/dealers/{dealer}/proprietor-photo',
            'action' => 'App\\Http\\Controllers\\ERP\\CRM\\DealerController@proprietorPhoto',
        ],
    ];

    foreach ($expected as $name => $details) {
        $matches = collect(
            Route::getRoutes()->getRoutes()
        )->filter(
            fn ($route) => in_array(
                'GET',
                $route->methods(),
                true
            ) && $route->uri() === $details['uri']
        );

        expect($matches)->toHaveCount(1);

        expect(
            Route::getRoutes()->getByName($name)
        )->not->toBeNull();

        expect(
            Route::getRoutes()
                ->getByName($name)
                ->getActionName()
        )->toBe($details['action']);
    }
});
