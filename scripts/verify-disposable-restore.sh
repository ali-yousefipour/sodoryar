#!/usr/bin/env bash
# Disposable CI-only backup/restore verification. NEVER pass production credentials.
set -euo pipefail
if [[ "${SODORYAR_INTEGRATION:-}" != 1 || "${DB_NAME:-}" != sodoryar_integration_test || "${DB_HOST:-}" != 127.0.0.1 ]]; then
  echo "Refusing backup verification outside disposable localhost test database" >&2
  exit 2
fi
export MYSQL_PWD="${DB_PASS:?Missing disposable CI database password}"
client="$(command -v mariadb || command -v mysql || true)"
dump_client="$(command -v mariadb-dump || command -v mysqldump || true)"
if [[ -z "$client" || -z "$dump_client" ]]; then
  echo "MariaDB command-line backup and restore clients not installed" >&2
  exit 2
fi
temporary="$(mktemp -d)"
trap 'rm -rf "$temporary"' EXIT
"$dump_client" --host="$DB_HOST" --port="${DB_PORT:-3306}" --user="${DB_USER:-root}" \
  --single-transaction --quick --skip-lock-tables --no-tablespaces \
  "$DB_NAME" > "$temporary/database.sql"
test -s "$temporary/database.sql"
"$client" --host="$DB_HOST" --port="${DB_PORT:-3306}" --user="${DB_USER:-root}" \
  -e 'DROP DATABASE IF EXISTS sodoryar_restore_verify; CREATE DATABASE sodoryar_restore_verify CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
"$client" --host="$DB_HOST" --port="${DB_PORT:-3306}" --user="${DB_USER:-root}" \
  sodoryar_restore_verify < "$temporary/database.sql"
for table in companies users customers invoices invoice_payments cash_registers cash_shifts financial_period_locks service_rate_versions user_preferences branches company_bank_accounts expenses expense_categories expense_items expense_payments expense_events expense_settlements expense_reversals expense_returns expense_policies expense_branch_approvers expense_mutation_requests expense_attachments; do
  original="$("$client" --host="$DB_HOST" --port="${DB_PORT:-3306}" --user="${DB_USER:-root}" -Nse "SELECT COUNT(*) FROM \`sodoryar_integration_test\`.\`$table\`")"
  restored="$("$client" --host="$DB_HOST" --port="${DB_PORT:-3306}" --user="${DB_USER:-root}" -Nse "SELECT COUNT(*) FROM \`sodoryar_restore_verify\`.\`$table\`")"
  if [[ "$original" != "$restored" ]]; then
    echo "Restored row count differs in $table" >&2
    exit 1
  fi
done
original_total="$("$client" --host="$DB_HOST" --port="${DB_PORT:-3306}" --user="${DB_USER:-root}" -Nse 'SELECT COALESCE(SUM(total_amount),0) FROM sodoryar_integration_test.invoices')"
restored_total="$("$client" --host="$DB_HOST" --port="${DB_PORT:-3306}" --user="${DB_USER:-root}" -Nse 'SELECT COALESCE(SUM(total_amount),0) FROM sodoryar_restore_verify.invoices')"
[[ "$original_total" == "$restored_total" ]] || { echo 'Invoice amount mismatch after restore' >&2; exit 1; }
original_paid="$("$client" --host="$DB_HOST" --port="${DB_PORT:-3306}" --user="${DB_USER:-root}" -Nse 'SELECT COALESCE(SUM(amount),0) FROM sodoryar_integration_test.invoice_payments')"
restored_paid="$("$client" --host="$DB_HOST" --port="${DB_PORT:-3306}" --user="${DB_USER:-root}" -Nse 'SELECT COALESCE(SUM(amount),0) FROM sodoryar_restore_verify.invoice_payments')"
[[ "$original_paid" == "$restored_paid" ]] || { echo 'Receipt amount mismatch after restore' >&2; exit 1; }
for financial_table in expenses expense_payments expense_settlements expense_returns; do
  amount_column=amount
  if [[ "$financial_table" == expenses ]]; then amount_column=total_amount; fi
  original_amount="$("$client" --host="$DB_HOST" --port="${DB_PORT:-3306}" --user="${DB_USER:-root}" -Nse "SELECT COALESCE(SUM($amount_column),0) FROM sodoryar_integration_test.$financial_table")"
  restored_amount="$("$client" --host="$DB_HOST" --port="${DB_PORT:-3306}" --user="${DB_USER:-root}" -Nse "SELECT COALESCE(SUM($amount_column),0) FROM sodoryar_restore_verify.$financial_table")"
  [[ "$original_amount" == "$restored_amount" ]] || { echo "Expense ledger amount mismatch after restore: $financial_table" >&2; exit 1; }
done
echo 'PASS: disposable MariaDB backup, restore, tenant records, invoice totals and receipts'
