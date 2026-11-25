<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'dm_policy')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('dm_policy', 24)->default('MUTUAL')->after('cover_url');
            });
        }

        if (! Schema::hasTable('user_notifications')) {
            Schema::create('user_notifications', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('actor_name');
                $table->text('actor_avatar_url')->nullable();
                $table->string('type', 40);
                $table->string('title', 255);
                $table->text('body')->nullable();
                $table->string('target_type', 24)->nullable();
                $table->unsignedBigInteger('target_id')->nullable();
                $table->string('action_url', 255)->nullable();
                $table->timestamp('read_at')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'read_at', 'created_at']);
                $table->index(['user_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        // Forward-only migration
    }
};
