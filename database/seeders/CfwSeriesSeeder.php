<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\ReadingSeries;
use App\Models\SeriesEntry;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CfwSeriesSeeder extends Seeder
{
    public function run(): void
    {
        $category = Category::where('slug', 'letras-teologia')->first()
            ?? Category::first();

        if (! $category) {
            $category = Category::create([
                'name' => 'Letras & Teologia',
                'slug' => 'letras-teologia',
                'latin_motto' => 'Fides quaerens intellectum',
                'description' => 'Estudos literários, filosofia clássica e teologia.',
                'accent_color' => 'burgundy',
                'icon_name' => 'BookOpen',
            ]);
        }

        $author = User::where('email', 'kauanog9@gmail.com')
            ->orWhere('username', 'kaua.nogueira')
            ->orWhere('name', 'Kauã Nogueira')
            ->orWhere('name', 'Kaua Nogueira')
            ->first();

        if (! $author) {
            $author = User::create([
                'name' => 'Kauã Nogueira',
                'username' => 'kaua.nogueira',
                'email' => 'kauanog9@gmail.com',
                'password' => Hash::make('password'),
                'role' => 'ADMIN',
                'title_badge' => 'Admin Mor & Autor • Estudos Reformados',
                'bio' => 'Autor dos Comentários à Confissão de Fé de Westminster (CFW) e Administrador Geral.',
                'is_banned' => false,
                'reputation_score' => 2000,
            ]);
        } else {
            $author->update([
                'name' => 'Kauã Nogueira',
                'role' => 'ADMIN',
                'title_badge' => 'Admin Mor & Autor • Estudos Reformados',
                'is_banned' => false,
                'reputation_score' => max((int) $author->reputation_score, 2000),
            ]);
        }

        $series = ReadingSeries::updateOrCreate(
            ['slug' => 'comentarios-confissao-de-fe-de-westminster'],
            [
                'title' => 'Comentários à Confissão de Fé de Westminster',
                'subtitle' => 'Leitura diária e exposição comentada seção por seção da Confissão de Fé de Westminster (CFW).',
                'description' => 'Série de comentários diários sobre a Confissão de Fé de Westminster (CFW), iniciada em 23/10/2025, percorrendo artigo por artigo o texto confessional com referências bíblicas e reflexões teológicas.',
                'book_title' => 'Confissão de Fé de Westminster (CFW)',
                'book_author' => 'Assembleia de Westminster (1646)',
                'cadence_label' => 'Publicação Diária',
                'category_id' => $category->id,
                'author_id' => $author->id,
                'status' => 'ONGOING',
            ]
        );

        $partFiles = [
            database_path('seeders/data/cfw_part1.json'),
            database_path('seeders/data/cfw_part2.json'),
            database_path('seeders/data/cfw_part3.json'),
        ];

        $totalImported = 0;

        foreach ($partFiles as $file) {
            if (! file_exists($file)) {
                $this->command?->error("Arquivo não encontrado: {$file}");
                continue;
            }

            $items = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

            foreach ($items as $item) {
                $num = (int) $item['entry_number'];
                $slug = sprintf(
                    'cfw-%02d-%s',
                    $num,
                    Str::slug($item['reference_label'])
                );

                SeriesEntry::updateOrCreate(
                    ['slug' => $slug],
                    [
                        'series_id' => $series->id,
                        'author_id' => $author->id,
                        'entry_number' => $num,
                        'reference_label' => $item['reference_label'],
                        'title' => $item['title'],
                        'body' => $item['body'],
                        'published_at' => $item['published_at'],
                    ]
                );

                $totalImported++;
            }
        }

        $series->entries_count = $series->entries()->count();
        $series->save();

        $this->command?->info("Série '{$series->title}' atualizada com {$totalImported} notas (total no banco: {$series->entries_count}).");
    }
}
