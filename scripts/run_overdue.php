<?php
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Command-line execution only.');}
require __DIR__.'/../app/bootstrap.php';
$GLOBALS['geo_ready']=true;
$admin=geo_raw("SELECT id,auth_version FROM users WHERE role='admin' AND is_system_admin=1 AND active=1 ORDER BY id LIMIT 1")->fetch();
if(!$admin){fwrite(STDERR,"An active System Admin is required.\n");exit(1);}
$_SESSION['uid']=(int)$admin['id'];$_SESSION['auth_version']=(int)$admin['auth_version'];
try{$r=followup_jobs('scheduler');echo date('c').' '.$r['sent']." in-app notifications created.\n";}catch(Throwable $e){error_log('CivicCare overdue runner failed: '.get_class($e));fwrite(STDERR,"Overdue follow-up failed; check server logs.\n");exit(1);}
