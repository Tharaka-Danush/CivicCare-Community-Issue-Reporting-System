'use strict';
document.addEventListener('DOMContentLoaded',()=>{
 document.querySelectorAll('[data-password-toggle]').forEach(button=>{
  const input=document.getElementById(button.dataset.passwordToggle);if(!input)return;
  button.addEventListener('click',()=>{const show=input.type==='password';input.type=show?'text':'password';button.textContent=show?'Hide':'Show';button.setAttribute('aria-pressed',String(show));});
 });
 document.querySelectorAll('[data-password-confirm]').forEach(form=>{
  const password=form.querySelector('[name="password"]'),confirm=form.querySelector('[name="password_confirm"]');if(!password||!confirm)return;
  const check=()=>confirm.setCustomValidity(confirm.value!==password.value?'New passwords do not match.':'');password.addEventListener('input',check);confirm.addEventListener('input',check);
 });
 document.querySelectorAll('[data-coverage-form]').forEach(form=>{
  const search=form.querySelector('[data-area-search]');search?.addEventListener('input',()=>{const query=search.value.trim().toLocaleLowerCase();form.querySelectorAll('[data-area-name]').forEach(row=>{row.hidden=!row.dataset.areaName.includes(query);});});
  const update=()=>{const all=form.querySelector('[name="coverage_scope"]:checked')?.value==='all';form.querySelectorAll('[name="area_ids[]"]').forEach(box=>{box.disabled=all;});};form.querySelectorAll('[name="coverage_scope"]').forEach(radio=>radio.addEventListener('change',update));update();
 });
 document.querySelectorAll('.admin-add-account').forEach(details=>{if(location.hash==='#add-account'&&details.closest('#add-account'))details.open=true;});
 document.querySelectorAll('a[href="#add-account"]').forEach(link=>link.addEventListener('click',()=>{const details=document.querySelector('#add-account .admin-add-account');if(details)details.open=true;}));
});
