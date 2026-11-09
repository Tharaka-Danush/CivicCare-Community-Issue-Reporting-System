<?php
function team_scope(){
 static $scope=false;if($scope===false)$scope=geo_raw('SELECT t.id town_id,t.name town,t.active,d.id district_id,d.name district,p.id province_id,p.name province,u.name district_officer,u.active officer_active FROM geo_towns t JOIN geo_districts d ON d.id=t.district_id JOIN geo_provinces p ON p.id=d.province_id LEFT JOIN users u ON u.id=d.officer_id WHERE t.id=?',[(int)(user()['town_id']??0)])->fetch()?:null;
 return $scope;
}
function team_scope_banner($compact=false){
 $scope=team_scope();
 if($compact){
  if(!$scope || !$scope['active']):?>
   <p class="team-setup-warning team-setup-compact">Town setup needs attention. <a class="text-link" href="index.php?page=team_work">View team setup →</a></p>
  <?php endif;
  return;
 }
 if(!$scope):?><section class="team-setup-warning" role="status"><strong>Setup incomplete — Town assignment required</strong><p>Your account is not linked to a maintenance town. The System Admin must configure it before new work can be assigned.</p><details><summary>How to complete setup</summary><ol><li>Ask the System Admin to open <strong>Province routing → Scoped accounts → Configure an existing account</strong>.</li><li>Choose <strong>Town Maintenance Team mapping</strong>, select your account and its correct district/town, then save.</li><li>Sign in again after the scope is saved.</li></ol><p class="small">Quote team account #<?=(int)user()['id']?> · <?=e(user()['email'])?>. Your town cannot be changed from this team login.</p><label class="sr-only" for="team-setup-message">Setup request details</label><textarea id="team-setup-message" class="team-copy-source" readonly>Please configure Town Maintenance Team account #<?=(int)user()['id']?> (<?=e(user()['email'])?>) under Province routing → Scoped accounts → Town Maintenance Team mapping. Confirm the correct province, district and town before saving.</textarea><button type="button" class="button secondary" data-team-copy="team-setup-message">Copy setup details</button><span class="small" data-team-copy-status role="status"></span></details></section><?php
 else:?><div class="team-scope-strip"><span><strong><?=e($scope['town'])?></strong> maintenance area <small><?=e($scope['province'])?> Province / <?=e($scope['district'])?> District</small></span><span class="small">District Officer: <?=e($scope['district_officer']&&$scope['officer_active']?$scope['district_officer']:'Not configured / unavailable')?></span></div><?php if(!$scope['active']):?><p class="team-setup-warning small">This town is currently inactive for new reports. Ask the System Admin to review coverage. Existing work remains visible.</p><?php endif;endif;
}
function team_queues(){return [
 'blocked'=>['Blocked / waiting',"i.status='In Progress' AND i.maintenance_stage='Blocked'"],
 'open'=>['All open',"i.status NOT IN ('Resolved','Rejected','Duplicate')"],
 'assigned'=>['Not started',"i.status IN ('Assigned','In Progress') AND i.maintenance_stage='Assigned'"],
 'progress'=>['In progress',"i.status='In Progress' AND i.maintenance_stage='In Progress'"],
 'returned'=>['Returned for more work',"i.status='In Progress' AND i.maintenance_stage='Returned'"],
 'review'=>['Officer review',"i.status IN ('Assigned','In Progress') AND i.maintenance_stage='Review'"],
 'approval'=>['Province approval',"i.status='Awaiting Approval'"],
 'closed'=>['Closed',"i.status IN ('Resolved','Rejected','Duplicate')"]
 ];}
