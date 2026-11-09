'use strict';
// App-owned messages only. No replacement of the browser's permission/file dialogs.
(()=>{
 const queue=[];let active=null,overlay=null,card=null,title=null,message=null,icon=null,cancel=null,accept=null,close=null,oldOverflow='',background=[];
 let pending=new WeakSet(),permitted=new WeakSet(),submitted=new WeakSet();
 function build(){
  if(overlay)return;
  overlay=document.createElement('div');overlay.className='civic-popup-overlay';overlay.hidden=true;
  card=document.createElement('section');card.className='civic-popup-card';card.tabIndex=-1;card.setAttribute('aria-modal','true');card.setAttribute('aria-labelledby','civic-popup-title');card.setAttribute('aria-describedby','civic-popup-message');
  const brand=document.createElement('span');brand.className='civic-popup-brand';brand.textContent='CivicCare';
  close=document.createElement('button');close.type='button';close.className='civic-popup-close';close.textContent='×';close.setAttribute('aria-label','Close message');
  icon=document.createElement('span');icon.className='civic-popup-icon';icon.setAttribute('aria-hidden','true');
  title=document.createElement('h2');title.id='civic-popup-title';message=document.createElement('p');message.id='civic-popup-message';
  const buttons=document.createElement('div');buttons.className='civic-popup-actions';cancel=document.createElement('button');cancel.type='button';cancel.className='button secondary';accept=document.createElement('button');accept.type='button';accept.className='button';buttons.append(cancel,accept);card.append(brand,close,icon,title,message,buttons);overlay.append(card);document.body.append(overlay);
  cancel.addEventListener('click',()=>finish(false));close.addEventListener('click',()=>finish(active?.kind==='alert'));accept.addEventListener('click',()=>finish(true));overlay.addEventListener('click',event=>{if(event.target===overlay)finish(active?.kind==='alert');});
  document.addEventListener('keydown',event=>{
   if(!active)return;
   if(event.key==='Escape'){event.preventDefault();event.stopPropagation();finish(active.kind==='alert');return;}
   if(event.key!=='Tab')return;const options=[close,...(cancel.hidden?[]:[cancel]),accept];const index=options.indexOf(document.activeElement);
   if(event.shiftKey&&(index<=0)){event.preventDefault();options[options.length-1].focus();}else if(!event.shiftKey&&(index<0||index===options.length-1)){event.preventDefault();options[0].focus();}
  },true);
  document.addEventListener('focusin',event=>{if(active&&!card.contains(event.target))(active.kind==='confirm'?cancel:accept).focus();},true);
 }
 function pump(){
  if(active||!queue.length||!document.body)return;build();active=queue.shift();
  if(!active.opener||overlay.contains(active.opener))active.opener=document.activeElement;
  const isConfirm=active.kind==='confirm',tone=active.options.tone||(isConfirm?'primary':'info');overlay.dataset.tone=tone;card.setAttribute('role',isConfirm?'alertdialog':'dialog');
  title.textContent=active.options.title||(isConfirm?'Confirm this action':'CivicCare message');message.textContent=active.message;icon.textContent=tone==='success'?'✓':(tone==='danger'||tone==='error'?'!':(isConfirm?'?':'i'));
  cancel.hidden=!isConfirm;cancel.textContent=active.options.cancelLabel||'Cancel';accept.textContent=active.options.confirmLabel||(isConfirm?'Confirm':'OK');accept.disabled=false;
  oldOverflow=document.body.style.overflow;document.body.style.overflow='hidden';background=[];Array.from(document.body.children).forEach(element=>{if(element!==overlay){background.push([element,Boolean(element.inert)]);element.inert=true;}});
  overlay.hidden=false;(isConfirm?cancel:accept).focus();
 }
 function finish(answer){
  if(!active)return;const item=active;active=null;overlay.hidden=true;document.body.style.overflow=oldOverflow;background.forEach(([element,value])=>element.inert=value);background=[];
  if(item.opener?.isConnected!==false&&typeof item.opener?.focus==='function')item.opener.focus();item.resolve(Boolean(answer));queueMicrotask(pump);
 }
 function open(kind,text,options={}){return new Promise(resolve=>{queue.push({kind,message:String(text??''),options,resolve,opener:active?.opener||document.activeElement});pump();});}
 const confirmAction=(text,options)=>open('confirm',text,options),alertMessage=(text,options)=>open('alert',text,options);
 globalThis.CivicDialog={confirm:confirmAction,alert:alertMessage};
 function actionOptions(form,submitter){
  const action=form.querySelector('[name="action"]')?.value||'',text=(submitter?.textContent||'').trim();
  const known={assign_officer:['Save officer assignment?','Save assignment'],team_assign:['Assign maintenance team?','Assign team'],generate_recovery:['Generate new recovery codes?','Generate codes'],geo_map_account:['Save account responsibility?','Save scope'],geo_classify:['Route this report?','Route report'],geo_relocate:['Correct report routing?','Save routing'],merge:['Link duplicate reports?','Link reports'],restore_backup:['Restore this backup?','Restore backup'],delete_issue:['Delete this report?','Delete report'],toggle_user:['Change account access?',text.toLowerCase().includes('disable')?'Disable account':'Enable account'],category_access:['Change category availability?',text.toLowerCase().includes('archive')?'Archive category':'Restore category'],logout:['Logout of CivicCare?','Logout']};
  const labels=known[action]||['Confirm this action',text||'Confirm'];const danger=['delete_issue','restore_backup','merge'].includes(action)||(action==='toggle_user'&&/disable/i.test(text))||(action==='category_access'&&/archive/i.test(text));
  return {title:form.dataset.confirmTitle||labels[0],confirmLabel:form.dataset.confirmLabel||labels[1],tone:form.dataset.confirmTone||(danger?'danger':'primary')};
 }
 document.addEventListener('submit',event=>{
  const form=event.target;if(!form?.matches?.('form'))return;
  if(permitted.has(form)){permitted.delete(form);submitted.add(form);queueMicrotask(()=>{if(event.defaultPrevented)submitted.delete(form);});return;}
  const text=form.dataset.confirm;if(!text)return;
  event.preventDefault();event.stopImmediatePropagation();if(pending.has(form)||submitted.has(form))return;
  pending.add(form);const submitter=event.submitter,action=form.querySelector('[name="action"]')?.value||'',target=form.action;
  confirmAction(text,actionOptions(form,submitter)).then(ok=>{
   pending.delete(form);if(!ok||form.isConnected===false)return;
   if(typeof form.reportValidity==='function'&&!form.reportValidity())return;
   // If action semantics changed while awaiting confirmation, ask again.
   if(form.dataset.confirm!==text||form.action!==target||(form.querySelector('[name="action"]')?.value||'')!==action){if(typeof form.requestSubmit==='function')form.requestSubmit(submitter?.isConnected!==false?submitter:undefined);return;}
   permitted.add(form);
   if(typeof form.requestSubmit==='function'){try{form.requestSubmit(submitter?.isConnected!==false?submitter:undefined);permitted.delete(form);}catch{permitted.delete(form);submitted.delete(form);alertMessage('The form could not be submitted. Check the details and try again.',{title:'Please try again',tone:'error'});}}
   else{
    const again=new Event('submit',{bubbles:true,cancelable:true});if(!form.dispatchEvent(again)){permitted.delete(form);submitted.delete(form);return;}
    let hidden=null;if(submitter?.name){hidden=document.createElement('input');hidden.type='hidden';hidden.name=submitter.name;hidden.value=submitter.value;form.append(hidden);}
    HTMLFormElement.prototype.submit.call(form);if(hidden)hidden.remove();
   }
  });
 },true);
 document.addEventListener('DOMContentLoaded',()=>{
  pump();document.querySelectorAll('[data-civic-flash]').forEach(element=>{if(element.dataset.popupShown==='true')return;element.dataset.popupShown='true';const type=element.dataset.civicFlash||'info';alertMessage(element.textContent.trim(),{title:{success:'Success',error:'Please check this',warning:'Attention needed',info:'CivicCare update'}[type]||'CivicCare update',tone:type});if(type==='success')element.hidden=true;});
 });
 window.addEventListener('pagehide',()=>{if(active)finish(false);while(queue.length)queue.shift().resolve(false);pending=new WeakSet();permitted=new WeakSet();submitted=new WeakSet();});
 window.addEventListener('pageshow',()=>{pending=new WeakSet();permitted=new WeakSet();submitted=new WeakSet();});
})();
