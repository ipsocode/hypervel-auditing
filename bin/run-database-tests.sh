#!/usr/bin/env bash

# Run the feature tests against one database connection. Adapted from hypervel/components'
# bin/run-database-tests.sh (branch 0.4, e44bad61bfd91a0f68eacbfdbc9d871f56cbb100).
#
# Usage:
#   ./bin/run-database-tests.sh mysql
#   ./bin/run-database-tests.sh pgsql -p 3 --filter=AuditTest
#
# Supported connection names are mysql, mariadb, pgsql, and sqlite. The runner
# sets DB_CONNECTION from this argument and runs tests/Feature. The connection's
# host, port, credentials, and database come from the DB_* environment
# variables, as config/database.php reads them.
#
# Arguments after the connection name are forwarded to ParaTest. A run with extra
# options tolerates an empty suite, because a selection option may match nothing;
# a run without them stays strict.

set -euo pipefail

if [[ $# -lt 1 ]]; then
    echo "Usage: $0 <mysql|mariadb|pgsql|sqlite> [ParaTest options]" >&2
    exit 2
fi

script_directory="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
project_directory="$(dirname "$script_directory")"
connection=$1
shift

case "$connection" in
    mysql | mariadb | pgsql | sqlite) ;;
    *)
        echo "Unsupported database connection: $connection" >&2
        echo "Usage: $0 <mysql|mariadb|pgsql|sqlite> [ParaTest options]" >&2
        exit 2
        ;;
esac

export DB_CONNECTION="$connection"

paratest_options=("$@")

if [[ $# -gt 0 ]]; then
    paratest_options+=(--do-not-fail-on-empty-test-suite)
fi

cd "$project_directory"

printf 'Running %s\n' 'tests/Feature'
vendor/bin/testbench package:test --parallel "${paratest_options[@]}" tests/Feature
