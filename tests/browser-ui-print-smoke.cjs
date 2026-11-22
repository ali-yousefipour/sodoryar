'use strict';
// Browser-level smoke tests: no credentials, mutations or production financial operations.
const fs = require('node:fs');
const assert = require('node:assert/strict');
const { chromium } = require('playwright');

async function main() {
  const executablePath = process.env.BROWSER_BIN || '/usr/bin/google-chrome';
  const origin = process.env.SODORYAR_TEST_ORIGIN || 'https://taxiranico.ir';
  const browser = await chromium.launch({
    headless: true, executablePath, args: ['--no-sandbox', '--disable-dev-shm-usage']
  });
  try {
    for (const width of [320, 390, 768, 1440]) {
      const page = await browser.newPage({ viewport: { width, height: 900 }, locale: 'fa-IR' });
      const url = new URL('/?page=login', origin).toString();
      const response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 45000 });
      assert.equal(response.status(), 200, 'login HTTP status at ' + width);
      await page.locator('form.login-form').waitFor({ state: 'visible' });
      assert.equal(await page.locator('html').getAttribute('dir'), 'rtl');
      assert.equal(await page.locator('input[name="csrf"]').count(), 1);
      assert.equal(await page.locator('input[name="password"][type="password"]').count(), 1);
      assert.equal(await page.locator('input[name="captcha_answer"]').count(), 1);
      const layout = await page.evaluate(() => ({
        viewport: innerWidth,
        scrollWidth: document.documentElement.scrollWidth,
        formWidth: document.querySelector('form.login-form')?.getBoundingClientRect().width || 0,
        submitVisible: Boolean(document.querySelector('form.login-form button[type="submit"]')?.getBoundingClientRect().width)
      }));
      assert(layout.formWidth > 180 && layout.submitVisible, 'unusable login form at ' + width);
      assert(layout.scrollWidth <= layout.viewport + 3,
        'horizontal overflow on login at ' + width + ': ' + JSON.stringify(layout));
      // Keyboard navigation must reach the username/password fields.
      await page.locator('input[name="username"]').focus();
      assert.equal(await page.evaluate(() => document.activeElement?.getAttribute('name')), 'username');
      await page.screenshot({ path: process.env.RUNNER_TEMP
        ? process.env.RUNNER_TEMP + '/login-' + width + '.png'
        : '/tmp/sodoryar-login-' + width + '.png' });
      await page.close();
    }

    // Synthetic finance form: test localized grouping, rejection of decimal input,
    // busy-state idempotence and recovery on browser-history restore.
    const financialPage = await browser.newPage({ viewport: { width: 390, height: 800 }, locale: 'fa-IR' });
    await financialPage.setContent('<html lang="fa" dir="rtl"><body><form data-once-submit id="moneyForm">' +
      '<label>مبلغ<input data-money-input id="moneyField" required></label>' +
      '<button type="submit">ثبت</button></form></body></html>');
    await financialPage.addScriptTag({ path: 'public/assets/app.js' });
    await financialPage.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));
    const moneyField = financialPage.locator('#moneyField');
    await moneyField.fill('۱.۵');
    assert(await moneyField.evaluate(el => el.validity.customError),
      'fractional finance input must be flagged, not silently changed into a larger integer');
    await moneyField.fill('۱٬۲۳۴');
    assert.equal(await moneyField.inputValue(), '۱٬۲۳۴');
    assert(!(await moneyField.evaluate(el => el.validity.customError)));
    await financialPage.evaluate(() => {
      const form = document.querySelector('#moneyForm');
      form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    });
    assert(await financialPage.locator('#moneyForm button').isDisabled(),
      'financial submit must disable repeated writes');
    await financialPage.evaluate(() => window.dispatchEvent(new Event('pageshow')));
    assert(await financialPage.locator('#moneyForm button').isEnabled(),
      'back-navigation must restore finance submit button');
    await financialPage.close();

    // Deterministic local print fixture; never pull real customer/financial data.
    const css = fs.readFileSync('public/assets/print-engine.css', 'utf8');
    const paper = [
      ['receipt-a4', 595],
      ['receipt-a5', 420],
      ['receipt-thermal receipt-roll-72', 227]
    ];
    for (const [paperClass, expectedWidth] of paper) {
      const page = await browser.newPage({ viewport: { width: 1000, height: 800 } });
      await page.setContent(`<!doctype html><html lang="fa" dir="rtl"><head>
        <style>${css}</style></head><body><div class="app-shell"><div class="main-wrap">
        <main class="content"><div class="receipt card ${paperClass}">
        <h1>فاکتور آزمایشی</h1><p>شماره: ۱۲۳۴۵۶</p>
        <table><thead><tr><th>خدمت</th><th>مبلغ</th></tr></thead>
        <tbody>${Array.from({ length: 12 }, (_, i) =>
          '<tr><td>خدمت آزمایشی شماره ' + i + '</td><td>۱۲۳٬۴۵۶ تومان</td></tr>').join('')}</tbody></table>
        <h2>جمع کل: ۱٬۴۸۱٬۴۷۲ تومان</h2></div></main></div></div>
        </body></html>`, { waitUntil: 'load' });
      const visible = await page.locator('.receipt').evaluate(el => getComputedStyle(el).display !== 'none');
      assert(visible, 'receipt hidden before printing: ' + paperClass);
      const pdf = await page.pdf({ printBackground: true, preferCSSPageSize: true });
      const matches = /\/MediaBox\s*\[\s*0\s+0\s+([\d.]+)\s+([\d.]+)/.exec(pdf.toString('latin1'));
      assert(matches, 'printed PDF missing page dimensions: ' + paperClass);
      const actualWidth = Number(matches[1]);
      assert(Math.abs(actualWidth - expectedWidth) < 8,
        'wrong print width ' + paperClass + ': expected ' + expectedWidth + ' pt, received ' + actualWidth);
      if (process.env.RUNNER_TEMP) {
        fs.writeFileSync(process.env.RUNNER_TEMP + '/print-' + paperClass.split(' ')[0] + '.pdf', pdf);
      }
      await page.close();
    }
    console.log('PASS: four live responsive login viewports, finance form interaction, and A4/A5/80mm print PDF smoke tests.');
  } finally {
    await browser.close();
  }
}
main().catch(error => { console.error(error); process.exitCode = 1; });
