<?php

declare(strict_types=1);

use Fastleo\Support\UserModelPatcher;

/**
 * Модель User из нового сайта на Laravel 13 (без изменений).
 */
function laravelUserModel(): string
{
    return <<<'PHP'
        <?php

        namespace App\Models;

        // use Illuminate\Contracts\Auth\MustVerifyEmail;
        use Database\Factories\UserFactory;
        use Illuminate\Database\Eloquent\Attributes\Fillable;
        use Illuminate\Database\Eloquent\Attributes\Hidden;
        use Illuminate\Database\Eloquent\Factories\HasFactory;
        use Illuminate\Foundation\Auth\User as Authenticatable;
        use Illuminate\Notifications\Notifiable;

        #[Fillable(['name', 'email', 'password'])]
        #[Hidden(['password', 'remember_token'])]
        class User extends Authenticatable
        {
            /** @use HasFactory<UserFactory> */
            use HasFactory, Notifiable;

            /**
             * Get the attributes that should be cast.
             *
             * @return array<string, string>
             */
            protected function casts(): array
            {
                return [
                    'email_verified_at' => 'datetime',
                    'password' => 'hashed',
                ];
            }
        }

        PHP;
}

it('connects the Laravel 13 user model with imports, interface and trait in alphabetical order', function (): void {
    $code = UserModelPatcher::patch(laravelUserModel(), 'User');

    expect($code)->toBe(str_replace(
        [
            "use Database\\Factories\\UserFactory;\n",
            "class User extends Authenticatable\n",
            'use HasFactory, Notifiable;',
        ],
        [
            "use Database\\Factories\\UserFactory;\nuse Fastleo\\Auth\\FastleoUser;\nuse Fastleo\\Auth\\HasFastleoAccess;\n",
            "class User extends Authenticatable implements FastleoUser\n",
            'use HasFactory, HasFastleoAccess, Notifiable;',
        ],
        laravelUserModel(),
    ));
});

it('adds the interface to an existing implements list and the trait to the end of the trait list', function (): void {
    $code = UserModelPatcher::patch(<<<'PHP'
        <?php

        namespace App\Models;

        use Illuminate\Contracts\Auth\MustVerifyEmail;
        use Illuminate\Foundation\Auth\User as Authenticatable;

        final class User extends Authenticatable implements MustVerifyEmail {
            use Billable;
        }
        PHP, 'User');

    expect($code)
        ->toContain("use Fastleo\\Auth\\FastleoUser;\nuse Fastleo\\Auth\\HasFastleoAccess;\nuse Illuminate\\Contracts\\Auth\\MustVerifyEmail;")
        ->toContain('final class User extends Authenticatable implements MustVerifyEmail, FastleoUser {')
        ->toContain('use Billable, HasFastleoAccess;');
});

it('appends imports after the last one and adds a trait line to a class without traits', function (): void {
    $code = UserModelPatcher::patch(<<<'PHP'
        <?php

        namespace App\Models;

        use App\Support\BaseUser;

        class Admin extends BaseUser
        {
            public function name(): string
            {
                return 'admin';
            }
        }
        PHP, 'Admin');

    expect($code)
        ->toContain("use App\\Support\\BaseUser;\nuse Fastleo\\Auth\\FastleoUser;\nuse Fastleo\\Auth\\HasFastleoAccess;")
        ->toContain("class Admin extends BaseUser implements FastleoUser\n{\n    use HasFastleoAccess;\n\n    public function name(): string");
});

it('puts imports right after the namespace when the file has none', function (): void {
    $code = UserModelPatcher::patch(<<<'PHP'
        <?php

        namespace App\Models;

        class User extends \Illuminate\Foundation\Auth\User
        {
        }
        PHP, 'User');

    expect($code)->toContain("namespace App\\Models;\n\nuse Fastleo\\Auth\\FastleoUser;\nuse Fastleo\\Auth\\HasFastleoAccess;\n\nclass User extends \\Illuminate\\Foundation\\Auth\\User implements FastleoUser");
});

