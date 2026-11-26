#!/usr/bin/env python3
"""Exercise expense posting and real settlement returns only on disposable localhost data."""
import importlib.util
import base64
import io
import pathlib
import re
import shutil
import zipfile
import xml.etree.ElementTree as ET
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
        # The receipt remains private and draft edits preserve identity before submission.
        page.goto(f'{ORIGIN}/?page=expenses')
        page.locator('#expenseOpen').click()
        page.get_by_label('عنوان', exact=True).fill('CI editable draft with receipt')
        png = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a7e8AAAAASUVORK5CYII=')
        page.locator('input[name="receipt"]').set_input_files({'name': 'receipt.png', 'mimeType': 'image/png', 'buffer': png})
        page.locator('#expenseNext').click()
        page.locator('#expenseItems input[name$="[title]"]').fill('Draft tea')
        page.locator('[data-price]').fill('۱۰۰٬۰۰۰')
        page.locator('#expenseExtra').fill('۲۰٬۰۰۰')
        page.locator('#expenseDiscount').fill('۵٬۰۰۰')
        page.locator('#expenseNext').click()
        page.locator('[data-method]').select_option('personal')
        page.locator('#expenseNext').click()
        expect(page.locator('#expensePreview')).to_contain_text('۱۱۵٬۰۰۰')
        page.locator('#expenseDraft').click()
        expect(page.get_by_text('پیش‌نویس هزینه ذخیره شد.', exact=True)).to_be_visible()
        page.locator('tr').filter(has_text='CI editable draft with receipt').get_by_role('link', name='جزئیات و چاپ').click()
        draft_detail = re.search(r'detail=(\d+)', page.url).group(1)
        attachment = page.get_by_role('link', name='receipt.png', exact=True).get_attribute('href')
        attachment_response = page.request.get(f'{ORIGIN}/{attachment}')
        assert attachment_response.status == 200 and attachment_response.body() == png
        assert foreign.request.get(f'{ORIGIN}/{attachment}').status == 404, 'Cross-tenant receipt download leaked'
        page.get_by_role('link', name='ویرایش و ارسال مجدد هزینه').click()
        expect(page.locator('#expenseDialog')).to_be_visible()
        expect(page.get_by_label('عنوان', exact=True)).to_have_value('CI editable draft with receipt')
        page.get_by_label('عنوان', exact=True).fill('CI corrected draft with receipt')
        for _ in range(3):
            page.locator('#expenseNext').click()
        page.add_init_script('window.print = () => { window.__expensePrinted = true; };')
        page.locator('#expensePrint').click()
        page.wait_for_url(lambda url: f'detail={draft_detail}' in url and 'print=1' in url)
        page.wait_for_function('window.__expensePrinted === true')
        expect(page.get_by_role('link', name='receipt.png', exact=True)).to_be_visible()
        expect(page.get_by_text('CI corrected draft with receipt', exact=False).first).to_be_visible()
        # Every report exports the same complete totals shown in its filtered UI.
        page.goto(f'{ORIGIN}/?page=expense_reports')
        report_types = page.locator('select[name="report"] option').evaluate_all('(options) => options.map(o => o.value)')
        assert len(report_types) == 19
        ns = {'s': 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}
        for report_type in report_types:
            response = page.goto(f'{ORIGIN}/?page=expense_reports&report={report_type}')
            assert response.status == 200
            expect(page.locator('.expense-report-print h2')).to_be_visible()
            assert page.locator('.alert.error').count() == 0, page.locator('body').inner_text()
            footer = page.locator('.expense-report-print tfoot th').all_text_contents()
            download = page.request.get(f'{ORIGIN}/?page=expense_reports&report={report_type}&export=xlsx')
            assert download.status == 200
            with zipfile.ZipFile(io.BytesIO(download.body())) as archive:
                sheet = ET.fromstring(archive.read('xl/worksheets/sheet1.xml'))
                last = sheet.findall('s:sheetData/s:row', ns)[-1].findall('s:c', ns)
                assert len(last) == len(footer)
                for cell, displayed in zip(last, footer):
                    text = cell.findtext('s:v', namespaces=ns) if cell.get('t') == 'n' else cell.findtext('s:is/s:t', default='', namespaces=ns)
                    if cell.get('t') == 'n':
                        expected = displayed.translate(financial.NUMBERS).replace('٬', '').replace(',', '').strip()
                        assert text == expected, (report_type, text, expected)
                    else:
                        assert text == displayed.strip(), (report_type, text, displayed)
            pdf_response = page.request.get(f'{ORIGIN}/?page=expense_reports&report={report_type}&export=pdf')
            assert pdf_response.status == 200 and pdf_response.body().startswith(b'%PDF-'), report_type
            assert b'/FontFile2' in pdf_response.body(), 'PDF did not embed its Persian font'
            if report_type == 'daily':
                (OUTPUT / 'expense-independent-report.pdf').write_bytes(pdf_response.body())
            for width in (320, 390, 768, 1440):
                page.set_viewport_size({'width': width, 'height': 844})
                assert page.evaluate('document.documentElement.scrollWidth <= innerWidth + 2'), f'{report_type} report overflow at {width}'
        page.screenshot(path=str(OUTPUT / 'expense-reports-desktop.png'), full_page=True)
        viewer_context, viewer = financial.log_in(browser, 'ci_expense_viewer')
        assert viewer.goto(f'{ORIGIN}/?page=expenses').status == 200
        assert viewer.locator('#expenseOpen').count() == 0, 'View-only permission exposed creation UI'
        viewer_csrf = viewer.locator('input[name="csrf"]').first.input_value()
        for action in ('create', 'settle', 'return', 'void', 'category', 'policy', 'approver'):
            denied = viewer.request.post(f'{ORIGIN}/?page=expenses', form={'csrf': viewer_csrf, 'action': action, 'id': detail})
            assert denied.status == 403, ('view-only mutation', action, denied.status)
        for url in ('?page=expenses&export=xlsx', '?page=expense_reports&export=xlsx', '?page=expense_reports&export=pdf'):
            assert viewer.request.get(f'{ORIGIN}/{url}').status == 403, ('view-only export', url)
        viewer.goto(f'{ORIGIN}/?page=expenses&detail={detail}')
        expect(viewer.get_by_text('سند یافت نشد یا دسترسی مجاز نیست.', exact=True)).to_be_visible()
        assert viewer.request.get(f'{ORIGIN}/{attachment}').status == 404, 'Same-company nonowner downloaded a private receipt'
        none_context, none = financial.log_in(browser, 'ci_expense_none')
        for route in ('expenses', 'expense_reports', 'expense_attachment'):
            assert none.request.get(f'{ORIGIN}/?page={route}').status == 403, ('missing view grant', route)
        none_context.close()
        viewer_context.close()
        assert not errors, errors
        foreign_context.close()
        context.close()
        browser.close()
    print('PASS: expense wizard, approval, bank reimbursement/partial return, draft edit/resubmit, private receipt, discount/extra, save/print, liability, XLSX, responsive UI, tenant isolation and JavaScript/CSP checks')


if __name__ == '__main__':
    main()
