-- =============================================================================
-- VERITAS & LITTERAE — Esquema Completo de Banco de Dados para PostgreSQL 16+
-- Compatível com Laravel 12 (Eloquent ORM) / Supabase / Neon / Docker Postgres
-- =============================================================================

BEGIN;

-- 1. Tabela de Usuários com Controle de Acesso Baseado em Papéis (RBAC)
CREATE TABLE IF NOT EXISTS users (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    username VARCHAR(255) UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    email_verified_at TIMESTAMPTZ NULL,
    password VARCHAR(255) NOT NULL,
    api_token VARCHAR(80) UNIQUE NULL,
    role VARCHAR(24) NOT NULL DEFAULT 'MEMBER' CHECK (role IN ('VISITOR', 'MEMBER', 'MODERATOR', 'ADMIN')),
    title_badge VARCHAR(255) NULL,
    bio TEXT NULL,
    avatar_url VARCHAR(255) NULL,
    is_banned BOOLEAN NOT NULL DEFAULT FALSE,
    reputation_score INTEGER NOT NULL DEFAULT 0,
    remember_token VARCHAR(100) NULL,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_users_role ON users(role);
CREATE INDEX IF NOT EXISTS idx_users_api_token ON users(api_token);

-- 2. Tabela de Categorias Temáticas (As 4 Cátedras)
CREATE TABLE IF NOT EXISTS categories (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL UNIQUE,
    latin_motto VARCHAR(255) NULL,
    description TEXT NOT NULL,
    accent_color VARCHAR(24) NOT NULL DEFAULT 'burgundy',
    icon_name VARCHAR(40) NOT NULL DEFAULT 'BookOpen',
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

-- 3. Tabela de Obras / Catálogo Curado (Livros, Cinema, Arte, Ensaios + Afiliado Amazon)
CREATE TABLE IF NOT EXISTS works (
    id BIGSERIAL PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    subtitle VARCHAR(255) NULL,
    slug VARCHAR(255) NOT NULL UNIQUE,
    creator VARCHAR(255) NOT NULL,
    media_type VARCHAR(24) NOT NULL DEFAULT 'BOOK' CHECK (media_type IN ('BOOK', 'FILM', 'DOCUMENTARY', 'ESSAY', 'SACRED_ART')),
    release_year INTEGER NOT NULL DEFAULT 1950,
    category_id BIGINT NOT NULL REFERENCES categories(id) ON DELETE CASCADE,
    cover_url VARCHAR(1024) NOT NULL,
    synopsis TEXT NOT NULL,
    notable_quotes JSONB NULL DEFAULT '[]'::jsonb,
    -- Módulo de Monetização e Afiliados
    affiliate_url VARCHAR(1024) NOT NULL,
    affiliate_cta_label VARCHAR(255) NOT NULL DEFAULT 'Adquirir na Amazon',
    affiliate_price_hint VARCHAR(255) NULL,
    affiliate_clicks_count INTEGER NOT NULL DEFAULT 0,
    -- Metadados Editoriais
    isbn_or_ref VARCHAR(255) NULL,
    publisher_or_studio VARCHAR(255) NULL,
    is_featured BOOLEAN NOT NULL DEFAULT FALSE,
    editorial_rating NUMERIC(3, 1) NOT NULL DEFAULT 4.9,
    reviews_count INTEGER NOT NULL DEFAULT 0,
    -- Governança e Moderação
    status VARCHAR(24) NOT NULL DEFAULT 'APPROVED' CHECK (status IN ('APPROVED', 'PENDING', 'REJECTED', 'HIDDEN')),
    submitted_by_id BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
    moderation_note TEXT NULL,
    deleted_at TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_works_category_id ON works(category_id);
CREATE INDEX IF NOT EXISTS idx_works_media_type ON works(media_type);
CREATE INDEX IF NOT EXISTS idx_works_status ON works(status);

-- 4. Tabela de Tópicos / Ensaios do Fórum Comunitário
CREATE TABLE IF NOT EXISTS topics (
    id BIGSERIAL PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL UNIQUE,
    excerpt TEXT NOT NULL,
    body TEXT NOT NULL,
    category_id BIGINT NOT NULL REFERENCES categories(id) ON DELETE CASCADE,
    work_id BIGINT NULL REFERENCES works(id) ON DELETE SET NULL,
    author_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    is_pinned BOOLEAN NOT NULL DEFAULT FALSE,
    views_count INTEGER NOT NULL DEFAULT 1,
    upvotes_count INTEGER NOT NULL DEFAULT 1,
    comments_count INTEGER NOT NULL DEFAULT 0,
    tags JSONB NULL DEFAULT '[]'::jsonb,
    status VARCHAR(24) NOT NULL DEFAULT 'APPROVED' CHECK (status IN ('APPROVED', 'PENDING', 'REJECTED', 'HIDDEN')),
    moderation_note TEXT NULL,
    deleted_at TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_topics_category_id ON topics(category_id);
CREATE INDEX IF NOT EXISTS idx_topics_work_id ON topics(work_id);
CREATE INDEX IF NOT EXISTS idx_topics_status ON topics(status);

-- 5. Tabela de Comentários e Resenhas Aninhadas (Self-Referencing parent_id)
CREATE TABLE IF NOT EXISTS comments (
    id BIGSERIAL PRIMARY KEY,
    body TEXT NOT NULL,
    rating SMALLINT NULL CHECK (rating IS NULL OR (rating >= 1 AND rating <= 5)),
    work_id BIGINT NULL REFERENCES works(id) ON DELETE CASCADE,
    topic_id BIGINT NULL REFERENCES topics(id) ON DELETE CASCADE,
    parent_id BIGINT NULL REFERENCES comments(id) ON DELETE CASCADE,
    author_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    upvotes_count INTEGER NOT NULL DEFAULT 1,
    status VARCHAR(24) NOT NULL DEFAULT 'APPROVED' CHECK (status IN ('APPROVED', 'PENDING', 'REJECTED', 'HIDDEN')),
    moderation_note TEXT NULL,
    deleted_at TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_comments_work_id ON comments(work_id);
CREATE INDEX IF NOT EXISTS idx_comments_topic_id ON comments(topic_id);
CREATE INDEX IF NOT EXISTS idx_comments_parent_id ON comments(parent_id);

-- 6. Tabela de Sinalizações / Fila de Moderação (Reports & Flags)
CREATE TABLE IF NOT EXISTS moderation_flags (
    id BIGSERIAL PRIMARY KEY,
    target_type VARCHAR(24) NOT NULL CHECK (target_type IN ('WORK', 'TOPIC', 'COMMENT')),
    target_id BIGINT NOT NULL,
    target_title VARCHAR(255) NOT NULL,
    target_excerpt TEXT NOT NULL,
    target_author_id BIGINT NULL,
    target_author_name VARCHAR(255) NOT NULL,
    reporter_id BIGINT NULL,
    reporter_name VARCHAR(255) NOT NULL,
    reason VARCHAR(40) NOT NULL,
    details TEXT NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'PENDING' CHECK (status IN ('PENDING', 'RESOLVED_APPROVED', 'RESOLVED_HIDDEN', 'RESOLVED_BANNED')),
    moderator_note TEXT NULL,
    resolved_at TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_moderation_flags_status ON moderation_flags(status);

-- 7. Tabela de Configurações Globais da Plataforma (Tag Global de Afiliado Amazon)
CREATE TABLE IF NOT EXISTS platform_settings (
    id BIGSERIAL PRIMARY KEY,
    amazon_associate_tag VARCHAR(255) NOT NULL DEFAULT 'veritaslitterae-20',
    default_affiliate_disclaimer TEXT NOT NULL,
    require_moderation_for_new_works BOOLEAN NOT NULL DEFAULT TRUE,
    editorial_edition_label VARCHAR(255) NOT NULL DEFAULT 'Edição de Outono • Vol. IV — MMXXVI',
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

-- 8. Tabela de Curtidas / Votos Únicos por Usuário (1 curtida por usuário em cada item)
CREATE TABLE IF NOT EXISTS upvotes (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    target_type VARCHAR(24) NOT NULL CHECK (target_type IN ('WORK', 'TOPIC', 'COMMENT')),
    target_id BIGINT NOT NULL,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT upvotes_user_target_unique UNIQUE (user_id, target_type, target_id)
);

CREATE INDEX IF NOT EXISTS idx_upvotes_target ON upvotes(target_type, target_id);

-- 9. Tabela de Logs de Auditoria da Moderação (Registro obrigatório de toda ação/exclusão)
CREATE TABLE IF NOT EXISTS moderation_logs (
    id BIGSERIAL PRIMARY KEY,
    actor_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    actor_name VARCHAR(255) NOT NULL,
    actor_role VARCHAR(24) NOT NULL,
    action_type VARCHAR(48) NOT NULL,
    target_type VARCHAR(24) NOT NULL,
    target_id BIGINT NULL,
    target_title VARCHAR(255) NOT NULL,
    target_author_name VARCHAR(255) NULL,
    reason TEXT NULL,
    ip_address VARCHAR(64) NULL,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_moderation_logs_action ON moderation_logs(action_type, created_at);
CREATE INDEX IF NOT EXISTS idx_moderation_logs_target ON moderation_logs(target_type, target_id);

-- =============================================================================
-- CARGA INICIAL (SEED) — CONFIGURAÇÕES, USUÁRIOS RBAC E CATEGORIAS
-- Senha padrão das contas iniciais (bcrypt de 'password'):
-- $2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi
-- =============================================================================

INSERT INTO platform_settings (id, amazon_associate_tag, default_affiliate_disclaimer, require_moderation_for_new_works, editorial_edition_label)
VALUES (
    1,
    'veritaslitterae-20',
    'Comprando por este link, você apoia a curadoria e manutenção editorial deste projeto sem custo adicional.',
    TRUE,
    'Edição de Outono • Vol. IV — MMXXVI'
) ON CONFLICT (id) DO NOTHING;

INSERT INTO users (id, name, username, email, password, role, title_badge, bio, reputation_score)
VALUES
    (1, 'Prof. Augusto Vasconcelos', 'augusto.vasconcelos', 'augusto@veritaslitterae.org', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'ADMIN', 'Editor-Chefe & Curador Patrístico', 'Doutor em Letras Clássicas e pesquisador da tradição agostiniana e do Trivium.', 1420),
    (2, 'Helena Monteiro de Barros', 'helena.barros', 'helena@veritaslitterae.org', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'MODERATOR', 'Moderadora • Educação Clássica', 'Preceptora de Artes Liberais, tradutora de latim e estudiosa de Charlotte Mason e Dorothy Sayers.', 980),
    (3, 'Lucas Albuquerque', 'lucas.albuquerque', 'lucas@leitor.org', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'MEMBER', 'Membro Confrade • Crítica Literária', 'Ensaísta e entusiasta da literatura clássica e do cinema contemplativo.', 430)
ON CONFLICT (email) DO NOTHING;

INSERT INTO categories (id, name, slug, latin_motto, description, accent_color, icon_name)
VALUES
    (1, 'Letras & Teologia', 'letras-teologia', 'Fides quaerens intellectum', 'Patrística, teologia histórica, clássicos da literatura universal, devocionais seculares e ficção mitopoética.', 'burgundy', 'BookOpen'),
    (2, 'Educação & Mente', 'educacao-mente', 'Artes Liberales & Paideia', 'Educação clássica cristã, Trivium e Quadrivium, formação do imaginário moral, pedagogia de Charlotte Mason e homeschooling.', 'forest', 'GraduationCap'),
    (3, 'Sétima Arte & Música', 'setima-arte-musica', 'Pulchritudo & Contemplatio', 'Cinema de alta densidade espiritual, documentários históricos, polifonia sacra, artes plásticas e estética do belo.', 'navy', 'Film'),
    (4, 'Diálogos Abertos', 'dialogos-abertos', 'In necessariis unitas, in dubiis libertas', 'Colóquios interdisciplinares, filosofia pública, cultura contemporânea, vocação intelectual e ética cristã.', 'gold', 'MessageSquareQuote')
ON CONFLICT (slug) DO NOTHING;

SELECT setval('users_id_seq', (SELECT COALESCE(MAX(id), 1) FROM users));
SELECT setval('categories_id_seq', (SELECT COALESCE(MAX(id), 1) FROM categories));

COMMIT;
