'use strict';
document.addEventListener('DOMContentLoaded',()=>{
 const $=s=>document.querySelector(s);
 $('.menu-toggle')?.addEventListener('click',()=>$('.sidebar').classList.toggle('is-open'));
 // Confirmation forms are handled by dialogs.js before their submit listeners run.
 document.querySelectorAll('input[data-preview]').forEach(input=>input.addEventListener('change',()=>{const img=document.getElementById(input.dataset.preview),file=input.files[0];if(img.dataset.url)URL.revokeObjectURL(img.dataset.url);img.hidden=true;if(file){const url=URL.createObjectURL(file);img.src=url;img.dataset.url=url;img.hidden=false;}}));
 const hasLeaflet=typeof L!=='undefined';
 function mapAt(id,lat=7.8731,lng=80.7718,zoom=7){
  const m=L.map(id,{scrollWheelZoom:true,minZoom:2,maxZoom:20}).setView([lat,lng],zoom);
  if(globalThis.CivicMapViews)globalThis.CivicMapViews.attach(m);
  else L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png',{attribution:'&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',maxZoom:20,maxNativeZoom:19,referrerPolicy:'strict-origin-when-cross-origin'}).addTo(m);
  return m;
 }
 let marker,pickMap,communityMap,checkTimer,controller;
 const lat=$('#latitude'),lng=$('#longitude'),cat=$('#category-input');
 function validCoords(){return lat&&lng&&lat.value!==''&&lng.value!==''&&Number.isFinite(Number(lat.value))&&Number.isFinite(Number(lng.value))&&Math.abs(Number(lat.value))<=90&&Math.abs(Number(lng.value))<=180;}
 function duplicateCheck(){
  if(!validCoords())return;
  clearTimeout(checkTimer);
  checkTimer=setTimeout(async()=>{
   if(controller)controller.abort();controller=new AbortController();
   const box=$('#duplicate-box');box.replaceChildren();const title=document.createElement('strong');title.textContent='Nearby reports in this category';box.append(title);
   try{
    const r=await fetch('index.php?'+new URLSearchParams({page:'duplicates',category:cat.value,lat:lat.value,lng:lng.value}),{signal:controller.signal});if(!r.ok)throw Error();
    const rows=await r.json();if(!Array.isArray(rows))throw Error();
    const p=document.createElement('p');p.textContent=rows.length?'The same problem? Open an existing report and select “This affects me too”.':'No matching open reports found within 300 metres.';box.append(p);
    rows.forEach(row=>{const a=document.createElement('a');a.href='index.php?page=issue&id='+Number(row.id);a.target='_blank';a.rel='noopener';a.textContent='#'+row.id+' · '+row.title+' · '+row.distance+' m ↗';box.append(a);});
   }catch(err){if(err.name!=='AbortError'){const p=document.createElement('p');p.textContent='Suggestions could not load. Check Community reports before submitting.';box.append(p);}}
  },350);
 }
 function setLocation(a,b,pan=true){lat.value=Number(a).toFixed(7);lng.value=Number(b).toFixed(7);globalThis.CivicDraft?.queue();globalThis.CivicReport?.locationChanged?.();if(pickMap){if(marker)marker.setLatLng([a,b]);else{marker=L.marker([a,b],{draggable:true}).addTo(pickMap);marker.on('dragend',()=>{const p=marker.getLatLng();setLocation(p.lat,p.lng,false);});}if(pan)pickMap.setView([a,b],16);}duplicateCheck();}
 globalThis.CivicReportMap={refresh:()=>pickMap?.invalidateSize(),reset:()=>{if(marker&&pickMap){pickMap.removeLayer(marker);marker=null;}pickMap?.setView([7.8731,80.7718],7);}};
 if($('#pick-map')){
  if(hasLeaflet){pickMap=mapAt('pick-map');pickMap.on('click',ev=>setLocation(ev.latlng.lat,ev.latlng.lng,false));if(validCoords())setLocation(Number(lat.value),Number(lng.value));}
  else $('#pick-map').textContent='The map could not load. Enter latitude and longitude below.';
  [lat,lng].forEach(el=>el.addEventListener('change',()=>{if(validCoords())setLocation(Number(lat.value),Number(lng.value));}));cat.addEventListener('change',duplicateCheck);
  $('#locate-button').addEventListener('click',()=>{if(!navigator.geolocation){CivicDialog.alert('Geolocation is unavailable. Enter coordinates manually.',{title:'Location unavailable',tone:'warning'});return;}const b=$('#locate-button');b.disabled=true;b.textContent='Finding your location…';navigator.geolocation.getCurrentPosition(p=>{setLocation(p.coords.latitude,p.coords.longitude);b.disabled=false;b.textContent='⌖ Use my current location';},()=>{CivicDialog.alert('Location unavailable. Select a point on the map or enter coordinates manually.',{title:'Choose the report location',tone:'warning'});b.disabled=false;b.textContent='⌖ Use my current location';},{enableHighAccuracy:true,timeout:12000});});
 }
 if($('#detail-map')){const el=$('#detail-map');if(hasLeaflet){const m=mapAt('detail-map',Number(el.dataset.lat),Number(el.dataset.lng),16);L.marker([el.dataset.lat,el.dataset.lng]).addTo(m);}else el.textContent='Map unavailable. Use the coordinates or the OpenStreetMap link below.';}
 if($('#community-map')){if(hasLeaflet){const m=communityMap=mapAt('community-map');const rows=JSON.parse($('#map-data').textContent);const bounds=rows.filter(r=>r.latitude!=null&&r.longitude!=null&&String(r.latitude).trim()!==''&&String(r.longitude).trim()!==''&&Number.isFinite(Number(r.latitude))&&Number.isFinite(Number(r.longitude))&&Math.abs(Number(r.latitude))<=90&&Math.abs(Number(r.longitude))<=180).map(r=>[Number(r.latitude),Number(r.longitude)]);if(globalThis.CivicMapClusters)globalThis.CivicMapClusters.attach(m,rows);else rows.forEach(r=>{const a=document.createElement('a');a.href='index.php?page=issue&id='+Number(r.id);a.textContent='#'+r.id+' · '+r.title+' ('+r.status+')';(r.color&&typeof L.circleMarker==='function'?L.circleMarker([r.latitude,r.longitude],{radius:9,color:'#ffffff',weight:2,fillColor:r.color,fillOpacity:0.95}):L.marker([r.latitude,r.longitude])).addTo(m).bindPopup(a);});const showReports=()=>{m.invalidateSize();if(bounds.length)m.fitBounds(bounds,{padding:[40,40],maxZoom:16});};showReports();$('#map-show-reports')?.addEventListener('click',showReports);}else $('#community-map').textContent='Map unavailable. Reports are listed below.';}
 
 globalThis.CivicCommunityMap={goTo:(a,b)=>{if(!communityMap)return false;communityMap.setView([a,b],15);return true;}};
 // Explicit searches only: no request is made while typing. Cache repeat searches
 // in this tab and limit how quickly users can start a new request.
 const placeCache=new Map();let lastPlaceRequest=0;
 document.querySelectorAll('.place-search').forEach(widget=>{
  const input=widget.querySelector('.place-query'),scope=widget.querySelector('.place-scope');
  const button=widget.querySelector('.place-search-button'),status=widget.querySelector('.place-search-status'),results=widget.querySelector('.place-results');
  let request=null,generation=0,searchMarker=null,lastAutoAddress='';
  function resetResults(){generation++;if(request)request.abort();results.replaceChildren();results.hidden=true;status.textContent='Press Search to find this location.';}
  input.addEventListener('input',resetResults);scope.addEventListener('change',resetResults);
  async function searchPlaces(){
   const query=input.value.trim();
   if(query.length<3){status.textContent='Enter at least 3 characters, such as a town or street name.';input.focus();return;}
   if(widget.dataset.mapTarget==='community-map'&&!communityMap){status.textContent='The map has not loaded. Reload the page with an internet connection.';return;}
   const key=scope.value+':'+query.toLocaleLowerCase();
   if(!placeCache.has(key)&&Date.now()-lastPlaceRequest<1200){status.textContent='Please wait a moment before searching again.';return;}
   const current=++generation;if(request)request.abort();request=new AbortController();
   const active=request;const timeout=setTimeout(()=>active.abort(),12000);
   button.disabled=true;button.textContent='Searching…';results.replaceChildren();results.hidden=true;status.textContent='Finding matching locations…';
   try{
    let rows=placeCache.get(key);
    if(!rows){
     const url=new URL(widget.dataset.searchUrl);url.searchParams.set('q',query);url.searchParams.set('limit','5');url.searchParams.set('lang','en');
     if(scope.value==='lk')url.searchParams.set('bbox','79.3,5.8,82.1,10.1');
     lastPlaceRequest=Date.now();
     const response=await fetch(url.toString(),{signal:active.signal,credentials:'omit',referrerPolicy:'strict-origin-when-cross-origin'});
     if(!response.ok)throw new Error('Search unavailable');
     const payload=await response.json();if(!Array.isArray(payload.features))throw new Error('Invalid search response');
     rows=payload.features.filter(f=>f.geometry?.type==='Point'&&Array.isArray(f.geometry.coordinates)&&typeof f.geometry.coordinates[0]==='number'&&typeof f.geometry.coordinates[1]==='number'&&Number.isFinite(f.geometry.coordinates[0])&&Number.isFinite(f.geometry.coordinates[1])&&Math.abs(f.geometry.coordinates[0])<=180&&Math.abs(f.geometry.coordinates[1])<=90).slice(0,5);
     if(placeCache.size>=40)placeCache.delete(placeCache.keys().next().value);placeCache.set(key,rows);
    }
    if(current!==generation)return;
    if(!rows.length){status.textContent='No locations found. Try a nearby town, a fuller street name, or Worldwide search.';return;}
    status.textContent=rows.length+' location'+(rows.length===1?'':'s')+' found. Choose one, then adjust the pin if needed.';
    rows.forEach(row=>{
     const p=row.properties||{},[longitude,latitude]=row.geometry.coordinates;
     const label=[...new Set([p.name,[p.housenumber,p.street].filter(Boolean).join(' '),p.district,p.city,p.county,p.state,p.country].filter(x=>typeof x==='string'&&x.trim()))].join(', ')||query;
     const result=document.createElement('button');result.type='button';result.className='place-result';result.textContent=label;
     const hint=document.createElement('small');hint.textContent='⌖ '+latitude.toFixed(5)+', '+longitude.toFixed(5)+' · Select this location';result.append(hint);
     result.addEventListener('click',()=>{
      if(widget.dataset.mapTarget==='pick-map'){
       setLocation(latitude,longitude);
       const address=document.querySelector('input[name="address"]');
       if(address&&(!address.value.trim()||address.value===lastAutoAddress)){address.value=label.slice(0,255);lastAutoAddress=address.value;}
       status.textContent='Location selected. Check the pin and move it to the exact issue location.';
      }else{
       communityMap.setView([latitude,longitude],15);
       if(searchMarker)searchMarker.setLatLng([latitude,longitude]);else searchMarker=L.marker([latitude,longitude],{opacity:0.65}).addTo(communityMap);
       const popup=document.createElement('span');popup.textContent=label+' — searched location, not a reported issue';searchMarker.bindPopup(popup).openPopup();
       status.textContent='Showing '+label+'. Existing report pins and filters are unchanged.';
      }
      results.hidden=true;
     });results.append(result);
    });results.hidden=false;
   }catch(error){if(current===generation)status.textContent=error.name==='AbortError'?'Search timed out. Try again, or select the location manually.':'Location search is temporarily unavailable. Try again or select a point on the map.';}
   finally{clearTimeout(timeout);if(request===active){button.disabled=false;button.textContent='⌕ Search';}}
  }
  button.addEventListener('click',searchPlaces);
  input.addEventListener('keydown',event=>{if(event.key==='Enter'){event.preventDefault();searchPlaces();}if(event.key==='Escape')resetResults();});
 });
});
