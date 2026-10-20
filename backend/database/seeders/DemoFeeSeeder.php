<?php

namespace Database\Seeders;

use App\Models\FeeType;
use App\Services\FeeRateService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoFeeSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Demonstration fees may only be seeded locally or in tests.');
        }

        DB::transaction(function () {
            foreach (['REGISTRATION' => ['Registration fee', '20.00'], 'ANNUAL' => ['Annual membership fee', '50.00']] as $code => [$name, $amount]) {
                $type = FeeType::firstOrCreate(['code' => $code], ['name' => $name]);
                FeeType::query()->lockForUpdate()->findOrFail($type->id);
                if (! $type->rates()->exists()) {
                    app(FeeRateService::class)->createRate($type, $amount, '2026-01-01');
                }
            }
        });
    }
}
