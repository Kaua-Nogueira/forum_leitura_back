<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('upvotes')) {
            Schema::create('upvotes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('target_type', 24); // WORK, TOPIC, COMMENT
                $table->unsignedBigInteger('target_id');
                $table->timestamps();

                $table->unique(['user_id', 'target_type', 'target_id'], 'upvotes_user_target_unique');
                $table->index(['target_type', 'target_id']);
            });
        }

        if (!Schema::hasTable('moderation_logs')) {
            Schema::create('moderation_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('actor_name');
                $table->string('actor_role', 24);
                $table->string('action_type', 48); // APPROVE_WORK, REJECT_WORK, DELETE_WORK, DELETE_TOPIC, DELETE_COMMENT, etc.
                $table->string('target_type', 24); // WORK, TOPIC, COMMENT, USER, SETTINGS
                $table->unsignedBigInteger('target_id')->nullable();
                $table->string('target_title');
                $table->string('target_author_name')->nullable();
                $table->text('reason')->nullable();
                $table->string('ip_address', 64)->nullable();
                $table->timestamps();

                $table->index(['action_type', 'created_at']);
                $table->index(['target_type', 'target_id']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('moderation_logs');
        Schema::dropIfExists('upvotes');
    }
};
