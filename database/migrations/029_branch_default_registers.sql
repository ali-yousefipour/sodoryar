-- 029: provision a cash register only for branches with no existing register.
-- Prerequisite: 024. Re-runnable; creates no shifts, receipts or opening balances.
-- Serialize migration execution with other schema changes.
INSERT INTO cash_registers(company_id,branch_id,name)
SELECT b.company_id,b.id,CONCAT('صندوق پیش‌فرض شعبه ',b.branch_number,' #',b.id)
FROM branches b
WHERE NOT EXISTS (
 SELECT 1 FROM cash_registers r WHERE r.company_id=b.company_id AND r.branch_id=b.id
);
