#!/usr/bin/env python3
"""Real Chromium smoke tests for checked-in financial UI and print styles.

Runs against synthetic financial data, never production credentials or records.
Print paper sizes validate browser CSS output, not physical printer calibration.
"""
from __future__ import annotations

import pathlib
import re
import shutil
import sys

from playwright.sync_api import sync_playwright

ROOT = pathlib.Path(__file__).resolve().parent.parent


def number(value: str) -> int:
    table = str.maketrans("۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩", "01234567890123456789")
    return int(re.sub(r"\D", "", value.translate(table)) or "0")


def media_width(pdf: bytes) -> float:
    sizes = re.findall(rb"/MediaBox\s*\[\s*0\s+0\s+([0-9.]+)\s+([0-9.]+)\s*\]", pdf)
    if not sizes:
        raise AssertionError("Chromium PDF did not contain a measurable page size")
    widths = [float(width) for width, _ in sizes]
    if max(widths) - min(widths) > 0.5:
        raise AssertionError("Printed document mixed different paper widths")
    return widths[0]


def fixture() -> str:
    style = "\n".join(
        (ROOT / file).read_text(encoding="utf-8")
        for file in (
            "public/assets/ux-unified.css",
            "public/assets/invoice-redesign.css",
            "public/assets/print-engine.css",
        )
    )
    rows = "".join(f"<tr><td>خدمت شماره {i}</td><td>۱۲٬۳۴۵ تومان</td></tr>" for i in range(90))
    return f"""<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <style>{style}</style></head><body>
    <main class="main-wrap"><div class="content">
      <p data-test-latin-id>CI E2E Service A</p>
      <form class="invoice-form" id="invoiceCreateForm">
        <fieldset class="invoice-services invoice-panel" data-invoice-services>
          <input type="search" data-service-search aria-label="جستجوی خدمت">
          <button type="button" data-service-clear hidden>پاک کردن</button>
          <span data-service-count></span><span data-service-selected></span>
          <strong data-service-total></strong>
          <p data-service-empty hidden>خدمتی پیدا نشد</p>
          <label data-service-option data-service-title="خدمت آزمایشی">
            <input type="checkbox" data-service-price="12345" value="1">
            خدمت آزمایشی
          </label>
        </fieldset>
        <fieldset class="invoice-extras invoice-panel">
          <div id="extraInvoiceItems"></div>
          <button type="button" data-add-invoice-item>افزودن آیتم دیگر</button>
        </fieldset>
        <span class="jalali-field"><input type="hidden" name="invoice_date" value="2026-11-22" data-jalali-iso>
          <button type="button" data-jalali-open>انتخاب تاریخ</button></span>
        <button class="invoice-submit" type="submit">ثبت فاکتور</button>
      </form>
      <button type="button" data-dialog-open="smokeDialog">نمایش مودال</button>
      <dialog class="app-dialog" id="smokeDialog"><h2>تست مودال</h2>
        <button type="button" data-dialog-close>بستن</button></dialog>
      <div class="receipt card receipt-thermal receipt-roll-72">
        <div class="receipt-toolbar"><button type="button" data-print-receipt>چاپ</button></div>
        <h1>فاکتور نمونه صدوریار</h1>
        <table><thead><tr><th>خدمت</th><th>مبلغ</th></tr></thead>
          <tbody>{rows}</tbody></table>
      </div>
    </div></main></body></html>"""


