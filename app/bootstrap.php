<?php
$config = require __DIR__ . '/../config.php';
date_default_timezone_set($config['timezone']);
ini_set('session.use_strict_mode', '1');
session_name($config['session_name']);
session_set_cookie_params(['httponly'=>true,'samesite'=>'Lax','secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
session_start();
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
// OSM tiles require the browser to send a valid cross-origin Referer.
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store');
function db() {
 global $config;
 static $pdo;
 if (!$pdo) {
  $pdo = new PDO('mysql:host='.$config['db_host'].';port='.$config['db_port'].';dbname='.$config['db_name'].';charset=utf8mb4', $config['db_user'],$config['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
  $pdo->exec("SET time_zone = '+05:30'");
 }
 return $pdo;
}
function q($sql,$params=[]) { $sql=function_exists('geo_scope_read')?geo_scope_read($sql):$sql; $s=db()->prepare($sql); $s->execute($params); return $s; }
function e($value) {return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
function go($url='index.php') {header('Location: '.$url);exit;}
function flash($message,$type='success') {$_SESSION['flash']=['message'=>$message,'type'=>$type];}
function token() {if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32));return $_SESSION['csrf'];}
function csrf() {echo '<input type="hidden" name="csrf" value="'.e(token()).'">';}
function check_csrf() {if(!hash_equals(token(),(string)($_POST['csrf']??''))) throw new RuntimeException('Your session expired. Refresh the page and try again.');}
function user() {
 static $u=false;
 if($u===false) $u=isset($_SESSION['uid']) ? q('SELECT * FROM users WHERE id=? AND active=1',[$_SESSION['uid']])->fetch():null;
 if($u && (!isset($_SESSION['auth_version']) || (int)$_SESSION['auth_version']!==(int)($u['auth_version']??1)))$u=null;
 return $u;
}
function require_login() {if(!user()) go('index.php?page=login');}
function require_role($roles) {require_login();if(!in_array(user()['role'],(array)$roles,true)) {http_response_code(403);exit('Access denied.');}}
function length_of($s) {return preg_match_all('/./us',$s,$matches);}
function field($name,$min,$max) {$raw=$_POST[$name]??'';if(!is_string($raw))throw new RuntimeException('Enter a valid '.str_replace('_',' ',$name).'.');$v=trim($raw);if(length_of($v)<$min || length_of($v)>$max) throw new RuntimeException(ucfirst(str_replace('_',' ',$name))." must be $min–$max characters.");return $v;}
function valid_email() {$v=strtolower(field('email',5,190));if(!filter_var($v,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid email address.');return $v;}
function password_input() {$v=(string)($_POST['password']??'');if(strlen($v)<10 || strlen($v)>72) throw new RuntimeException('Use a password of 10–72 bytes.');return $v;}
function coords($lat,$lng) {return is_numeric($lat)&&is_numeric($lng)&&is_finite((float)$lat)&&is_finite((float)$lng)&&$lat>=-90&&$lat<=90&&$lng>=-180&&$lng<=180;}
function categories($include_archived=true) {return q('SELECT * FROM categories'.($include_archived?'':' WHERE active=1').' ORDER BY id')->fetchAll();}
function event_add($id,$kind,$message) {q('INSERT INTO issue_events(issue_id,user_id,kind,message) VALUES(?,?,?,?)',[$id,user()['id'],$kind,$message]);}
function audit($action,$detail) {q('INSERT INTO audit_log(actor_id,action,details) VALUES(?,?,?)',[user()['id'],$action,$detail]);}
function notify_issue($issue,$message) {
 $ids=[$issue['user_id']];if(!empty($issue['maintenance_id']))$ids[]=$issue['maintenance_id'];if($issue['officer_id'])$ids[]=$issue['officer_id'];
 foreach(q('SELECT user_id FROM supports WHERE issue_id=?',[$issue['id']])->fetchAll() as $r)$ids[]=$r['user_id'];
 foreach(array_unique($ids) as $uid)if($uid!=user()['id']) q('INSERT INTO notifications(user_id,issue_id,message) VALUES(?,?,?)',[$uid,$issue['id'],$message]);
}
function photo_upload($field,$required=false) {
 global $config;
 $f=$_FILES[$field]??null;
 if(!$f || $f['error']===UPLOAD_ERR_NO_FILE) {if($required)throw new RuntimeException('Please add a photo.');return null;}
 if($f['error']!==UPLOAD_ERR_OK || $f['size']>$config['upload_max_bytes']) throw new RuntimeException('Photo upload failed. Maximum size is 5 MB.');
 $info=@getimagesize($f['tmp_name']);
 if(!$info || !in_array($info[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG,IMAGETYPE_WEBP],true)) throw new RuntimeException('Upload a valid JPG, PNG or WebP image.');
 if($info[0]*$info[1]>24000000) throw new RuntimeException('Image is too large. Use an image below 24 megapixels.');
 if(!function_exists('imagecreatefromstring'))throw new RuntimeException('Enable the PHP GD extension to upload photos.');
 $im=@imagecreatefromstring(file_get_contents($f['tmp_name']));
 if(!$im) throw new RuntimeException('This image could not be processed. Try a JPG or PNG.');
 $ratio=min(1,1800/max($info[0],$info[1]));$width=max(1,(int)round($info[0]*$ratio));$height=max(1,(int)round($info[1]*$ratio));
 $out=imagecreatetruecolor($width,$height);$white=imagecolorallocate($out,255,255,255);imagefill($out,0,0,$white);
 imagecopyresampled($out,$im,0,0,0,0,$width,$height,$info[0],$info[1]);
 $name=bin2hex(random_bytes(20)).'.jpg';
 $path=__DIR__.'/../storage/uploads/'.$name;
 if(!is_writable(dirname($path))||!@imagejpeg($out,$path,85)||!is_file($path)||!filesize($path)){
  remove_photo($name);
  throw new RuntimeException('Cannot save photo. Check storage/uploads permissions.');
 }
 imagedestroy($im);imagedestroy($out);return $name;
}
function remove_photo($name) {if($name && preg_match('/^[a-f0-9]{40}\.jpg$/',$name)) @unlink(__DIR__.'/../storage/uploads/'.$name);}
function badge($status) {return '<span class="badge b-'.strtolower(str_replace(' ','-',$status)).'">'.e($status).'</span>';}
function issue_query() {return 'SELECT i.*,gp.name province_name,gd.name district_name,gt.name town_name,c.name category,u.name reporter,o.name officer,m.name maintenance_team,COALESCE(gt.name,a.name) area_name,(SELECT COUNT(*) FROM supports s WHERE s.issue_id=i.id) support_count FROM issues i JOIN categories c ON c.id=i.category_id JOIN users u ON u.id=i.user_id LEFT JOIN users o ON o.id=i.officer_id LEFT JOIN users m ON m.id=i.maintenance_id LEFT JOIN service_areas a ON a.id=i.area_id LEFT JOIN geo_provinces gp ON gp.id=i.province_id LEFT JOIN geo_districts gd ON gd.id=i.district_id LEFT JOIN geo_towns gt ON gt.id=i.town_id';}
function issue_get($id) {return q(issue_query().' WHERE i.id=?',[$id])->fetch();}
function distance_m($lat1,$lon1,$lat2,$lon2) {$a=sin(deg2rad($lat2-$lat1)/2)**2+cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin(deg2rad($lon2-$lon1)/2)**2;return 6371000*2*atan2(sqrt(min(1,$a)),sqrt(max(0,1-$a)));}
function duplicate_candidates($cat,$lat,$lng) {
 $rows=q("SELECT id,title,status,latitude,longitude FROM issues WHERE category_id=? AND status NOT IN ('Rejected','Duplicate','Resolved') AND ABS(latitude-?)<0.005 ORDER BY created_at DESC",[$cat,$lat])->fetchAll();$out=[];
 foreach($rows as $r) {$d=distance_m($lat,$lng,$r['latitude'],$r['longitude']);if($d<=300){$r['distance']=(int)round($d);$out[]=$r;}}
 usort($out,function($a,$b){return $a['distance']<=>$b['distance'];});return array_slice($out,0,8);
}

require __DIR__.'/citizen_helpers.php';

require __DIR__.'/staff_helpers.php';

require __DIR__.'/operations_helpers.php';
require __DIR__.'/backup_helpers.php';

require __DIR__.'/maintenance_helpers.php';

require __DIR__.'/officer_workspace.php';

require __DIR__.'/admin_workspace.php';

require __DIR__.'/citizen_workspace.php';

require __DIR__.'/professional_workspace.php';

require __DIR__.'/geography.php';

require __DIR__.'/team_workspace.php';

require __DIR__.'/oversight.php';

require __DIR__.'/flow.php';
require __DIR__.'/flow_ui.php';

require __DIR__.'/followup.php';

require __DIR__.'/management.php';

require __DIR__.'/photo_visibility.php';
