<?php

declare(strict_types=1);

use Fastleo\Auth\UserCleanup;
use Fastleo\Imports\Import;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Workbench\App\Models\Article;
use Workbench\App\Models\User;

/**
 * Строки таблиц пакета, привязанные к пользователю.
 *
 * @return array{two_factor: int, logins: int, layouts: int, notifications: int, imports: int, publications: int}
 */
function rowsOf(int|string $id): array
{
    return [
        'two_factor' => DB::table('fastleo_two_factor')->where('user_id', (string) $id)->count(),
        'logins' => DB::table('fastleo_logins')->where('user_id', (string) $id)->count(),
        'layouts' => DB::table('fastleo_dashboard_layouts')->where('user_id', (string) $id)->count(),
        'notifications' => DB::table('fastleo_notifications')->where('user_id', (string) $id)->count(),
        'imports' => DB::table('fastleo_imports')->where('user_id', (string) $id)->count(),
        'publications' => DB::table('fastleo_publications')->where('user_id', (string) $id)->count(),
    ];
}

/**
 * Данные пакета, оставшиеся без пользователя.
 */
const NONE = ['two_factor' => 0, 'logins' => 0, 'layouts' => 0, 'notifications' => 0, 'imports' => 0, 'publications' => 0];

/**
 * Данные пакета, которые есть у пользователя после `dataFor()`.
 */
const ALL = ['two_factor' => 1, 'logins' => 1, 'layouts' => 1, 'notifications' => 1, 'imports' => 1, 'publications' => 1];

/**
 * Дать пользователю секрет 2FA, устройство и раскладку дашборда.
 */
function dataFor(int|string $id): void
{
    $now = now();
    DB::table('fastleo_two_factor')->insert(['user_id' => (string) $id, 'secret' => 'x', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('fastleo_logins')->insert(['user_id' => (string) $id, 'fingerprint' => sha1((string) $id), 'ip' => '10.0.0.1', 'created_at' => $now, 'last_seen_at' => $now]);
    DB::table('fastleo_dashboard_layouts')->insert(['user_id' => (string) $id, 'layout' => '[]', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('fastleo_notifications')->insert(['id' => (string) Str::uuid(), 'user_id' => (string) $id, 'data' => '{"title":"Новый вход","body":"10.0.0.1"}', 'created_at' => $now]);
    DB::table('fastleo_publications')->insert(['text' => 'Пост', 'user_id' => (string) $id, 'created_at' => $now, 'updated_at' => $now]);
    Import::query()->create(['user_id' => (string) $id, 'resource' => 'articles', 'file_name' => 'a.xlsx', 'path' => 'fastleo/imports/a-'.$id.'.xlsx', 'status' => 'done']);
}

it('removes the 2FA secret, the devices, the dashboard layout, the bell and the import reports together with the user, and keeps the publications without an author', function (): void {
    $gone = User::factory()->create();
    $stays = User::factory()->create();
    dataFor($gone->id);
    dataFor($stays->id);

    $gone->delete();

    expect(rowsOf($gone->id))->toBe(NONE)
        ->and(rowsOf($stays->id))->toBe(ALL)
        // Публикация осталась, но достаться новому пользователю с тем же id она больше не может.
        ->and(DB::table('fastleo_publications')->count())->toBe(2)
        ->and(DB::table('fastleo_publications')->whereNull('user_id')->count())->toBe(1);
});

it('removes the files of the import reports of the deleted user', function (): void {
    $gone = User::factory()->create();
    $stays = User::factory()->create();
    dataFor($gone->id);
    dataFor($stays->id);
    Storage::fake('local');
    $disk = Import::disk();

    foreach ([$gone, $stays] as $user) {
        $import = Import::query()->where('user_id', (string) $user->id)->firstOrFail();
        $disk->put($import->path, 'xlsx');
        $disk->put($import->errorsPath(), 'errors');
        // Файл пропущенных строк прежней версии (xlsx): в нём строки клиентов, он не должен остаться после удаления администратора.
        $disk->put('fastleo/imports/'.$import->id.'-errors.xlsx', 'old errors');
    }

    $gone->delete();

    $kept = Import::query()->where('user_id', (string) $stays->id)->firstOrFail();

    expect($disk->allFiles('fastleo/imports'))->toEqualCanonicalizing([$kept->path, $kept->errorsPath(), 'fastleo/imports/'.$kept->id.'-errors.xlsx']);
});

it('removes all the import reports of a user who has more than one chunk of them', function (): void {
    $gone = User::factory()->create();
    $stays = User::factory()->create();
    $now = now();
    $row = fn (User $user, int $number): array => ['user_id' => (string) $user->id, 'resource' => 'articles', 'file_name' => 'a'.$number.'.xlsx', 'path' => 'fastleo/imports/a'.$number.'.xlsx', 'status' => 'done', 'created_at' => $now, 'updated_at' => $now];

    DB::table('fastleo_imports')->insert(array_map(fn (int $number): array => $row($gone, $number), range(1, 2500)));
    DB::table('fastleo_imports')->insert($row($stays, 1));

    $gone->delete();

    // Удаление внутри порции со смещением пропускало бы каждую вторую порцию.
    expect(DB::table('fastleo_imports')->where('user_id', (string) $gone->id)->count())->toBe(0)
        ->and(DB::table('fastleo_imports')->where('user_id', (string) $stays->id)->count())->toBe(1);
});

it('keeps the data of a user whose deletion was rolled back, and removes it only when the transaction commits', function (): void {
    $user = User::factory()->create();
    dataFor($user->id);

    try {
        DB::transaction(function () use ($user): void {
            $user->delete();

            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
        // откат: пользователь остался, и секрет его 2FA тоже
    }

    expect(User::query()->whereKey($user->id)->exists())->toBeTrue()
        ->and(rowsOf($user->id))->toBe(ALL);

    // Модель после отката считает себя удалённой: берём заново.
    DB::transaction(fn () => User::query()->findOrFail($user->id)->delete());

    expect(rowsOf($user->id))->toBe(NONE);
});

it('leaves the data alone when another model with the same key is deleted', function (): void {
    $user = User::factory()->create();
    dataFor($user->id);
    $article = Article::factory()->create();
    Article::query()->whereKey($article->id)->update(['id' => $user->id]);

    Article::query()->findOrFail($user->id)->delete();

    expect(rowsOf($user->id))->toBe(ALL);
});

it('keeps the data of a user that was only soft deleted, because it can be restored', function (): void {
    $user = User::factory()->create();
    dataFor($user->id);
    $soft = new class extends User
    {
        public function isForceDeleting(): bool
        {
            return false;
        }
    };
    $soft->forceFill(['id' => $user->id]);

    UserCleanup::forget($soft);

    expect(rowsOf($user->id))->toBe(ALL);
});
