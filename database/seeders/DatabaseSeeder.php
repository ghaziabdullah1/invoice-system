<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run()
    {
        $this->call([
            PermissionSystemSeeder::class,
            InstallmentInterestTierSeeder::class,
            ContentPagesSeeder::class,
            //BranchesSeeder::class,
            //InstallmentPlansSeeder::class,
        ]);
    }
}
