<?php
function maintenance_home($role){return $role==='maintenance'?'team_work':($role==='officer'?'assigned':'dashboard');}
function maintenance_release($i,$reason='Maintenance assignment cleared because the supervising officer changed.'){
 if(!$i['maintenance_id'])return;
 q('INSERT INTO notifications(user_id,issue_id,message) VALUES(?,?,?)',[$i['maintenance_id'],$i['id'],'Issue #'.$i['id'].' is no longer assigned to your maintenance team.']);
 q('UPDATE issues SET maintenance_id=NULL,maintenance_stage=NULL,maintenance_photo=NULL,maintenance_note=NULL,maintenance_version=maintenance_version+1 WHERE id=?',[$i['id']]);
 event_add($i['id'],'team_assignment',$reason);
}
function archive_resolution_evidence($issue){
 $photo=$issue['resolution_photo'];
 if($photo&&!q('SELECT id FROM issue_progress_photos WHERE issue_id=? AND filename=?',[$issue['id'],$photo])->fetchColumn()){
  q("INSERT INTO issue_progress_photos(issue_id,user_id,filename,note,phase) VALUES(?,?,?,?,'completion')",
    [$issue['id'],user()['id'],$photo,'Completion evidence from the previous review.']);
 }
}
function maintenance_return($i,$note){q("UPDATE issues SET maintenance_stage='Returned',maintenance_photo=NULL,maintenance_note=?,maintenance_version=maintenance_version+1 WHERE id=?",[$note,$i['id']]);}
function maintenance_panel($i){
 if(!in_array(user()['role'],['admin','officer'],true))return;
 if(user()['role']==='officer' && (int)$i['officer_id']!==(int)user()['id'])return;
 $can=user()['role']==='officer' && in_array($i['status'],['Assigned','In Progress'],true) && $i['officer_id'];
 $handover=geo_system() && $i['officer_id'] && $i['maintenance_id'] && in_array($i['status'],['Assigned','In Progress','Awaiting Approval'],true);
?><section class="panel" id="maintenance-work"><h3>Maintenance team</h3><p class="small">Team: <strong><?=e($i['maintenance_id']?(q('SELECT name FROM users WHERE id=?',[$i['maintenance_id']])->fetchColumn()?:'Unavailable'):'Not assigned')?></strong><br>Work stage: <?=e($i['maintenance_stage']?:'Not started')?></p>
<?php if($can||$handover):?><details <?=!$i['maintenance_id']?'open':''?>><summary><?=$i['maintenance_id']?'Change maintenance team':'Assign a maintenance team'?></summary><form method="post" data-confirm="Assign this work to the selected maintenance team? Previous photos and history are retained. The replacement team must submit its own completion evidence."><?php csrf();?><input type="hidden" name="action" value="team_assign"><input type="hidden" name="issue_id" value="<?=$i['id']?>"><input type="hidden" name="team_version" value="<?=$i['maintenance_version']?>"><label>Team<select name="maintenance_id" required><option value="">Choose category-qualified team for this town</option><?php foreach(q("SELECT u.id,u.name,(SELECT COUNT(*) FROM issues i WHERE i.maintenance_id=u.id AND i.status NOT IN ('Resolved','Rejected','Duplicate')) workload FROM users u WHERE u.role='maintenance' AND u.active=1 AND EXISTS(SELECT 1 FROM team_categories tc WHERE tc.team_id=u.id AND tc.category_id=?) AND u.town_id=? ORDER BY workload,u.name",[$i['category_id'],$i['town_id']])->fetchAll() as $t):?><option value="<?=$t['id']?>"><?=e($t['name'])?> · <?=$t['workload']?> open</option><?php endforeach;?></select></label><label><?=$i['maintenance_id']?'Handover reason and work instructions':'Work instructions'?><textarea name="note" minlength="3" maxlength="2000" required></textarea></label><button class="button secondary">Assign team</button><p class="small muted">Province Admin creates town-scoped team logins under Province routing. Configure service categories under Workflow queues → Team categories. The supervising officer stays responsible for this issue.</p></form></details><?php endif;?>
<?php if($i['maintenance_note']):?><p class="small preline"><?=e($i['maintenance_note'])?></p><?php endif;?>
<?php if($i['maintenance_stage']==='Review' && $can):?><h4>Team completion · officer review</h4><?php if($i['maintenance_photo']):?><img class="resolution-photo" src="media.php?file=<?=e($i['maintenance_photo'])?>" alt="Team completion evidence"><?php endif;?><form method="post"><?php csrf();?><input type="hidden" name="action" value="team_review"><input type="hidden" name="issue_id" value="<?=$i['id']?>"><input type="hidden" name="team_version" value="<?=$i['maintenance_version']?>"><label>Decision<select name="decision"><option value="approve">Send to administrator for final approval</option><option value="return">Return to team for more work</option></select></label><label>Review note<textarea name="note" minlength="3" maxlength="2000" required></textarea></label><button class="button">Save team review</button></form><?php endif;?></section><?php
}
