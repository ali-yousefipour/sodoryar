# PC-POS LAN integration — implementation status

## Scope
This branch introduces an additive, tenant-and-branch-scoped payment-request schema. It **does not** yet enable financial posting, live device communication, or any PSP-approved transaction.

## Network architecture
The web host cannot reach RFC1918 addresses within branches. A Windows branch agent must initiate outbound HTTPS polling to a dedicated server endpoint, obtain one leased command at a time, communicate with an officially supported PSP SDK/protocol on the local LAN, then report a sanitized result. Never expose the POS directly to the internet or accept payment confirmation from the browser.

## PSP verification matrix
| PSP | Protocol evidence | Production adapter |
| --- | --- | --- |
| Behpardakht Mellat | Model-specific official PC-POS SDK/protocol **not obtained** | Not enabled |
| Fanava Card | Model-specific official PC-POS SDK/protocol **not obtained** | Not enabled |
| Parsian PEC | Community reverse-engineering exists; not a certification | Not enabled |
| Simulator | Allowed only in isolated test environments | Not production-capable |

Examples: https://github.com/rnetis/pec-pcpos and https://github.com/jvdi/rahkar-pcpos . Neither proves compatibility with Behpardakht or Fanava.

## Deployment
1. Back up the database and verify restoration.
2. Apply `database/migrations/026_pos_bridge_foundation.sql` after 024 and existing invoice payment migrations.
3. Confirm all new tables are present and old invoice totals are unchanged.
4. Do not activate terminals until an authenticated bridge API, real SDK adapters, verified result reconciliation, financial settlement, UI, and integration tests are completed.
5. Run hardware acceptance tests against **each exact model and PSP protocol revision** before marking it supported.

## Safety invariants for the next stage
- Generate 256-bit random bridge credentials, store only a SHA-256 digest, and rotate credentials with audit trails.
- Only allow active branch/user assignments and tenant-scoped terminals; bind requests to the invoice branch.
- Use amount in **IRR as an integer**, with explicit conversion from invoice's configured unit (never guess Toman/Rial).
- Validate `company_id`, `branch_id`, amount, reference, PSP terminal identity and final approval before inserting `invoice_payments`.
- Commit approved transaction and invoice payment together within a transaction; enforce unique request and bank identifiers.
- A timeout, lost connection or unrecognized code is **unknown**, never automatically declined or paid.
- Keep all immutable audit evidence; don't persist PIN, PAN, CVV2, track data or card holder secrets.
- Physical POS support is not validated by passing simulator tests.
