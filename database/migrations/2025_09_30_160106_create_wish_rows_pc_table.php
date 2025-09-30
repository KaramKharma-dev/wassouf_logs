<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('wish_rows_pc', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('wish_batches')->cascadeOnDelete();
            $table->unsignedInteger('seq_no');

            $table->date('op_date')->nullable();
            $table->string('reference', 64)->nullable(); // مثال: pc:2505702359
            $table->string('service')->nullable();
            $table->string('description')->nullable();

            $table->decimal('debit', 12, 2)->nullable();
            $table->decimal('credit', 12, 2)->nullable();
            $table->decimal('balance_after', 14, 3)->nullable(); // الملف فيه 3 منازل

            $table->enum('row_status', ['VALID','INVALID','PROCESSED'])->default('VALID');
            $table->string('row_hash', 64)->nullable();

            $table->timestamps();

            $table->unique(['reference'], 'wish_rows_pc_reference_unique');
            $table->index(['op_date']);
            $table->index(['row_status']);
            $table->index(['batch_id','seq_no']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('wish_rows_pc');
    }
};
