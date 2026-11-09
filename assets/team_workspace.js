'use strict';
document.addEventListener('DOMContentLoaded',()=>{
 document.querySelectorAll('[data-team-copy]').forEach(button=>button.addEventListener('click',async()=>{
  const source=document.getElementById(button.dataset.teamCopy),status=button.parentElement.querySelector('[data-team-copy-status]');if(!source)return;
  try{if(!navigator.clipboard?.writeText)throw Error();await navigator.clipboard.writeText(source.value);if(status)status.textContent='Copied. Share these details privately with the System Admin or your District Officer.';}catch{source.focus();source.select();if(status)status.textContent='Select and copy the text above using your browser.';}
 }));
 const form=document.querySelector('[data-team-update]');if(form){
  const type=form.querySelector('[name="action"]'),submit=document.getElementById('team-submit-update'),help=document.getElementById('team-update-help');
  const update=()=>{const completion=type.value==='team_submit';if(completion){form.dataset.confirm='Submit completion evidence to the District Officer? Further uploads will be locked while it is reviewed.';form.dataset.confirmTitle='Submit completed work?';form.dataset.confirmLabel='Submit completion';}else{delete form.dataset.confirm;delete form.dataset.confirmTitle;delete form.dataset.confirmLabel;}submit.textContent=completion?'Submit completion to District Officer':'Upload progress evidence';help.textContent=completion?'Completion is sent to the District Officer for review, then Province Admin approval. Further uploads are locked while it is reviewed.':'Add evidence while your team continues working.';};type.addEventListener('change',update);update();
  const result=document.getElementById('team-upload-result');
  let busy=false;
  form.addEventListener('submit',async event=>{
   if(event.defaultPrevented)return;
   if(busy){event.preventDefault();return;}
   busy=true;
   submit.disabled=true;
   submit.textContent='Sending update…';
   if(!result||typeof fetch!=='function')return;
   event.preventDefault();
   result.textContent='Uploading evidence…';
   const controller=new AbortController();
   const timer=setTimeout(()=>controller.abort(),90000);
   try{
    const response=await fetch(form.action,{
     method:'POST',body:new FormData(form),credentials:'same-origin',
     headers:{'X-CivicCare-Upload':'team-evidence'},signal:controller.signal
    });
    if(response.redirected||response.status===403){
     result.textContent='Your access may have changed. Open My team’s work in another tab to check your session or assignment. Your note and photo are still here.';
     return;
    }
    const body=await response.json();
    if(!response.ok||body.saved!==true){
     result.textContent=body.error||'The server could not confirm the upload. Check the work history before trying again.';
     return;
    }
    result.textContent='Evidence saved. Refreshing work details…';
    window.location.reload();
   }catch{
    result.textContent='The connection did not confirm the upload. Your note and photo are still here. Check the work history in another tab, then retry if the update is missing.';
   }finally{
    clearTimeout(timer);
    busy=false;
    submit.disabled=false;
    update();
   }
  });
  window.addEventListener('pageshow',()=>{busy=false;submit.disabled=false;update();});
 }
 const photo=document.getElementById('team-work-photo');if(photo){
  const preview=document.getElementById('team-photo-preview'),status=document.getElementById('team-photo-status'),remove=document.getElementById('team-clear-photo');let url=null,version=0;
  const cleanup=()=>{if(url)URL.revokeObjectURL(url);url=null;preview.hidden=true;preview.removeAttribute('src');remove.hidden=true;};
  const reject=message=>{cleanup();photo.value='';photo.setCustomValidity('');status.textContent=message;status.classList.add('team-photo-error');};
  const check=()=>{const mine=++version;cleanup();photo.setCustomValidity('');status.classList.remove('team-photo-error');const file=photo.files[0];if(!file){status.textContent='Choose a photo showing the current work.';return;}if(!['image/jpeg','image/png','image/webp'].includes(file.type)){reject('Choose a JPG, PNG or WebP photo.');return;}if(file.size>Number(photo.dataset.maxBytes)){reject('The photo is larger than 5 MB. Choose a smaller image.');return;}url=URL.createObjectURL(file);const selected=url,image=new Image();photo.setCustomValidity('Wait while the selected photo is checked.');status.textContent='Checking photo…';image.onload=()=>{if(mine!==version)return;if(image.naturalWidth*image.naturalHeight>24000000){reject('Choose a photo below 24 megapixels.');return;}photo.setCustomValidity('');preview.src=selected;preview.hidden=false;remove.hidden=false;status.textContent=file.name+' · '+(file.size/1048576).toFixed(2)+' MB · '+image.naturalWidth+' × '+image.naturalHeight;};image.onerror=()=>{if(mine===version)reject('This image could not be opened. Choose another photo.');};image.src=selected;};
  photo.addEventListener('change',check);remove.addEventListener('click',()=>{version++;photo.value='';photo.setCustomValidity('');cleanup();status.textContent='Selected photo removed. Choose another photo before uploading.';});window.addEventListener('pagehide',()=>{version++;cleanup();});window.addEventListener('pageshow',event=>{if(event.persisted&&photo.files.length)check();});
 }
 const start=document.querySelector('[data-team-start]');if(start){let busy=false;const button=start.querySelector('button[type="submit"],button:not([type])');start.addEventListener('submit',event=>{if(event.defaultPrevented)return;if(busy){event.preventDefault();return;}busy=true;if(button){button.disabled=true;button.textContent='Saving…';}});window.addEventListener('pageshow',()=>{busy=false;if(button){button.disabled=false;button.textContent=start.dataset.resume==='true'?'Resume work':'Start work';}});}
});
