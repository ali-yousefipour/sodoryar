#!/usr/bin/env python3
"""Isolated browser E2E of real PHP pages and MariaDB-backed financial workflows.

Only uses localhost disposable test data; never touches live company accounts.
"""
from __future__ import annotations

import io
import zipfile
import os
import pathlib
import re
import shutil
from urllib.parse import urlparse

from playwright.sync_api import expect, sync_playwright

ORIGIN = os.environ.get("SODORYAR_E2E_ORIGIN", "http://127.0.0.1:8089").rstrip("/")
parsed = urlparse(ORIGIN)
if parsed.hostname not in ("127.0.0.1", "localhost") or parsed.scheme != "http":
    raise RuntimeError("Browser E2E must only target an isolated local PHP server")

OUTPUT = pathlib.Path(os.environ.get("GITHUB_WORKSPACE", ".")) / "e2e-artifacts"
OUTPUT.mkdir(parents=True, exist_ok=True)
NUMBERS = str.maketrans("۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩", "01234567890123456789")


def ascii_number(value: str) -> int:
    return int(re.sub(r"\D", "", value.translate(NUMBERS)) or "0")


def log_in(browser, name: str):
    context = browser.new_context(viewport={"width": 390, "height": 844})
    page = context.new_page()
    response = page.goto(f"{ORIGIN}/?page=login", wait_until="domcontentloaded")
    assert response is not None and response.status == 200, "Login page failed to load"
    expect(page.locator(".login-card")).to_be_visible()
    # Shared number handling must keep visible Persian digits in numeric text
    # and in DOM elements rendered after the initial page load.
    page.evaluate("""() => {
      const input=document.createElement('input');
      input.name='ci_numeric_probe';
      input.type='text';
      input.inputMode='numeric';
      input.id='ciNumericProbe';
      document.body.append(input);
      const span=document.createElement('span');
      span.id='ciDynamicDigits';
      span.textContent='شماره 12345';
      document.body.append(span);
    }""")
    page.locator("#ciNumericProbe").fill("12345")
    expect(page.locator("#ciNumericProbe")).to_have_value("۱۲۳۴۵")
    expect(page.locator("#ciDynamicDigits")).to_have_text("شماره ۱۲۳۴۵")
    page.evaluate("() => {document.getElementById('ciNumericProbe').remove();document.getElementById('ciDynamicDigits').remove();}")
    assert "Vazirmatn" in page.locator("body").evaluate(
        "(el) => getComputedStyle(el).fontFamily"
    ), "Login must use the shared Persian font stack"
    expect(page.locator("#loginUsername")).to_have_attribute("autocomplete", "username")
    expect(page.locator("#loginPassword")).to_have_attribute("autocomplete", "current-password")
    assert page.locator('input[name="csrf"]').count() == 1, "Login CSRF field missing"
    stylesheet = page.locator('link[href*="login-experience.css"]')
    assert stylesheet.count() == 1, "Login redesign CSS link is missing"
    css_path = stylesheet.get_attribute("href")
    assert css_path and css_path.startswith("assets/login-experience.css?v="), (
        "Login CSS must use a versioned URL"
    )
    css_response = page.request.get(f"{ORIGIN}/{css_path}", timeout=15000)
    assert css_response.status == 200, "Login CSS is missing from the host"
    assert b".login-layout" in css_response.body(), "Login CSS payload is incorrect"
    assert page.locator(".login-card").evaluate(
        "(el) => getComputedStyle(el).backgroundColor"
    ) != "rgba(0, 0, 0, 0)", "Login card styling is missing"
    # Responsive checks run against real rendered PHP login markup in Chromium.
    for width in (320, 390, 768, 1440):
        page.set_viewport_size({"width": width, "height": 844})
        assert page.evaluate("document.documentElement.scrollWidth <= innerWidth + 2"), (
            f"Login page overflows horizontally at {width}px"
        )
        assert page.locator("#loginUsername").is_visible(), (
            f"Login username is hidden at {width}px"
        )
        assert page.locator("#loginCaptcha").is_visible(), (
            f"Login CAPTCHA is hidden at {width}px"
        )
    page.set_viewport_size({"width": 390, "height": 844})
    if name == "ci_manager_a":
        page.screenshot(path=str(OUTPUT / "login-mobile.png"), full_page=True)
    # Check password reveal and concealment without changing the actual login.
    toggle = page.locator("[data-login-password-toggle]")
    expect(toggle).to_be_visible()
    toggle.click()
    expect(page.locator("#loginPassword")).to_have_attribute("type", "text")
    expect(toggle).to_have_attribute("aria-pressed", "true")
    toggle.click()
    expect(page.locator("#loginPassword")).to_have_attribute("type", "password")
    expect(toggle).to_have_attribute("aria-pressed", "false")
    figures = page.locator("#captchaQuestion span").all_text_contents()
    if len(figures) < 3:
        raise AssertionError("PHP login CAPTCHA did not render")
    answer = ascii_number(figures[0]) + ascii_number(figures[2])
    page.locator("#loginUsername").fill(name)
    page.locator("#loginPassword").fill("Temporary-Test-Passphrase-123!")
    page.locator("#loginCaptcha").fill(str(answer))
    page.locator(".login-submit").click()
    page.wait_for_url(lambda url: "page=dashboard" in url, timeout=15000)
    expect(page.locator(".app-shell")).to_be_visible()
    return context, page


