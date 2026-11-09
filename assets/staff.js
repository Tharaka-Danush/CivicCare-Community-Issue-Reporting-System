'use strict';
document.addEventListener('DOMContentLoaded',()=>{
 const area=document.querySelector('#assignment-area'),officer=document.querySelector('#assignment-officer'),hint=document.querySelector('#assignment-hint');
 if(!area||!officer)return;
 let current=null,generation=0;
 const form=area.closest('form'),submit=form.querySelector('button[type="submit"],button.button');
 area.addEventListener('change',async()=>{
  if(current)current.abort();current=new AbortController();const request=current,n=++generation,previous=officer.value;
  officer.disabled=true;if(submit)submit.disabled=true;hint.textContent='Checking service coverage and open workload…';
  const timeout=setTimeout(()=>request.abort(),12000);
  try{
   const response=await fetch('index.php?'+new URLSearchParams({page:'officer_options',area:area.value}),{signal:request.signal});
   if(!response.ok)throw Error('Officers unavailable');const rows=await response.json();if(!Array.isArray(rows))throw Error();if(n!==generation)return;
   officer.replaceChildren();const empty=document.createElement('option');empty.value='';empty.textContent='Unassigned';officer.append(empty);
   rows.forEach(row=>{const option=document.createElement('option');option.value=String(row.id);option.textContent=row.name+' · '+row.workload+' open · '+(Number(row.area_match)?'area match':Number(row.area_count)?'coverage set':'unrestricted');officer.append(option);});
   if(rows.some(row=>String(row.id)===previous))officer.value=previous;
   else officer.value='';
   hint.textContent=rows.length?'Matching officers are listed first, then the lowest open workload. Select an officer before assigning.':'No eligible officers. Configure coverage under Areas & workload.';
  }catch(error){if(n===generation)hint.textContent='Officer suggestions could not load. Reload the report before changing its area; the server checks coverage when saving.';}
  finally{clearTimeout(timeout);if(n===generation){officer.disabled=false;if(submit)submit.disabled=false;}}
 });
});