def main() -> None:
    browser_path = next(
        (p for executable in ("google-chrome-stable", "google-chrome", "chromium", "chromium-browser")
         if (p := shutil.which(executable))),
        None,
    )
    if not browser_path:
        raise RuntimeError("Chromium/Chrome binary is required for browser UI and print QA")

    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(
            executable_path=browser_path,
            headless=True,
            args=["--no-sandbox", "--disable-dev-shm-usage"],
        )
        page = browser.new_page(viewport={"width": 360, "height": 800}, device_scale_factor=1)
        errors: list[str] = []
        page.on("pageerror", lambda error: errors.append(str(error)))
        # A server page must retain all 20 rows; local sorting/search would
        # misleadingly operate on only this subset of the report.
        rows = ''.join(f'<tr><td>{i}</td><td>نمونه</td></tr>' for i in range(20))
        page.set_content('<div class="main-wrap"><main class="content">'
                         '<div class="table-wrap"><table data-pagination="server">'
                         '<thead><tr><th>شماره</th><th>شرح</th></tr></thead>'
                         f'<tbody>{rows}</tbody></table></div></main></div>')
        page.add_script_tag(content=(ROOT / "public/assets/table-experience.js").read_text(encoding="utf-8"))
        page.evaluate("document.dispatchEvent(new Event('DOMContentLoaded'))")
        assert page.locator('tbody tr:visible').count() == 20
        assert page.locator('.sd-table-toolbar,.sd-table-pager,.sd-sort-button').count() == 0
        page.locator('table').evaluate("el => el.removeAttribute('data-pagination')")
        page.evaluate("document.dispatchEvent(new Event('DOMContentLoaded'))")
        assert page.locator('tbody tr:visible').count() == 15
        page.get_by_role('button', name='بعدی', exact=True).click()
        assert page.locator('tbody tr:visible').count() == 5
        print('PASS: server pagination retains every row; ordinary tables still paginate')
        page.set_content(fixture(), wait_until="load")
        page.add_script_tag(content=(ROOT / "public/assets/app.js").read_text(encoding="utf-8"))
        page.evaluate("document.dispatchEvent(new Event('DOMContentLoaded'))")
        assert page.locator("[data-test-latin-id]").inner_text() == "CI E2E Service A", (
            "Visible digit localization corrupted Latin-alphanumeric identifiers"
        )
        page.locator("[data-service-option] input").check()
        total = page.locator("[data-service-total]")
        assert number(total.inner_text()) == 12345, "Fixed service amount missing from UI total"
        page.locator("[data-add-invoice-item]").click()
        page.locator('input[name="extra_title[]"]').fill("خدمت موردی")
        price_field = page.locator('input[name="extra_price[]"]')
        price_field.fill("۱.۵")
        assert price_field.evaluate("(el) => el.validity.customError"), (
            "A decimal money value was silently converted instead of rejected"
        )
        assert "نامعتبر" in total.inner_text(), (
            "Invoice preview silently treated an invalid decimal as a monetary amount"
        )
        price_field.fill("۱,۵")
        assert price_field.evaluate("(el) => el.validity.customError"), (
            "Malformed comma grouping was silently converted into a different amount"
        )
        assert "نامعتبر" in total.inner_text(), (
            "Malformed grouping was reflected as a different valid total"
        )
        price_field.fill("۲۵۰۰۰")
        assert not price_field.evaluate("(el) => el.validity.customError")
        assert number(total.inner_text()) == 37345, "Ad-hoc amount missing from live invoice total"
        columns = page.locator(".extra-invoice-row").evaluate(
            "(element) => getComputedStyle(element).gridTemplateColumns"
        )
        assert len(columns.split()) == 1, f"Mobile extra row is not one-column: {columns}"
        page.locator(".extra-invoice-row button").click()
        assert number(total.inner_text()) == 12345, "Deleting extra item left stale total"
        page.set_viewport_size({"width": 1280, "height": 900})
        page.locator("[data-add-invoice-item]").click()
        columns = page.locator(".extra-invoice-row").evaluate(
            "(element) => getComputedStyle(element).gridTemplateColumns"
        )
        assert len(columns.split()) == 3, f"Desktop financial row is not three-column: {columns}"

        page.locator('[data-dialog-open="smokeDialog"]').click()
        assert page.locator("#smokeDialog").evaluate("(element) => element.open"), "Modal failed to open"
        assert page.locator("#smokeDialog").get_attribute("aria-labelledby"), (
            "Modal lacks a programmatically associated accessible title"
        )
        page.locator("#smokeDialog [data-dialog-close]").click()
        assert not page.locator("#smokeDialog").evaluate("(element) => element.open"), "Modal failed to close"
        page.locator("[data-jalali-open]").click()
        assert page.locator(".jalali-dialog").evaluate("(element) => element.open"), "Jalali calendar did not open"
        assert page.locator(".jalali-dialog").get_attribute("aria-label") == "انتخاب تاریخ شمسی", (
            "Dynamic Jalali dialog lacks an accessible name"
        )
        page.locator(".jalali-dialog .calendar-day").first.click()
        assert re.fullmatch(r"\d{4}-\d{2}-\d{2}", page.locator('[name="invoice_date"]').input_value()), (
            "Calendar did not update ISO form value"
        )

        page.evaluate("""() => document.querySelector('#invoiceCreateForm')
            .dispatchEvent(new Event('submit', {bubbles: true, cancelable: true}))""")
        assert page.locator(".invoice-submit").is_disabled(), "Invoice form was not locked after submission"
        page.evaluate("window.dispatchEvent(new Event('pageshow'))")
        assert page.locator(".invoice-submit").is_enabled(), (
            "Browser history restoration left invoice submission disabled"
        )

        page.emulate_media(media="print")
        toolbar = page.locator(".receipt-toolbar").evaluate(
            "(element) => getComputedStyle(element).display"
        )
        assert toolbar == "none", "Receipt printing must hide interactive controls"

        for paper_class, lower, upper in (
            ("receipt-thermal", 220, 239),
            ("receipt-a5", 410, 430),
            ("receipt-a4", 587, 603),
        ):
            page.locator(".receipt").evaluate(
                "(element, paper) => element.className = 'receipt card ' + paper + ' receipt-roll-72'",
                paper_class,
            )
            pdf = page.pdf(print_background=True, prefer_css_page_size=True)
            width = media_width(pdf)
            assert lower <= width <= upper, f"{paper_class} printed at unexpected width {width:.1f}pt"
            pages = len(re.findall(rb"/Type\s*/Page\b", pdf))
            assert pages >= 1, f"{paper_class} produced no printable pages"
            if paper_class == "receipt-thermal":
                assert pages >= 2, "Long thermal receipt did not paginate"
            print(f"Chrome {paper_class}: PDF page width {width:.1f}pt; {pages} page(s)")
        page.emulate_media(media="screen")
        page.set_content("""<form id="invoiceCreateForm"><select id="invoiceBranch"><option value="1">One</option><option value="2">Two</option></select>
        <label data-service-option><input type="checkbox" checked data-service-price="1000"></label><div id="extraInvoiceItems"></div>
        <button type="button" data-payment-mode="pos">POS</button><button type="button" id="invoiceAddPayment">Add</button>
        <select id="invoiceTerminalOptions" hidden><option value="">Select</option><option value="11" data-branches="1">First</option><option value="22" data-branches="2">Second</option></select>
        <div id="invoicePaymentRows"></div><p id="invoicePaymentError" hidden></p>
        <span data-payable-total></span><span data-payment-paid></span><span data-payment-remaining></span><span data-payment-count></span></form>""")
        page.add_script_tag(content=(ROOT / "public/assets/invoice-payments.js").read_text(encoding="utf-8"))
        page.evaluate("document.dispatchEvent(new Event('DOMContentLoaded'))")
        page.locator('[data-payment-mode="pos"]').click()
        terminal=page.locator('select[name$="[terminal]"]')
        terminal.select_option('11')
        amount_before=page.locator('input[name$="[amount]"]').input_value()
        page.locator('#invoiceBranch').select_option('2')
        assert terminal.input_value()=="", "Changing branch retained invalid terminal"
        assert terminal.locator('option[value="11"]').evaluate("el => el.disabled"), "Foreign branch terminal remained selectable"
        assert page.locator('input[name$="[amount]"]').input_value()==amount_before, "Changing branch lost draft payment amount"
        terminal.select_option('22')
        assert terminal.input_value()=="22"
        print("PASS: branch change clears invalid POS selection and preserves draft payment amount")
        assert not errors, "Browser JavaScript errors: " + "; ".join(errors)
        browser.close()
    print("PASS: Chromium invoice amounts, invalid input, busy-state recovery, responsive forms, modal, Jalali date, print widths and pagination")


if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print(f"Browser UI/print audit failed: {exc}", file=sys.stderr)
        raise
