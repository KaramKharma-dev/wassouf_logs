<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('wish_batches', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('filename', 255);
            $table->string('checksum', 64)->unique(); // SHA256 لمنع الازدواج
            $table->date('statement_from')->nullable();
            $table->date('statement_to')->nullable();
            $table->date('issued_on')->nullable(); // Issued on
            $table->enum('status', ['UPLOADED','PARSED','PARSED_WITH_WARNINGS'])->default('UPLOADED');
            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('rows_valid')->default(0);
            $table->unsignedInteger('rows_invalid')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('wish_batches');
    }
};
