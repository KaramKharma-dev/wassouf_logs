<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('wish_rows_alt', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('batch_id')->constrained('wish_batches')->cascadeOnDelete();
            $table->unsignedInteger('seq_no');
            $table->date('op_date')->nullable();
            $table->string('reference', 32)->nullable();
            $table->string('service', 100)->nullable();
            $table->text('description')->nullable();
            $table->decimal('debit', 14, 2)->nullable();
            $table->decimal('credit', 14, 2)->nullable();
            $table->decimal('balance_after', 14, 2)->nullable();
            $table->enum('row_status', ['VALID','INVALID'])->default('VALID');
            $table->string('row_hash', 64);
            $table->timestamps();

            $table->unique(['batch_id','seq_no']);
            $table->unique(['batch_id','row_hash']);
            $table->unique(['batch_id','reference']);
            $table->index(['op_date','service']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('wish_rows_alt');
    }
};
