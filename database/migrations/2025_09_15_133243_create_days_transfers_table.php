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
        Schema::create('days_transfers', function (Illuminate\Database\Schema\Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('sender_number', 20);
            $table->string('receiver_number', 20);

            $table->decimal('amount_usd', 12, 2); // كمية الدولارات
            $table->integer('months_count');      // عدد الأشهر المرسلة
            $table->decimal('price', 12, 2);      // سعر المبيع بالدولار
            $table->enum('provider', ['alfa','mtc']);
            $table->timestamps();
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('days_transfers');
    }
};
