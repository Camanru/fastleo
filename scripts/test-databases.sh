#!/usr/bin/env bash
#
# Прогон набора на всех СУБД из CI (SQLite, MySQL, PostgreSQL) перед выпуском: MySQL сортирует ключи JSON,
# PostgreSQL ломает транзакцию после ошибки запроса, `uuid` есть не везде — SQLite этого не покажет.
#
#   composer test:databases
#
# Параметры подключения переопределяются переменными окружения (значения по умолчанию — локальные серверы).
# Базы `fastleo_test` пересоздаются тестами: рабочие базы здесь не указывать.

set -uo pipefail

cd "$(dirname "$0")/.."

log_dir="$(mktemp -d)"
suite=(vendor/bin/pest --exclude-testsuite=Browser)

run_mysql() {
    DB_CONNECTION=mysql DB_HOST="${MYSQL_HOST:-127.0.0.1}" DB_PORT="${MYSQL_PORT:-3306}" DB_DATABASE="${MYSQL_DATABASE:-fastleo_test}" \
        DB_USERNAME="${MYSQL_USERNAME:-root}" DB_PASSWORD="${MYSQL_PASSWORD:-root}" "${suite[@]}" >"$log_dir/mysql.log" 2>&1
}

run_pgsql() {
    DB_CONNECTION=pgsql DB_HOST="${PGSQL_HOST:-127.0.0.1}" DB_PORT="${PGSQL_PORT:-5432}" DB_DATABASE="${PGSQL_DATABASE:-fastleo_test}" \
        DB_USERNAME="${PGSQL_USERNAME:-$USER}" DB_PASSWORD="${PGSQL_PASSWORD:-}" "${suite[@]}" >"$log_dir/pgsql.log" 2>&1
}

run_sqlite() {
    "${suite[@]}" --parallel >"$log_dir/sqlite.log" 2>&1
}

# MySQL и PostgreSQL — разные серверы и разные базы, поэтому идут одновременно; SQLite в памяти не мешает обоим.
run_mysql & mysql_pid=$!
run_pgsql & pgsql_pid=$!
run_sqlite & sqlite_pid=$!

status=0

for name in sqlite mysql pgsql; do
    pid_var="${name}_pid"
    wait "${!pid_var}" || status=1
    printf '%-7s %s\n' "$name" "$(grep -E 'Tests:|Fatal|SQLSTATE' "$log_dir/$name.log" | head -3 | tr -s ' ' | paste -sd ' ' -)"
done

if [ "$status" -ne 0 ]; then
    echo "Есть падения, полные логи: $log_dir"
    exit 1
fi

rm -rf "$log_dir"
echo "Все СУБД зелёные."
