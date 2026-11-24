<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('reading_series')) {
            Schema::create('reading_series', function (Blueprint $table) {
                $table->id();
                $table->string('title');
                $table->string('slug')->unique();
                $table->string('subtitle')->nullable();
                $table->text('description');
                $table->string('book_title')->nullable();
                $table->string('book_author')->nullable();
                $table->string('cover_url')->nullable();
                $table->string('cadence_label', 80)->default('Publicação Diária');
                $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
                $table->foreignId('work_id')->nullable()->constrained('works')->nullOnDelete();
                $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
                $table->string('status', 24)->default('ACTIVE'); // ACTIVE, COMPLETED, ARCHIVED
                $table->integer('entries_count')->default(0);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('series_entries')) {
            Schema::create('series_entries', function (Blueprint $table) {
                $table->id();
                $table->foreignId('series_id')->constrained('reading_series')->cascadeOnDelete();
                $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
                $table->integer('entry_number')->default(1);
                $table->string('reference_label', 120)->nullable();
                $table->string('title');
                $table->string('slug')->unique();
                $table->longText('body');
                $table->integer('upvotes_count')->default(0);
                $table->integer('comments_count')->default(0);
                $table->timestamp('published_at')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('comments') && !Schema::hasColumn('comments', 'series_entry_id')) {
            Schema::table('comments', function (Blueprint $table) {
                $table->foreignId('series_entry_id')
                    ->nullable()
                    ->after('topic_id')
                    ->constrained('series_entries')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        // Forward-only migration policy
    }
};
