<?php

declare(strict_types=1);

namespace Fastleo\Fields;

use Fastleo\Fastleo;
use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\CastsInboundAttributes;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Override;

/**
 * Секрет: токен, ключ API. Ведёт себя как `Password` — форма всегда пустая, пустое поле при редактировании
 * оставляет прежнее значение, в списке, на просмотре, в экспорте и MCP не показывается, —
 * но пишет в модель само значение, а не хеш. Шифрование — каст `encrypted` в модели; без него (или своего каста,
 * или мутатора записи) поле отказывается сохранять значение, чтобы токен не лёг в базу открытым текстом.
 *
 * ```php
 * Secret::make('api_key', 'Ключ API')->required();
 * // в модели: protected function casts(): array { return ['api_key' => 'encrypted']; }
 * ```
 */
class Secret extends Password
{
    /** Наибольшая длина значения. */
    protected int $maxLength = 1000;

    #[Override]
    protected function typeRules(): array
    {
        return ['string', 'max:'.$this->maxLength];
    }

    /**
     * Пробелы по краям — случайные (вставка из буфера), убираются.
     */
    #[Override]
    public function dehydrate(mixed $value): mixed
    {
        $value = is_string($value) ? trim($value) : $value;

        return $value === '' ? null : $value;
    }

    /**
     * Колонка модели шифруется: каст `encrypted` (в том числе `encrypted:array`), свой каст-класс или мутатор записи.
     *
     * ```php
     * Secret::make('api_key')->isEncryptedBy(new Supplier()); // true при 'api_key' => 'encrypted'
     * ```
     */
    public function isEncryptedBy(Model $model): bool
    {
        $cast = $model->getCasts()[$this->name] ?? null;

        return (is_string($cast) && $this->castEncrypts($cast))
            || $model->hasSetMutator($this->name)
            || $model->hasAttributeSetMutator($this->name);
    }

    /**
     * Каст пишет значение шифрованным: `encrypted…`, `AsEncrypted…` Laravel или класс каста сайта (что он шифрует,
     * проверить нельзя — его автору доверяют). Остальное с «\» в имени шифрованием не считается: enum, `AsArrayObject`,
     * `AsCollection` и другие касты Laravel кладут значение в базу открытым текстом.
     */
    private function castEncrypts(string $cast): bool
    {
        if (str_starts_with($cast, 'encrypted')) {
            return true;
        }

        $class = explode(':', $cast, 2)[0];

        if (! array_any([CastsAttributes::class, CastsInboundAttributes::class, Castable::class], fn (string $contract): bool => is_subclass_of($class, $contract))) {
            return false;
        }

        return ! str_starts_with($class, 'Illuminate\\Database\\Eloquent\\Casts\\') || str_starts_with(class_basename($class), 'AsEncrypted');
    }

    /**
     * Пустое значение не меняет секрет, иначе в модель пишется само значение.
     *
     * @throws LogicException у колонки нет шифрования: токен лёг бы в базу открытым текстом
     */
    #[Override]
    public function fill(Model $record, mixed $value): void
    {
        if (! is_string($value)) {
            return;
        }

        if (! $this->isEncryptedBy($record)) {
            throw new LogicException(__('fastleo::exceptions.secret_needs_encrypted_cast', ['field' => $this->name, 'model' => $record::class], Fastleo::LOCALE));
        }

        $record->fill([$this->name => $value]);
    }
}
