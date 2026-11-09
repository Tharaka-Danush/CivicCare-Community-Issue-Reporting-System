<?php
require __DIR__.'/../app/flow.php';
$i=['province_id'=>1,'district_id'=>2,'town_id'=>3,'latitude'=>'6.8500000','longitude'=>'79.9200000','address'=>'Library Road','status'=>'Submitted','priority'=>'Low'];
$h=flow_location_hash($i);$n=0;
foreach(['province_id'=>9,'district_id'=>8,'town_id'=>7,'latitude'=>'6.8600000','longitude'=>'79.9300000','address'=>'Another Road'] as $key=>$value){$j=$i;$j[$key]=$value;if(flow_location_hash($j)===$h)throw new RuntimeException('Location change was not detected: '.$key);$n++;}
$j=$i;$j['province_id']='1';$j['district_id']='2';$j['town_id']='3';if(flow_location_hash($j)!==$h)throw new RuntimeException('Equivalent PDO ID types changed snapshot');$n++;
$j=$i;$j['status']='Assigned';$j['priority']='High';if(flow_location_hash($j)!==$h)throw new RuntimeException('Non-location updates changed location snapshot');$n++;
if(flow_location_hash(array_reverse($i,true))!==$h)throw new RuntimeException('Column order changed snapshot');$n++;
echo $n." location snapshot checks passed.\n";
