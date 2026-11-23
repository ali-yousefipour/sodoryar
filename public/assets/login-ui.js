// Login-only progressive enhancement; authentication and CAPTCHA stay server-side.
document.addEventListener('DOMContentLoaded',()=>{
  const toggle=document.querySelector('[data-login-password-toggle]');
  const password=document.getElementById('loginPassword');
  if(toggle&&password){
    toggle.addEventListener('click',()=>{
      const visible=password.type==='password';
      password.type=visible?'text':'password';
      toggle.textContent=visible?'پنهان':'نمایش';
      toggle.setAttribute('aria-label',visible?'پنهان کردن رمز عبور':'نمایش رمز عبور');
      toggle.setAttribute('aria-pressed',String(visible));
    });
  }
  const form=document.querySelector('.login-form');
  if(form){
    form.addEventListener('submit',()=>{
      if(!form.checkValidity())return;
      const submit=form.querySelector('.login-submit');
      if(!submit||submit.disabled)return;
      submit.disabled=true;
      submit.setAttribute('aria-busy','true');
      const label=submit.querySelector('span');
      if(label)label.textContent='در حال بررسی اطلاعات…';
    });
  }
});
