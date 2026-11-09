<?php
function assignment_revision($i) {return hash('sha256',json_encode(array_map(function($k)use($i){return (string)($i[$k]??'');},['officer_id','status','priority','due_date','area_id','province_id','district_id','town_id','updated_at','maintenance_version','resolution_photo'])));}
function handle_assignment_action($action) {
 if($action!=='assign_officer')return;
 require_role('admin');
 $id=(int)($_POST['issue_id']??0);$officer=(int)($_POST['officer_id']??0);
 $note=field('note',3,2000);$due=valid_date(trim((string)($_POST['due_date']??'')),'due');
 $priority=(string)($_POST['priority']??'');
 if(!in_array($priority,['Low','Medium','High'],true))throw new RuntimeException('Choose a valid priority.');
 db()->beginTransaction();
 $i=q('SELECT * FROM issues WHERE id=? FOR UPDATE',[$id])->fetch();
 if(!$i || $i['duplicate_of'] || !in_array($i['status'],['Verified','Assigned','In Progress'],true))throw new RuntimeException('Only verified or active assigned reports can be assigned here. Review Submitted reports first.');
 // Reject a stale form rather than overwrite another administrator's assignment.
 if(!hash_equals(assignment_revision($i),(string)($_POST['previous_revision']??'')))throw new RuntimeException('This report changed. Refresh and review its current assignment.');
 if(!$officer || !q("SELECT id FROM users WHERE id=? AND role='officer' AND active=1 FOR UPDATE",[$officer])->fetch())throw new RuntimeException('Choose an active officer.');
 if(!officer_eligible($officer,$i['area_id'],true))throw new RuntimeException('This officer does not cover the report area. Choose a matching or unrestricted officer.');
 $status=$i['status']==='Verified'?'Assigned':$i['status'];
 if((int)$i['officer_id']!==$officer)maintenance_release($i);
 q('UPDATE issues SET officer_id=?,status=?,priority=?,due_date=? WHERE id=?',[$officer,$status,$priority,$due,$id]);
 if((int)$i['officer_id']!==$officer){geo_raw('INSERT IGNORE INTO issue_flow(issue_id) VALUES(?)',[$id]);geo_raw('UPDATE issue_flow SET accepted_by=NULL,accepted_at=NULL,block_kind=NULL,block_reason=NULL,next_review=NULL,version=version+1 WHERE issue_id=?',[$id]);}
 event_add($id,'status','Officer: '.($i['officer_id']?'#'.$i['officer_id']:'unassigned').' → #'.$officer.'. '.$i['status'].' → '.$status.'. Priority: '.$priority.'. Due: '.($due?:'7-day attention indicator').'. '.$note);
 if($i['officer_id'] && (int)$i['officer_id']!==$officer)q('INSERT INTO notifications(user_id,issue_id,message) VALUES(?,?,?)',[$i['officer_id'],$id,'Issue #'.$id.' has been reassigned to another officer.']);
 $before=array_intersect_key($i,array_flip(['officer_id','status','priority','due_date']));
 $before['officer']=$before['officer_id'];unset($before['officer_id']);
 $i['officer_id']=$officer;notify_issue($i,'Officer assignment updated for issue #'.$id.'. Status: '.$status);
 oversight_audit_change('issue.assigned','Issue #'.$id,$before,['officer'=>$officer,'status'=>$status,'priority'=>$priority,'due_date'=>$due],$note);db()->commit();
 flash('Issue #'.$id.' assigned successfully.');go('index.php?page=assignments&view='.'assigned'.'&id='.$id);
}
