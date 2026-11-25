-- Read-only post-migration verification for migration 024.
-- Run before and after on a restored copy of the production database.
-- Any nonzero orphan/mismatch count blocks rollout.
SELECT 'companies_without_branch_one' AS check_name,COUNT(*) AS failures
FROM companies c LEFT JOIN branches b ON b.company_id=c.id AND b.branch_number=1 WHERE b.id IS NULL
UNION ALL
SELECT 'invoices_without_branch',COUNT(*) FROM invoices WHERE branch_id IS NULL
UNION ALL
SELECT 'payments_without_branch',COUNT(*) FROM invoice_payments WHERE branch_id IS NULL
UNION ALL
SELECT 'cash_registers_without_branch',COUNT(*) FROM cash_registers WHERE branch_id IS NULL
UNION ALL
SELECT 'invoices_wrong_company',COUNT(*) FROM invoices i
LEFT JOIN branches b ON b.id=i.branch_id AND b.company_id=i.company_id WHERE b.id IS NULL
UNION ALL
SELECT 'payments_wrong_company',COUNT(*) FROM invoice_payments p
LEFT JOIN branches b ON b.id=p.branch_id AND b.company_id=p.company_id WHERE b.id IS NULL
UNION ALL
SELECT 'user_assignments_wrong_company',COUNT(*) FROM user_branch_assignments a
LEFT JOIN branches b ON b.id=a.branch_id AND b.company_id=a.company_id WHERE b.id IS NULL
UNION ALL
SELECT 'historical_invoices_not_branch_one',COUNT(*) FROM invoices i
JOIN branches b ON b.id=i.branch_id WHERE b.branch_number<>1
UNION ALL
SELECT 'historical_payments_not_branch_one',COUNT(*) FROM invoice_payments p
JOIN branches b ON b.id=p.branch_id WHERE b.branch_number<>1;

-- Capture these BEFORE migration and AFTER migration, per company; counts and sums must be identical.
SELECT company_id,COUNT(*) AS invoices_count,
       COALESCE(SUM(total_amount),0) AS invoices_total
FROM invoices GROUP BY company_id ORDER BY company_id;
SELECT company_id,payment_method,COUNT(*) AS payments_count,
       COALESCE(SUM(amount),0) AS payments_total,
       SUM(voided_at IS NOT NULL) AS voided_count
FROM invoice_payments GROUP BY company_id,payment_method ORDER BY company_id,payment_method;
SELECT company_id,COUNT(*) AS register_count FROM cash_registers GROUP BY company_id ORDER BY company_id;
