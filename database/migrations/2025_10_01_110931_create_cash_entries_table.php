<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cash_entries', function (Blueprint $table) {
            $table->id();
            $table->string('description', 200);
            $table->enum('entry_type', ['RECEIPT','PAYMENT']); // قبض/دفع
            $table->decimal('amount', 16, 2);
            $table->timestamps();

            $table->index(['entry_type','created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_entries');
    }
};
