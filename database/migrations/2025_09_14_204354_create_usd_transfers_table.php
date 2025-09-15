<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('usd_transfers', function (Illuminate\Database\Schema\Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('sender_number', 20);
            $table->string('receiver_number', 20);
            $table->decimal('amount_usd', 12, 2);
            $table->decimal('fees', 12, 2)->default(0);
            $table->decimal('price', 12, 2);
            $table->enum('provider', ['alfa','mtc']);
            $table->timestamps();
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('usd_transfers');
    }
};
