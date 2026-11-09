'use strict';
document.addEventListener('DOMContentLoaded',()=>{
 const form=document.getElementById('logout-form');if(!form)return;
 form.dataset.confirm='You will need to sign in again to access your account.';
 form.dataset.confirmTitle='Logout of CivicCare?';form.dataset.confirmLabel='Logout';
});
