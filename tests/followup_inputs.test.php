<?php
require __DIR__.'/../app/followup.php';$n=0;
$_POST=['scope_id'=>'12'];if(followup_id('scope_id')!==12)throw new RuntimeException('Valid ID rejected');$n++;
foreach([['scope_id'=>['12']],['scope_id'=>'0'],['scope_id'=>'1e2'],['scope_id'=>'4294967296'],['scope_id'=>'-1']] as $post){$_POST=$post;$bad=false;try{followup_id('scope_id');}catch(RuntimeException $e){$bad=true;}if(!$bad)throw new RuntimeException('Malformed ID accepted');$n++;}
$_POST=['target_days'=>['3']];$bad=false;try{followup_text('target_days');}catch(RuntimeException $e){$bad=true;}if(!$bad)throw new RuntimeException('Array value accepted');$n++;
echo $n." follow-up input checks passed.\n";
