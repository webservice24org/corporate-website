<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->Create([
            'name' => 'Mohiuddin Bhuiyan',
            'email' => 'mohiuddin3824@gmail.com',
            'password' => Hash::make('Mwt@08456@M#'),
        ]);

        $this->call([
            RolePermissionSeeder::class,
            OpportunitySeeder::class,
            PurchasePermissionSeeder::class,
            SalesPermissionSeeder::class,
        ]);
    }
}
