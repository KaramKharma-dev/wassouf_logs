<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('wish_rows_pc_lb', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('batch_id');
            $table->unsignedInteger('seq_no')->default(0);

            $table->date('op_date')->nullable();
            $table->string('reference', 64)->nullable();
            $table->string('service', 100)->nullable();
            $table->text('description')->nullable();

            $table->decimal('debit', 16, 2)->nullable();
            $table->decimal('credit', 16, 2)->nullable();
            $table->decimal('balance_after', 20, 2)->nullable();

            $table->enum('row_status', ['VALID','INVALID','PROCESSED'])->default('INVALID');
            $table->char('row_hash', 64)->nullable();

            $table->timestamps();

            $table->index(['batch_id','seq_no']);
            $table->index('op_date');
            $table->index('reference');
            $table->index('row_status');

            $table->foreign('batch_id')
                  ->references('id')->on('wish_batches')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wish_rows_pc_lb');
    }
};
