<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $search = trim(
            (string) $request->input(
                'search',
                ''
            )
        );

        $users = User::query()
            ->with([
                'profile',
                'roles:id,name',
            ])
            ->when(
                $search !== '',
                function ($query) use ($search) {
                    $query->where(
                        function ($query) use ($search) {
                            $query
                                ->where(
                                    'name',
                                    'like',
                                    "%{$search}%"
                                )
                                ->orWhere(
                                    'email',
                                    'like',
                                    "%{$search}%"
                                )
                                ->orWhereHas(
                                    'profile',
                                    function ($profileQuery) use ($search) {
                                        $profileQuery
                                            ->where(
                                                'employee_id',
                                                'like',
                                                "%{$search}%"
                                            )
                                            ->orWhere(
                                                'phone',
                                                'like',
                                                "%{$search}%"
                                            )
                                            ->orWhere(
                                                'designation',
                                                'like',
                                                "%{$search}%"
                                            );
                                    }
                                );
                        }
                    );
                }
            )
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return inertia(
            'Admin/Users/Index',
            [
                'users' => $users,

                'filters' => [
                    'search' => $search,
                ],
            ]
        );
    }

    public function create()
    {
        return inertia(
            'Admin/Users/Create',
            [
                'roles' => $this->roles(),
            ]
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            /*
             * Account
             */
            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'email' => [
                'required',
                'email',
                'max:255',
                'unique:users,email',
            ],

            'password' => [
                'required',
                'confirmed',
                Password::defaults(),
            ],

            /*
             * Roles
             */
            'roles' => [
                'array',
            ],

            'roles.*' => [
                'string',
                'exists:roles,name',
            ],

            /*
             * Profile
             */
            'profile_photo' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:3072',
            ],

            'phone' => [
                'nullable',
                'string',
                'max:50',
            ],

            'employee_id' => [
                'nullable',
                'string',
                'max:100',
                'unique:user_profiles,employee_id',
            ],

            'designation' => [
                'nullable',
                'string',
                'max:255',
            ],

            'department' => [
                'nullable',
                'string',
                'max:255',
            ],

            'date_of_birth' => [
                'nullable',
                'date',
            ],

            'gender' => [
                'nullable',
                'string',
                'max:30',
            ],

            'address' => [
                'nullable',
                'string',
                'max:1000',
            ],

            'city' => [
                'nullable',
                'string',
                'max:150',
            ],

            'country' => [
                'nullable',
                'string',
                'max:150',
            ],

            'bio' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ]);

        DB::transaction(
            function () use ($request, $validated) {
                $user = User::create([
                    'name' => $validated['name'],

                    'email' => $validated['email'],

                    'password' => Hash::make(
                        $validated['password']
                    ),
                ]);

                $photoPath = null;

                if (
                    $request->hasFile(
                        'profile_photo'
                    )
                ) {
                    $photoPath =
                        $request
                            ->file(
                                'profile_photo'
                            )
                            ->store(
                                'user-profiles',
                                'public'
                            );
                }

                $user->profile()->create([
                    'profile_photo' => $photoPath,

                    'phone' => $validated['phone'] ??
                        null,

                    'employee_id' => $validated['employee_id'] ??
                        null,

                    'designation' => $validated['designation'] ??
                        null,

                    'department' => $validated['department'] ??
                        null,

                    'date_of_birth' => $validated['date_of_birth'] ??
                        null,

                    'gender' => $validated['gender'] ??
                        null,

                    'address' => $validated['address'] ??
                        null,

                    'city' => $validated['city'] ??
                        null,

                    'country' => $validated['country'] ??
                        null,

                    'bio' => $validated['bio'] ??
                        null,
                ]);

                $user->syncRoles(
                    $validated['roles'] ??
                    []
                );
            }
        );

        return redirect()
            ->route(
                'admin.users.index'
            )
            ->with(
                'success',
                'User created successfully.'
            );
    }

    public function edit(User $user)
    {
        $user->load([
            'profile',
            'roles:id,name',
        ]);

        return inertia(
            'Admin/Users/Edit',
            [
                'user' => [
                    'id' => $user->id,

                    'name' => $user->name,

                    'email' => $user->email,

                    'email_verified_at' => $user->email_verified_at,

                    'roles' => $user
                        ->roles
                        ->pluck('name')
                        ->values(),

                    'profile' => $user->profile,
                ],

                'roles' => $this->roles(),
            ]
        );
    }

    public function update(
        Request $request,
        User $user
    ) {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'email' => [
                'required',
                'email',
                'max:255',

                Rule::unique(
                    'users',
                    'email'
                )->ignore($user->id),
            ],

            'password' => [
                'nullable',
                'confirmed',
                Password::defaults(),
            ],

            'roles' => [
                'array',
            ],

            'roles.*' => [
                'string',
                'exists:roles,name',
            ],

            'profile_photo' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:3072',
            ],

            'remove_profile_photo' => [
                'nullable',
                'boolean',
            ],

            'phone' => [
                'nullable',
                'string',
                'max:50',
            ],

            'employee_id' => [
                'nullable',
                'string',
                'max:100',

                Rule::unique(
                    'user_profiles',
                    'employee_id'
                )->ignore(
                    $user->profile?->getKey()
                ),
            ],

            'designation' => [
                'nullable',
                'string',
                'max:255',
            ],

            'department' => [
                'nullable',
                'string',
                'max:255',
            ],

            'date_of_birth' => [
                'nullable',
                'date',
            ],

            'gender' => [
                'nullable',
                'string',
                'max:30',
            ],

            'address' => [
                'nullable',
                'string',
                'max:1000',
            ],

            'city' => [
                'nullable',
                'string',
                'max:150',
            ],

            'country' => [
                'nullable',
                'string',
                'max:150',
            ],

            'bio' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ]);

        DB::transaction(
            function () use ($request, $validated, $user) {
                $userData = [
                    'name' => $validated['name'],

                    'email' => $validated['email'],
                ];

                if (
                    ! empty(
                        $validated['password']
                    )
                ) {
                    $userData['password'] =
                        Hash::make(
                            $validated['password']
                        );
                }

                $user->update(
                    $userData
                );

                $profile =
                    $user
                        ->profile()
                        ->firstOrCreate([]);

                $photoPath =
                    $profile->getAttribute('profile_photo');

                if (
                    $request->boolean(
                        'remove_profile_photo'
                    )
                ) {
                    if ($photoPath) {
                        Storage::disk(
                            'public'
                        )->delete(
                            $photoPath
                        );
                    }

                    $photoPath = null;
                }

                if (
                    $request->hasFile(
                        'profile_photo'
                    )
                ) {
                    if ($photoPath) {
                        Storage::disk(
                            'public'
                        )->delete(
                            $photoPath
                        );
                    }

                    $photoPath =
                        $request
                            ->file(
                                'profile_photo'
                            )
                            ->store(
                                'user-profiles',
                                'public'
                            );
                }

                $profile->update([
                    'profile_photo' => $photoPath,

                    'phone' => $validated['phone'] ??
                        null,

                    'employee_id' => $validated['employee_id'] ??
                        null,

                    'designation' => $validated['designation'] ??
                        null,

                    'department' => $validated['department'] ??
                        null,

                    'date_of_birth' => $validated['date_of_birth'] ??
                        null,

                    'gender' => $validated['gender'] ??
                        null,

                    'address' => $validated['address'] ??
                        null,

                    'city' => $validated['city'] ??
                        null,

                    'country' => $validated['country'] ??
                        null,

                    'bio' => $validated['bio'] ??
                        null,
                ]);

                $user->syncRoles(
                    $validated['roles'] ??
                    []
                );
            }
        );

        return redirect()
            ->route(
                'admin.users.index'
            )
            ->with(
                'success',
                'User updated successfully.'
            );
    }

    public function destroy(
        Request $request,
        User $user
    ) {
        /*
         * Never allow a user to delete
         * their own logged-in account.
         */
        if (
            $request->user()->id ===
            $user->id
        ) {
            return back()->with(
                'error',
                'You cannot delete your own account.'
            );
        }

        /*
         * Prevent deleting the last
         * Super Admin.
         */
        if (
            $user->hasRole(
                'super-admin'
            )
        ) {
            $superAdminCount =
                User::role(
                    'super-admin'
                )->count();

            if (
                $superAdminCount <= 1
            ) {
                return back()->with(
                    'error',
                    'The last Super Admin account cannot be deleted.'
                );
            }
        }

        $profilePhoto = $user->profile?->getAttribute('profile_photo');

        if (is_string($profilePhoto) && $profilePhoto !== '') {
            Storage::disk('public')->delete($profilePhoto);
        }

        $user->delete();

        return back()->with(
            'success',
            'User deleted successfully.'
        );
    }

    private function roles()
    {
        return Role::query()
            ->where(
                'guard_name',
                'web'
            )
            ->orderBy('name')
            ->get([
                'id',
                'name',
            ]);
    }
}
