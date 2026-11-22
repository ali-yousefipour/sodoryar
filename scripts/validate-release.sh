#!/usr/bin/env bash
# Repeatable offline/CI release gate. Never deploys and never modifies a production DB.
set -euo pipefail
cd "$(dirname "$0")/.."

command -v php >/dev/null || { echo 'PHP CLI is required.' >&2; exit 1; }
command -v node >/dev/null || { echo 'Node.js CLI is required.' >&2; exit 1; }
php -r '
if (PHP_VERSION_ID < 80400) { fwrite(STDERR,"PHP 8.4+ is required for this release gate.\n");exit(1); }
foreach (["pdo_mysql","mbstring","zip","gd"] as $extension) {
    if (!extension_loaded($extension)) {
        fwrite(STDERR,"PHP extension missing: $extension\n");exit(1);
    }
}
'

find public src scripts tests integration -type f -name '*.php' -print0 |
  xargs -0 -r -n1 php -l
for js in public/assets/*.js; do
  node --check "$js"
done
for test_file in tests/*.php; do
  echo "Running ${test_file}"
  php "$test_file"
done
if [[ "${SODORYAR_INTEGRATION:-0}" == "1" ]]; then
  if [[ "${DB_HOST:-}" != "127.0.0.1" && "${DB_HOST:-}" != "localhost" ]] ||
     [[ "${DB_NAME:-}" != "sodoryar_integration_test" ]]; then
    echo 'Refusing integration test outside disposable local sodoryar_integration_test database.' >&2
    exit 2
  fi
  php integration/mysql-tenant-smoke.php
else
  echo 'DB integration not run. Set up an isolated MariaDB database and SODORYAR_INTEGRATION=1.'
fi
echo 'Source/language and PHP contract tests passed.'
