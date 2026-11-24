<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_follows')) {
            Schema::create('user_follows', function (Blueprint $table) {
                $table->id();
                $table->foreignId('follower_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('following_id')->constrained('users')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['follower_id', 'following_id'], 'user_follows_unique');
                $table->index('following_id');
            });
        }

        if (! Schema::hasTable('direct_messages')) {
            Schema::create('direct_messages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
                $table->text('body');
                $table->string('shared_type', 24)->nullable();
                $table->string('shared_slug', 255)->nullable();
                $table->string('shared_title', 255)->nullable();
                $table->timestamp('read_at')->nullable();
                $table->timestamps();

                $table->index(['sender_id', 'recipient_id', 'created_at']);
                $table->index(['recipient_id', 'read_at']);
            });
        }
    }

    public function down(): void
    {
        // Forward-only migration
    }
};
