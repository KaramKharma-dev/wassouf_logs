<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
    use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('balances', function (Illuminate\Database\Schema\Blueprint $table) {
            $table->bigIncrements('id');
            $table->enum('provider', ['alfa','mtc','wish','my_balance'])->unique();
            $table->decimal('balance', 18, 4)->default(0);
            $table->timestamps();
        });

        DB::table('balances')->insert([
            ['provider' => 'alfa',       'balance' => 0, 'created_at'=>now(), 'updated_at'=>now()],
            ['provider' => 'mtc',        'balance' => 0, 'created_at'=>now(), 'updated_at'=>now()],
            ['provider' => 'wish',       'balance' => 0, 'created_at'=>now(), 'updated_at'=>now()],
            ['provider' => 'my_balance', 'balance' => 0, 'created_at'=>now(), 'updated_at'=>now()],
        ]);
    }
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('balances');
    }
};
