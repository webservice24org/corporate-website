<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleController extends Controller
{
    public function index()
    {
        $roles = Role::query()
            ->withCount([
                'permissions',
                'users',
            ])
            ->orderBy('name')
            ->get();

        return inertia(
            'Admin/Roles/Index',
            [
                'roles' => $roles,
            ]
        );
    }

    public function create()
    {
        return inertia(
            'Admin/Roles/Create',
            [
                'permissionGroups' =>
                    $this->permissionGroups(),
            ]
        );
    }

    public function store(
        Request $request
    ) {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
                'alpha_dash:ascii',
                Rule::unique('roles', 'name')
                    ->where(
                        'guard_name',
                        'web'
                    ),
            ],

            'permissions' => [
                'array',
            ],

            'permissions.*' => [
                'string',
                'exists:permissions,name',
            ],
        ]);

        $role = Role::create([
            'name' =>
                $validated['name'],

            'guard_name' =>
                'web',
        ]);

        $role->syncPermissions(
            $validated[
                'permissions'
            ] ?? []
        );

        app(
            PermissionRegistrar::class
        )->forgetCachedPermissions();

        return redirect()
            ->route(
                'admin.roles.index'
            )
            ->with(
                'success',
                'Role created successfully.'
            );
    }

    public function edit(
        Role $role
    ) {
        return inertia(
            'Admin/Roles/Edit',
            [
                'role' => [
                    'id' => $role->id,

                    'name' =>
                        $role->name,

                    'permissions' =>
                        $role
                            ->permissions
                            ->pluck('name')
                            ->values(),
                ],

                'permissionGroups' =>
                    $this->permissionGroups(),

                'protectedRole' =>
                    $role->name ===
                    'super-admin',
            ]
        );
    }

    public function update(
        Request $request,
        Role $role
    ) {
        if (
            $role->name ===
            'super-admin'
        ) {
            return back()->with(
                'error',
                'The Super Admin role cannot be modified.'
            );
        }

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
                'alpha_dash:ascii',

                Rule::unique(
                    'roles',
                    'name'
                )
                    ->ignore($role->id)
                    ->where(
                        'guard_name',
                        'web'
                    ),
            ],

            'permissions' => [
                'array',
            ],

            'permissions.*' => [
                'string',
                'exists:permissions,name',
            ],
        ]);

        $role->update([
            'name' =>
                $validated['name'],
        ]);

        $role->syncPermissions(
            $validated[
                'permissions'
            ] ?? []
        );

        app(
            PermissionRegistrar::class
        )->forgetCachedPermissions();

        return redirect()
            ->route(
                'admin.roles.index'
            )
            ->with(
                'success',
                'Role updated successfully.'
            );
    }

    public function destroy(
        Role $role
    ) {
        if (
            $role->name ===
            'super-admin'
        ) {
            return back()->with(
                'error',
                'The Super Admin role cannot be deleted.'
            );
        }

        if (
            $role->users()->exists()
        ) {
            return back()->with(
                'error',
                'This role is assigned to users and cannot be deleted.'
            );
        }

        $role->delete();

        app(
            PermissionRegistrar::class
        )->forgetCachedPermissions();

        return back()->with(
            'success',
            'Role deleted successfully.'
        );
    }

    private function permissionGroups(): array
    {
        return Permission::query()
            ->orderBy('name')
            ->get()
            ->groupBy(
                function (
                    Permission $permission
                ) {
                    return explode(
                        '.',
                        $permission->name
                    )[0];
                }
            )
            ->map(
                function ($permissions) {
                    return $permissions
                        ->pluck('name')
                        ->values()
                        ->all();
                }
            )
            ->toArray();
    }
}