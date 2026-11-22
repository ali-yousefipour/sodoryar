#!/usr/bin/env python3
"""Isolated browser E2E of real PHP pages and MariaDB-backed financial workflows.

Only uses localhost disposable test data; never touches live company accounts.
"""
from __future__ import annotations

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
    page.goto(f"{ORIGIN}/?page=login", wait_until="domcontentloaded")
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
        if manager_a.get_by_text("CI E2E Service A").count() == 0:
            manager_a.screenshot(path=str(OUTPUT / "service-error.png"), full_page=True)
            raise AssertionError(
                f"Company A service missing: HTTP {service_response.status if service_response else 'none'}; "
                f"URL={manager_a.url}; page={manager_a.locator('body').inner_text()[:1800]}"
            )
        expect(manager_a.get_by_text("CI E2E Service A")).to_be_visible()
        assert manager_a.get_by_text("CI E2E Service B").count() == 0, "Foreign company service leaked"

        manager_a.goto(f"{ORIGIN}/?page=invoices")
        expect(manager_a.locator("#invoiceCreateForm")).to_be_visible()
        manager_a.screenshot(path=str(OUTPUT / "invoices-mobile.png"), full_page=True)
        viewport_width = manager_a.evaluate("window.innerWidth")
        page_width = manager_a.evaluate("document.documentElement.scrollWidth")
        assert page_width <= viewport_width + 2, (
            f"Invoice mobile page overflows horizontally: {page_width}px > {viewport_width}px"
        )
        manager_a.locator("#invoiceDriverNational").fill("1234567890")
        expect(manager_a.locator("#invoiceDriverStatus")).to_contain_text("CI Driver", timeout=12000)
        manager_a.locator('[data-service-option][data-service-title="CI E2E Service A"] input').check()
        selected_total = manager_a.locator("[data-service-total]").inner_text()
        assert ascii_number(selected_total) == 45000, "Selected service price did not update"
        manager_a.locator(".invoice-submit").click()
        manager_a.wait_for_url(lambda url: "page=receipt" in url, timeout=15000)
        expect(manager_a.locator(".receipt")).to_be_visible()
        expect(manager_a.locator(".receipt")).to_contain_text("۴۵٬۰۰۰")
        invoice_id = re.search(r"[?&]id=(\d+)", manager_a.url)
        assert invoice_id, "New invoice did not redirect to its receipt"
        receipt_pdf = manager_a.pdf(prefer_css_page_size=True, print_background=True)
        media = re.findall(rb"/MediaBox\s*\[\s*0\s+0\s+([0-9.]+)", receipt_pdf)
        assert media and 585 < float(media[0]) < 605, "Default invoice receipt did not print on A4"
        (OUTPUT / "invoice-a4.pdf").write_bytes(receipt_pdf)

        manager_a.goto(f"{ORIGIN}/?page=bills")
        form = manager_a.locator("form[data-once-submit]").first
        form.locator('[name="bill_id"]').fill("CI-E2E-BILL")
        form.locator('[name="payment_id"]').fill("CI-E2E-PAYMENT")
        form.locator('[name="amount"]').fill("۲۵۰۰۰")
        form.locator('[name="paid_method"]').select_option("pos")
        expect(form.locator('[name="ref_code"]')).to_have_attribute("required", "")
        form.locator('[name="ref_code"]').fill("CI-E2E-REF")
        form.locator("button").last.click()
        manager_a.wait_for_url(lambda url: "page=bills" in url, timeout=15000)
        expect(manager_a.get_by_text("CI-E2E-BILL")).to_be_visible()

        manager_a.goto(f"{ORIGIN}/?page=reports")
        expect(manager_a.locator(".content")).to_be_visible()
        assert manager_a.get_by_text("CI-B-0001").count() == 0, "Foreign company invoice leaked into reports"

        context_b, manager_b = log_in(browser, "ci_manager_b")
        manager_b.goto(f"{ORIGIN}/?page=services")
        expect(manager_b.get_by_text("CI E2E Service B")).to_be_visible()
        assert manager_b.get_by_text("CI E2E Service A").count() == 0, "Company A service leaked into B"
        manager_b.goto(f"{ORIGIN}/?page=receipt&id={invoice_id.group(1)}")
        expect(manager_b.get_by_text("فاکتور پیدا نشد.")).to_be_visible()
        assert manager_b.locator(".receipt").count() == 0, "Foreign invoice receipt accessible by ID"

        context_b.close()
        context_a.close()
        browser.close()
    print("PASS: isolated real-login, mobile invoice, receipt PDF, POS bill, reports and cross-tenant E2E")


if __name__ == "__main__":
    main()
