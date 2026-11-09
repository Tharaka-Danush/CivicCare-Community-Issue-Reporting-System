<?php
function photo_evidence_visible($issue) {
 return user()['role'] !== 'citizen' || (int)user()['id'] === (int)$issue['user_id'];
}

function photo_primary_visible($issue) {
 return photo_evidence_visible($issue) || photo_primary_published($issue);
}

function photo_primary_published($issue) {
 return (
  !empty($issue['public_photo']) && $issue['public_photo'] === $issue['photo']
  && !in_array($issue['status'], ['Rejected','Duplicate'], true)
 );
}

function report_photo($issue,$class='',$lazy=false) {
 if (photo_primary_visible($issue)) { ?>
  <img class="<?=e($class)?>" src="media.php?file=<?=e($issue['photo'])?>" alt="<?=e($issue['title'])?>" <?=$lazy?'loading="lazy"':''?>>
 <?php } else { ?>
  <div class="<?=e($class)?> photo-review-placeholder"><strong><?=e($issue['category'])?></strong><small>Photo unavailable to the community</small></div>
 <?php }
}

function photo_visibility_action($action) {
 if ($action !== 'review_photo_visibility') return;
 require_role('admin');
 $id = (int)($_POST['issue_id'] ?? 0);
 $issue = geo_require_issue($id);
 $decision = $_POST['visibility'] ?? '';
 $snapshot = $_POST['photo_snapshot'] ?? '';
 if (!is_string($snapshot) || !hash_equals($issue['photo'],$snapshot)) {
  throw new RuntimeException('The report photo changed. Review the current photo before saving.');
 }
 if (!in_array($decision,['publish','hide'],true)) throw new RuntimeException('Choose a photo visibility decision.');
 if ($decision === 'publish' && in_array($issue['status'],['Rejected','Duplicate'],true)) {
  throw new RuntimeException('Photos on rejected or duplicate reports remain private.');
 }
 db()->beginTransaction();
 q('UPDATE issues SET public_photo=? WHERE id=?',[$decision === 'publish' ? $issue['photo'] : null,$id]);
 oversight_audit_change('issue.photo_visibility','Issue #'.$id,['published'=>photo_primary_published($issue)],['published'=>$decision==='publish'],$decision==='publish'?'Approved for community viewing.':'Withdrawn from community viewing.');
 db()->commit();
 flash($decision === 'publish' ? 'Report photo approved for the community.' : 'Report photo hidden from the community.');
 go('index.php?page=issue&id='.$id.'#photo-review');
}

function photo_visibility_panel($issue) {
 if (user()['role'] !== 'admin' || !geo_can_manage($issue)) return;
 $published = photo_primary_published($issue);
 ?>
 <section class="panel section-space" id="photo-review">
  <h3>Community photo visibility</h3>
  <p><?=$published?'Approved for the community.':'Visible only to the reporter and staff who can access this report.'?></p>
  <?php if (!in_array($issue['status'],['Rejected','Duplicate'],true)): ?>
  <p class="small muted">Approve only if the image shows the issue and is suitable for community viewing. Progress and completion photos stay private.</p>
  <form method="post">
   <?php csrf(); ?>
   <input type="hidden" name="action" value="review_photo_visibility">
   <input type="hidden" name="issue_id" value="<?=(int)$issue['id']?>">
   <input type="hidden" name="photo_snapshot" value="<?=e($issue['photo'])?>">
   <button class="button secondary" name="visibility" value="<?=$published?'hide':'publish'?>"><?=$published?'Hide from community':'Approve report photo'?></button>
  </form>
  <?php endif; ?>
 </section>
 <?php
}
