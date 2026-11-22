# CI revalidation after GitHub Actions repository fix — 2026-11-22

## Scope
- Repository: `ali-yousefipour/sodoryar`, default branch `main`.
- Prior HEAD inspected: `df90d5673238bb264d4c4f02d5818799b3029aaa`.
- Request: re-run validations after GitHub Actions repository-side issue was fixed.
- Existing workflow runs `45043314335` (PHP syntax check) and `45043314286` (deployment with isolated MariaDB gate) were explicitly re-run.
- On the re-run, GitHub hosted runners began executing actual steps; the PHP run completed source and regression steps successfully at the time of review, while MariaDB setup was still in progress.
- Historical runs only validate their original revisions. This documentation commit intentionally triggers both push-based workflows on the **current main HEAD** so that the latest code is tested together.
- CI must not be declared green until the new-run conclusions are checked. Deployment must not be declared complete until FTPS ownership marker, TLS validation, live commit manifest and asset hashes all pass.
- Do not weaken SQL/tenant/security gates, bypass TLS, alter secrets, or run production migrations automatically to make deployment succeed.

## Validation priorities
1. PHP 8.4 syntax and all source regression tests.
2. Isolated MariaDB 11.4 fresh-install/migration and tenant isolation.
3. Safe deployment only after both independent checks succeed.
4. Record the precise failing step and sanitized error, then apply targeted fixes if needed.
