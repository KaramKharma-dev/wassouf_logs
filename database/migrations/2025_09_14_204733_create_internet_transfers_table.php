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
        Schema::create('internet_transfers', function (Illuminate\Database\Schema\Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('sender_number', 20);
            $table->string('receiver_number', 20);
            $table->decimal('quantity_gb', 12, 3);
            $table->decimal('price', 12, 2);
            $table->enum('provider', ['alfa','mtc']);
            $table->string('type', 50)->nullable();

            $table->timestamps();
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('internet_transfers');
    }
};