function team_counts(){
 $parts=[];foreach(team_queues() as $key=>$q)$parts[]='COALESCE(SUM('.$q[1].'),0) `'.$key.'`';
 return q('SELECT '.implode(',',$parts).' FROM issues i WHERE i.maintenance_id=?',[user()['id']])->fetch();
}
function team_filter(){
 $queues=team_queues();$view=(string)($_GET['view']??'open');if(!isset($queues[$view]))$view='open';$search=trim(substr((string)($_GET['search']??''),0,160));$priority=(string)($_GET['priority']??'');if(!in_array($priority,['High','Medium','Low'],true))$priority='';$sort=(string)($_GET['sort']??'priority');$orders=['priority'=>"FIELD(i.priority,'High','Medium','Low'),i.created_at,i.id",'due'=>"COALESCE(i.due_date,DATE_ADD(DATE(i.created_at),INTERVAL 7 DAY)),i.id",'updated'=>'i.updated_at DESC,i.id DESC','newest'=>'i.created_at DESC,i.id DESC'];if(!isset($orders[$sort]))$sort='priority';
 $w=['i.maintenance_id=?','('.$queues[$view][1].')'];$params=[user()['id']];if($priority){$w[]='i.priority=?';$params[]=$priority;}if($search!==''){$w[]='(i.title LIKE ? OR i.address LIKE ? OR i.id=?)';$params[]='%'.$search.'%';$params[]='%'.$search.'%';$params[]=(int)ltrim($search,'#');}
 return ['view'=>$view,'search'=>$search,'priority'=>$priority,'sort'=>$sort,'order'=>$orders[$sort],'sql'=>' WHERE '.implode(' AND ',$w),'params'=>$params];
}
function team_stage_label($i){
 if(in_array($i['status'],['Resolved','Rejected','Duplicate'],true))return $i['status']==='Resolved'?'Completed and approved':$i['status'];if($i['status']==='Awaiting Approval')return 'Province Admin approval pending';
 return ['Blocked'=>'Blocked — District Officer follow-up','Officer Return'=>'Province returned — District Officer review','Assigned'=>'Not started','In Progress'=>'Work in progress','Returned'=>'Returned for more work','Review'=>'District Officer review pending','Approved'=>'Officer approved'][$i['maintenance_stage']]??'Officer attention needed';
}
function team_next_action($i){
 $active=in_array($i['status'],['Assigned','In Progress'],true);if($active&&$i['maintenance_stage']==='Assigned')return ['Start work','start'];if($active&&$i['maintenance_stage']==='Returned')return ['Review feedback / resume','start'];if($active&&$i['maintenance_stage']==='In Progress')return ['Update progress / completion','update'];if($active&&$i['maintenance_stage']==='Blocked')return ['Review blocker / resume','workflow'];return ['View details','details'];
}
function team_deadline($i){return [$i['due_date']?:date('Y-m-d',strtotime($i['created_at'].' +7 days')),$i['due_date']?'Due date':'Attention date'];}
function team_instruction($id,$team){
 $prefix='Maintenance team assigned: #'.(int)$team.'. ';$r=q("SELECT ev.*,u.name FROM issue_events ev LEFT JOIN users u ON u.id=ev.user_id WHERE ev.issue_id=? AND ev.kind='maintenance' AND ev.message LIKE ? ORDER BY ev.id DESC LIMIT 1",[$id,$prefix.'%'])->fetch();if($r)$r['message']=substr($r['message'],strlen($prefix));return $r;
}
function team_feedback($id){return q("SELECT ev.*,u.name FROM issue_events ev JOIN users u ON u.id=ev.user_id WHERE ev.issue_id=? AND ((ev.kind='maintenance' AND ev.message LIKE 'Officer returned work%') OR (ev.kind='status' AND ev.message LIKE 'Awaiting Approval → In Progress.%')) ORDER BY ev.id DESC LIMIT 1",[$id])->fetch();}
function team_coverage_panel(){
 if (management_without_town()) { ?>
  <section class="panel team-coverage">
   <h2>Nationwide maintenance administration</h2>
   <p>Manage Maintenance Team accounts, town mappings and service categories across Sri Lanka.</p>
   <p class="small muted">Login email: <?=e(user()['email'])?></p>
   <a class="text-link" href="index.php?page=management">Open Maintenance Team Admin →</a>
  </section>
 <?php return; }
 $scope=team_scope();
 $categories=geo_raw('SELECT c.name FROM team_categories tc JOIN categories c ON c.id=tc.category_id WHERE tc.team_id=? AND c.active=1 ORDER BY c.name',[(int)user()['id']])->fetchAll(PDO::FETCH_COLUMN);
 $ready=$scope && $scope['active'] && $categories;
 ?><section class="panel team-coverage"><div class="row between"><h2>Service coverage</h2><span class="badge <?=$ready?'b-active':'b-action-needed'?>"><?=$ready?'Configured':'Setup required'?></span></div><dl><div><dt>Province</dt><dd><?=e($scope['province']??'Not configured')?></dd></div><div><dt>District</dt><dd><?=e($scope['district']??'Not configured')?></dd></div><div><dt>Town / maintenance area</dt><dd><?=e($scope['town']??'Not configured')?></dd></div><div><dt>Supervising District Officer</dt><dd><?=e($scope&&$scope['district_officer']&&$scope['officer_active']?$scope['district_officer']:'Not configured / unavailable')?></dd></div><div><dt>Login email</dt><dd><?=e(user()['email'])?></dd></div><div><dt>Service categories</dt><dd><?=e($categories?implode(', ',$categories):'Ask your System Admin or District Officer to configure categories.')?></dd></div></dl><p class="small muted">Read-only coverage. The System Admin manages town assignments; the District Officer dispatches this town’s reports to your team.</p></section><?php
}
function team_job_card($i){$next=team_next_action($i);[$date,$label]=team_deadline($i);$late=in_array($i['status'],['Assigned','In Progress'],true)&&in_array($i['maintenance_stage'],['Assigned','In Progress','Returned'],true)&&($i['due_date']?$i['due_date']<date('Y-m-d'):strtotime($i['created_at'])<time()-7*86400);?><article class="team-job-card"><div class="row between"><span class="priority-chip p-<?=strtolower($i['priority'])?>"><?=e($i['priority'])?> priority</span><small class="muted">#<?=str_pad($i['id'],4,'0',STR_PAD_LEFT)?></small></div><h3><a href="index.php?page=team_work&amp;id=<?=$i['id']?>"><?=e($i['title'])?></a></h3><p class="team-stage stage-<?=e(strtolower(str_replace(' ','-',$i['maintenance_stage']?:'unknown')))?>"><?=e(team_stage_label($i))?></p><p class="small muted"><?=e($i['address'])?></p><dl class="team-job-meta"><div><dt>Town</dt><dd><?=e($i['town_name']?:'Not configured')?></dd></div><div><dt>District Officer</dt><dd><?=e($i['officer']?:'Unavailable')?></dd></div><div><dt><?=e($label)?></dt><dd class="<?=$late?'overdue-label':''?>"><?=e($date)?><?=$late?' · Needs attention':''?></dd></div><div><dt>Updated</dt><dd><?=e(date('d M Y, H:i',strtotime($i['updated_at'])))?></dd></div></dl><?php if($i['maintenance_stage']==='Returned' && trim((string)$i['maintenance_note'])!==''):?>
 <div class="team-return-reason"><strong>Return reason</strong><p class="preline"><?=e($i['maintenance_note'])?></p></div>
<?php endif;?><a class="button <?=$next[1]==='details'?'secondary':''?>" href="index.php?page=team_work&amp;id=<?=$i['id']?><?=$next[1]==='details'?'':($next[1]==='workflow'?'#workflow':'#team-action')?>"><?=e($next[0])?> →</a></article><?php
}
