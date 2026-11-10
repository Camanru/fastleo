<?php

declare(strict_types=1);

namespace Fastleo\Console;

use Fastleo\Auth\TwoFactor;
use Fastleo\Fastleo;
use Fastleo\Support\UserModel;
use Fastleo\Support\ValidationMessages;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\Console\Attribute\AsCommand;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Создаёт пользователя или выдаёт доступ в панель существующему пользователю.
 *
 * ```bash
 * php artisan fastleo:user admin@site.ru            # спросит имя и пароль
 * php artisan fastleo:user admin@site.ru --name=Иван --password=secret123 --no-interaction
 * php artisan fastleo:user admin@site.ru --reset-two-factor   # потерян телефон с кодами
 * ```
 */
#[AsCommand(name: 'fastleo:user')]
#[Description('Создать пользователя fastleo или выдать доступ существующему пользователю')]
#[Signature('fastleo:user
        {email? : Email пользователя}
        {--name= : Имя (для нового пользователя)}
        {--password= : Пароль (лучше ввести по запросу — так он не попадёт в историю команд)}
        {--reset-two-factor : Отключить двухфакторную защиту пользователя (потерян телефон и резервные коды)}')]
final class UserCommand extends Command
{
    /** Минимальная длина пароля пользователя. */
    public const int PASSWORD_MIN = 8;

    /**
     * Создание или обновление пользователя с флагом fastleo_admin.
     */
    public function handle(): int
    {
        app()->setLocale(Fastleo::LOCALE);

        $model = UserModel::fastleoClassName();

        if ($model === null) {
            $this->components->error(__('fastleo::console.user_model_invalid', ['model' => UserModel::className() ?? '—']));

            return self::FAILURE;
        }

        $email = Str::lower(trim($this->stringArgument('email') ?? text(
            label: __('fastleo::console.email'),
            required: true,
        )));

        // Без учёта регистра: в PostgreSQL и SQLite «Admin@site.ru» и «admin@site.ru» — разные строки,
        // и обычный where создал бы второго пользователя. Совпадение затем проверяется точно:
        // сортировка MySQL считает «ívan@» и «ivan@» одним адресом.
        $matches = $model::query()
            ->whereRaw('lower(email) = ?', [$email])
            ->get()
            ->filter(fn (Model $user): bool => is_string($user->getAttribute('email')) && Str::lower($user->getAttribute('email')) === $email);

        if ($matches->count() > 1) {
            $this->components->error(__('fastleo::console.email_ambiguous', ['email' => $email]));

            return self::FAILURE;
        }

        $user = $matches->first();
        $isNew = $user === null;

        // Сброс 2FA — только для существующего пользователя: опечатка в email не должна создавать пользователя.
        if ($isNew && $this->option('reset-two-factor') === true) {
            $this->components->error(__('fastleo::console.user_not_found', ['email' => $email]));

            return self::FAILURE;
        }

        $user ??= new $model();

        $name = $this->stringOption('name');
        $password = $this->stringOption('password');

        if ($isNew && $name === null && $this->input->isInteractive()) {
            $name = text(label: __('fastleo::console.name'), default: Str::before($email, '@'), required: true);
        }

        if ($password === null && $this->input->isInteractive()) {
            $password = password(
                label: $isNew ? __('fastleo::console.password') : __('fastleo::console.password_keep'),
                required: $isNew,
            );
        }

        // Пустой или из одних пробелов пароль — «не менять»: правила проверки пропускают такую строку как пустую, и она стала бы паролем.
        $password = $password === null || trim($password) === '' ? null : $password;

        $validator = Validator::make(
            ['email' => $email, 'password' => $password],
            [
                'email' => ['required', 'email', 'max:255'],
                // Правила пароля сайта (`Password::defaults()`) те же, что в профиле и при сбросе: через консоль слабый пароль не задать.
                'password' => [$isNew ? 'required' : 'nullable', 'string', 'min:'.self::PASSWORD_MIN, 'max:255', Password::defaults()],
            ],
            [
                ...ValidationMessages::all(),
                'email.required' => __('fastleo::console.email_invalid'),
                'email.email' => __('fastleo::console.email_invalid'),
                'email.max' => __('fastleo::console.email_invalid'),
                'password.required' => __('fastleo::console.password_required'),
            ],
            ['password' => __('fastleo::console.password_label'), 'email' => 'Email'],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        $attributes = ['email' => $email, 'fastleo_admin' => true];

        if ($isNew) {
            $attributes['name'] = $name ?? Str::before($email, '@');
        }

        if ($password !== null) {
            $attributes['password'] = Hash::make($password);
        }

        try {
            // forceFill: fastleo_admin намеренно не входит в $fillable модели.
            $user->forceFill($attributes)->save();
        } catch (UniqueConstraintViolationException) {
            // MySQL считает «ívan@» и «ivan@» одним адресом: нового пользователя создать нельзя, а чужому доступ не выдаётся.
            $this->components->error(__('fastleo::console.email_similar', ['email' => $email]));

            return self::FAILURE;
        }

        $this->components->info(__($isNew ? 'fastleo::console.user_created' : 'fastleo::console.user_updated', ['email' => $email]));

        if ($this->option('reset-two-factor') === true && $user instanceof Authenticatable) {
            resolve(TwoFactor::class)->disable($user);
            $this->components->info(__('fastleo::console.two_factor_reset', ['email' => $email]));
        }

        return self::SUCCESS;
    }

    /**
     * Аргумент строкой или null, если не передан.
     */
    private function stringArgument(string $key): ?string
    {
        $value = $this->argument($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Опция строкой или null, если не передана.
     */
    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) ? $value : null;
    }
}
