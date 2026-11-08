<?php

declare(strict_types=1);

namespace Workbench\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Workbench\App\Models\JsonSample;

/**
 * Фабрика записей витрины «Разметка и JSON».
 *
 * @extends Factory<JsonSample>
 */
final class JsonSampleFactory extends Factory
{
    /** @var class-string<JsonSample> */
    protected $model = JsonSample::class;

    /**
     * Запись с заполненными полями витрины.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->unique()->words(2, true),
            'subtitle' => ['ru' => 'Подзаголовок', 'en' => 'Subtitle'],
            'content' => '<p>'.fake()->paragraph().'</p>',
            'notes' => "**Заметка** редакции\n\n- первый пункт\n- второй пункт",
            'plans' => [['title' => 'Базовый', 'items' => [['name' => 'Доставка', 'price' => '300']]]],
            'specs' => ['Вес' => '1 кг', 'Цвет' => 'Белый'],
            'body' => [
                ['type' => 'text', 'data' => ['body' => '<p>Магазин работает с 2005 года.</p>']],
                ['type' => 'quote', 'data' => ['text' => 'Лучшие цены города.', 'author' => 'Покупатель']],
            ],
            'seo' => ['title' => 'Пример SEO-заголовка', 'description' => 'Описание для поисковиков'],
        ];
    }
}
