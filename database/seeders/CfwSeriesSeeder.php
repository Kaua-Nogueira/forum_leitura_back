<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\ReadingSeries;
use App\Models\SeriesEntry;
use App\Models\User;
use Illuminate\Database\Seeder;
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
                'description' => 'Estudos literários, filosofia clássica e teologia.',
                'accent_color' => '#7C2D12',
            ]);
        }

        $author = User::where('email', 'kauanog9@gmail.com')->first()
            ?? User::where('role', 'ADMIN')->first()
            ?? User::first();

        if (! $author) {
            $this->command?->error('Nenhum usuário encontrado no banco para atribuir a série.');
            return;
        }

        if ($author->role === 'MEMBER') {
            $author->role = 'COLUMNIST';
            $author->save();
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
