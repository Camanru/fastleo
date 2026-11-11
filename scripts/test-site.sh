#!/usr/bin/env bash
#
# Тесты, которые готовые модули кладут на сайт (tests/Feature/Fastleo): в наборе пакета они только компилируются,
# а сами модули проверяют песочницы. Скрипт собирает настоящий сайт — свежий Laravel с Pest, пакет из этой папки
# (символическая ссылка), все модули с публичной частью — и запускает эти тесты и `fastleo:doctor`, как их запустит
# разработчик после установки.
#
#   composer test:site
#
# Нужна сеть: Composer ставит Laravel и Pest. Сайт создаётся во временной папке и удаляется; чтобы его осмотреть,
# задайте SITE_DIR=/путь/к/пустой/папке — папка останется.

set -euo pipefail

package="$(cd "$(dirname "$0")/.." && pwd)"
log="$(mktemp)"

if [ -n "${SITE_DIR:-}" ]; then
    site="$SITE_DIR"
    trap 'rm -f "$log"' EXIT
else
    site="$(mktemp -d)"
    trap 'rm -rf "$site" "$log"' EXIT
fi

# Модули — все, что лежат в stubs/modules (папки с «_» в начале — общие файлы, не модули).
modules="$(find "$package/stubs/modules" -mindepth 1 -maxdepth 1 -type d ! -name '_*' -exec basename {} \; | sort | paste -sd ',' -)"

# Шаг идёт тихо; если он упал, печатается его вывод.
step() {
    local title="$1"
    shift
    printf '· %s\n' "$title"

    if ! "$@" >"$log" 2>&1; then
        cat "$log"
        echo "Шаг не прошёл: $title"
        exit 1
    fi
}

step "Свежий Laravel" composer create-project laravel/laravel "$site" --no-interaction --no-progress --prefer-dist

cd "$site"

repository="{\"type\": \"path\", \"url\": \"$package\", \"options\": {\"symlink\": true, \"versions\": {\"camanru/fastleo\": \"9999999-dev\"}}}"

step "Пакет из этой папки" composer config repositories.fastleo "$repository" --json
step "Pest" composer require --dev pestphp/pest pestphp/pest-plugin-laravel --with-all-dependencies --no-interaction --no-progress
step "Fastleo" composer require "camanru/fastleo:@dev" --no-interaction --no-progress
step "Pest: настройка тестов" vendor/bin/pest --init --no-interaction
step "Модули ($modules) с публичной частью" php artisan fastleo:install --no-interaction --modules="$modules" --setup

if [ -z "$(find tests/Feature/Fastleo -name '*Test.php' 2>/dev/null)" ]; then
    echo "Установщик не положил тесты на сайт (tests/Feature/Fastleo)."
    exit 1
fi

php artisan fastleo:doctor
vendor/bin/pest tests/Feature/Fastleo
