"""Disposable expense wizard browser test. Uses production PHP renderer and assets, no database."""
import os
import shutil
import subprocess
from pathlib import Path
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[1]
php = os.environ.get("SODORYAR_PHP", "php")
markup = subprocess.check_output([php, str(ROOT / "tests/expense-ui-contract.php"), "--render"], text=True, encoding="utf-8")
css = "\n".join((ROOT / "public/assets" / name).read_text(encoding="utf-8") for name in ["app.css", "expenses.css"])
js = "\n".join((ROOT / "public/assets" / name).read_text(encoding="utf-8") for name in ["app.js", "expenses.js"])
html = '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><style>' + css + '</style><body><button id="expenseWizardOpen">ثبت هزینه</button>' + markup + '<script>' + js + '</script></body></html>'
with sync_playwright() as p:
    options = {"headless": True}
    chrome = os.environ.get("SODORYAR_CHROME") or next((path for name in ("google-chrome-stable","google-chrome","chromium","chromium-browser") if (path:=shutil.which(name))),None)
    if chrome:
        options["executable_path"] = chrome
    browser = p.chromium.launch(**options)
    page = browser.new_page(viewport={"width": 1366, "height": 768})
    errors = []
    page.on("pageerror", lambda error: errors.append(str(error)))
    page.set_content(html)
    page.locator("#expenseWizardOpen").click()
    page.locator('[name="branch_id"]').select_option("1")
    page.locator('[name="category_id"]').select_option("1")
    page.locator('[name="title"]').fill("خرید ملزومات شرکت")
    page.locator('[data-expense-next]').click()
    page.locator('[name="items[0][title]"]').fill("ملزومات")
    page.locator('[name="items[0][quantity]"]').fill("۱.۱۲۵")
    page.locator('[name="items[0][unit_price]"]').fill("۲۰۰")
    page.locator('[data-expense-add-item]').click()
    page.locator('[name="items[1][title]"]').fill("کاغذ")
    page.locator('[name="items[1][quantity]"]').fill("۰.۵")
    page.locator('[name="items[1][unit_price]"]').fill("۱۰۱")
    # 1.125*200 =225; .5*101 rounded half up =51; +100-25 =351.
    page.locator('[name="extra_amount"]').fill("۱۰۰")
    page.locator('[name="discount_amount"]').fill("۲۵")
    assert page.locator('[data-expense-total]').inner_text() == "۳۵۱"
    page.locator('[name="items[1][unit_price]"]').fill("1,5")
    page.locator('[data-expense-next]').click()
    assert page.locator('[data-expense-stage="1"]').is_visible()
    assert page.locator('[data-expense-error]').is_visible()
    page.locator('[name="items[1][unit_price]"]').fill("۱۰۱")
    page.locator('[data-expense-next]').click()
    page.locator('[data-expense-add-payment]').click()
    page.locator('[name="payments[0][cash_shift_id]"]').select_option("11")
    page.locator('[name="payments[0][amount]"]').fill("۱۰۰")
    page.locator('[data-expense-prev]').click()
    page.locator('[data-expense-prev]').click()
    page.locator('[name="branch_id"]').select_option("2")
    assert page.locator('[name="payments[0][cash_shift_id]"]').input_value() == ""
    assert page.locator('[name="payments[0][amount]"]').input_value() == "۱۰۰"
    page.locator('[data-expense-next]').click()
    page.locator('[data-expense-next]').click()
    page.locator('[name="payments[0][method]"]').select_option("personal")
    page.locator('[name="payments[0][amount]"]').fill("۳۵۲")
    page.locator('[data-expense-next]').click()
    assert page.locator('[data-expense-stage="2"]').is_visible()
    assert page.locator('[data-expense-error]').is_visible()
    page.locator('[name="payments[0][amount]"]').fill("۱۰۰")
    page.locator('[data-expense-next]').click()
    assert page.locator('[data-expense-stage="3"]').is_visible()
    assert page.locator('[data-expense-remaining]').inner_text() == "۲۵۱"
    assert page.locator('[data-expense-save]').is_visible()
    for width in [375, 768, 1366]:
        page.set_viewport_size({"width": width, "height": 812})
        assert page.locator('#expenseWizard').evaluate('(el)=>el.scrollWidth<=el.clientWidth+2'), f"Wizard overflow at {width}"
        assert page.locator('.expense-wizard-body').evaluate('(el)=>el.scrollWidth<=el.clientWidth+2'), f"Body overflow at {width}"
        if os.environ.get("SODORYAR_CAPTURE"):
            capture=Path(os.environ["SODORYAR_CAPTURE"]);capture.mkdir(parents=True,exist_ok=True)
            page.screenshot(path=str(capture/f"expense-{width}.png"))
    assert not errors, errors
    browser.close()
print("Expense wizard 4 stages, exact sums, source reset, payment validation and responsive checks passed")
