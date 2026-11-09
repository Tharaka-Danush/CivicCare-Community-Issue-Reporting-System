// Run: node tests/location_search.test.cjs (no package installation required).
const vm=require('node:vm'),fs=require('node:fs'),assert=require('node:assert/strict'),path=require('node:path');
let assertions=0;const check=(ok,msg)=>{assert.ok(ok,msg);assertions++;};
class Element{
 constructor(){this.value='';this.dataset={};this.handlers={};this.children=[];this.hidden=false;this.textContent='';this.disabled=false;}
 addEventListener(name,fn){this.handlers[name]=fn;}
 append(...nodes){this.children.push(...nodes);}
 replaceChildren(...nodes){this.children=nodes;}
 focus(){this.focused=true;}
}
function environment(target='pick-map',rows=[]){
 const input=new Element(),scope=new Element(),button=new Element(),status=new Element(),results=new Element(),widget=new Element();
 scope.value='lk';widget.dataset={mapTarget:target,searchUrl:'https://photon.komoot.io/api/'};
 const parts={'.place-query':input,'.place-scope':scope,'.place-search-button':button,'.place-search-status':status,'.place-results':results};widget.querySelector=s=>parts[s];
 const lat=new Element(),lng=new Element(),cat=new Element(),address=new Element(),box=new Element(),mapNode=new Element(),locate=new Element();cat.value='1';
 let markers=[],calls=[],now=2000,payload={features:[{geometry:{type:'Point',coordinates:[79.92,6.85]},properties:{name:'Maharagama',country:'Sri Lanka'}}]},failure=false;
 const resetButton=new Element();const map={invalidateSize(){this.refreshes=(this.refreshes||0)+1;},setView(pos,z){this.center=pos;this.zoom=z;return this;},on(){},fitBounds(points){this.bounds=points;this.fits=(this.fits||0)+1;}};
 const nodes={'#latitude':target==='pick-map'?lat:null,'#longitude':target==='pick-map'?lng:null,'#category-input':target==='pick-map'?cat:null,'#pick-map':target==='pick-map'?mapNode:null,'#community-map':target==='community-map'?mapNode:null,'#locate-button':locate,'#duplicate-box':box,'#map-data':{textContent:JSON.stringify(rows)},'#map-show-reports':resetButton,'input[name="address"]':address};
 const document={addEventListener:(_,fn)=>fn(),querySelector:s=>nodes[s]||null,querySelectorAll:s=>s==='.place-search'?[widget]:[],createElement:()=>new Element()};
 const context={document,URL,URLSearchParams,AbortController,Date:{now:()=>now},setTimeout:()=>1,clearTimeout:()=>{},navigator:{},L:{map:()=>map,tileLayer:()=>({addTo:()=>{}}),marker:pos=>{const m={position:pos,addTo(){markers.push(this);return this;},on(){},setLatLng(p){this.position=p;return this;},bindPopup(p){this.popup=p;return this;},openPopup(){return this;}};return m;}},fetch:async(url,options)=>{calls.push({url,options});if(failure)throw Error('offline');return {ok:true,json:async()=>payload};}};
 vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../assets/app.js'),'utf8'),context);
 return {input,scope,button,status,results,lat,lng,address,map,resetButton,get calls(){return calls;},get markers(){return markers;},set payload(v){payload=v;},set failure(v){failure=v;},tick(){now+=1300;},search:()=>button.handlers.click()};
}
(async()=>{
 const r=environment();check(r.calls.length===0,'No request at page load');r.input.value='Ma';await r.search();check(r.calls.length===0,'Short query not sent');
 r.input.value='Maharagama';r.input.handlers.input();check(r.calls.length===0,'No request while typing');await r.search();
 check(r.calls.length===1,'One request for explicit search');check(new URL(r.calls[0].url).searchParams.get('bbox')==='79.3,5.8,82.1,10.1','Sri Lanka search bounds');
 check(r.results.children.length===1&&!r.results.hidden,'Search results visible');r.results.children[0].handlers.click();
 check(r.lat.value==='6.8500000'&&r.lng.value==='79.9200000','Report coordinates updated');check(r.markers[0].position[0]===6.85&&r.markers[0].position[1]===79.92,'Report marker uses latitude/longitude order');check(r.address.value==='Maharagama, Sri Lanka','Empty address filled');check(r.map.zoom===16,'Report map zooms to location');
 await r.search();check(r.calls.length===1,'Repeated query uses cache');r.address.value='My exact landmark';r.results.children[0].handlers.click();check(r.address.value==='My exact landmark','User-entered address preserved');
 r.tick();r.scope.value='world';r.scope.handlers.change();await r.search();check(!new URL(r.calls[1].url).searchParams.has('bbox'),'Worldwide search omits country bounds');
 r.tick();r.input.value='No matching town';r.payload={features:[]};await r.search();check(r.status.textContent.includes('No locations found'),'Empty state explained');check(!r.button.disabled,'Search button restored');
 r.tick();r.input.value='Offline query';r.failure=true;await r.search();check(r.status.textContent.includes('temporarily unavailable'),'Network failure explained');check(!r.button.disabled,'Error allows retry');
 r.tick();r.failure=false;r.input.value='Invalid coordinates';r.payload={features:[{geometry:{type:'Point',coordinates:[999,999]},properties:{name:'Bad'}}]};await r.search();check(r.results.hidden,'Invalid coordinates filtered');
 r.tick();r.input.value='Markup test';r.payload={features:[{geometry:{type:'Point',coordinates:[80,7]},properties:{name:'<script>bad()</script>'}}]};await r.search();check(r.results.children[0].textContent==='<script>bad()</script>','Remote names use textContent');
 let prevented=false;await r.input.handlers.keydown({key:'Enter',preventDefault(){prevented=true;}});check(prevented,'Enter does not submit issue form');
 const c=environment('community-map');c.input.value='Maharagama';await c.search();c.results.children[0].handlers.click();check(c.map.center[0]===6.85&&c.map.zoom===15,'Community map moves to searched location');check(c.markers[0].popup.textContent.includes('not a reported issue'),'Search marker distinct from issue');
 check(c.results.hidden,'Results close on selection');
 const fit=environment('community-map',[{id:1,title:'Road issue',status:'Submitted',latitude:6.85,longitude:79.92}]);check(fit.map.fits===1&&fit.map.bounds.length===1,'Map initially fits current report coordinates');fit.resetButton.handlers.click();check(fit.map.fits===2&&fit.map.refreshes===2,'Show all reports refits map after navigation');
console.log(assertions+' location search checks passed.');
})().catch(e=>{console.error(e);process.exit(1);});
