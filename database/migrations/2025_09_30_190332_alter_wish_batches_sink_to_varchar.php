<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE `wish_batches` MODIFY `sink` VARCHAR(16) NOT NULL");
    }

    public function down(): void
    {
        // رجوع اختياري إلى ENUM الشائع
        DB::statement("ALTER TABLE `wish_batches` MODIFY `sink` ENUM('raw','alt','pc','pc_lb') NOT NULL");
    }
};
