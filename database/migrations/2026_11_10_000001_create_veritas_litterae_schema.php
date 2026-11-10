<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations for Veritas & Litterae (Catálogo Editorial, Afiliados, Fórum & Governança).
     */
    public function up(): void
    {
        // 1. Extend Users with RBAC Roles (VISITOR, MEMBER, MODERATOR, ADMIN) & Profile Metadata
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->unique()->nullable()->after('name');
            $table->string('role', 24)->default('MEMBER')->after('email'); // VISITOR, MEMBER, MODERATOR, ADMIN
            $table->string('title_badge')->nullable()->after('role');
            $table->text('bio')->nullable()->after('title_badge');
            $table->string('avatar_url')->nullable()->after('bio');
            $table->boolean('is_banned')->default(false)->after('avatar_url');
            $table->integer('reputation_score')->default(0)->after('is_banned');
        });

        // 2. Categories (4 Cátedras Temáticas)
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('latin_motto')->nullable();
            $table->text('description');
            $table->string('accent_color', 24)->default('burgundy');
            $table->string('icon_name', 40)->default('BookOpen');
            $table->timestamps();
        });

        // 3. Works / Curated Catalog Items (with Affiliate URL & Quotes)
        Schema::create('works', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->string('slug')->unique();
            $table->string('creator'); // Autor, Diretor ou Compositor
            $table->string('media_type', 24)->default('BOOK'); // BOOK, FILM, DOCUMENTARY, ESSAY, SACRED_ART
            $table->integer('release_year')->default(1950);
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->string('cover_url');
            $table->longText('synopsis');
            $table->json('notable_quotes')->nullable();
            // Affiliate Module
            $table->string('affiliate_url', 1024);
            $table->string('affiliate_cta_label')->default('Adquirir na Amazon');
            $table->string('affiliate_price_hint')->nullable();
            $table->unsignedInteger('affiliate_clicks_count')->default(0);
            // Editorial Metadata
            $table->string('isbn_or_ref')->nullable();
            $table->string('publisher_or_studio')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->decimal('editorial_rating', 3, 1)->default(4.9);
            $table->unsignedInteger('reviews_count')->default(0);
            // Moderation & Governance
            $table->string('status', 24)->default('APPROVED'); // APPROVED, PENDING, REJECTED, HIDDEN
            $table->foreignId('submitted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('moderation_note')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        // 4. Community Forum Topics / Posts
        Schema::create('topics', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('excerpt');
            $table->longText('body');
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->foreignId('work_id')->nullable()->constrained('works')->nullOnDelete();
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('is_pinned')->default(false);
            $table->unsignedInteger('views_count')->default(1);
            $table->unsignedInteger('upvotes_count')->default(1);
            $table->unsignedInteger('comments_count')->default(0);
            $table->json('tags')->nullable();
            $table->string('status', 24)->default('APPROVED'); // APPROVED, PENDING, REJECTED, HIDDEN
            $table->text('moderation_note')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        // 5. Nested Comments & Member Reviews
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->longText('body');
            $table->unsignedTinyInteger('rating')->nullable(); // 1..5 stars for work reviews
            $table->foreignId('work_id')->nullable()->constrained('works')->cascadeOnDelete();
            $table->foreignId('topic_id')->nullable()->constrained('topics')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('comments')->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('upvotes_count')->default(1);
            $table->string('status', 24)->default('APPROVED'); // APPROVED, PENDING, REJECTED, HIDDEN
            $table->text('moderation_note')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        // 6. Reports / Moderation Flags Queue
        Schema::create('moderation_flags', function (Blueprint $table) {
            $table->id();
            $table->string('target_type', 24); // WORK, TOPIC, COMMENT
            $table->unsignedBigInteger('target_id');
            $table->string('target_title');
            $table->text('target_excerpt');
            $table->unsignedBigInteger('target_author_id')->nullable();
            $table->string('target_author_name');
            $table->unsignedBigInteger('reporter_id')->nullable();
            $table->string('reporter_name');
            $table->string('reason', 40); // SPAM, UNETHICAL_CONDUCT, GUIDELINE_VIOLATION, IRREVERENCE, BROKEN_AFFILIATE_LINK
            $table->text('details');
            $table->string('status', 32)->default('PENDING'); // PENDING, RESOLVED_APPROVED, RESOLVED_HIDDEN, RESOLVED_BANNED
            $table->text('moderator_note')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        // 7. Global Platform Settings (Global Amazon Affiliate Tag & Moderation Rules)
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->string('amazon_associate_tag')->default('veritaslitterae-20');
            $table->text('default_affiliate_disclaimer');
            $table->boolean('require_moderation_for_new_works')->default(true);
            $table->string('editorial_edition_label')->default('Edição de Outono • Vol. IV — MMXXVI');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
        Schema::dropIfExists('moderation_flags');
        Schema::dropIfExists('comments');
        Schema::dropIfExists('topics');
        Schema::dropIfExists('works');
        Schema::dropIfExists('categories');
    }
};
