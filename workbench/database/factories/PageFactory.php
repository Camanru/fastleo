<?php

declare(strict_types=1);

namespace Workbench\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Workbench\App\Models\Page;

/**
 * Фабрика страниц демо-сайта.
 *
 * @extends Factory<Page>
 */
final class PageFactory extends Factory
{
    /** @var class-string<Page> */
    protected $model = Page::class;

    /**
     * Страница с вопросами и характеристиками.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->unique()->sentence(3);

        return [
            'title' => ['ru' => $title, 'en' => 'Page '.Str::random(6)],
            'slug' => Str::slug($title),
            'faq' => [['question' => 'Как записаться?', 'answer' => 'По телефону или на сайте.']],
            'specs' => ['Площадь' => '120 м²', 'Этаж' => '2'],
            'content' => [
                ['type' => 'text', 'data' => ['body' => '<p>Клиника работает с 2005 года.</p>']],
                ['type' => 'quote', 'data' => ['text' => 'Лучшие врачи города.', 'author' => 'Пациент']],
            ],
        ];
    }
}