def main() -> None:
    chrome = next(
        (path for name in ("google-chrome-stable", "google-chrome", "chromium", "chromium-browser")
         if (path := shutil.which(name))),
        None,
    )
    if not chrome:
        raise RuntimeError("A Chromium/Chrome binary is required")

    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(
            executable_path=chrome, headless=True,
            args=["--no-sandbox", "--disable-dev-shm-usage"],
        )
        context_a, manager_a = log_in(browser, "ci_manager_a")
        service_response=manager_a.goto(f"{ORIGIN}/?page=services")
        if manager_a.get_by_text("CI E۲E Service A").count() == 0:
            manager_a.screenshot(path=str(OUTPUT / "service-error.png"), full_page=True)
            raise AssertionError(
                f"Company A service missing: HTTP {service_response.status if service_response else 'none'}; "
                f"URL={manager_a.url}; page={manager_a.locator('body').inner_text()[:1800]}"
            )
        expect(manager_a.get_by_text("CI E۲E Service A")).to_be_visible()
        assert manager_a.get_by_text("CI E۲E Service B").count() == 0, "Foreign company service leaked"

        # Global Ctrl+K search must honor tenant isolation and keyboard dismissal.
        manager_a.keyboard.press("Control+k")
        search_dialog=manager_a.locator("#globalSearchDialog")
        expect(search_dialog).to_be_visible()
        search_dialog.locator("#globalSearchInput").fill("CI E2E Service A")
        expect(search_dialog.locator("#globalSearchResults")).to_contain_text(
            "CI E۲E Service A", timeout=12000
        )
        assert "CI E۲E Service B" not in search_dialog.inner_text(), (
            "Cross-company service leaked into global search"
        )
        manager_a.keyboard.press("Escape")
        expect(search_dialog).not_to_be_visible()

        manager_a.goto(f"{ORIGIN}/?page=invoices")
        expect(manager_a.locator("#invoiceCreateForm")).not_to_be_visible()
        assert "Vazirmatn" in manager_a.locator(".app-shell").evaluate(
            "(el) => getComputedStyle(el).fontFamily"
        ), "Application typography differs from the login screen"
        assert manager_a.locator(".main-wrap>.content input").first.evaluate(
            "(el) => parseFloat(getComputedStyle(el).fontSize)"
        ) >= 13, "Application text fields use an unreadable font size"
        scroll_hints = manager_a.locator(".sd-table-scroll-hint")
        assert scroll_hints.count() > 0, "Wide invoice table has no scroll guidance"
        assert any(scroll_hints.nth(i).is_visible() for i in range(scroll_hints.count())), (
            "Mobile invoice table hides columns without a horizontal scroll hint"
        )
        manager_a.screenshot(path=str(OUTPUT / "invoices-mobile.png"), full_page=True)
        viewport_width = manager_a.evaluate("window.innerWidth")
        page_width = manager_a.evaluate("document.documentElement.scrollWidth")
        assert page_width <= viewport_width + 2, (
            f"Invoice mobile page overflows horizontally: {page_width}px > {viewport_width}px"
        )
        manager_a.locator("#invoiceWizardOpen").click()
        wizard=manager_a.locator("#invoiceWizard")
        expect(wizard).to_be_visible()
        expect(wizard.locator(".invoice-wizard-steps li[aria-current=step]")).to_contain_text("تاکسیران")
        expect(wizard.locator("[data-wizard-prev]")).not_to_be_visible()
        expect(wizard.locator("[data-wizard-total]")).to_be_visible()
        assert wizard.evaluate("(el) => el.scrollWidth <= el.clientWidth + 2"), "Wizard horizontal overflow"
        assert wizard.locator("[data-wizard-next]").evaluate("(el) => el.getBoundingClientRect().height >= 44"), "Wizard touch target too small"
        manager_a.locator("#invoiceDriverNational").fill("1234567890")
        expect(manager_a.locator("#invoiceDriverStatus")).to_contain_text("CI Driver", timeout=12000)
        manager_a.locator("[data-wizard-next]").click()
        manager_a.locator('[data-service-option][data-service-title="CI E2E Service A"] input').check()
        selected_total = manager_a.locator("[data-service-total]").inner_text()
        assert ascii_number(selected_total) == 45000, "Selected service price did not update"
        manager_a.locator("[data-add-invoice-item]").click()
        manager_a.locator('input[name="extra_title[]"]').fill("CI E2E Custom Service")
        extra_price = manager_a.locator('input[name="extra_price[]"]')
        extra_price.fill("۱۲۳۴")
        expect(extra_price).to_have_value("۱٬۲۳۴")
        extra_price.press("End")
        extra_price.press("5")
        expect(extra_price).to_have_value("۱۲٬۳۴۵")
        expect(manager_a.locator("[data-service-total]")).to_contain_text("۵۷٬۳۴۵")
        extra_price.fill("۱۲۳۴۵67")
        expect(extra_price).to_have_value("۱٬۲۳۴٬۵۶۷")
        assert not re.search(r"[0-9٠-٩]", extra_price.input_value()), (
            "Localized amount mixes Persian and Latin digits"
        )
        extra_price.fill("1,5")
        expect(extra_price).to_have_attribute("aria-invalid", "true")
        expect(extra_price).to_have_value("۱,۵")
        expect(manager_a.locator(".money-input-error").first).to_be_visible()
        extra_price.fill("999999999999999")
        expect(extra_price).to_have_attribute("aria-invalid", "true")
        expect(manager_a.locator("[data-service-total]")).to_contain_text(
            "مبلغ موردی نامعتبر"
        )
        assert not re.search(r"[0-9٠-٩]", extra_price.input_value()), (
            "Over-limit monetary input must not display Latin or Arabic numerals"
        )
        extra_price.fill("۱۲۳٬45۶")
        expect(extra_price).to_have_value("۱۲۳٬۴۵۶")
        expect(extra_price).not_to_have_attribute("aria-invalid", "true")
        extra_price.fill("1")
        extra_price.press("End")
        extra_price.type(",5")
        expect(extra_price).to_have_attribute("aria-invalid", "true")
        extra_price.fill("۲۵۰۰۰")
        assert ascii_number(manager_a.locator("[data-service-total]").inner_text()) == 70000, (
            "Custom invoice item was not included in the browser total"
        )
        manager_a.locator("[data-wizard-next]").click()
        manager_a.locator("[data-wizard-prev]").click()
        expect(extra_price).to_have_value("۲۵٬۰۰۰")
        manager_a.locator("[data-wizard-next]").click()
        method = manager_a.locator('#invoicePaymentRows [name$="[method]"]')
        for mode in ('cash', 'pos', 'cash'):
            manager_a.locator(f'[data-payment-mode="{mode}"]').click()
            assert manager_a.locator('#invoicePaymentRows select[name$="[method]"]').count() == 0
            expect(method).to_have_attribute('type', 'hidden')
            expect(method).to_have_value(mode)
            assert method.is_enabled(), 'Payment method must remain included in form submission'
            assert manager_a.locator('#invoicePaymentRows .payment-pos-fields').is_visible() == (mode == 'pos')
            assert manager_a.locator('#invoicePaymentRows input[name$="[tracking]"]').evaluate('(el) => el.required') == (mode == 'pos')
        manager_a.locator('[data-payment-mode="multi"]').click()
        assert method.locator('option').evaluate_all('(options) => options.map(o => o.value)') == ['cash', 'pos']
        method.select_option('pos')
        expect(manager_a.locator('#invoicePaymentRows .payment-pos-fields')).to_be_visible()
        manager_a.locator('[data-wizard-next]').click()
        assert manager_a.locator('#invoicePaymentRows input[name$="[tracking]"]').evaluate('(el) => el.validity.valueMissing')
        assert 'page=invoices' in manager_a.url, 'POS without tracking was submitted'
        method.select_option('cash')
        expect(manager_a.locator('#invoicePaymentRows .payment-pos-fields')).to_be_hidden()
        payment_amount = manager_a.locator('#invoiceCreateForm').locator('input[name="payments[0][amount]"]')
        payment_amount.fill("1,5")
        manager_a.locator("[data-wizard-next]").click()
        expect(manager_a.locator("[data-wizard-error]")).to_contain_text("اصلاح")
        payment_amount.fill("۲۰۰۰۰")
        manager_a.locator("#invoiceAddPayment").click()
        manager_a.locator('#invoiceCreateForm').locator('select[name="payments[1][method]"]').select_option("pos")
        manager_a.locator('#invoiceCreateForm').locator('input[name="payments[1][tracking]"]').fill("CI-INVOICE-POS-2026")
        manager_a.locator('#invoicePaymentRows select[name="payments[1][terminal]"]').select_option(index=1)
        expect(manager_a.locator("[data-payment-paid]")).to_contain_text("۷۰٬۰۰۰")
        expect(manager_a.locator("[data-wizard-total]")).to_contain_text("۷۰٬۰۰۰")
        expect(manager_a.locator("[data-payment-count]")).to_contain_text("۲")
        manager_a.locator("[data-wizard-next]").click()
        manager_a.locator('[data-print="1"]').click()
        manager_a.wait_for_url(lambda url: "page=receipt" in url, timeout=15000)
        expect(manager_a.locator(".receipt")).to_be_visible()
        expect(manager_a.locator(".receipt")).to_contain_text("۷۰٬۰۰۰")
        expect(manager_a.locator(".receipt")).to_contain_text("CI-INVOICE-POS-۲۰۲۶")
        assert re.search(r'شماره فاکتور:\s*[A-Z]{2}[0-9]{6}(?![0-9])', manager_a.locator('.receipt').inner_text()), (
            'New invoice number must contain two letters and six digits'
        )
        invoice_id = re.search(r"[?&]id=(\d+)", manager_a.url)
        assert invoice_id, "New invoice did not redirect to its receipt"
        receipt_pdf = manager_a.pdf(prefer_css_page_size=True, print_background=True)
        media = re.findall(rb"/MediaBox\s*\[\s*0\s+0\s+([0-9.]+)", receipt_pdf)
        assert media and 585 < float(media[0]) < 605, "Default invoice receipt did not print on A4"
        (OUTPUT / "invoice-a4.pdf").write_bytes(receipt_pdf)
        # A second invoice cannot reuse the same active POS receipt.
        manager_a.goto(f"{ORIGIN}/?page=invoices")
        manager_a.locator("#invoiceWizardOpen").click()
        manager_a.locator('#invoiceDriverNational').fill('1234567890')
        expect(manager_a.locator('#invoiceDriverStatus')).to_contain_text('CI Driver', timeout=12000)
        manager_a.locator("[data-wizard-next]").click()
        manager_a.locator('[data-service-option][data-service-title="CI E2E Service A"] input').check()
        manager_a.locator("[data-wizard-next]").click()
        manager_a.locator('[data-payment-mode="pos"]').click()
        manager_a.locator('#invoicePaymentRows input[name$="[tracking]"]').fill('CI-INVOICE-POS-2026')
        manager_a.locator('#invoicePaymentRows select[name="payments[0][terminal]"]').select_option(index=1)
        manager_a.locator("[data-wizard-next]").click()
        manager_a.locator('[data-print="1"]').click()
        expect(manager_a.get_by_text('این فیش کارتخوان قبلاً در شرکت ثبت شده است؛ شماره پیگیری تکراری است.')).to_be_visible()
        manager_a.goto(f'{ORIGIN}/?page=dashboard')
        for key in ('today_count','month_count','today_total','month_total','receipts_today','cash_today','pos_today','due_total','unsettled_count','open_cashboxes','pending_cashboxes','unreviewed_issues'):
            card = manager_a.locator(f'[data-dashboard-detail="{key}"]')
            expect(card).to_have_js_property('tagName','ARTICLE')
            assert card.evaluate("el => getComputedStyle(el).backgroundImage.includes('gradient')"), 'Dashboard card lost its original gradient'
            card.click()
            expect(manager_a.locator('#dashboardDetail')).to_be_visible()
            expect(manager_a.locator('#dashboardDetailSearch')).to_be_enabled(timeout=15000)
            manager_a.locator('#dashboardDetail [data-dialog-close]').first.click()
        # Issue a partially collected invoice and settle the remainder later.
        manager_a.goto(f"{ORIGIN}/?page=invoices")
        manager_a.locator("#invoiceWizardOpen").click()
        manager_a.locator("#invoiceDriverNational").fill("1234567890")
        expect(manager_a.locator("#invoiceDriverStatus")).to_contain_text("CI Driver", timeout=12000)
        manager_a.locator("[data-wizard-next]").click()
        manager_a.locator('[data-service-option][data-service-title="CI E2E Service A"] input').check()
        manager_a.locator("[data-wizard-next]").click()
        manager_a.locator("#invoiceAllowPartial").check()
        manager_a.locator('#invoiceCreateForm input[name="payments[0][amount]"]').fill("15000")
        expect(manager_a.locator("[data-payment-remaining]")).to_contain_text("۳۰٬۰۰۰")
        manager_a.locator("[data-wizard-next]").click()
        old_nonce=manager_a.locator('[name="invoice_create_nonce"]').input_value()
        old_count=manager_a.locator('[data-dialog-open^="invoiceCollect"]').count()
        manager_a.locator('[data-save-next="1"]').click()
        expect(manager_a.locator("#invoiceWizard")).to_be_visible(timeout=15000)
        expect(manager_a.locator(".invoice-wizard-steps li[aria-current=step]")).to_contain_text("تاکسیران")
        expect(manager_a.locator("#invoiceDriverNational")).to_have_value("")
        expect(manager_a.locator("[data-wizard-total]")).to_have_text("۰")
        assert manager_a.locator('[name="invoice_create_nonce"]').input_value()!=old_nonce
        assert manager_a.locator('[name="service_ids[]"]:checked').count()==0
        assert not manager_a.locator("#invoiceAllowPartial").is_checked()
        expect(manager_a.locator('[data-save-next="1"]')).not_to_be_visible()
        manager_a.locator("[data-wizard-cancel]").click()
        candidates=manager_a.locator('[data-dialog-open^="invoiceCollect"]')
        assert candidates.count()==old_count+1
        partial_id=re.search(r"invoiceCollect([0-9]+)",candidates.first.get_attribute("data-dialog-open"))
        assert partial_id
        # Shared invoice editor: edit date and payment in one dialog, never a separate date modal.
        invoice_editor=manager_a.locator(
            f'[data-dialog-open="invoiceEditor{partial_id.group(1)}"]'
        )
        expect(invoice_editor).to_be_visible()
        assert manager_a.locator('[data-dialog-open^="invoiceDateEdit"]').count()==0
        invoice_editor.click()
        editor_dialog=manager_a.locator(f'#invoiceEditor{partial_id.group(1)}')
        expect(editor_dialog).to_be_visible()
        assert editor_dialog.locator('[name="action"][value="edit_invoice_date"]').count()==1
        assert editor_dialog.locator('[name="invoice_date"][data-jalali-iso]').count()==1
        assert editor_dialog.locator('[name="action"][value="replace_payments"]').count()==1
        assert editor_dialog.locator('dialog').count()==0
        expect(editor_dialog.locator('[data-invoice-tab="date"]')).to_have_attribute("aria-selected","true")
        expect(editor_dialog.locator('[data-invoice-panel="date"]')).to_be_visible()
        expect(editor_dialog.locator('[data-invoice-panel="payment"]')).to_be_hidden()
        size=editor_dialog.bounding_box()
        assert size and size["height"] <= 615, "Invoice editor is too tall"
        editor_dialog.locator('[data-invoice-tab="payment"]').click()
        expect(editor_dialog.locator('[data-invoice-panel="payment"]')).to_be_visible()
        expect(editor_dialog.locator('[data-invoice-panel="date"]')).to_be_hidden()
        editor_dialog.locator('[data-invoice-tab="date"]').click()
        expect(editor_dialog.locator('[data-invoice-panel="date"]')).to_be_visible()
        editor_dialog.locator('[data-dialog-close]').first.click()
        expect(editor_dialog).not_to_be_visible()
        collect_button=manager_a.locator(f'[data-dialog-open="invoiceCollect{partial_id.group(1)}"]')
        expect(collect_button).to_be_visible()
        collect_button.click()
        collect_form=manager_a.locator(f'#invoiceCollect{partial_id.group(1)} form')
        collect_form.locator('[name="amount"]').fill("30000")
        collect_form.locator('button[type="submit"]').click()
        manager_a.wait_for_load_state("domcontentloaded")
        manager_a.goto(f"{ORIGIN}/?page=receipt&id={partial_id.group(1)}")
        expect(manager_a.locator(".receipt")).to_contain_text("جمع دریافتی")
        expect(manager_a.locator(".receipt")).to_contain_text("۴۵٬۰۰۰")
        assert "مانده: ۰" in manager_a.locator(".receipt").inner_text(), (
            "Follow-up receipt did not fully settle invoice"
        )
        manager_a.goto(f"{ORIGIN}/?page=invoice_manager")
        # Manager page reuses the same date+payment editor instead of adding a date dialog.
        manager_editor=manager_a.locator(
            f'[data-dialog-open="invoiceEditor{partial_id.group(1)}"]'
        )
        expect(manager_editor).to_be_visible()
        assert manager_a.locator('[data-dialog-open^="invoiceDateEdit"]').count()==0
        manager_editor.click()
        manager_dialog=manager_a.locator(f'#invoiceEditor{partial_id.group(1)}')
        expect(manager_dialog).to_be_visible()
        assert manager_dialog.locator('[name="action"][value="edit_invoice_date"]').count()==1
        assert manager_dialog.locator('[name="invoice_date"][data-jalali-iso]').count()==1
        assert manager_dialog.locator('[name="action"][value="replace_payments"]').count()==1
        manager_dialog.locator('[data-invoice-tab="payment"]').click()
        expect(manager_dialog.locator('[data-invoice-panel="payment"]')).to_be_visible()
        assert manager_dialog.evaluate("(el) => el.scrollHeight <= window.innerHeight + 10")
        manager_dialog.locator('[data-dialog-close]').first.click()
        expect(manager_a.get_by_role("heading", name="مدیریت و گزارش فاکتورها")).to_be_visible()
        expect(manager_a.locator(".manager-results")).to_contain_text("CI E۲E Custom Service")
        assert manager_a.locator(".manager-results tbody tr").count() > 0, (
            "Invoice manager did not show the newly issued invoice"
        )
        manager_a.locator('.manager-filters [name="q"]').fill("does-not-exist-ci")
        manager_a.locator('.manager-filters button[type="submit"]').click()
        expect(manager_a.locator(".manager-results")).to_contain_text("فاکتوری با این فیلترها پیدا نشد.")
        manager_a.goto(f"{ORIGIN}/?page=invoice_manager&method=mixed")
        expect(manager_a.locator(".manager-results tbody")).to_contain_text("ترکیبی")
        report_url=(
            f"{ORIGIN}/?page=invoice_manager&export=excel&method=mixed"
            "&columns%5B%5D=number&columns%5B%5D=amount&columns%5B%5D=paid"
        )
        export_response=context_a.request.get(report_url)
        assert export_response.status==200, (
            f"Filtered invoice XLSX returned HTTP {export_response.status}"
        )
        with zipfile.ZipFile(io.BytesIO(export_response.body())) as workbook:
            sheet=workbook.read("xl/worksheets/sheet1.xml")
            assert b'rightToLeft="1"' in sheet and b'autoFilter' in sheet and b'pane' in sheet, (
                "Invoice XLSX must keep RTL, filters and frozen header"
            )
            assert b't="n"' in sheet, "Money columns must be numeric Excel cells"
            assert b'r="D1"' not in sheet, "Export did not honor selected three-column layout"



        # Driver financial profile must stay tenant-scoped and keep numeric XLSX cells.
        manager_a.goto(f"{ORIGIN}/?page=customers")
        driver_link=manager_a.locator('a[href^="?page=driver_account&id="]').first
        expect(driver_link).to_be_visible()
        driver_url=driver_link.get_attribute("href")
        assert driver_url, "Customer financial statement link missing"
        manager_a.goto(f"{ORIGIN}/{driver_url}")
        expect(manager_a.get_by_role("heading", name="پرونده مالی تاکسیران")).to_be_visible()
        expect(manager_a.get_by_text("مانده مطالبات")).to_be_visible()
        export_link=manager_a.locator('a[href*="export=excel"]').first
        expect(export_link).to_be_visible()
        statement=context_a.request.get(f"{ORIGIN}/{export_link.get_attribute('href').replace('&amp;', '&')}")
        assert statement.status==200, "Driver account Excel export failed"
        with zipfile.ZipFile(io.BytesIO(statement.body())) as archive:
            sheet=archive.read("xl/worksheets/sheet1.xml")
            assert b'rightToLeft="1"' in sheet, "Driver XLSX must retain RTL sheet setting"
            assert b't="n"' in sheet, "Driver invoice monetary amounts must be numeric XLSX cells"
            assert b"CI-B-0001" not in sheet, "Foreign-company invoice leaked into driver statement"

        manager_a.goto(f"{ORIGIN}/?page=bills")
        form = manager_a.locator("form[data-once-submit]").first
        form.locator('[name="bill_id"]').fill("CI-E2E-BILL")
        form.locator('[name="payment_id"]').fill("CI-E2E-PAYMENT")
        form.locator('[name="amount"]').fill("۲۵۰۰۰")
        form.locator('[name="paid_method"]').select_option("pos")
        expect(form.locator('[name="ref_code"]')).to_have_attribute("required", "")
        form.locator('[name="ref_code"]').fill("CI-E2E-REF")
        form.locator("button").last.click()
        manager_a.wait_for_load_state("domcontentloaded")
        if manager_a.get_by_text("CI-E۲E-BILL").count() == 0:
            manager_a.screenshot(path=str(OUTPUT / "bill-error.png"), full_page=True)
            raise AssertionError(
                "POS bill not visible after save; URL=" + manager_a.url
                + "; body=" + manager_a.locator("body").inner_text()[:2300]
            )
        expect(manager_a.get_by_role("cell", name="CI-E۲E-BILL", exact=True)).to_be_visible()
        for bill_id, payment_id, tracking in (
            ('CI-E2E-BILL', 'CI-E2E-PAYMENT', 'CI-OTHER-REF'),
            ('CI-OTHER-BILL', 'CI-OTHER-PAYMENT', 'CI-E2E-REF'),
        ):
            manager_a.goto(f'{ORIGIN}/?page=bills')
            form = manager_a.locator('form[data-once-submit]').first
            form.locator('[name="bill_id"]').fill(bill_id)
            form.locator('[name="payment_id"]').fill(payment_id)
            form.locator('[name="amount"]').fill('25000')
            form.locator('[name="paid_method"]').select_option('pos')
            form.locator('[name="ref_code"]').fill(tracking)
            form.locator('button').last.click()
            expect(manager_a.get_by_text('این فیش قبلاً ثبت شده است؛ شناسه قبض و پرداخت یا شماره پیگیری تکراری است.')).to_be_visible()

        manager_a.goto(f"{ORIGIN}/?page=reports")
        expect(manager_a.locator(".content")).to_be_visible()
        assert manager_a.get_by_text("CI-B-۰۰۰۱").count() == 0, "Foreign company invoice leaked into reports"

        # Exercise XLSX output using only the disposable database.
        for route in ("reports", "activity"):
            downloaded = manager_a.request.get(f"{ORIGIN}/?page={route}&export=excel")
            assert downloaded.status == 200, f"{route} spreadsheet response failed"
            with zipfile.ZipFile(io.BytesIO(downloaded.body())) as archive:
                assert "xl/worksheets/sheet1.xml" in archive.namelist()
                sheet = archive.read("xl/worksheets/sheet1.xml").decode("utf-8")
                if route == "reports":
                    assert "CI-A-OTHER" in sheet
                    assert "CI-B-0001" not in sheet

        context_b, manager_b = log_in(browser, "ci_manager_b")
        manager_b.goto(f"{ORIGIN}/?page=services")
        expect(manager_b.get_by_text("CI E۲E Service B")).to_be_visible()
        assert manager_b.get_by_text("CI E۲E Service A").count() == 0, "Company A service leaked into B"
        manager_b.goto(f"{ORIGIN}/?page=receipt&id={invoice_id.group(1)}")
        expect(manager_b.get_by_text("فاکتور پیدا نشد.")).to_be_visible()
        assert manager_b.locator(".receipt").count() == 0, "Foreign invoice receipt accessible by ID"

        # Audit menu destinations and responsive disclosure before walking routes.
        manager_a.goto(f"{ORIGIN}/?page=invoices", wait_until="domcontentloaded")
        links = manager_a.locator("#primaryNav a.nav-link")
        assert links.count() >= 10, "Company manager navigation is incomplete"
        destinations = links.evaluate_all(
            "(els) => els.map(el => el.getAttribute('href'))"
        )
        assert len(destinations) == len(set(destinations)), "Duplicate sidebar destinations"
        expect(manager_a.locator("#primaryNav .nav-link.active")).to_have_count(1)
        mobile_menu = manager_a.locator("[data-sidebar-toggle]")
        mobile_menu.click()
        expect(mobile_menu).to_have_attribute("aria-expanded", "true")
        expect(manager_a.locator("#primaryNav")).to_have_class(
            re.compile("nav-open")
        )
        mobile_menu.click()
        expect(mobile_menu).to_have_attribute("aria-expanded", "false")
        # Authenticated navigation audit across all company-management modules.
        # Every route must render without PHP errors or viewport-wide overflow.
        for route in (
            "dashboard", "users", "roles", "customers", "services", "invoices",
            "reports", "finance_center", "cashbox", "periods", "bills", "pos",
            "commissions", "templates", "settings", "activity", "profile",
        ):
            response = manager_a.goto(f"{ORIGIN}/?page={route}", wait_until="domcontentloaded")
            status = response.status if response else 0
            body = manager_a.locator("body").inner_text()
            if status != 200 or "Fatal error:" in body or "Uncaught " in body:
                manager_a.screenshot(path=str(OUTPUT / f"route-{route}-failure.png"), full_page=True)
                raise AssertionError(f"{route} HTTP {status}, body={body[:1200]}")
            active_route = manager_a.locator("#primaryNav a[aria-current='page']")
            expect(active_route).to_have_count(1)
            assert active_route.get_attribute("href") == f"?page={route}", (
                f"{route} sidebar highlight points to a different page"
            )
            if route in ("dashboard", "customers", "services", "invoices", "users",
                         "roles", "reports", "bills", "settings", "templates", "profile"):
                manager_a.screenshot(
                    path=str(OUTPUT / f"layout-{route}-mobile.png"), full_page=True
                )
            width = manager_a.evaluate("document.documentElement.scrollWidth")
            viewport = manager_a.evaluate("window.innerWidth")
            if width > viewport + 2:
                manager_a.screenshot(path=str(OUTPUT / f"route-{route}-overflow.png"), full_page=True)
                raise AssertionError(f"{route} overflows 390px viewport ({width}px > {viewport}px)")
        # IAM permission categories should stay compact and keyboard-expandable.
        manager_a.goto(f"{ORIGIN}/?page=roles", wait_until="domcontentloaded")
        permission_groups = manager_a.locator(".permission-matrix").first.locator(
            "details.permission-group"
        )
        assert permission_groups.count() >= 2, "IAM permission categories missing"
        expect(permission_groups.first).to_have_attribute("open", "")
        second_permission = permission_groups.nth(1)
        assert not second_permission.evaluate("(el) => el.open"), (
            "Secondary IAM permission groups must be collapsed on mobile"
        )
        second_permission.locator("summary").click()
        assert second_permission.evaluate("(el) => el.open"), (
            "IAM permissions must expand on keyboard or pointer activation"
        )
        expect(second_permission.locator('input[type="checkbox"]').first).to_be_visible()
        # The categorized settings page must render every original editable
        # field inside one CSRF-protected form, without exposing new routes.
        manager_a.goto(f"{ORIGIN}/?page=settings", wait_until="domcontentloaded")
        expect(manager_a.locator(".settings-workspace")).to_be_visible()
        expect(manager_a.locator(".settings-index-link")).to_have_count(3)
        for section in ("settings-company", "settings-commission", "settings-invoice"):
            expect(manager_a.locator(f"#{section}")).to_be_visible()
        settings_form = manager_a.locator(".settings-form")
        expect(settings_form.locator('input[name="csrf"]')).to_have_count(1)
        for field in (
            "company_name", "company_phone", "company_website", "company_address",
            "commission_enabled", "commission_type", "commission_rate",
            "commission_period", "commission_limit", "commission_over_limit_rate",
            "currency", "invoice_footer",
        ):
            assert settings_form.locator(f'[name="{field}"]').count() == 1, (
                f"Settings redesign lost the {field} field"
            )
        expect(settings_form.locator('button[type="submit"]')).to_be_visible()
        assert manager_a.locator(".settings-savebar").evaluate(
            "(el) => getComputedStyle(el).position"
        ) == "static", "Mobile settings save bar obscures editable fields"
        manager_a.screenshot(path=str(OUTPUT / "settings-mobile.png"), full_page=True)
        # Exercise branch editing and recoverable archiving only in the guarded disposable site.
        manager_a.goto(f"{ORIGIN}/?page=branches")
        branch_add = manager_a.locator('form').filter(has=manager_a.locator('input[name="number"]'))
        branch_add.locator('[name="number"]').fill('887')
        branch_add.locator('[name="name"]').fill('CI branch lifecycle')
        branch_add.locator('[name="manager_name"]').fill('CI responsible person')
        branch_add.locator('[name="postal_code"]').fill('۱۲۳۴۵۶۷۸۹۰')
        branch_add.get_by_role('button', name='ثبت شعبه', exact=True).click()
        branch_card = manager_a.locator('details').filter(has_text='CI branch lifecycle')
        branch_card.locator('summary').click()
        branch_id = branch_card.locator('[name="branch_id"]').input_value()
        expect(branch_card.locator('[name="postal_code"]')).to_have_value('1234567890')
        branch_card.locator('[name="name"]').fill('CI branch edited')
        branch_card.locator('[name="is_active"]').uncheck()
        branch_card.get_by_role('button', name='ذخیره شعبه', exact=True).click()
        branch_card = manager_a.locator('details').filter(has_text='CI branch edited')
        expect(branch_card.locator('summary')).to_contain_text('غیرفعال')
        manager_a.goto(f"{ORIGIN}/?page=branch_users")
        assert manager_a.locator('select[name="branch_id"] option[value="'+branch_id+'"]').count() == 0, 'Archived branch remains assignable'
        manager_a.goto(f"{ORIGIN}/?page=branches")
        branch_card = manager_a.locator('details').filter(has_text='CI branch edited')
        branch_card.locator('summary').click()
        branch_card.locator('[name="is_active"]').check()
        branch_card.get_by_role('button', name='ذخیره شعبه', exact=True).click()
        manager_a.goto(f"{ORIGIN}/?page=branch_users")
        assignment = manager_a.locator('form').filter(has=manager_a.locator('[name="action"][value="assign"]'))
        assignment.locator('[name="branch_id"]').select_option(branch_id)
        assigned_user = assignment.locator('[name="user_id"]').input_value()
        assignment.locator('[name="is_default"]').check()
        assignment.get_by_role('button', name='ثبت تخصیص', exact=True).click()
        revoke = manager_a.locator('form').filter(has=manager_a.locator('[name="action"][value="revoke"]')).filter(has=manager_a.locator('[name="branch_id"][value="'+branch_id+'"]')).filter(has=manager_a.locator('[name="user_id"][value="'+assigned_user+'"]'))
        revoke.get_by_role('button', name='لغو دسترسی شعبه', exact=True).click()
        expect(manager_a.locator('#mainContent')).to_contain_text('لغوشده')
        manager_a.goto(f"{ORIGIN}/?page=settings")
        assert manager_a.locator("#primaryNav .nav-link.active").count() == 1, (
            "Categorized sidebar lost the active route"
        )
        # Verify the real authenticated workflow at compact mobile, tablet and
        # desktop widths, not just the isolated component fixture.
        for width in (320, 768, 1440):
            manager_a.set_viewport_size({"width": width, "height": 900})
            for route in ("dashboard", "invoices", "bills", "templates", "reports", "finance_center", "cashbox", "periods", "settings"):
                response = manager_a.goto(f"{ORIGIN}/?page={route}", wait_until="domcontentloaded")
                status = response.status if response else 0
                if status != 200:
                    raise AssertionError(f"{route} returned HTTP {status} at {width}px")
                document_width = manager_a.evaluate("document.documentElement.scrollWidth")
                actual_viewport = manager_a.evaluate("window.innerWidth")
                if document_width > actual_viewport + 2:
                    manager_a.screenshot(
                        path=str(OUTPUT / f"responsive-{route}-{width}-overflow.png"),
                        full_page=True,
                    )
                    raise AssertionError(
                        f"{route} overflows {width}px viewport ({document_width}px > {actual_viewport}px)"
                    )
            manager_a.goto(f"{ORIGIN}/?page=receipt&id={invoice_id.group(1)}")
            expect(manager_a.locator(".receipt")).to_be_visible()
            assert manager_a.evaluate("document.documentElement.scrollWidth") <= width + 2, (
                f"Invoice receipt overflows {width}px viewport"
            )
        manager_a.set_viewport_size({"width": 390, "height": 844})
        context_b.close()
        context_a.close()
        browser.close()
    print("PASS: isolated real-login, finance PDF, tenant boundaries, 14 protected routes and multi-breakpoint authenticated views")


if __name__ == "__main__":
    main()
