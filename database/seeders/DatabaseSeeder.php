<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database with the demo workspace.
     */
    public function run(): void
    {
        $this->call(PlanSeeder::class);
        $this->call(DemoSeeder::class);
    }
}
