<?php

declare(strict_types=1);

use Fastleo\Installer\ChildArtisan;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    // «Сайт» с artisan, который печатает свои аргументы: настоящий сайт здесь не нужен.
    $this->sandbox = sys_get_temp_dir().'/fastleo-child-'.getmypid().'-'.uniqid();
    File::ensureDirectoryExists($this->sandbox);
    File::put($this->sandbox.'/artisan', "<?php\nfwrite(STDOUT, json_encode(array_slice(\$argv, 1), JSON_UNESCAPED_UNICODE));\nexit((int) (\$argv[1] === 'fail'));\n");
    $this->app->setBasePath($this->sandbox);
});

afterEach(function (): void {
    File::deleteDirectory($this->sandbox);
});

it('runs an artisan command of the site in a separate process and passes its arguments and output on', function (): void {
    $output = '';

    $code = new ChildArtisan()->run('fastleo:user', ['email' => 'a@b.ru', '--name' => 'Иван Петров', '--password' => null, '--force' => true, '--skip' => false], false, function (string $buffer) use (&$output): void {
        $output .= $buffer;
    });

    expect($code)->toBe(0)
        ->and(json_decode($output, true))->toBe(['fastleo:user', 'a@b.ru', '--name=Иван Петров', '--force', '--no-interaction']);
});

it('returns the exit code of the command and does not turn off the questions when it may ask them', function (): void {
    $output = '';

    $code = new ChildArtisan()->run('fail', [], true, function (string $buffer) use (&$output): void {
        $output .= $buffer;
    });

    expect($code)->toBe(1)
        ->and(json_decode($output, true))->toBe(['fail']);
});

it('runs without an output callback', function (): void {
    expect(new ChildArtisan()->run('fastleo:doctor'))->toBe(0);
});
