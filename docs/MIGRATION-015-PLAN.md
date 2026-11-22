# طرح اجرای ایمن Migration 015 — یکپارچگی اطلاعات شرکت‌ها

این Migration فقط محدودیت‌های داده را به بانک اطلاعاتی اضافه می‌کند؛ هیچ کاربر، سند یا رکورد مالی را حذف یا منتقل نمی‌کند. نصب روی دیتابیس عملیاتی **خودکار نیست**.

## پیش‌نیازهای عملیاتی

۱. از کل بانک اطلاعاتی نسخه پشتیبان بگیرید و صحت بازیابی آن را در محیط غیرعملیاتی بررسی کنید.

۲. مطمئن شوید Migrationهای 009 تا 014 قبلاً اجرا شده‌اند. سپس این بررسی‌های **فقط خواندنی** را با دسترسی دیتابیس انجام دهید:

```sql
SELECT a.user_id, a.company_id AS assigned_company, u.company_id AS actual_company
FROM user_role_assignments a
JOIN users u ON u.id = a.user_id
WHERE NOT (a.company_id <=> u.company_id);

SELECT s.user_id, s.company_id AS scoped_company, u.company_id AS actual_company
FROM user_document_scopes s
JOIN users u ON u.id = s.user_id
WHERE NOT (s.company_id <=> u.company_id);
```

هر دو نتیجه باید خالی باشند. اگر مغایرت وجود دارد، ابتدا بررسی انسانی انجام دهید؛ اصلاح یا حذف خودکار رکورد ممنوع است.

۳. Migration `database/migrations/015_user_tenant_foreign_keys.sql` را ابتدا روی Staging اجرا کرده و آزمون درج نامعتبر بین‌شرکتی را انجام دهید. این فایل قابل اجرای مجدد طراحی شده است.

۴. در یک پنجره نگهداری، پس از پشتیبان‌گیری تازه، Migration را روی دیتابیس عملیاتی اعمال کنید. محدودیت‌های موجود نباید با خاموش‌کردن Foreign Key Checks دور زده شوند.

۵. وجود `uq_users_id_company`، `fk_assignment_user_tenant` و `fk_scope_user_tenant` را در `information_schema` بررسی کنید. آزمون‌های CI فقط صحت محیط ایزوله MariaDB را تضمین می‌کنند، نه نصب واقعی روی هاست.

## بازگشت

در صورت مشکل، ابتدا جلوی نوشتن‌های جدید مرتبط را بگیرید. حذف دستی FK تنها با طرح بازگشت مستند، بررسی وابستگی و نسخه پشتیبان تأییدشده مجاز است. چون عملیات ALTER در MySQL ممکن است Commit ضمنی انجام دهد، به Rollback تراکنشی تکیه نکنید.
