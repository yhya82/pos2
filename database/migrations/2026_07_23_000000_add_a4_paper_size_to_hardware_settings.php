<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE hardware_settings MODIFY paper_size ENUM('58mm','80mm','A4') NOT NULL DEFAULT '80mm'");
    }

    public function down(): void
    {
        DB::statement("UPDATE hardware_settings SET paper_size = '80mm' WHERE paper_size = 'A4'");
        DB::statement("ALTER TABLE hardware_settings MODIFY paper_size ENUM('58mm','80mm') NOT NULL DEFAULT '80mm'");
    }
};
