<?php
function ops_options(){
 static $options;
 if($options===null){$defaults=['email_verification_required'=>false,'app_url'=>'http://localhost/community_issue_system','mail'=>['host'=>'','port'=>587,'encryption'=>'tls','username'=>'','password'=>'','from_email'=>'','from_name'=>'CivicCare'],'backup_max_bytes'=>100*1024*1024,'backup_max_uncompressed_bytes'=>300*1024*1024,'rate_limits'=>['report'=>[5,900],'comment'=>[10,60],'edit'=>[10,60],'support'=>[30,60],'register'=>[5,3600],'email'=>[3,3600],'admin_reauth'=>[10,900]]];$path=__DIR__.'/../operations_config.php';$custom=is_file($path)?require $path:[];if(!is_array($custom))throw new RuntimeException('operations_config.php must return an array.');$options=array_replace_recursive($defaults,$custom);}
 return $options;
}
function ops_write_lock(){
 global $config;static $locked=false;if($locked)return;
 $name='civiccare_write_'.substr(hash('sha256',$config['db_name']),0,32);
 if(!(int)q('SELECT GET_LOCK(?,5)',[$name])->fetchColumn())throw new RuntimeException('The system is busy with another update or backup. Please try again.');
 $locked=true;register_shutdown_function(function()use($name){try{q('SELECT RELEASE_LOCK(?)',[$name]);}catch(Throwable $e){}});
}
function ops_rate_limit($scope,$byIp=false){
 $policy=ops_options()['rate_limits'][$scope]??[10,60];$limit=max(1,(int)$policy[0]);$seconds=max(1,(int)$policy[1]);$actor=$byIp?'ip:'.($_SERVER['REMOTE_ADDR']??'unknown'):'user:'.(user()['id']??0);$key=hash('sha256',$scope.'|'.$actor);$own=!db()->inTransaction();
 try{if($own)db()->beginTransaction();q('INSERT IGNORE INTO action_rate_buckets(key_hash,window_started,used_count) VALUES(?,NOW(),0)',[$key]);$row=q('SELECT * FROM action_rate_buckets WHERE key_hash=? FOR UPDATE',[$key])->fetch();$elapsed=time()-strtotime($row['window_started']);$used=$elapsed>=$seconds?0:(int)$row['used_count'];
  if($used>=$limit)throw new RuntimeException('Too many '.$scope.' attempts. Try again in '.max(1,$seconds-$elapsed).' seconds.');
  q('UPDATE action_rate_buckets SET used_count=?,window_started=? WHERE key_hash=?',[$used+1,$elapsed>=$seconds?date('Y-m-d H:i:s'):$row['window_started'],$key]);if($own)db()->commit();
 }catch(Throwable $e){if($own&&db()->inTransaction())db()->rollBack();throw $e;}
}
function ops_admin_password(){require_role('admin');ops_rate_limit('admin_reauth');if(!password_verify((string)($_POST['admin_password']??''),user()['password']))throw new RuntimeException('Administrator password is incorrect.');}
function ops_require_verified(){if(ops_options()['email_verification_required'] && user()['role']==='citizen' && !user()['email_verified_at'])throw new RuntimeException('Verify your email from My profile before submitting reports or comments.');}
function ops_mail_ready(){ $mail=ops_options()['mail'];return trim($mail['host'])!=='' && filter_var($mail['from_email'],FILTER_VALIDATE_EMAIL); }
function ops_mail($to,$subject,$body){
 if(!ops_mail_ready())throw new RuntimeException('Email sending is not configured. Contact the administrator.');
 foreach(['Exception','SMTP','PHPMailer'] as $file)require_once __DIR__.'/vendor/PHPMailer/'.$file.'.php';
 $settings=ops_options()['mail'];$mail=new \PHPMailer\PHPMailer\PHPMailer(true);
 try{$mail->isSMTP();$mail->Host=$settings['host'];$mail->Port=(int)$settings['port'];$mail->SMTPAuth=$settings['username']!=='';$mail->Username=$settings['username'];$mail->Password=$settings['password'];$mail->SMTPSecure=$settings['encryption'];$mail->Timeout=12;$mail->CharSet='UTF-8';$mail->setFrom($settings['from_email'],$settings['from_name']);$mail->addAddress($to);$mail->Subject=$subject;$mail->Body=$body;$mail->send();}
 catch(Throwable $e){error_log('CivicCare SMTP delivery failed.');throw new RuntimeException('The verification email could not be sent. Please try again later or contact the administrator.');}
}
function ops_verification_email($uid,$email){
 $base=rtrim(ops_options()['app_url'],'/');$u=parse_url($base);if(!$u || !in_array($u['scheme']??'', ['https','http'],true) || (strtolower($u['scheme'])==='http' && !in_array($u['host']??'',['localhost','127.0.0.1','::1'],true)))throw new RuntimeException('Set a valid HTTPS app_url (or localhost for local testing) in operations_config.php.');
 $raw=bin2hex(random_bytes(32));q('DELETE FROM email_verifications WHERE user_id=?',[$uid]);q('INSERT INTO email_verifications(user_id,token_hash,expires_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 1 DAY))',[$uid,hash('sha256',$raw)]);
 $url=$base.'/index.php?page=verify&token='.rawurlencode($raw);ops_mail($email,'Verify your CivicCare email',"Confirm this email address for CivicCare by opening the link below, then choosing Verify email:\n\n".$url."\n\nThis link expires in 24 hours. If you did not request it, ignore this email.");
}
function ops_bytes($value){$value=trim((string)$value);if($value===''||$value==='0')return 0;$n=(float)$value;$suffix=strtolower(substr($value,-1));return (int)($n*($suffix==='g'?1073741824:($suffix==='m'?1048576:($suffix==='k'?1024:1))));}
function ops_notifications_page(){
 $uid=user()['id'];$unread=(int)q('SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0',[$uid])->fetchColumn();$filter=$_GET['filter']??'all';$filter=$filter==='unread'?'unread':'all';$where=' WHERE user_id=?'.($filter==='unread'?' AND is_read=0':'');$total=(int)q('SELECT COUNT(*) FROM notifications'.$where,[$uid])->fetchColumn();$pages=max(1,(int)ceil($total/20));$pn=min($pages,max(1,(int)($_GET['p']??1)));$rows=q('SELECT * FROM notifications'.$where.' ORDER BY id DESC LIMIT 20 OFFSET '.(($pn-1)*20),[$uid])->fetchAll();
 layout_start('Your updates','Status changes, conversation updates and resolution progress.');?><nav class="report-tabs"><a class="report-tab <?=$filter==='all'?'selected':''?>" href="index.php?page=notifications">All</a><a class="report-tab <?=$filter==='unread'?'selected':''?>" href="index.php?page=notifications&amp;filter=unread">Unread <b><?=$unread?></b></a></nav><section class="panel"><?php if($unread):?><form method="post"><?php csrf();?><input type="hidden" name="action" value="read_notifications"><button class="button secondary">Mark all as read</button></form><?php endif;?><p class="small muted"><?=$total?> notifications · Page <?=$pn?> of <?=$pages?></p><?php if(!$rows):?><p class="small muted">No notifications in this selection.</p><?php endif;foreach($rows as $n):?><form method="post" class="notification-open-form"><?php csrf();?><input type="hidden" name="action" value="open_notification"><input type="hidden" name="notification_id" value="<?=$n['id']?>"><button class="notification-item <?=$n['is_read']?'':'unread'?>" type="submit"><span><strong class="notification-type"><?=e(citizen_notification_type($n['message']))?></strong><?=e($n['message'])?></span><small><?=e($n['created_at'])?><?php if(user()['role']==='maintenance'):?><br>Open team work →<?php endif;?></small></button></form><?php endforeach;?></section><?php if($pages>1):?><nav class="pagination"><?php if($pn>1):?><a href="index.php?page=notifications&amp;filter=<?=$filter?>&amp;p=<?=$pn-1?>">← Previous</a><?php endif;?><span><?=$pn?> / <?=$pages?></span><?php if($pn<$pages):?><a href="index.php?page=notifications&amp;filter=<?=$filter?>&amp;p=<?=$pn+1?>">Next →</a><?php endif;?></nav><?php endif;layout_end();
}
