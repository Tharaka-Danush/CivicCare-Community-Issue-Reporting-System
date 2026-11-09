<?php
function ops_backup_tables(){return ['users','categories','geo_provinces','geo_districts','geo_towns','service_areas','officer_areas','issues','supports','issue_events','notifications','recovery_codes','issue_feedback','issue_progress_photos','audit_log','login_attempts','action_rate_buckets','admin_reset_tokens','email_verifications','team_categories','issue_flow','issue_internal_notes','category_service_targets','temporary_cover','geo_service_boundaries'];}
function ops_columns($table){return array_column(q('SHOW COLUMNS FROM `'.$table.'`')->fetchAll(),'Field');}
function ops_photo_names($data){$names=[];foreach($data['issues'] as $i){$names[]=$i['photo'];if($i['resolution_photo'])$names[]=$i['resolution_photo'];if(!empty($i['maintenance_photo']))$names[]=$i['maintenance_photo'];}foreach($data['issue_progress_photos'] as $p)$names[]=$p['filename'];$names=array_values(array_unique($names));foreach($names as $name)if(!is_string($name)||!preg_match('/^[a-f0-9]{40}\.jpg$/',$name))throw new RuntimeException('The backup contains an invalid photo reference.');sort($names);return $names;}
function ops_create_backup(){
 if(!class_exists('ZipArchive'))throw new RuntimeException('Enable the PHP ZIP extension before creating backups.');
 $path=tempnam(sys_get_temp_dir(),'civic-backup-');register_shutdown_function(function()use($path){if(is_file($path))@unlink($path);});$zip=new ZipArchive();
 try{
  if($zip->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Could not create the backup archive.');
  db()->beginTransaction();$data=[];$columns=[];
  foreach(ops_backup_tables() as $table){$columns[$table]=ops_columns($table);$data[$table]=q('SELECT * FROM `'.$table.'`')->fetchAll();}
  $json=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
  if(strlen($json)>16*1024*1024)throw new RuntimeException('Database data exceeds the 16 MB browser-backup limit. Use an administrator database backup tool for this dataset.');
  $photoNames=ops_photo_names($data);
  if(count($photoNames)>10000)throw new RuntimeException('Backup exceeds the 10,000-photo restore limit.');
  $photos=[];$total=strlen($json);
  foreach($photoNames as $name){$file=__DIR__.'/../storage/uploads/'.$name;if(!is_file($file))throw new RuntimeException('A referenced photo is missing. Repair missing uploads before backing up.');$bytes=filesize($file);if($bytes===false||$bytes>10*1024*1024)throw new RuntimeException('A photo exceeds the 10 MB restore limit.');$total+=$bytes;if($total>ops_options()['backup_max_uncompressed_bytes'])throw new RuntimeException('Backup content exceeds the configured size limit.');$photos[$name]=['bytes'=>$bytes,'sha256'=>hash_file('sha256',$file)];if(!$zip->addFile($file,'photos/'.$name))throw new RuntimeException('Could not add a photo to the backup.');}
  $manifest=['format'=>'civiccare_backup_v1','created_at'=>gmdate('c'),'columns'=>$columns,'counts'=>array_map('count',$data),'database_sha256'=>hash('sha256',$json),'photos'=>$photos];
  $manifestJson=json_encode($manifest,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
  if(strlen($manifestJson)>2*1024*1024 || $total+strlen($manifestJson)>ops_options()['backup_max_uncompressed_bytes']){
   throw new RuntimeException('Backup metadata exceeds the configured restore limits.');
  }
  if(!$zip->addFromString('database.json',$json)||!$zip->addFromString('manifest.json',$manifestJson))throw new RuntimeException('Could not build the backup.');
  if(!$zip->close())throw new RuntimeException('Could not finish the backup archive.');
  if(filesize($path)>ops_options()['backup_max_bytes'])throw new RuntimeException('Backup archive exceeds the configured size limit.');
  db()->commit();return $path;
 }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();try{$zip->close();}catch(Throwable $ignored){}@unlink($path);throw $e;}
}
function ops_zip_read($zip,$name,$max){$info=$zip->statName($name);if(!$info||$info['size']>$max)throw new RuntimeException('Backup entry is missing or too large.');$bytes=$zip->getFromName($name);if($bytes===false||strlen($bytes)!==$info['size'])throw new RuntimeException('Could not read backup data.');return $bytes;}
function ops_restore_backup($file,$validateOnly=false){
 if(!class_exists('ZipArchive'))throw new RuntimeException('Enable the PHP ZIP extension before restoring backups.');
 $options=ops_options();if(!is_file($file)||filesize($file)>$options['backup_max_bytes'])throw new RuntimeException('Backup exceeds the configured restore limit.');
 $operator=user();$zip=new ZipArchive();$newFiles=[];$staged=[];$data=null;
 if($zip->open($file)!==true)throw new RuntimeException('Choose a valid CivicCare backup ZIP.');
 try{
  if($zip->numFiles>10002)throw new RuntimeException('Too many backup entries.');$seen=[];$total=0;
  for($n=0;$n<$zip->numFiles;$n++){$stat=$zip->statIndex($n);$name=$stat['name'];if(isset($seen[$name])||!($name==='manifest.json'||$name==='database.json'||preg_match('~^photos/[a-f0-9]{40}\.jpg$~',$name)))throw new RuntimeException('The archive has duplicate, unexpected or unsafe paths.');$seen[$name]=true;$total+=$stat['size'];if($total>$options['backup_max_uncompressed_bytes'])throw new RuntimeException('Uncompressed backup content is too large.');}
  $manifest=json_decode(ops_zip_read($zip,'manifest.json',2*1024*1024),true,64,JSON_THROW_ON_ERROR);
  $json=ops_zip_read($zip,'database.json',16*1024*1024);
  if(($manifest['format']??'')!=='civiccare_backup_v1'||!hash_equals((string)($manifest['database_sha256']??''),hash('sha256',$json)))throw new RuntimeException('The backup format or database checksum is invalid.');
  $data=json_decode($json,true,64,JSON_THROW_ON_ERROR);$tables=ops_backup_tables();if(is_array($data))flow_normalize_backup($data,$manifest,$tables);if(!is_array($data)||array_keys($data)!==$tables)throw new RuntimeException('This backup predates province routing or has a different table set. Restore it in its matching application version, then apply the routing migration before creating a new backup.');
  // Older archives can have categories(id,name).
  // Keep checksum validation above intact; normalise this known shape in memory.
  if(($manifest['columns']['categories']??null)===['id','name'] && ops_columns('categories')===['id','name','active']){
   foreach($data['categories'] as &$category){if(!is_array($category)||array_keys($category)!==['id','name'])throw new RuntimeException('Invalid legacy category fields.');$category['active']=1;}unset($category);
   $manifest['columns']['categories']=['id','name','active'];
  }
  foreach($data['categories'] as $category)if(!isset($category['active'])||!in_array((string)$category['active'],['0','1'],true))throw new RuntimeException('Invalid category availability in backup.');
  $adminId=null;$backupMax=0;
  $userColumns=ops_columns('users');
  if(end($userColumns)==='management_role' && ($manifest['columns']['users']??null)===array_slice($userColumns,0,-1)){
   foreach($data['users'] as &$account){
    if(!is_array($account) || array_keys($account)!==array_slice($userColumns,0,-1))throw new RuntimeException('Invalid legacy account fields.');
    $account['management_role']=null;
   }
   unset($account);
   $manifest['columns']['users']=$userColumns;
  }
  $issueColumns=ops_columns('issues');
  if(end($issueColumns)==='public_photo' && ($manifest['columns']['issues']??null)===array_slice($issueColumns,0,-1)){
   foreach($data['issues'] as &$issue){
    if(!is_array($issue) || array_keys($issue)!==array_slice($issueColumns,0,-1))throw new RuntimeException('Invalid legacy issue fields.');
    $issue['public_photo']=null;
   }
   unset($issue);
   $manifest['columns']['issues']=$issueColumns;
  }
  foreach($tables as $table){$columns=ops_columns($table);if(($manifest['columns'][$table]??null)!==$columns || !is_array($data[$table]) || count($data[$table])>100000 || ($manifest['counts'][$table]??-1)!==count($data[$table]))throw new RuntimeException('The backup schema or row counts do not match.');foreach($data[$table] as $row){if(!is_array($row)||array_keys($row)!==$columns)throw new RuntimeException('The backup contains invalid row fields.');foreach($row as $value)if(!is_null($value)&&!is_scalar($value))throw new RuntimeException('Invalid backup value.');}}
  foreach($data['users'] as $account){
   if($account['management_role']!==null && !management_kind($account))throw new RuntimeException('Backup contains an invalid team administration role.');
  }
  foreach($data['issues'] as $issue){
   if($issue['public_photo']!==null && $issue['public_photo']!==$issue['photo'])throw new RuntimeException('Backup contains an invalid public photo reference.');
  }
  geo_validate_backup($data);flow_validate_backup($data);followup_validate_backup($data);
  foreach($data['users'] as $u){$backupMax=max($backupMax,(int)$u['auth_version']);if(strcasecmp($u['email'],$operator['email'])===0 && $u['role']==='admin' && !empty($u['is_system_admin']) && (int)$u['active']===1)$adminId=(int)$u['id'];}
  if(!$adminId)throw new RuntimeException('The backup must contain an active administrator with your email address.');
  $names=ops_photo_names($data);$manifestNames=array_keys($manifest['photos']??[]);sort($manifestNames);if($names!==$manifestNames || count($seen)!==count($names)+2)throw new RuntimeException('The backup photo manifest does not match its records.');
  $issueMap=[];foreach($data['issues'] as $i)$issueMap[(int)$i['id']]=$i;foreach($data['issues'] as $i)if($i['duplicate_of']!==null){$parent=$issueMap[(int)$i['duplicate_of']]??null;if(!$parent||$parent['duplicate_of']!==null||(int)$i['id']===(int)$i['duplicate_of'])throw new RuntimeException('The backup has invalid duplicate-report links.');}
  $upload=__DIR__.'/../storage/uploads/';if(!is_dir($upload)||!is_writable($upload))throw new RuntimeException('The uploads directory is not writable.');
  // Read only exact, validated entries. Never extract archive paths to disk.
  foreach($names as $name){$meta=$manifest['photos'][$name];$info=$zip->statName('photos/'.$name);if(!$info||$info['size']!==(int)($meta['bytes']??-1)||$info['size']>10*1024*1024)throw new RuntimeException('Invalid backup photo size.');$stream=$zip->getStream('photos/'.$name);if(!$stream)throw new RuntimeException('Could not read a backup photo.');$temp=tempnam($upload,'.restore-');$staged[]=$temp;$out=fopen($temp,'wb');$copied=stream_copy_to_stream($stream,$out,$info['size']+1);fclose($stream);fclose($out);if($copied!==$info['size']||!hash_equals((string)$meta['sha256'],hash_file('sha256',$temp)))throw new RuntimeException('A backup photo checksum does not match.');$image=@getimagesize($temp);if(!$image||$image[2]!==IMAGETYPE_JPEG)throw new RuntimeException('A backup photo is not a JPEG image.');if($validateOnly){unlink($temp);continue;}$target=$upload.$name;if(is_file($target)){if(!hash_equals(hash_file('sha256',$target),$meta['sha256']))throw new RuntimeException('An existing photo conflicts with this backup.');unlink($temp);}else{if(!rename($temp,$target))throw new RuntimeException('Could not stage a backup photo.');$newFiles[]=$target;}}
  if($validateOnly){$zip->close();foreach($staged as $temp)if(is_file($temp))@unlink($temp);return ['created_at'=>(string)($manifest['created_at']??'unknown'),'tables'=>count($tables),'photos'=>count($names)];}
  $liveMax=(int)q('SELECT COALESCE(MAX(auth_version),0) FROM users')->fetchColumn();$generation=max($liveMax,$backupMax)+1;
  db()->beginTransaction();q('UPDATE issues SET duplicate_of=NULL');foreach(array_reverse($tables) as $table)q('DELETE FROM `'.$table.'`');$duplicates=[];
  foreach($tables as $table){$columns=ops_columns($table);$statement=db()->prepare('INSERT INTO `'.$table.'` (`'.implode('`,`',$columns).'`) VALUES('.implode(',',array_fill(0,count($columns),'?')).')');foreach($data[$table] as $row){if($table==='issues'&&$row['duplicate_of']!==null){$duplicates[]=[(int)$row['duplicate_of'],$row['updated_at'],(int)$row['id']];$row['duplicate_of']=null;}if($table==='users'){$row['auth_version']=$generation;if((int)$row['id']===$adminId)$row['password']=$operator['password'];}$statement->execute(array_values($row));}}
  foreach($duplicates as $link)q('UPDATE issues SET duplicate_of=?,updated_at=? WHERE id=?',$link);
  foreach(['recovery_codes','admin_reset_tokens','email_verifications','login_attempts','action_rate_buckets'] as $table)q('DELETE FROM `'.$table.'`');
  q('INSERT INTO audit_log(actor_id,action,details) VALUES(?,?,?)',[$adminId,'system.backup_restored','Backup from '.substr((string)($manifest['created_at']??'unknown'),0,40).'; administrator password preserved; sessions and recovery/verification tokens invalidated.']);
  db()->commit();$zip->close();foreach($staged as $temp)if(is_file($temp))@unlink($temp);return;
 }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();foreach($newFiles as $path)@unlink($path);foreach($staged as $temp)if(is_file($temp))@unlink($temp);$zip->close();if($e instanceof RuntimeException && !($e instanceof PDOException))throw $e;error_log('CivicCare restore validation/transaction failed.');throw new RuntimeException('Restore failed. The database transaction was rolled back. Confirm that the backup matches this application version.');}
}
