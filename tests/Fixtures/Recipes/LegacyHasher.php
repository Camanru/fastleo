<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Recipes;

use Illuminate\Hashing\BcryptHasher;

/**
 * Хешер из рецепта «Старые пароли при переезде» (docs/recipes.md): проверяет md5(strrev(md5())) и просит перехешировать.
 */
final class LegacyHasher extends BcryptHasher
{
    /**
     * Старый хеш сверяется как раньше, остальные — как bcrypt.
     */
    public function check($value, $hashedValue, array $options = []): bool
    {
        if ($this->isLegacy($hashedValue)) {
            return hash_equals($hashedValue, md5(strrev(md5($value))));
        }

        return parent::check($value, $hashedValue, $options);
    }

    /**
     * Старый хеш всегда заменяется.
     */
    public function needsRehash($hashedValue, array $options = []): bool
    {
        return $this->isLegacy($hashedValue) || parent::needsRehash($hashedValue, $options);
    }

    private function isLegacy(?string $hash): bool
    {
        return $hash !== null && preg_match('/^[a-f0-9]{32}$/', $hash) === 1;
    }
}
