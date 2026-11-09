<?php
require __DIR__.'/app/bootstrap.php';require __DIR__.'/app/layout.php';
// Installer only runs on localhost and is permanently closed once any user exists.
if(!in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true)){http_response_code(403);exit('Setup is available on localhost only.');}
$error='';$ready=false;
try {$ready=true;$count=(int)q('SELECT COUNT(*) FROM users')->fetchColumn();if($count>0){http_response_code(403);exit('Setup is locked because users already exist. Sign in at index.php.');}}
catch(Throwable $ex){$ready=false;$error='Create database community_issues, import database/schema.sql and check config.php before continuing.';}
if($ready && $_SERVER['REQUEST_METHOD']==='POST') {
 try {check_csrf();$name=field('name',2,100);$email=valid_email();$pass=password_input();
  // Named lock serializes first-account creation, including concurrent setup requests.
  $lock=q("SELECT GET_LOCK('civiccare_initial_admin',5)")->fetchColumn();if(!$lock)throw new RuntimeException('Setup is busy. Try again.');
  try{if((int)q('SELECT COUNT(*) FROM users')->fetchColumn()>0)throw new RuntimeException('Setup is already complete.');q("INSERT INTO users(name,email,password,role,is_system_admin) VALUES(?,?,?,'admin',1)",[$name,$email,password_hash($pass,PASSWORD_DEFAULT)]);}finally{q("SELECT RELEASE_LOCK('civiccare_initial_admin')");}
  flash('Administrator created. Sign in to get started.');go('index.php?page=login');
 }catch(Throwable $ex){$error=$ex instanceof PDOException?'Setup failed. Check your database configuration.':$ex->getMessage();}
}
head_html('Set up CivicCare');?><main class="setup-card"><a class="brand" href="index.php"><span class="brand-mark">C</span>CivicCare</a><h1 style="margin-top:30px">Make it your community.</h1><p class="muted">Create the first administrator. Setup locks automatically after the account is created.</p><?php if($error):?><div class="alert error"><?=e($error)?></div><?php endif;?><ul><li>PHP 7.4+ with PDO MySQL, GD and Fileinfo.</li><li>MySQL 5.7+ or MariaDB 10.4+.</li><li>Writable storage/uploads directory.</li></ul><?php if($ready):?><form method="post"><?php csrf();?><label>Administrator name<input name="name" minlength="2" maxlength="100" required></label><label>Email address<input type="email" name="email" maxlength="190" required></label><label>Password<input type="password" name="password" minlength="10" maxlength="72" autocomplete="new-password" required></label><button class="button full">Create administrator</button></form><?php endif;?></main></body></html>
