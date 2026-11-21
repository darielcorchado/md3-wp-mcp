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

# Wait until PHP can open the test database the way WordPress will (host, user
# and database from tests/wp-tests-config.php). A fresh MySQL first runs a setup
# server that only answers on its socket, then restarts, so a ping inside the db
# container passes too early. Some Docker Compose versions also report success
# from `exec` whatever the command returns.
CAN_CONNECT='mysqli_report( MYSQLI_REPORT_OFF ); require "tests/wp-tests-config.php"; exit( @mysqli_connect( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME ) ? 0 : 1 );'
ready=0
for _ in $(seq 1 60); do
	if "${COMPOSE[@]}" run --rm -T php php -r "$CAN_CONNECT" >/dev/null 2>&1; then
		ready=1
		break
	fi
	sleep 1
done
if [ "$ready" -ne 1 ]; then
	echo "Database did not become ready in time" >&2
	exit 1
fi

"${COMPOSE[@]}" run --rm -e WP_PHPUNIT__TESTS_CONFIG=tests/wp-tests-config.php php php vendor/bin/phpunit "$@"
