<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        // O e-mail é único: sem esta checagem, rodar db:seed pela segunda vez falharia por duplicidade
        if(!User::where('email', 'test@example.com')->exists()):
            User::factory()->create([
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);
        endif;

        $this->call(EstoqueSeeder::class);
    }
}
