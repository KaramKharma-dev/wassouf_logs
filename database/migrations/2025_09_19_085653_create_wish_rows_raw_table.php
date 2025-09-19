<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('wish_rows_raw', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('batch_id')->constrained('wish_batches')->cascadeOnDelete();
            $table->unsignedInteger('seq_no');                 // ترتيب السطر داخل الـPDF
            $table->date('op_date')->nullable();               // Date
            $table->string('reference', 32)->nullable();       // tr:183666144
            $table->text('description')->nullable();           // Service Description
            $table->decimal('debit', 14, 2)->nullable();       // Debit
            $table->decimal('credit', 14, 2)->nullable();      // Credit
            $table->decimal('balance_after', 14, 2)->nullable();// Balance
            $table->enum('row_status', ['VALID','INVALID'])->default('VALID');
            $table->string('row_hash', 64);                    // توقيع منع تكرار السطر
            $table->timestamps();

            $table->unique(['batch_id','seq_no']);
            $table->unique(['batch_id','row_hash']);
            $table->unique(['reference']);                     // يسمح بعدة NULL
            $table->index('op_date');
        });
    }

    public function down(): void {
        Schema::dropIfExists('wish_rows_raw');
    }
};
