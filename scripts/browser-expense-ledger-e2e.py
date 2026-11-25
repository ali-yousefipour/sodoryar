#!/usr/bin/env python3
"""Exercise expense posting and real settlement returns only on disposable localhost data."""
import importlib.util
import io
import pathlib
import re
import shutil
import zipfile
from playwright.sync_api import expect, sync_playwright

spec = importlib.util.spec_from_file_location('financial_e2e', pathlib.Path(__file__).with_name('browser-financial-e2e.py'))
financial = importlib.util.module_from_spec(spec)
spec.loader.exec_module(financial)
ORIGIN, OUTPUT = financial.ORIGIN, financial.OUTPUT


def main():
    chrome = next((p for n in ('google-chrome-stable', 'google-chrome', 'chromium', 'chromium-browser') if (p := shutil.which(n))), None)
    if not chrome:
        raise RuntimeError('Chromium is required')
    with sync_playwright() as pw:
        browser = pw.chromium.launch(executable_path=chrome, headless=True, args=['--no-sandbox', '--disable-dev-shm-usage'])
        context, page = financial.log_in(browser, 'ci_manager_a')
        errors = []
        page.on('pageerror', lambda error: errors.append(str(error)))
        page.on('console', lambda msg: errors.append(msg.text) if msg.type == 'error' and 'Content Security Policy' in msg.text else None)
        page.set_viewport_size({'width': 1440, 'height': 844})
        page.goto(f'{ORIGIN}/?page=expenses')
        expect(page.get_by_role('link', name='هزینه‌ها', exact=True)).to_be_visible()
        page.locator('#expenseOpen').click()
        for width in (320, 390, 768, 1440):
            page.set_viewport_size({'width': width, 'height': 844})
            assert page.locator('#expenseDialog').evaluate('(el) => el.scrollWidth <= el.clientWidth + 2'), f'Dialog overflow at {width}'
            expect(page.get_by_label('عنوان', exact=True)).to_be_visible()
        page.set_viewport_size({'width': 390, 'height': 844})
        page.get_by_label('عنوان', exact=True).fill('CI browser personal tea')
        page.locator('#expenseNext').click()
        page.locator('#expenseItems input[name$="[title]"]').fill('چای')
        page.locator('[data-price]').fill('۲۰۰٬۰۰۰')
        page.screenshot(path=str(OUTPUT / 'expense-wizard-mobile.png'), full_page=True)
        page.locator('#expenseNext').click()
        page.locator('[data-method]').select_option('personal')
        page.locator('#expenseNext').click()
        expect(page.locator('#expensePreview')).to_contain_text('۲۰۰٬۰۰۰')
        page.locator('#expenseSave').click()
        expect(page.get_by_text('هزینه در انتظار تأیید ثبت شد.', exact=True)).to_be_visible()
        row = page.locator('tr').filter(has_text='CI browser personal tea')
        row.get_by_role('link', name='جزئیات و چاپ').click()
        detail = re.search(r'detail=(\d+)', page.url).group(1)
        review = page.locator('form').filter(has=page.locator('input[name="action"][value="review"]'))
        review.locator('input[name="reason"]').fill('CI browser approval')
        review.locator('button[value="approved"]').click()
        page.goto(f'{ORIGIN}/?page=expenses&detail={detail}')
        expect(page.get_by_text('مانده قابل بازپرداخت به', exact=False)).to_contain_text('۲۰۰٬۰۰۰')
        settle = page.locator('form').filter(has=page.locator('input[name="action"][value="settle"]')).filter(has=page.locator('input[name="method"][value="bank"]'))
        settle.locator('..').locator('summary').click()
        settle.locator('input[name="amount"]').fill('۵۰٬۰۰۰')
        settle.locator('select[name="source"]').select_option(index=1)
        settle.locator('input[name="payment_confirmed"]').check()
        settle.get_by_role('button', name='ثبت تسویه', exact=True).click()
        page.goto(f'{ORIGIN}/?page=expenses&detail={detail}')
        expect(page.get_by_text('مانده قابل بازپرداخت به', exact=False)).to_contain_text('۱۵۰٬۰۰۰')
        refund = page.locator('form').filter(has=page.locator('input[name="action"][value="return"]'))
        refund.locator('..').locator('summary').click()
        refund.locator('input[name="amount"]').fill('۲۰٬۰۰۰')
        refund.locator('input[name="reference"]').fill('CI real bank return')
        refund.locator('textarea[name="reason"]').fill('CI employee returned partial settlement')
        refund.locator('input[name="refund_confirmed"]').check()
        refund.get_by_role('button', name='ثبت دریافت برگشت وجه').click()
        page.goto(f'{ORIGIN}/?page=expenses&detail={detail}')
        expect(page.get_by_text('مانده قابل بازپرداخت به', exact=False)).to_contain_text('۱۷۰٬۰۰۰')
        expect(page.get_by_role('cell', name='CI real bank return', exact=True)).to_be_visible()
        for width in (320, 390, 768, 1440):
            page.set_viewport_size({'width': width, 'height': 844})
            assert page.evaluate('document.documentElement.scrollWidth <= innerWidth + 2'), f'Expense detail overflow at {width}'
        page.screenshot(path=str(OUTPUT / 'expense-return-desktop.png'), full_page=True)
        # This verifies browser print output only, not a standalone application PDF export.
        pdf = page.pdf(format='A4', print_background=True)
        assert pdf.startswith(b'%PDF-') and len(pdf) > 1000
        (OUTPUT / 'expense-browser-print.pdf').write_bytes(pdf)
        response = page.request.get(f'{ORIGIN}/?page=expenses&export=xlsx&q=CI%20browser%20personal%20tea')
        assert response.status == 200
        with zipfile.ZipFile(io.BytesIO(response.body())) as archive:
            sheet = archive.read('xl/worksheets/sheet1.xml').decode()
            assert 'CI browser personal tea' in sheet and '200000' in sheet
        foreign_context, foreign = financial.log_in(browser, 'ci_manager_b')
        foreign.goto(f'{ORIGIN}/?page=expenses&detail={detail}')
        expect(foreign.get_by_text('سند یافت نشد یا دسترسی مجاز نیست.', exact=True)).to_be_visible()
        assert not errors, errors
        foreign_context.close()
        context.close()
        browser.close()
    print('PASS: expense wizard, approval, bank reimbursement/partial return, liability, XLSX, print, responsive UI, tenant isolation and JavaScript/CSP checks')


if __name__ == '__main__':
    main()
