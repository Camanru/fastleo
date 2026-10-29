<?php

declare(strict_types=1);

namespace Fastleo\Http\Controllers;

use Fastleo\Fastleo;
use Fastleo\Fields\Field;
use Fastleo\Settings;
use Fastleo\Support\RecordWriter;
use Fastleo\Support\SettingsRecord;
use Fastleo\Support\SettingsStore;
use Fastleo\Support\ValidationMessages;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Страницы настроек сайта: форма на полях и сохранение в `fastleo_settings`.
 *
 * Проверяются и записываются только поля, описанные на странице, и только видимые: лишние ключи запроса
 * отбрасываются. Поле, скрытое условием `visibleWhen()`, сохраняет прежнее значение.
 */
final readonly class SettingsController
{
    /**
     * @param  SettingsStore  $store  хранилище значений
     */
    public function __construct(private SettingsStore $store) {}

    /**
     * Форма страницы настроек.
     */
    public function edit(string $page): View
    {
        $settings = $this->page($page);

        $record = $this->record($settings);

        // Секреты в форму не выводятся: значение остаётся в базе, поле пустое.
        foreach ($settings->encrypted() as $name) {
            $record->setAttribute($name, null);
        }

        return view('fastleo::settings.edit', ['settings' => $settings, 'record' => $record]);
    }

    /**
     * Проверить и сохранить настройки.
     */
    public function update(Request $request, string $page): RedirectResponse
    {
        $settings = $this->page($page);
        $record = $this->record($settings);
        $fields = $this->withoutKeptSecrets($settings, $record, $request, $this->writable($settings, $record));

        $rules = [];
        $attributes = [];

        foreach ($fields as $field) {
            $rules = [...$rules, ...$field->getRules($record)];
            $attributes = [...$attributes, ...$field->validationAttributes()];
        }

        $input = RecordWriter::inInputOrder(Validator::make($request->all(), $rules, ValidationMessages::all(), $attributes)->validate(), $request->all());
        $dehydrated = [];

        foreach ($fields as $field) {
            if (array_key_exists($field->name, $input)) {
                $dehydrated[$field->name] = $field->dehydrate($input[$field->name]);
                $field->fill($record, $dehydrated[$field->name]);
            }
        }

        DB::transaction(function () use ($settings, $record, $fields, $dehydrated): void {
            foreach ($fields as $field) {
                $field->beforeSave($record);
            }

            $values = [];

            foreach ($settings->fieldNames() as $name) {
                $values[$name] = $record->getAttribute($name);
            }

            $this->store->save($settings, $values);

            foreach ($fields as $field) {
                if (array_key_exists($field->name, $dehydrated)) {
                    $field->afterSave($record, $dehydrated[$field->name]);
                }
            }
        });

        return to_route('fastleo.settings.edit', $settings::slug())->with('fastleo.success', __('fastleo::settings.saved'));
    }

    /**
     * Секрет, который уже сохранён и пришёл из формы пустым, не меняется: поле исключается из проверки и записи.
     *
     * @param  list<Field>  $fields
     * @return list<Field>
     */
    private function withoutKeptSecrets(Settings $settings, Model $record, Request $request, array $fields): array
    {
        return array_values(array_filter($fields, function (Field $field) use ($settings, $record, $request): bool {
            if (! in_array($field->name, $settings->encrypted(), true)) {
                return true;
            }

            $stored = $record->getAttribute($field->name);
            $input = $request->input($field->name);

            return ! (is_string($stored) && $stored !== '' && (! is_string($input) || trim($input) === ''));
        }));
    }

    /**
     * Страница настроек по адресу; нет такой — 404, нет доступа — 403.
     */
    private function page(string $slug): Settings
    {
        $page = Fastleo::resources()->findSettings($slug);
        abort_unless($page instanceof Settings, 404);
        abort_unless($page->canAccess(Fastleo::guard()->user()), 403);

        return $page;
    }

    /**
     * Значения страницы: значения по умолчанию полей, поверх них — сохранённые.
     */
    private function record(Settings $settings): SettingsRecord
    {
        $defaults = [];

        foreach ($settings->schema()->fields() as $field) {
            $defaults[$field->name] = $field->untouchedValue();
        }

        return SettingsRecord::from([...$defaults, ...$this->store->values($settings::slug(), $settings)], $settings->fieldNames());
    }

    /**
     * Поля, которые админ видит и может менять.
     *
     * @return list<Field>
     */
    private function writable(Settings $settings, Model $record): array
    {
        return array_values(array_filter(
            $settings->schema()->fields(),
            fn (Field $field): bool => $field->isVisibleInForm($record) && $field->isWritable(),
        ));
    }
}
