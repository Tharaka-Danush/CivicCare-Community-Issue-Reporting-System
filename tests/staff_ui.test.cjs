const vm=require('node:vm'),fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
let count=0;const check=(ok,label)=>{assert.ok(ok,label);count++;};
class Element{constructor(){this.value='';this.handlers={};this.children=[];this.disabled=false;}addEventListener(name,fn){this.handlers[name]=fn;}replaceChildren(){this.children=[];this.value='';}append(value){this.children.push(value);}}
const area=new Element(),officer=new Element(),hint=new Element(),submit=new Element();area.closest=()=>({querySelector:()=>submit});let rows=[],fail=false;
const ctx={document:{addEventListener:(_,fn)=>fn(),querySelector:id=>({'#assignment-area':area,'#assignment-officer':officer,'#assignment-hint':hint}[id]),createElement:()=>new Element()},AbortController,URLSearchParams,setTimeout:()=>1,clearTimeout:()=>{},fetch:async()=>{if(fail)throw Error();return{ok:true,json:async()=>rows};}};
vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../assets/staff.js'),'utf8'),ctx);
(async()=>{
 rows=[{id:1,name:'Officer A',workload:3,area_match:1,area_count:1}];area.value='1';officer.value='1';await area.handlers.change();check(officer.value==='1','Existing eligible selection retained');check(officer.children[1].textContent.includes('3 open'),'Workload shown in suggestions');check(!officer.disabled&&!submit.disabled,'Form reenabled after suggestions');
 rows=[{id:2,name:'Officer B',workload:0,area_match:1,area_count:1}];area.value='2';await area.handlers.change();check(officer.value==='','Ineligible previous selection cleared');check(officer.value!=='2','Suggested officer not assigned automatically');
 rows=[];await area.handlers.change();check(hint.textContent.includes('No eligible officers'),'Empty coverage state explained');
 fail=true;await area.handlers.change();check(hint.textContent.includes('could not load'),'Failed lookup explained');check(!officer.disabled&&!submit.disabled,'Retry possible after error');
 console.log(count+' staff assignment UI checks passed.');
})().catch(error=>{console.error(error);process.exit(1);});
