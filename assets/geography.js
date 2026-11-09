'use strict';
document.addEventListener('DOMContentLoaded',()=>{
 const refreshers=[];
 document.querySelectorAll('[data-geo-selector]').forEach(box=>{
  const province=box.querySelector('[data-geo-province]'),district=box.querySelector('[data-geo-district]'),town=box.querySelector('[data-geo-town]');
  const districts=[...district.options].slice(1).map(o=>o.cloneNode(true)),towns=[...town.options].slice(1).map(o=>o.cloneNode(true));
  function options(select,source,parentKey,parent){const selected=select.value;[...select.options].slice(1).forEach(option=>option.remove());source.filter(option=>option.dataset[parentKey]===parent||(box.hasAttribute('data-geo-filter')&&!parent&&(!province.value||parentKey!=='district'||option.dataset.province===province.value))).forEach(option=>select.append(option.cloneNode(true)));select.value=selected;if(!select.value)select.value='';}
  function refresh(){options(district,districts,'province',province.value);options(town,towns,'district',district.value);}
  province.addEventListener('change',()=>{district.value='';town.value='';refresh();});district.addEventListener('change',()=>{town.value='';refresh();});refreshers.push(refresh);refresh();
  const kind=box.closest('form')?.querySelector('[data-geo-account-kind]');if(kind){const required=()=>{province.required=true;district.required=kind.value!=='province';town.required=kind.value==='town';district.closest('label').hidden=kind.value==='province';town.closest('label').hidden=kind.value!=='town';};kind.addEventListener('change',required);required();}else if(box.closest('form')?.querySelector('[name=action]')?.value==='geo_create_town'){province.required=true;district.required=true;town.required=false;town.closest('label').hidden=true;}
 });
 globalThis.CivicGeography={refresh:()=>refreshers.forEach(refresh=>refresh())};
});
