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
        page.set_content(fixture(), wait_until="load")
        page.add_script_tag(content=(ROOT / "public/assets/app.js").read_text(encoding="utf-8"))
        page.evaluate("document.dispatchEvent(new Event('DOMContentLoaded'))")
        page.locator("[data-service-option] input").check()
        total = page.locator("[data-service-total]")
        assert number(total.inner_text()) == 12345, "Fixed service amount missing from UI total"
        page.locator("[data-add-invoice-item]").click()
        page.locator('input[name="extra_title[]"]').fill("خدمت موردی")
        page.locator('input[name="extra_price[]"]').fill("۲۵۰۰۰")
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
        page.locator("#smokeDialog [data-dialog-close]").click()
        assert not page.locator("#smokeDialog").evaluate("(element) => element.open"), "Modal failed to close"
        page.locator("[data-jalali-open]").click()
        assert page.locator(".jalali-dialog").evaluate("(element) => element.open"), "Jalali calendar did not open"
        page.locator(".jalali-dialog .calendar-day").first.click()
        assert re.fullmatch(r"\d{4}-\d{2}-\d{2}", page.locator('[name="invoice_date"]').input_value()), (
            "Calendar did not update ISO form value"
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
        assert not errors, "Browser JavaScript errors: " + "; ".join(errors)
        browser.close()
    print("PASS: real Chromium invoice totals, responsive forms, modal, Jalali calendar, print widths and pagination")


if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print(f"Browser UI/print audit failed: {exc}", file=sys.stderr)
        raise
