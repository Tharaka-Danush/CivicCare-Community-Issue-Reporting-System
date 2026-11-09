<?php
function handle_staff_action($action){
 if($action==='create_area'){
  require_role('admin');$name=field('area_name',2,100);
  if(q('SELECT id FROM service_areas WHERE name=?',[$name])->fetch())throw new RuntimeException('That service area already exists.');
  db()->beginTransaction();q('INSERT INTO service_areas(name) VALUES(?)',[$name]);audit('area.created',$name);db()->commit();flash('Service area added.');go('index.php?page=areas');
 }
 if($action==='officer_coverage'){
  require_role('admin');$officer=(int)($_POST['officer_id']??0);$ids=$_POST['area_ids']??[];
  if(!is_array($ids))throw new RuntimeException('Choose valid coverage areas.');$ids=array_unique(array_map('intval',$ids));if(isset($_POST['coverage_scope'])){if(!in_array($_POST['coverage_scope'],['all','selected'],true))throw new RuntimeException('Choose a valid coverage mode.');if($_POST['coverage_scope']==='all')$ids=[];elseif(!$ids)throw new RuntimeException('Select at least one service area.');}
  db()->beginTransaction();$u=q("SELECT id FROM users WHERE id=? AND role='officer' AND active=1 FOR UPDATE",[$officer])->fetch();if(!$u)throw new RuntimeException('Choose an active officer.');
  foreach($ids as $id)if($id<=0 || !q('SELECT id FROM service_areas WHERE id=?',[$id])->fetch())throw new RuntimeException('Invalid service area.');
  // Do not change coverage so that an officer's existing open work falls outside it.
  if($ids){$marks=implode(',',array_fill(0,count($ids),'?'));$p=array_merge([$officer],array_values($ids));if(q("SELECT id FROM issues WHERE officer_id=? AND status NOT IN ('Resolved','Rejected','Duplicate') AND area_id IS NOT NULL AND area_id NOT IN ($marks) LIMIT 1",$p)->fetch())throw new RuntimeException('Reassign open reports outside this coverage before changing the areas.');}
  q('DELETE FROM officer_areas WHERE officer_id=?',[$officer]);foreach($ids as $id)q('INSERT INTO officer_areas(officer_id,area_id) VALUES(?,?)',[$officer,$id]);audit('officer.coverage','Officer #'.$officer.' areas: '.($ids?implode(',',$ids):'unrestricted'));db()->commit();flash('Officer coverage updated.');go('index.php?page=areas');
 }
 if($action==='progress_photo'){
  require_role(['admin','officer']);$id=(int)($_POST['issue_id']??0);$photo=null;
  try{db()->beginTransaction();$i=q('SELECT * FROM issues WHERE id=? FOR UPDATE',[$id])->fetch();
   if(!$i || (user()['role']!=='admin' && $i['officer_id']!=user()['id']))throw new RuntimeException('This report is not assigned to you.');
   if(!in_array($i['status'],['Assigned','In Progress','Awaiting Approval'],true))throw new RuntimeException('Progress photos can be added only to assigned, active or pending-approval work.');
   $note=field('progress_note',3,2000);$photo=photo_upload('progress_photo',true);
   q('INSERT INTO issue_progress_photos(issue_id,user_id,filename,note,phase) VALUES(?,?,?,?,?)',[$id,user()['id'],$photo,$note,'progress']);event_add($id,'progress_photo',$note);audit('issue.progress_photo','Issue #'.$id);notify_issue($i,'New progress photo on issue #'.$id);db()->commit();
  }catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();remove_photo($photo);throw $ex;}
  flash('Progress photo added.');go('index.php?page=issue&id='.$id);
 }
}
