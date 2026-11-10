<?php

declare(strict_types=1);

/**
 * Простые значения конфига пакета задаются из .env: FASTLEO_ и путь ключа заглавными через «_».
 */
const CONFIG_FILE = __DIR__.'/../../config/fastleo.php';

/** Значения null, за которыми класс раздела или список, и папка ресурсов с namespace: в .env их нет. */
const NOT_FROM_ENV = ['resources.path', 'resources.namespace', 'notifications.bots', 'leads.resource', 'publishing.channels', 'publishing.publications', 'locales'];

/**
 * Прочитать конфиг пакета заново (env() вызывается при каждом чтении файла).
 *
 * @return array<string, mixed>
 */
function packageConfig(): array
{
    return (static fn (): array => require CONFIG_FILE)();
}

/**
 * Простые значения конфига: путь через точку → значение по умолчанию. Классы разделов и списки не входят.
 *
 * @param  array<array-key, mixed>  $config
 * @return array<string, scalar|null>
 */
function simpleConfigValues(array $config, string $prefix = ''): array
{
    $values = [];

    foreach ($config as $key => $value) {
        $path = $prefix.$key;

        if (is_array($value)) {
            $values += array_is_list($value) ? [] : simpleConfigValues($value, $path.'.');
        } elseif (! in_array($path, NOT_FROM_ENV, true) && (! is_string($value) || ! class_exists($value))) {
            $values[$path] = $value;
        }
    }

    return $values;
}

/**
 * Задать переменную окружения так, как её видит env().
 */
function setEnv(string $name, string $value): void
{
    $_SERVER[$name] = $_ENV[$name] = $value;
}

afterEach(function (): void {
    foreach (array_keys($_SERVER + $_ENV) as $name) {
        if (str_starts_with((string) $name, 'FASTLEO_')) {
            unset($_SERVER[$name], $_ENV[$name]);
        }
    }
});

it('reads every simple value from FASTLEO_ and the path of the key, with the type of the default', function (): void {
    $defaults = simpleConfigValues(packageConfig());
    $expected = [];

    foreach ($defaults as $path => $default) {
        $name = 'FASTLEO_'.strtoupper(str_replace('.', '_', $path));

        [$raw, $expected[$path]] = match (true) {
            is_bool($default) => [$default ? 'false' : 'true', ! $default],
            is_int($default) => [(string) ($default + 7), $default + 7],
            $path === 'files.max_size' => ['2048', 2048],
            $path === 'menu.collapsed' => ['true', true],
            default => ['from-env', 'from-env'],
        };

        setEnv($name, $raw);
    }

    $config = packageConfig();

    expect($defaults)->toHaveCount(67);

    foreach ($expected as $path => $value) {
        expect(data_get($config, $path))->toBe($value, $path);
    }
});

it('turns 1 and 0 into on and off and keeps the defaults when the variable is not set', function (): void {
    setEnv('FASTLEO_AUTH_TWO_FACTOR_REQUIRED', '1');
    setEnv('FASTLEO_AUTH_NEW_LOGIN_MAIL', '0');

    $config = packageConfig();

    expect($config['auth']['two_factor_required'])->toBeTrue()
        ->and($config['auth']['new_login_mail'])->toBeFalse()
        ->and($config['path'])->toBe('fastleo')
        ->and($config['files']['max_size'])->toBeNull()
        ->and($config['record_view'])->toBe('modal');
});

it('keeps the resources folder and namespace in the file only', function (): void {
    setEnv('FASTLEO_RESOURCES_PATH', 'app/Admin');
    setEnv('FASTLEO_RESOURCES_NAMESPACE', 'App\\Admin');

    expect(packageConfig()['resources'])->toBe(['path' => app_path('Fastleo'), 'namespace' => 'App\\Fastleo']);
});

it('accepts false as the record page mode and null as an unset value', function (): void {
    setEnv('FASTLEO_RECORD_VIEW', 'false');
    setEnv('FASTLEO_DOMAIN', 'null');

    expect(packageConfig()['record_view'])->toBeFalse()
        ->and(packageConfig()['domain'])->toBeNull();
});

it('takes the default addresses of the leads when the variables are empty', function (): void {
    setEnv('FASTLEO_LEADS_PATH', '');
    setEnv('FASTLEO_LEADS_API_PATH', '');

    expect(packageConfig()['leads'])->toMatchArray(['path' => 'leads', 'api_path' => 'api/leads']);
});
