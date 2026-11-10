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

        $workConfissoes = Work::updateOrCreate(
            ['slug' => 'confissoes-santo-agostinho'],
            [
                'title' => 'Confissões',
                'subtitle' => 'A Anatomia da Alma, a Memória e o Repouso em Deus no Pensamento de Santo Agostinho',
                'creator' => 'Santo Agostinho de Hipona',
                'media_type' => 'BOOK',
                'release_year' => 398,
                'category_id' => $catLetras->id,
                'cover_url' => 'https://images.unsplash.com/photo-1544716278-ca5e3f4abd8c?auto=format&fit=crop&w=900&q=85',
                'synopsis' => "Escritas entre 397 e 400 d.C., quando Agostinho já ocupava a cátedra episcopal de Hipona, as *Confissões* não inauguram apenas o gênero da autobiografia espiritual no Ocidente: elas fundam uma nova psicologia da memória, do tempo e do desejo humano diante da transcendência divina. O termo latino *confessio* possui aqui um duplo sentido inseparável: é simultaneamente o reconhecimento humilde das próprias misérias e o louvor extasiado à misericórdia do Criador.\n\n> Fizeste-nos para Ti, Senhor, e inquieto está o nosso coração enquanto não repousa em Ti.\n\n## I. Da Retórica da Vaidade à Sede da Verdade\n\nNos primeiros seis livros, acompanhamos a formação intelectual do jovem nascido em Tagaste. Agostinho não esconde a ambição que o movia nas escolas de Cartago e Roma: o ensino das letras era visto não como busca da sabedoria, mas como moeda de prestígio social e vitória nos tribunais. Contudo, a leitura do *Hortênsio* — diálogo hoje perdido de Cícero — acende nele uma chama que nenhuma honraria mundana pôde apagar: o amor à filosofia (*amor sapientiae*).\n\n* * *\n\n## II. O Jardim de Milão e a Cura da Vontade Dividida\n\nO clímax dramático do Livro VIII — a célebre cena do jardim em que uma voz infantil entoa *\"Tolle, lege! Tolle, lege!\"* (Toma e lê!) — é precedido por uma das análises mais penetrantes já escritas sobre a paralisia da vontade humana. Agostinho já estava intelectualmente convencido da verdade cristã, mas permanecia preso pelos grilhões do hábito. Ele descobre que a razão sozinha não redime o homem: é preciso que a Graça divina encante e reordene o próprio peso do coração (*pondus meum amor meus*).\n\n> Tarde Vos amei, ó Beleza tão antiga e tão nova, tarde Vos amei! Eis que habitáveis dentro de mim, e eu lá fora a procurar-Vos!\n\n## III. Memória, Tempo e Eternidade (Livros X a XIII)\n\nNos livros finais, Agostinho investiga os vastos palácios da memória (Livro X), formula sua extraordinária teoria do tempo como *distentio animi* — distensão da alma entre a lembrança, a atenção presente e a espera (Livro XI) — e encerra com uma leitura alegórica e trinitária do relato da Criação no Gênesis.",
                'notable_quotes' => [
                    [
                        'quote_text' => 'Fizeste-nos para Ti, Senhor, e o nosso coração permanece inquieto enquanto não descansa em Ti.',
                        'source_note' => 'Livro I, Capítulo 1',
                    ],
                    [
                        'quote_text' => 'Tarde Vos amei, ó Beleza tão antiga e tão nova, tarde Vos amei! Eis que habitáveis dentro de mim, e eu lá fora a procurar-Vos!',
                        'source_note' => 'Livro X, Capítulo 27',
                    ],
                ],
                'affiliate_url' => 'https://www.amazon.com.br/s?k=Confissoes+Santo+Agostinho+Capa+Dura',
                'affiliate_cta_label' => 'Adquirir na Amazon',
                'affiliate_price_hint' => 'Edição de Luxo Capa Dura • R$ 69,90',
                'affiliate_clicks_count' => 184,
                'isbn_or_ref' => 'ISBN 978-85-326-5412-0',
                'publisher_or_studio' => 'Penguin-Companhia / Ecclesiae',
                'is_featured' => true,
                'editorial_rating' => 5.0,
                'reviews_count' => 18,
                'status' => 'APPROVED',
                'submitted_by_id' => $admin->id,
            ]
        );

        $workLewis = Work::updateOrCreate(
            ['slug' => 'a-abolicao-do-homem-cs-lewis'],
            [
                'title' => 'A Abolição do Homem',
                'subtitle' => 'A Educação das Afeições Ordenadas, a Lei Natural (Tao) e o Perigo dos "Homens sem Peito"',
                'creator' => 'C. S. Lewis',
                'media_type' => 'BOOK',
                'release_year' => 1943,
                'category_id' => $catEducacao->id,
                'cover_url' => 'https://images.unsplash.com/photo-1512820790803-83ca734da794?auto=format&fit=crop&w=900&q=85',
                'synopsis' => "Originalmente proferido como as *Riddell Memorial Lectures* na Universidade de Durham em 1943, *A Abolição do Homem* é o mais rigoroso ensaio filosófico e pedagógico de C. S. Lewis. Partindo de um modesto livro didático de língua inglesa, Lewis diagnostica como o subjetivismo moral moderno corrói silenciosamente a própria possibilidade da virtude.\n\n> Fazemos homens sem peito e esperamos deles virtude e empreendimento; rimos da honra e nos chocamos ao encontrar traidores entre nós; castramos e exigimos que os castrados sejam fecundos.\n\n## I. A Parábola da Cachoeira de Coleridge\n\nNo primeiro capítulo, Lewis recorda o famoso episódio em que Samuel Taylor Coleridge observava uma cachoeira ao lado de dois turistas. Um deles a chamou de \"sublime\", enquanto o outro a definiu como \"bonita\". Coleridge aprovou o primeiro juízo e rejeitou o segundo com veemência. Os autores modernos, porém, ensinavam às crianças que dizer \"a cachoeira é sublime\" nada afirma sobre a realidade, mas apenas sobre os sentimentos subjetivos de quem fala.\n\n* * *\n\n## II. O Coração como Mediador entre o Intelecto e o Ventre\n\nRecuperando Platão, Aristóteles e Santo Agostinho, Lewis recorda que a cabeça (o intelecto calculista) não pode governar diretamente o ventre (os apetites instintivos). Ela precisa do **Peito** — a sede das afeições justas e da magnanimidade treinada pelo hábito da verdade e da beleza.",
                'notable_quotes' => [
                    [
                        'quote_text' => 'A tarefa do educador moderno não é derrubar florestas, mas irrigar desertos. A defesa correta contra os sentimentos falsos é inculcar sentimentos justos.',
                        'source_note' => 'Capítulo 1 — Homens sem Peito',
                    ],
                ],
                'affiliate_url' => 'https://www.amazon.com.br/s?k=A+Abolicao+do+Homem+CS+Lewis',
                'affiliate_cta_label' => 'Adquirir na Amazon',
                'affiliate_price_hint' => 'Edição Thomas Nelson / Capa Dura • R$ 42,90',
                'affiliate_clicks_count' => 231,
                'isbn_or_ref' => 'ISBN 978-85-7860-985-6',
                'publisher_or_studio' => 'Thomas Nelson Brasil',
                'is_featured' => true,
                'editorial_rating' => 4.9,
                'reviews_count' => 24,
                'status' => 'APPROVED',
                'submitted_by_id' => $moderator->id,
            ]
        );

        $workMalick = Work::updateOrCreate(
            ['slug' => 'a-arvore-da-vida-terrence-malick'],
            [
                'title' => 'A Árvore da Vida (The Tree of Life)',
                'subtitle' => 'Poema Cinematográfico sobre Graça, Natureza, Memória Familiar e a Resposta de Deus a Jó',
                'creator' => 'Terrence Malick (Direção) • Emmanuel Lubezki (Fotografia)',
                'media_type' => 'FILM',
                'release_year' => 2011,
                'category_id' => $catArte->id,
                'cover_url' => 'https://images.unsplash.com/photo-1518709268805-4e9042af9f23?auto=format&fit=crop&w=900&q=85',
                'synopsis' => "Palma de Ouro no Festival de Cannes, *A Árvore da Vida* abre com a pergunta epigráfica do Livro de Jó (38:4,7): *\"Onde estavas tu quando eu lançava os fundamentos da terra?\"*.\n\n> As freiras nos ensinaram que há dois caminhos através da vida: o caminho da Natureza e o caminho da Graça. Você deve escolher qual deles seguirá.\n\n## A Teodiceia através da Beleza e da Memória\n\nQuando a família O'Brien recebe o telegrama comunicando a morte precoce de um dos filhos, Malick responde ao grito de luto da mãe exatamente como Deus responde a Jó no capítulo 38: desdobrando uma meditação monumental sobre a criação do cosmos e o mistério da graça presente em cada instante da infância.",
                'notable_quotes' => [
                    [
                        'quote_text' => 'As freiras nos ensinaram que há dois caminhos através da vida: o caminho da Natureza e o caminho da Graça. Você deve escolher qual deles seguirá.',
                        'source_note' => 'Monólogo de Abertura — Sra. O’Brien',
                    ],
                ],
                'affiliate_url' => 'https://www.amazon.com.br/s?k=The+Tree+of+Life+Terrence+Malick+Blu-ray',
                'affiliate_cta_label' => 'Onde Encontrar (Criterion / Mídia)',
                'affiliate_price_hint' => 'Blu-Ray Criterion Collection / Digital',
                'affiliate_clicks_count' => 112,
                'isbn_or_ref' => 'Criterion Spine #938 • 139 min',
                'publisher_or_studio' => 'Fox Searchlight / Criterion',
                'is_featured' => true,
                'editorial_rating' => 4.9,
                'reviews_count' => 15,
                'status' => 'APPROVED',
                'submitted_by_id' => $member->id,
            ]
        );

        Work::updateOrCreate(
            ['slug' => 'ortodoxia-gk-chesterton'],
            [
                'title' => 'Ortodoxia',
                'subtitle' => 'A Aventura Intelectual da Fé Cristã, a Ética da Terra dos Elfos e a Democracia dos Mortos',
                'creator' => 'G. K. Chesterton',
                'media_type' => 'BOOK',
                'release_year' => 1908,
                'category_id' => $catLetras->id,
                'cover_url' => 'https://images.unsplash.com/photo-1481627834876-b7833e8f5570?auto=format&fit=crop&w=900&q=85',
                'synopsis' => "Publicado em 1908, *Ortodoxia* permanece como uma das mais brilhantes apologéticas literárias já escritas. Com seu inconfundível domínio do paradoxo, Chesterton narra como tentou fundar uma nova filosofia apenas para descobrir que ela já existia há dois mil anos no Credo dos Apóstolos.\n\n> O louco não é o homem que perdeu a razão. O louco é o homem que perdeu tudo, exceto a razão.\n\n## A Ética da Terra dos Elfos e a Tradição\n\nNo quarto capítulo, Chesterton mostra que aprendeu mais sobre a gratidão ontológica nos contos de fadas da infância do que em todos os tratados positivistas modernos, definindo a Tradição como **a democracia dos mortos**.",
                'notable_quotes' => [
                    [
                        'quote_text' => 'A tradição significa dar votos à mais obscura de todas as classes, aos nossos antepassados. É a democracia dos mortos.',
                        'source_note' => 'Capítulo IV — A Ética da Terra dos Elfos',
                    ],
                ],
                'affiliate_url' => 'https://www.amazon.com.br/s?k=Ortodoxia+Chesterton+Mundo+Cristao',
                'affiliate_cta_label' => 'Adquirir na Amazon',
                'affiliate_price_hint' => 'Edição Capa Dura • R$ 39,90',
                'affiliate_clicks_count' => 164,
                'isbn_or_ref' => 'ISBN 978-85-7325-534-8',
                'publisher_or_studio' => 'Mundo Cristão / Ecclesiae',
                'is_featured' => false,
                'editorial_rating' => 4.9,
                'reviews_count' => 19,
                'status' => 'APPROVED',
                'submitted_by_id' => $admin->id,
            ]
        );

        $rootComment = Comment::updateOrCreate(
            ['work_id' => $workConfissoes->id, 'author_id' => $moderator->id, 'parent_id' => null],
            [
                'body' => "O Livro XI das *Confissões*, dedicado à natureza do tempo e da eternidade, permanece insuperável.\n\n> Se ninguém me pergunta o que é o tempo, eu sei; se quero explicá-lo a quem me pergunta, já não sei.\n\nPara os confrades que estão iniciando a leitura em grupo: recomendo ler os livros I a IX em paralelo com o diálogo *De Magistro*.",
                'rating' => 5,
                'upvotes_count' => 29,
                'status' => 'APPROVED',
            ]
        );

        Comment::updateOrCreate(
            ['work_id' => $workConfissoes->id, 'author_id' => $member->id, 'parent_id' => $rootComment->id],
            [
                'body' => 'Excelente aponte, Professora Helena! A tradução clássica preserva uma cadência raríssima em língua portuguesa.',
                'rating' => null,
                'upvotes_count' => 14,
                'status' => 'APPROVED',
            ]
        );

        Topic::updateOrCreate(
            ['slug' => 'subcriacao-e-eucatastrofe-em-tolkien'],
            [
                'title' => 'O conceito de "Subcriação" e "Eucatástrofe" no ensaio Sobre Histórias de Fadas, de J.R.R. Tolkien',
                'excerpt' => 'Como Tolkien articula a vocação poética humana à imagem do Criador e por que os Evangelhos contêm a maior de todas as eucatástrofes da história.',
                'body' => "Ao longo de sua célebre conferência *On Fairy-Stories* (1939), J.R.R. Tolkien oferece a mais profunda teologia da imaginação literária do século XX.\n\n> Fazemos em nossa medida e em nosso modo derivado, porque fomos feitos: e não apenas feitos, mas feitos à imagem e semelhança de um Criador.\n\n## A Eucatástrofe e os Evangelhos\n\nNo Epílogo de seu ensaio, Tolkien cunha o termo **Eucatástrofe** e argumenta que na Encarnação e na Ressurreição a Lenda e a História encontraram-se definitivamente.",
                'category_id' => $catLetras->id,
                'work_id' => $workConfissoes->id,
                'author_id' => $admin->id,
                'is_pinned' => true,
                'views_count' => 642,
                'upvotes_count' => 58,
                'comments_count' => 14,
                'tags' => ['Tolkien', 'Mitopoese', 'Estética Cristã', 'Inklings'],
                'status' => 'APPROVED',
            ]
        );

        Topic::updateOrCreate(
            ['slug' => 'ordo-amoris-curriculo-classico-domestico'],
            [
                'title' => 'Ordo Amoris e a Educação da Imaginação Moral: Por onde começar um currículo clássico doméstico?',
                'excerpt' => 'Guia prático de transição para famílias e tutores: como harmonizar a leitura de "livros vivos" (Charlotte Mason) com o rigor gramatical do Trivium.',
                'body' => "Na tradição patrística e medieval, antes da análise lógica vem o encantamento poético e musical.\n\n> A virtude é a ordem do amor (Virtus est ordo amoris). — Santo Agostinho\n\nCompartilho nossa grade de leituras para o ciclo fundamental e gostaria de ouvir a experiência dos demais preceptores.",
                'category_id' => $catEducacao->id,
                'work_id' => $workLewis->id,
                'author_id' => $moderator->id,
                'is_pinned' => true,
                'views_count' => 489,
                'upvotes_count' => 47,
                'comments_count' => 19,
                'tags' => ['Educação Clássica', 'Charlotte Mason', 'Trivium', 'Homeschooling'],
                'status' => 'APPROVED',
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
