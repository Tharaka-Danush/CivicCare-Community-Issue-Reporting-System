'use strict';
document.addEventListener('DOMContentLoaded',()=>{
 const $=s=>document.querySelector(s);
 const clear=$('#clear-report-draft');if(clear){try{localStorage.removeItem(clear.dataset.key);}catch{}}
 const form=$('#citizen-report-form');
 if(form){
  const names=['category_id','area_id','province_id','district_id','town_id','title','description','address','latitude','longitude'];
  const key=form.dataset.draftKey,status=$('#draft-status');let timer,restoring=false,submitting=false,saveEnabled=true;
  const fields=Object.fromEntries(names.map(n=>[n,form.querySelector('[name="'+n+'"]')]));
  function meaningful(values){return ['title','description','address'].some(n=>typeof values[n]==='string'&&values[n].trim()!=='') || (typeof values.latitude==='string'&&typeof values.longitude==='string'&&values.latitude.trim()!==''&&values.longitude.trim()!==''&&Number.isFinite(Number(values.latitude))&&Number.isFinite(Number(values.longitude))&&Math.abs(Number(values.latitude))<=90&&Math.abs(Number(values.longitude))<=180);}
  function read(){try{const data=JSON.parse(localStorage.getItem(key));if(!data||data.version!==1||typeof data.values!=='object'||data.values===null||Array.isArray(data.values))return null;if(!meaningful(data.values)){localStorage.removeItem(key);return null;}return data;}catch{return null;}}
  function save(){
   if(restoring||submitting||!saveEnabled)return;
   try{const values=Object.fromEntries(names.map(n=>[n,fields[n].value]));if(!meaningful(values)){localStorage.removeItem(key);$('#restore-draft').hidden=true;$('#discard-draft').hidden=true;status.textContent='Add issue details or a location before saving a draft.';return;}localStorage.setItem(key,JSON.stringify({version:1,values,at:Date.now()}));status.textContent='Saved on this browser · '+new Date().toLocaleTimeString()+'. Photos are not saved.';$('#restore-draft').hidden=false;$('#discard-draft').hidden=false;}
   catch{status.textContent='Draft could not be saved on this browser. Keep this page open until you submit.';}
  }
  function restore(){
   const draft=read();if(!draft){status.textContent='No saved draft is available.';return;}
   restoring=true;
   names.forEach(n=>{if(typeof draft.values[n]==='string'){fields[n].value=draft.values[n];if(['province_id','district_id'].includes(n))globalThis.CivicGeography?.refresh();}});
   fields.latitude.dispatchEvent(new Event('change',{bubbles:true}));
   globalThis.CivicGeography?.refresh();restoring=false;globalThis.CivicReport?.restored?.();status.textContent='Draft restored'+(Number.isFinite(draft.at)?' · saved '+new Date(draft.at).toLocaleString():'')+'. Check the details and select your photo again.';
  }
  names.forEach(n=>['input','change'].forEach(event=>fields[n].addEventListener(event,()=>{if(restoring)return;saveEnabled=true;clearTimeout(timer);timer=setTimeout(save,500);})));
  globalThis.CivicDraft={discard:()=>{clearTimeout(timer);saveEnabled=false;localStorage.removeItem(key);$('#restore-draft').hidden=true;$('#discard-draft').hidden=true;status.textContent='Saved draft discarded. Further edits will create a new draft.';},submitted:()=>{submitting=true;},queue:()=>{saveEnabled=true;clearTimeout(timer);timer=setTimeout(save,500);}};
  // Map clicks update coordinates programmatically; save their current values
  // on page exit too, without trying to store browser-protected photo files.
  window.addEventListener('pagehide',()=>{clearTimeout(timer);if(!submitting)save();});
  form.addEventListener('submit',()=>{clearTimeout(timer);save();submitting=form.dataset.wizard!=='true';});
  $('#save-draft').addEventListener('click',()=>{saveEnabled=true;save();});
  $('#restore-draft').addEventListener('click',restore);
  $('#discard-draft').addEventListener('click',async()=>{const button=$('#discard-draft');if(button.disabled)return;button.disabled=true;let approved;try{approved=await CivicDialog.confirm('Discard the saved draft on this browser? Your current form stays filled.',{title:'Discard saved draft?',confirmLabel:'Discard draft',tone:'danger'});}finally{button.disabled=false;if(button.isConnected!==false)button.focus();}if(!approved)return;clearTimeout(timer);saveEnabled=false;try{localStorage.removeItem(key);status.textContent='Saved draft discarded. Further edits will create a new draft.';$('#restore-draft').hidden=true;$('#discard-draft').hidden=true;}catch{status.textContent='This browser could not remove the saved draft.';}});
  if(read()){$('#restore-draft').hidden=false;$('#discard-draft').hidden=false;status.textContent='A saved draft is available'+(Number.isFinite(read().at)?' · '+new Date(read().at).toLocaleString():'')+'. Restore it to replace the currently displayed details.';}
 }
 const photo=$('#citizen-photo');
 if(photo){
  const preview=$('#report-preview'),status=$('#photo-status'),remove=$('#clear-photo');let objectUrl=null,selection=0;
  function cleanup(){if(objectUrl){URL.revokeObjectURL(objectUrl);objectUrl=null;}preview.hidden=true;preview.removeAttribute('src');remove.hidden=true;}
  function fail(message){cleanup();photo.value='';photo.setCustomValidity('');status.textContent=message;status.classList.add('photo-error');}
  photo.addEventListener('change',()=>{
   const current=++selection;cleanup();photo.setCustomValidity('');status.classList.remove('photo-error');const file=photo.files[0];if(!file){status.textContent='No replacement selected. Choose a photo if required.';return;}
   if(!['image/jpeg','image/png','image/webp'].includes(file.type)){fail('Choose a JPG, PNG or WebP photo.');return;}
   if(file.size>Number(photo.dataset.maxBytes)){fail('This photo is larger than 5 MB. Choose a smaller image.');return;}
   objectUrl=URL.createObjectURL(file);const url=objectUrl,image=new Image();photo.setCustomValidity('Please wait while the photo is checked.');status.textContent='Checking photo…';
   image.onload=()=>{if(current!==selection)return;if(image.naturalWidth*image.naturalHeight>24000000){fail('Choose a photo below 24 megapixels.');return;}photo.setCustomValidity('');preview.src=url;preview.hidden=false;remove.hidden=false;status.textContent=file.name+' · '+(file.size/1024/1024).toFixed(2)+' MB · '+image.naturalWidth+' × '+image.naturalHeight;};
   image.onerror=()=>{if(current===selection)fail('This image cannot be opened. Try a different JPG or PNG photo.');};image.src=url;
  });
  $('#change-photo').addEventListener('click',()=>photo.click());
  remove.addEventListener('click',()=>{selection++;photo.value='';photo.setCustomValidity('');cleanup();status.textContent='Selected photo removed. An existing report photo is kept when editing; a new report needs a photo.';});
  window.addEventListener('pagehide',cleanup);
 }
 $('#download-recovery')?.addEventListener('click',()=>{
  const blob=new Blob(['CivicCare single-use password recovery codes\nKeep these private.\n\n'+$('#recovery-code-text').textContent+'\n'],{type:'text/plain'});
  const url=URL.createObjectURL(blob),a=document.createElement('a');a.href=url;a.download='CivicCare_Recovery_Codes.txt';a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);
 });
});
