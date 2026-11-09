<?php
function street_view_options(){
 static $settings;
 if($settings===null){$settings=['apiKey'=>'','zoom'=>19,'radius'=>50,'delay'=>800];$file=__DIR__.'/../street_view_config.php';$options=is_file($file)?require $file:[];if(!is_array($options))$options=[];$settings['setupState']=!is_file($file)?'missing_file':(empty($options['enabled'])?'disabled':(empty(trim((string)($options['api_key']??'')))?'missing_key':'configured'));$settings['apiKey']=!empty($options['enabled'])?trim((string)($options['api_key']??'')):'';$settings['zoom']=max(18,min(20,(int)($options['auto_zoom']??19)));$settings['radius']=max(10,min(100,(int)($options['search_radius_metres']??50)));$settings['delay']=max(500,min(3000,(int)($options['delay_ms']??800)));}
 return $settings;
}
