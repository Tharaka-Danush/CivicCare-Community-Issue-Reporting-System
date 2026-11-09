<?php
function team_upload_request(){
 return ($_GET['page']??'')==='team_work'
  && ($_SERVER['HTTP_X_CIVICCARE_UPLOAD']??'')==='team-evidence'
  && in_array($_POST['action']??'',['team_progress','team_submit',''],true);
}

function handle_maintenance_action($action){
 if(!in_array($action,['team_assign','team_start','team_progress','team_submit','team_review'],true))return;
 require_login();$photo=null;
 try{
  if(in_array($action,['team_assign','team_review'],true))require_role(['admin','officer']);else require_role('maintenance');
  ops_rate_limit('team_work');db()->beginTransaction();$id=(int)($_POST['issue_id']??0);$i=q('SELECT * FROM issues WHERE id=? FOR UPDATE',[$id])->fetch();
  $handover=$action==='team_assign'&&geo_system()&&!empty($i['maintenance_id']);
  if($action==='team_assign'&&geo_system()&&!$handover)throw new RuntimeException('Only the supervising District Officer can make the initial team assignment.');
  $allowedStatuses=$handover?['Assigned','In Progress','Awaiting Approval']:['Assigned','In Progress'];
  if(!$i || $i['duplicate_of'] || !in_array($i['status'],$allowedStatuses,true) || !$i['officer_id'])throw new RuntimeException('This report is not available for maintenance work.');
  if(user()['role']==='officer' && (int)$i['officer_id']!==(int)user()['id'])throw new RuntimeException('This report is not assigned to you.');
  if(user()['role']==='maintenance' && (int)$i['maintenance_id']!==(int)user()['id']){http_response_code(403);throw new RuntimeException('This report is not assigned to your maintenance team.');}
  if(!isset($_POST['team_version']) || (string)$i['maintenance_version']!==(string)$_POST['team_version'])throw new RuntimeException('This team assignment changed. Refresh and review the current work.');
  $note=field('note',3,2000);
  if($action==='team_assign'){
   $team=(int)($_POST['maintenance_id']??0);if(!q("SELECT id FROM users WHERE id=? AND role='maintenance' AND active=1 FOR UPDATE",[$team])->fetch())throw new RuntimeException('Choose an active maintenance team.');
   if((int)$i['maintenance_id']===$team)throw new RuntimeException('This team already has the work. Use the review controls to return completion for more work.');
   if($i['maintenance_id'])q('INSERT INTO notifications(user_id,issue_id,message) VALUES(?,?,?)',[$i['maintenance_id'],$id,'Issue #'.$id.' has been reassigned to another maintenance team.']);
   $before=['team'=>$i['maintenance_id'],'stage'=>$i['maintenance_stage'],'status'=>$i['status']];
   $status=$i['status']==='Awaiting Approval'?'In Progress':$i['status'];
   archive_resolution_evidence($i);
   oversight_audit_change('issue.team_assign','Issue #'.$id,$before,['team'=>$team,'stage'=>'Assigned','status'=>$status],$note);
   q("UPDATE issues SET status=?,resolution_photo=NULL,maintenance_id=?,maintenance_stage='Assigned',maintenance_photo=NULL,maintenance_note=?,maintenance_version=maintenance_version+1 WHERE id=?",[$status,$team,$note,$id]);geo_raw('UPDATE issue_flow SET block_kind=NULL,block_reason=NULL,next_review=NULL,version=version+1 WHERE issue_id=?',[$id]);$i['maintenance_id']=$team;$message='Maintenance team assigned: #'.$team.'. '.$note;
  }elseif($action==='team_review'){
   if($i['maintenance_stage']!=='Review' || !$i['maintenance_photo'])throw new RuntimeException('There is no submitted team completion to review.');
   $decision=(string)($_POST['decision']??'');if(!in_array($decision,['approve','return'],true))throw new RuntimeException('Choose a valid review decision.');
   if($decision==='approve'){
    q("UPDATE issues SET maintenance_stage='Approved',maintenance_note=?,maintenance_version=maintenance_version+1,status='Awaiting Approval',resolution_photo=? WHERE id=?",[$note,$i['maintenance_photo'],$id]);$message='Officer reviewed team completion and sent it for administrator approval. '.$note;notify_admins($i,'Maintenance completion awaiting final approval: issue #'.$id);
   }else{maintenance_return($i,$note);q("UPDATE issues SET status='In Progress' WHERE id=?",[$id]);$message='Officer returned work to the maintenance team. '.$note;}
  }else{
   if($action==='team_start'){
    if(!in_array($i['maintenance_stage'],['Assigned','Returned'],true))throw new RuntimeException('This team work has already started.');
    q("UPDATE issues SET status='In Progress',maintenance_stage='In Progress',maintenance_note=?,maintenance_version=maintenance_version+1 WHERE id=?",[$note,$id]);$message=($i['maintenance_stage']==='Returned'?'Maintenance team resumed returned work. ':'Maintenance team started work. ').$note;
   }else{
    if($i['maintenance_stage']!=='In Progress')throw new RuntimeException('Start the assigned work before uploading progress or completion.');
    $photo=photo_upload('work_photo',true);$completion=$action==='team_submit';
    q('INSERT INTO issue_progress_photos(issue_id,user_id,filename,note,phase) VALUES(?,?,?,?,?)',[$id,user()['id'],$photo,$note,$completion?'completion':'progress']);
    q('UPDATE issues SET maintenance_stage=?,maintenance_photo=?,maintenance_note=?,maintenance_version=maintenance_version+1 WHERE id=?',[$completion?'Review':'In Progress',$completion?$photo:$i['maintenance_photo'],$note,$id]);$message=$completion?'Maintenance team submitted completion for officer review. '.$note:'Maintenance team added progress evidence. '.$note;
   }
  }
  event_add($id,'maintenance',$message);if($action!=='team_assign')audit('issue.'.$action,'Issue #'.$id);notify_issue($i,$message);db()->commit();
 }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();remove_photo($photo);throw $e;}
 flash('Maintenance work updated.');
 if(team_upload_request()){
  header('Content-Type: application/json');
  echo json_encode(['saved'=>true]);
  exit;
 }
 go('index.php?page='.(user()['role']==='maintenance'?'team_work':'issue').'&id='.$id);
}
