<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE `balances`
            MODIFY `provider` ENUM('alfa','mtc','mb_wish_us','mb_wish_lb','pc_wish_us','pc_wish_lb','my_balance')
            NOT NULL
        ");

        DB::table('balances')
            ->where('provider', 'wish')
            ->update(['provider' => 'mb_wish_us']);

        $now = now();
        foreach (['mb_wish_lb','pc_wish_us','pc_wish_lb'] as $p) {
            if (!DB::table('balances')->where('provider', $p)->exists()) {
                DB::table('balances')->insert([
                    'provider'   => $p,
                    'balance'    => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        $now = now();

        if (!DB::table('balances')->where('provider', 'wish')->exists()) {
            DB::table('balances')->insert([
                'provider'   => 'wish',
                'balance'    => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $sum = DB::table('balances')
            ->whereIn('provider', ['mb_wish_us','mb_wish_lb','pc_wish_us','pc_wish_lb'])
            ->sum('balance');

        DB::table('balances')->where('provider', 'wish')->update([
            'balance'    => DB::raw('`balance` + '.((float)$sum)),
            'updated_at' => $now,
        ]);

        DB::table('balances')->whereIn('provider', ['mb_wish_us','mb_wish_lb','pc_wish_us','pc_wish_lb'])->delete();

        DB::statement("
            ALTER TABLE `balances`
            MODIFY `provider` ENUM('alfa','mtc','wish','my_balance')
            NOT NULL
        ");
    }
};
