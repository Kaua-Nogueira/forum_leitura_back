<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Comment;
use App\Models\ModerationFlag;
use App\Models\PlatformSetting;
use App\Models\Topic;
use App\Models\User;
use App\Models\Work;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the Veritas & Litterae database with medium/long-form book essays.
     */
    public function run(): void
    {
        PlatformSetting::updateOrCreate(
            ['id' => 1],
            [
                'amazon_associate_tag' => 'veritaslitterae-20',
                'default_affiliate_disclaimer' => 'Comprando por este link, você apoia a curadoria e manutenção editorial deste projeto sem custo adicional.',
                'require_moderation_for_new_works' => true,
                'editorial_edition_label' => 'Edição de Outono • Vol. IV — MMXXVI',
            ]
        );

        $admin = User::updateOrCreate(
            ['email' => 'augusto@veritaslitterae.org'],
            [
                'name' => 'Prof. Augusto Vasconcelos',
                'username' => 'augusto.vasconcelos',
                'password' => Hash::make('password'),
                'role' => 'ADMIN',
                'title_badge' => 'Editor-Chefe & Curador Patrístico',
                'bio' => 'Doutor em Letras Clássicas e pesquisador da tradição agostiniana e do Trivium.',
                'reputation_score' => 1420,
            ]
        );

        $moderator = User::updateOrCreate(
            ['email' => 'helena@veritaslitterae.org'],
            [
                'name' => 'Helena Monteiro de Barros',
                'username' => 'helena.barros',
                'password' => Hash::make('password'),
                'role' => 'MODERATOR',
                'title_badge' => 'Moderadora • Educação Clássica',
                'bio' => 'Preceptora de Artes Liberais, tradutora de latim e estudiosa de Charlotte Mason e Dorothy Sayers.',
                'reputation_score' => 980,
            ]
        );

        User::updateOrCreate(
            ['email' => 'mod@veritas.com'],
            [
                'name' => 'Moderador Editorial',
                'username' => 'moderador.veritas',
                'password' => Hash::make('123456'),
                'role' => 'MODERATOR',
                'title_badge' => 'Conselho de Moderação • Revisão Editorial',
                'bio' => 'Responsável pela revisão e aprovação das resenhas e ensaios submetidos pelos membros.',
                'reputation_score' => 1100,
            ]
        );

        User::updateOrCreate(
            ['email' => 'admin@veritas.com'],
            [
                'name' => 'Administrador Geral',
                'username' => 'admin.veritas',
                'password' => Hash::make('123456'),
                'role' => 'ADMIN',
                'title_badge' => 'Administrador & Editor-Chefe',
                'bio' => 'Gestão de papéis RBAC, curadoria e configurações globais.',
                'reputation_score' => 1500,
            ]
        );

        $member = User::updateOrCreate(
            ['email' => 'lucas@leitor.org'],
            [
                'name' => 'Lucas Albuquerque',
                'username' => 'lucas.albuquerque',
                'password' => Hash::make('password'),
                'role' => 'MEMBER',
                'title_badge' => 'Membro Confrade • Crítica Literária',
                'bio' => 'Ensaísta e entusiasta da literatura clássica e do cinema contemplativo.',
                'reputation_score' => 430,
            ]
        );

        $catLetras = Category::updateOrCreate(
            ['slug' => 'letras-teologia'],
            [
                'name' => 'Letras & Teologia',
                'latin_motto' => 'Fides quaerens intellectum',
                'description' => 'Patrística, teologia histórica, clássicos da literatura universal, devocionais seculares e ficção mitopoética.',
                'accent_color' => 'burgundy',
                'icon_name' => 'BookOpen',
            ]
        );

        $catEducacao = Category::updateOrCreate(
            ['slug' => 'educacao-mente'],
            [
                'name' => 'Educação & Mente',
                'latin_motto' => 'Artes Liberales & Paideia',
                'description' => 'Educação clássica cristã, Trivium e Quadrivium, formação do imaginário moral, pedagogia de Charlotte Mason e homeschooling.',
                'accent_color' => 'forest',
                'icon_name' => 'GraduationCap',
            ]
        );

        $catArte = Category::updateOrCreate(
            ['slug' => 'setima-arte-musica'],
            [
                'name' => 'Sétima Arte & Música',
                'latin_motto' => 'Pulchritudo & Contemplatio',
                'description' => 'Cinema de alta densidade espiritual, documentários históricos, polifonia sacra, artes plásticas e estética do belo.',
                'accent_color' => 'navy',
                'icon_name' => 'Film',
            ]
        );

        $catDialogos = Category::updateOrCreate(
            ['slug' => 'dialogos-abertos'],
            [
                'name' => 'Diálogos Abertos',
                'latin_motto' => 'In necessariis unitas, in dubiis libertas',
                'description' => 'Colóquios interdisciplinares, filosofia pública, cultura contemporânea, vocação intelectual e ética cristã.',
                'accent_color' => 'gold',
                'icon_name' => 'MessageSquareQuote',
            ]
        );

       

        if (ModerationFlag::count() === 0) {
            ModerationFlag::create([
                'target_type' => 'WORK',
                'target_id' => 4,
                'target_title' => 'Verificação de Edição & Link de Afiliado: Ortodoxia (G. K. Chesterton)',
                'target_excerpt' => 'Solicitação de revisão do link da edição de capa dura e nota bibliográfica da tradução.',
                'target_author_id' => $member->id,
                'target_author_name' => $member->name,
                'reporter_id' => $moderator->id,
                'reporter_name' => $moderator->name,
                'reason' => 'BROKEN_AFFILIATE_LINK',
                'details' => 'Conferir se a tag global de associado Amazon está aplicada ao ASIN da edição recomendada.',
                'status' => 'PENDING',
            ]);
        }
    }
}
