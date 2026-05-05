<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $email = env('INVENTORY_ADMIN_EMAIL');
        $password = env('INVENTORY_ADMIN_PASSWORD');

        if (! $email || ! $password) {
            return;
        }

        User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => env('INVENTORY_ADMIN_NAME', 'Inventory Admin'),
                'password' => Hash::make($password),
            ],
        );
    }
}