it('puts the trait into the class body, not into a comment with braces, when the model already implements an interface', function (): void {
    $code = UserModelPatcher::patch(<<<'PHP'
        <?php

        /** Модель из пакета {@see docs} */

        namespace App\Models;

        use Illuminate\Contracts\Auth\MustVerifyEmail;
        use Illuminate\Foundation\Auth\User as Authenticatable;

        /**
         * @property array{street: string} $address
         */
        class User extends Authenticatable implements MustVerifyEmail
        {
            public function x(): int
            {
                return 1;
            }
        }
        PHP, 'User');

    expect($code)
        ->toContain("/**\n * @property array{street: string} \$address\n */")
        ->toContain("implements MustVerifyEmail, FastleoUser\n{\n    use HasFastleoAccess;\n\n    public function x()")
        ->not->toContain('use Fastleo\Auth\FastleoUser, HasFastleoAccess');
    expect(UserModelPatcher::compiles((string) $code))->toBeTrue();
});

it('adds the interface to an implements list written on several lines', function (): void {
    $code = UserModelPatcher::patch(<<<'PHP'
        <?php

        namespace App\Models;

        use Illuminate\Foundation\Auth\User as Authenticatable;

        class User extends Authenticatable implements
            MustVerifyEmail,
            Other
        {
            use Notifiable;
        }
        PHP, 'User');

    expect($code)
        ->toContain("    MustVerifyEmail,\n    Other, FastleoUser\n{")
        ->toContain('use HasFastleoAccess, Notifiable;');
});

it('keeps CRLF line endings of the model file', function (): void {
    $code = UserModelPatcher::patch(str_replace("\n", "\r\n", laravelUserModel()), 'User');

    expect($code)
        ->toContain("implements FastleoUser\r\n{")
        ->toContain("use Fastleo\\Auth\\HasFastleoAccess;\r\n")
        ->toContain('use HasFactory, HasFastleoAccess, Notifiable;');
    expect(str_replace("\r\n", '', (string) $code))->not->toContain("\n");
});

it('does not touch a file with mixed LF and CRLF line endings', function (): void {
    $mixed = preg_replace("/\n/", "\r\n", laravelUserModel(), 3);

    expect(UserModelPatcher::patch((string) $mixed, 'User'))->toBeNull();
});

it('adds a separate trait line when the first thing in the class is a trait with a conflict block', function (): void {
    $code = UserModelPatcher::patch(<<<'PHP'
        <?php

        namespace App\Models;

        use Illuminate\Foundation\Auth\User as Authenticatable;

        class User extends Authenticatable
        {
            use A, B {
                A::hello insteadof B;
            }

            use C;
        }
        PHP, 'User');

    expect($code)
        ->toContain("{\n    use HasFastleoAccess;\n\n    use A, B {")
        ->toContain('use C;');
    expect(UserModelPatcher::compiles((string) $code))->toBeTrue();
});

it('checks that code compiles with php -l', function (): void {
    expect(UserModelPatcher::compiles('<?php class A {}'))->toBeTrue()
        ->and(UserModelPatcher::compiles("<?php\nuse X\\Y as Z;\nuse X\\Y as Z;\n"))->toBeFalse();
});

it('leaves a file alone when it cannot be changed safely', function (string $code): void {
    expect(UserModelPatcher::patch($code, 'User'))->toBeNull();
})->with([
    'already started by hand' => [str_replace('class User extends Authenticatable', 'class User extends Authenticatable implements FastleoUser', laravelUserModel())],
    'trait added by hand' => [str_replace('use HasFactory, Notifiable;', 'use HasFactory, HasFastleoAccess, Notifiable;', laravelUserModel())],
    'no namespace' => ["<?php\n\nclass User extends Authenticatable\n{\n}\n"],
    'another class name' => [str_replace('class User extends', 'class Member extends', laravelUserModel())],
    'broken PHP' => [str_replace("        ];\n    }\n}", "        ];\n    }\n", laravelUserModel())],
]);
