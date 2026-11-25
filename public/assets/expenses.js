(() => {
  'use strict';
  const $ = id => document.getElementById(id), dialog = $('expenseDialog'), form = $('expenseForm');
  if (!dialog || !form) return;
  const sources = JSON.parse($('expenseSources').textContent), fmt = new Intl.NumberFormat('fa-IR');
  const sections = [...form.querySelectorAll('[data-exp-step]')];
  let step = 0, itemIndex = 0, paymentIndex = 0, submitting = false;
  const digits = value => String(value).replace(/[۰-۹]/g, c => String(c.charCodeAt(0) - 1776)).replace(/[٠-٩]/g, c => String(c.charCodeAt(0) - 1632));
  function amount(value) {
    const text = digits(value).trim().replace(/[٬،]/g, ',');
    return /^(?:[0-9]+|[0-9]{1,3}(?:,[0-9]{3})+)$/.test(text) ? BigInt(text.replace(/,/g, '')) : 0n;
  }
  function field(row, name, caption, attrs = {}) {
    const label = document.createElement('label'), input = document.createElement('input');
    label.textContent = caption; input.name = name;
    for (const [key, value] of Object.entries(attrs)) input.setAttribute(key, value);
    label.append(input); row.append(label); return input;
  }
  function select(row, name, caption, options) {
    const label = document.createElement('label'), input = document.createElement('select');
    label.textContent = caption; input.name = name;
    for (const [value, text] of options) input.add(new Option(text, value));
    label.append(input); row.append(label); return input;
  }
  function remove(row) {
    const button = document.createElement('button'); button.type = 'button'; button.textContent = 'حذف';
    button.addEventListener('click', () => { row.remove(); calculate(); }); row.append(button);
  }
  function item(seed = null) {
    const row = document.createElement('div'); row.className = 'form-grid'; row.dataset.item = '1';
    const name = 'items[' + itemIndex++ + ']';
    field(row, name + '[title]', 'نام کالا یا خدمت', {required: '', maxlength: '180', value: seed?.title || ''});
    field(row, name + '[description]', 'شرح', {maxlength: '500', value: seed?.description || ''});
    field(row, name + '[quantity]', 'تعداد', {required: '', inputmode: 'numeric', value: seed?.quantity || '۱', 'data-qty': ''});
    field(row, name + '[unit]', 'واحد', {required: '', maxlength: '40', value: seed?.unit || 'عدد'});
    field(row, name + '[price]', 'قیمت واحد (تومان)', {required: '', inputmode: 'numeric', value: seed?.unit_price || '', 'data-price': ''});
    const total = document.createElement('p'); total.dataset.line = ''; row.append(total); remove(row);
    $('expenseItems').append(row); calculate();
  }
  function refresh(row, selected = null) {
    const method = row.querySelector('[data-method]').value, input = row.querySelector('[data-source]');
    const previous = selected ?? input.value, branch = $('expenseBranch').value;
    input.replaceChildren(new Option('انتخاب منبع', ''));
    for (const source of sources[method] || []) {
      if (String(source.branch_id) === branch || (method === 'bank' && source.branch_id === null)) input.add(new Option(source.name || source.title, source.id));
    }
    input.value = [...input.options].some(option => option.value === String(previous)) ? String(previous) : '';
    input.disabled = method === 'personal'; input.required = method !== 'personal'; input.parentElement.hidden = method === 'personal';
  }
  function payment(seed = null) {
    const row = document.createElement('div'); row.className = 'form-grid'; row.dataset.payment = '1';
    const name = 'payments[' + paymentIndex++ + ']';
    const method = select(row, name + '[method]', 'روش پرداخت', [['cash', 'صندوق نقدی'], ['bank', 'حساب بانکی'], ['personal', 'پرداخت شخصی']]);
    method.dataset.method = ''; if (seed?.method) method.value = seed.method;
    const source = select(row, name + '[source]', 'منبع پرداخت', []); source.dataset.source = '';
    field(row, name + '[amount]', 'مبلغ (تومان)', {required: '', inputmode: 'numeric', value: seed?.amount || '', 'data-amount': ''});
    field(row, name + '[tracking]', 'شماره پیگیری', {maxlength: '120', value: seed?.tracking || ''});
    remove(row); $('expensePayments').append(row);
    method.addEventListener('change', () => refresh(row));
    refresh(row, seed?.cash_shift_id ?? seed?.account_id); calculate();
  }
  function calculate() {
    let subtotal = 0n, paid = 0n;
    for (const row of form.querySelectorAll('[data-item]')) {
      const line = amount(row.querySelector('[data-qty]').value) * amount(row.querySelector('[data-price]').value);
      subtotal += line; row.querySelector('[data-line]').textContent = 'جمع: ' + fmt.format(line) + ' تومان';
    }
    const total = subtotal + amount($('expenseExtra')?.value || '0') - amount($('expenseDiscount')?.value || '0');
    for (const input of form.querySelectorAll('[data-amount]')) paid += amount(input.value);
    $('expenseTotal').textContent = fmt.format(total); $('expensePaid').textContent = fmt.format(paid); return [total, paid];
  }
  function show(index) {
    step = index; sections.forEach((section, i) => section.hidden = i !== index);
    $('expenseStep').textContent = ['۱. اطلاعات هزینه', '۲. اقلام خرید', '۳. روش پرداخت', '۴. بررسی نهایی'][index];
    $('expenseBack').hidden = index === 0; $('expenseNext').hidden = index === 3;
    for (const id of ['expenseSave', 'expensePrint', 'expenseDraft']) if ($(id)) $(id).hidden = index !== 3;
    if (index === 3) {
      const [total] = calculate();
      const lines = [form.elements.title.value, 'شعبه: ' + $('expenseBranch').selectedOptions[0].text, 'تاریخ: ' + form.elements.expense_date.value, 'دسته‌بندی: ' + form.elements.category_id.selectedOptions[0].text];
      for (const row of form.querySelectorAll('[data-item]')) lines.push(row.querySelector('input').value + ' — ' + row.querySelector('[data-line]').textContent);
      lines.push('هزینه جانبی: ' + fmt.format(amount($('expenseExtra')?.value || '0')), 'تخفیف: ' + fmt.format(amount($('expenseDiscount')?.value || '0')));
      for (const row of form.querySelectorAll('[data-payment]')) {
        const source = row.querySelector('[data-source]');
        lines.push(row.querySelector('[data-method]').selectedOptions[0].text + ' — ' + (source.disabled ? 'ثبت‌کننده سند' : source.selectedOptions[0]?.text || '') + ' — ' + fmt.format(amount(row.querySelector('[data-amount]').value)) + ' تومان');
      }
      lines.push('جمع کل: ' + fmt.format(total) + ' تومان'); $('expensePreview').textContent = lines.join('\n');
    }
  }
  function validate() {
    for (const input of sections[step].querySelectorAll('input,select,textarea')) if (!input.reportValidity()) return false;
    const [total, paid] = calculate();
    if (step === 0 && form.elements.receipt?.files[0]?.size > 5242880) { alert('حجم پیوست نباید بیشتر از ۵ مگابایت باشد.'); return false; }
    if (step === 1 && (total <= 0n || !form.querySelector('[data-item]') || [...form.querySelectorAll('[data-item]')].some(row => !amount(row.querySelector('[data-qty]').value) || !amount(row.querySelector('[data-price]').value)))) { alert('جمع نهایی، تعداد و قیمت همه اقلام باید مثبت باشد.'); return false; }
    if (step === 2 && (paid !== total || [...form.querySelectorAll('[data-amount]')].some(input => !amount(input.value)))) { alert('جمع پرداخت‌ها باید دقیقاً برابر جمع هزینه باشد.'); return false; }
    return true;
  }
  $('expenseOpen')?.addEventListener('click', () => { dialog.showModal(); show(0); });
  $('expenseCancel').onclick = () => dialog.close(); $('expenseBack').onclick = () => show(step - 1);
  $('expenseNext').onclick = () => {
    if (!validate()) return;
    if (step === 1 && form.querySelectorAll('[data-amount]').length === 1 && !amount(form.querySelector('[data-amount]').value)) form.querySelector('[data-amount]').value = fmt.format(calculate()[0]);
    show(step + 1);
  };
  $('expenseAddItem').onclick = () => item(); $('expenseAddPayment').onclick = () => payment();
  $('expenseBranch').onchange = () => form.querySelectorAll('[data-payment]').forEach(row => refresh(row));
  form.addEventListener('input', calculate);
  form.addEventListener('submit', event => {
    if (step !== 3 || submitting) { event.preventDefault(); return; }
    for (let index = 0; index < 3; index++) { step = index; if (!validate()) { event.preventDefault(); show(index); return; } }
    step = 3; submitting = true;
    for (const button of form.querySelectorAll('button[type="submit"]')) button.setAttribute('aria-disabled', 'true');
  });
  if (sources.draft) {
    sources.draft.items.forEach(item); sources.draft.payments.forEach(payment);
    if (!sources.draft.payments.length) payment(); dialog.showModal();
  } else { item(); payment(); }
  show(0);
})();
