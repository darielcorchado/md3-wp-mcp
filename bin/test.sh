#!/usr/bin/env bash
# Runs the PHPUnit suite inside the docker-compose.test.yml stack (PHP 8.2 + MySQL 8).
# Usage: bin/test.sh [phpunit args, e.g. --filter test_defaults]
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

if [ ! -d vendor ]; then
	composer install --no-interaction
fi
# The classmap autoloader only knows classes present when it was last dumped.
composer dump-autoload --quiet

COMPOSE=(docker compose -f docker-compose.test.yml)

"${COMPOSE[@]}" up -d db

ready=0
for _ in $(seq 1 30); do
	if "${COMPOSE[@]}" exec -T db mysqladmin ping -uroot -proot --silent >/dev/null 2>&1; then
		ready=1
		break
	fi
	sleep 2
done
if [ "$ready" -ne 1 ]; then
	echo "Database did not become ready in time" >&2
	exit 1
fi

"${COMPOSE[@]}" run --rm -e WP_PHPUNIT__TESTS_CONFIG=tests/wp-tests-config.php php php vendor/bin/phpunit "$@"
