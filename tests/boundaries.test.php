<?php
require __DIR__.'/../app/followup.php';$n=0;
function check_boundary($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;}
$outer=[[0,0],[10,0],[10,10],[0,10],[0,0]];$hole=[[4,4],[6,4],[6,6],[4,6],[4,4]];
$g=followup_geometry(json_encode(['type'=>'Polygon','coordinates'=>[$outer,$hole]]));
check_boundary(followup_contains($g,2,2),'Inside outer ring');
check_boundary(!followup_contains($g,5,5),'Hole excluded');
check_boundary(!followup_contains($g,4,5),'Hole boundary excluded');
check_boundary(followup_contains($g,0,5),'Outer boundary included');
check_boundary(followup_contains($g,0,0),'Outer vertex included');
check_boundary(!followup_contains($g,20,20),'Outside ring excluded');
$multi=followup_geometry(json_encode(['type'=>'MultiPolygon','coordinates'=>[[$outer],[[[20,20],[22,20],[22,22],[20,22],[20,20]]]]]));
check_boundary(followup_contains($multi,21,21),'Second MultiPolygon part included');
check_boundary(!followup_contains($multi,15,15),'Gap between MultiPolygon parts excluded');
$feature=followup_geometry(json_encode(['type'=>'Feature','properties'=>['ignored'=>'metadata'],'geometry'=>['type'=>'Polygon','coordinates'=>[$outer]]]));
check_boundary(followup_contains($feature,1,1),'Single Feature supported');
$bad=['bad json','[]','"hello"',json_encode(['type'=>'Point','coordinates'=>[0,0]]),json_encode(['type'=>'Polygon','coordinates'=>[]]),json_encode(['type'=>'Polygon','coordinates'=>[[[0,0],[1,0],[1,1],[0,1]]]]),json_encode(['type'=>'Polygon','coordinates'=>[[[0,0],[0,0],[0,0],[0,0]]]]),json_encode(['type'=>'Polygon','coordinates'=>[[[0,0],[200,0],[200,1],[0,0]]]]),json_encode(['type'=>'Polygon','coordinates'=>[[[0,0],['1',0],[1,1],[0,0]]]]),json_encode(['type'=>'Polygon','coordinates'=>[[['a'=>0,'b'=>0],[1,0],[1,1],['a'=>0,'b'=>0]]]])];
foreach($bad as $raw){$rejected=false;try{followup_geometry($raw);}catch(RuntimeException $e){$rejected=true;}check_boundary($rejected,'Malformed or unsupported geometry rejected');}
echo $n." boundary geometry checks passed.\n";
