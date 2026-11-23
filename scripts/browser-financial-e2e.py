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
        manager_a.locator("[data-add-invoice-item]").click()
        manager_a.locator('input[name="extra_title[]"]').fill("CI E2E Custom Service")
        manager_a.locator('input[name="extra_price[]"]').fill("۲۵۰۰۰")
        assert ascii_number(manager_a.locator("[data-service-total]").inner_text()) == 70000, (
            "Custom invoice item was not included in the browser total"
        )
        manager_a.locator('[data-payment="pos"]').click()
        manager_a.locator("#posRef").fill("CI-INVOICE-POS-2026")
        manager_a.locator(".invoice-submit").click()
        manager_a.wait_for_url(lambda url: "page=receipt" in url, timeout=15000)
        expect(manager_a.locator(".receipt")).to_be_visible()
        expect(manager_a.locator(".receipt")).to_contain_text("۷۰٬۰۰۰")
        expect(manager_a.locator(".receipt")).to_contain_text("CI-INVOICE-POS-2026")
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
        manager_a.wait_for_load_state("domcontentloaded")
        if manager_a.get_by_text("CI-E2E-BILL").count() == 0:
            manager_a.screenshot(path=str(OUTPUT / "bill-error.png"), full_page=True)
            raise AssertionError(
                "POS bill not visible after save; URL=" + manager_a.url
                + "; body=" + manager_a.locator("body").inner_text()[:2300]
            )
        expect(manager_a.get_by_role("cell", name="CI-E2E-BILL", exact=True)).to_be_visible()

        manager_a.goto(f"{ORIGIN}/?page=reports")
        expect(manager_a.locator(".content")).to_be_visible()
        assert manager_a.get_by_text("CI-B-0001").count() == 0, "Foreign company invoice leaked into reports"

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
        expect(manager_b.get_by_text("CI E2E Service B")).to_be_visible()
        assert manager_b.get_by_text("CI E2E Service A").count() == 0, "Company A service leaked into B"
        manager_b.goto(f"{ORIGIN}/?page=receipt&id={invoice_id.group(1)}")
        expect(manager_b.get_by_text("فاکتور پیدا نشد.")).to_be_visible()
        assert manager_b.locator(".receipt").count() == 0, "Foreign invoice receipt accessible by ID"

        # Authenticated navigation audit across all company-management modules.
        # Every route must render without PHP errors or viewport-wide overflow.
        for route in (
            "dashboard", "users", "roles", "customers", "services", "invoices",
            "reports", "bills", "pos", "commissions", "templates", "settings",
            "activity", "profile",
        ):
            response = manager_a.goto(f"{ORIGIN}/?page={route}", wait_until="domcontentloaded")
            status = response.status if response else 0
            body = manager_a.locator("body").inner_text()
            if status != 200 or "Fatal error:" in body or "Uncaught " in body:
                manager_a.screenshot(path=str(OUTPUT / f"route-{route}-failure.png"), full_page=True)
                raise AssertionError(f"{route} HTTP {status}, body={body[:1200]}")
            width = manager_a.evaluate("document.documentElement.scrollWidth")
            viewport = manager_a.evaluate("window.innerWidth")
            if width > viewport + 2:
                manager_a.screenshot(path=str(OUTPUT / f"route-{route}-overflow.png"), full_page=True)
                raise AssertionError(f"{route} overflows 390px viewport ({width}px > {viewport}px)")
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
        manager_a.screenshot(path=str(OUTPUT / "settings-mobile.png"), full_page=True)
        assert manager_a.locator("#primaryNav .nav-link.active").count() == 1, (
            "Categorized sidebar lost the active route"
        )
        # Verify the real authenticated workflow at compact mobile, tablet and
        # desktop widths, not just the isolated component fixture.
        for width in (320, 768, 1440):
            manager_a.set_viewport_size({"width": width, "height": 900})
            for route in ("dashboard", "invoices", "bills", "templates", "reports", "settings"):
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
