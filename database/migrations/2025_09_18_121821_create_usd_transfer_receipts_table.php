<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // php artisan make:migration create_usd_transfer_receipts_table
    public function up(): void
    {
        Schema::create('usd_transfer_receipts', function (Illuminate\Database\Schema\Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('usd_transfer_id')->nullable(); // يرتبط بطلب مفتوح
            $table->string('receiver_number', 20);
            $table->enum('provider', ['alfa','mtc']);
            $table->decimal('chunk_amount_usd', 12, 2);   // قيمة الرسالة الواحدة (3 أو 1 ...الخ)
            $table->decimal('fee_usd', 12, 4);           // 0.14 لكل رسالة
            $table->decimal('price_usd', 12, 4);         // chunk * 1.1236
            $table->text('raw_body')->nullable();
            $table->timestamp('received_at')->useCurrent();
            $table->timestamps();

            $table->foreign('usd_transfer_id')->references('id')->on('usd_transfers')->nullOnDelete();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('usd_transfer_receipts');
    }

};
