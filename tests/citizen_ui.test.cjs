// Focused DOM/Storage tests. No npm packages, network or browser required.
const vm=require('node:vm'),fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
let count=0;function check(ok,label){assert.ok(ok,label);count++;}
class E{
 constructor(){this.value='';this.handlers={};this.dataset={};this.hidden=false;this.attrs={};this.files=[];this.textContent='';this.classList={add(){},remove(){}};}
 addEventListener(k,fn){this.handlers[k]=fn;}dispatchEvent(event){this.handlers[event.type]?.(event);}removeAttribute(k){delete this.attrs[k];}setCustomValidity(v){this.validityMessage=v;}click(){this.clicked=true;}focus(){this.focused=true;}
}
function setup({stored=null,post=false,photoOnly=false,clear=false}={}){
 const ids={},fields={},storage=new Map(),timers=new Map(),windowEvents={};let tid=0,revokes=0;
 const names=['category_id','area_id','province_id','district_id','town_id','title','description','address','latitude','longitude'];names.forEach(n=>fields[n]=new E());fields.category_id.value='1';
 const form=photoOnly?null:new E();if(form){form.dataset={draftKey:'civiccare.draft.9.new',serverPost:post?'1':'0'};form.querySelector=s=>s.includes('action')?{value:'create_issue'}:fields[s.match(/name="([^"]+)/)[1]];ids['#citizen-report-form']=form;}
 for(const id of ['draft-status','save-draft','restore-draft','discard-draft','citizen-photo','report-preview','photo-status','clear-photo','change-photo'])ids['#'+id]=new E();
 ids['#citizen-photo'].dataset.maxBytes=String(5*1024*1024);
 if(stored)storage.set('civiccare.draft.9.new',JSON.stringify({version:1,values:stored}));
 if(clear)ids['#clear-report-draft']={dataset:{key:'civiccare.draft.9.new'}};
 const images=[];class MockImage{constructor(){images.push(this);this.naturalWidth=800;this.naturalHeight=600;}set src(v){this.url=v;}}
 const context={document:{addEventListener:(_,fn)=>fn(),querySelector:s=>ids[s]||null,createElement:()=>new E()},window:{addEventListener:(name,fn)=>windowEvents[name]=fn},localStorage:{getItem:k=>storage.get(k)||null,setItem:(k,v)=>storage.set(k,v),removeItem:k=>storage.delete(k)},Event:class{constructor(type){this.type=type;}},Image:MockImage,URL:{createObjectURL:()=> 'blob:test',revokeObjectURL:()=>revokes++},CivicDialog:{confirm:async()=>true},Date,Blob,setTimeout:fn=>{timers.set(++tid,fn);return tid;},clearTimeout:id=>timers.delete(id)};
 vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../assets/citizen.js'),'utf8'),context);
 return {ids,fields,form,storage,images,context,windowEvents,flush(){const queued=[...timers.values()];timers.clear();queued.forEach(fn=>fn());},get revokes(){return revokes;}};
}
(async()=>{
const a=setup();a.fields.title.value='Draft issue title';a.fields.title.handlers.input();a.flush();
let draft=JSON.parse(a.storage.get('civiccare.draft.9.new'));check(draft.values.title==='Draft issue title','Text autosaves per account');check(!('photo'in draft.values),'Photo not stored in localStorage');
a.fields.latitude.value='6.85';a.fields.longitude.value='79.92';a.context.CivicDraft.queue();a.flush();draft=JSON.parse(a.storage.get('civiccare.draft.9.new'));check(draft.values.latitude==='6.85','Programmatic map changes autosave');
await a.ids['#discard-draft'].handlers.click();a.windowEvents.pagehide();check(!a.storage.has('civiccare.draft.9.new'),'Discarded draft not recreated on exit');
a.ids['#save-draft'].handlers.click();check(a.storage.has('civiccare.draft.9.new'),'Explicit save works after discard');
const b=setup({stored:{title:'Restored title',latitude:'7',longitude:'80'}});check(b.fields.title.value===''&&!b.ids['#restore-draft'].hidden,'Saved draft is offered without overwriting a new form');b.ids['#restore-draft'].handlers.click();check(b.fields.title.value==='Restored title','Explicit restore fills saved draft');
const c=setup({stored:{title:'Old draft'},post:true});check(c.fields.title.value==='','Server error response not overwritten by draft');c.ids['#restore-draft'].handlers.click();check(c.fields.title.value==='Old draft','Explicit restore works');
const d=setup({stored:{title:'Success cleared'},clear:true});check(!d.storage.has('civiccare.draft.9.new'),'Successful submission clears account draft');
const p=setup({photoOnly:true}),input=p.ids['#citizen-photo'];
input.files=[{name:'bad.exe',type:'application/octet-stream',size:100}];input.handlers.change();check(p.ids['#photo-status'].textContent.includes('JPG'),'Unsupported file type rejected');
input.files=[{name:'large.jpg',type:'image/jpeg',size:6*1024*1024}];input.handlers.change();check(p.ids['#photo-status'].textContent.includes('larger than 5 MB'),'Oversized file rejected before submit');
input.files=[{name:'photo.jpg',type:'image/jpeg',size:1024}];input.handlers.change();check(input.validityMessage.includes('wait'),'Pending decode prevents premature submit');p.images.at(-1).onload();check(!p.ids['#report-preview'].hidden,'Valid image preview appears');check(input.validityMessage==='','Valid image unlocks submission');
p.ids['#clear-photo'].handlers.click();check(p.ids['#report-preview'].hidden&&input.value==='','Remove clears selected preview');check(p.revokes>0,'Preview object URLs released');
input.files=[{name:'huge.png',type:'image/png',size:1024}];input.handlers.change();p.images.at(-1).naturalWidth=10000;p.images.at(-1).naturalHeight=10000;p.images.at(-1).onload();check(p.ids['#photo-status'].textContent.includes('24 megapixels'),'Oversized dimensions rejected');
input.files=[{name:'broken.png',type:'image/png',size:1024}];input.handlers.change();p.images.at(-1).onerror();check(p.ids['#photo-status'].textContent.includes('cannot be opened'),'Invalid image decode explained');
p.ids['#change-photo'].handlers.click();check(input.clicked,'Change button opens file picker');
const canceled=setup({stored:{title:'Keep this saved draft'}});let decide,confirmCalls=0;canceled.context.CivicDialog.confirm=()=>{confirmCalls++;return new Promise(resolve=>decide=resolve);};const pendingDiscard=canceled.ids['#discard-draft'].handlers.click();check(canceled.ids['#discard-draft'].disabled&&canceled.storage.has('civiccare.draft.9.new'),'Draft stays saved while confirmation is pending');await canceled.ids['#discard-draft'].handlers.click();check(confirmCalls===1,'Repeated draft clicks do not queue duplicate confirmations');decide(false);await pendingDiscard;check(!canceled.ids['#discard-draft'].disabled&&canceled.ids['#discard-draft'].focused&&canceled.storage.has('civiccare.draft.9.new'),'Cancel retains saved draft and restores the button');
console.log(count+' citizen UI checks passed.');

})().catch(error=>{console.error(error);process.exit(1);});
