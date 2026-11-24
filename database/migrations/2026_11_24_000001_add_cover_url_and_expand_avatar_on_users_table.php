<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users')) {
            if (! Schema::hasColumn('users', 'cover_url')) {
                Schema::table('users', function (Blueprint $table) {
                    $table->longText('cover_url')->nullable()->after('avatar_url');
                });
            }

            if (DB::getDriverName() === 'pgsql') {
                DB::statement('ALTER TABLE users ALTER COLUMN avatar_url TYPE TEXT');
                DB::statement('ALTER TABLE users ALTER COLUMN cover_url TYPE TEXT');
            }
        }
    }

    public function down(): void
    {
        // Forward-only migration
    }
};
