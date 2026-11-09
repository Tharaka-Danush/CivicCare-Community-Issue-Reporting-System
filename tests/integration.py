"""Run ONLY against a fresh disposable database/server: python3 tests/integration.py URL.
Requires requests and Pillow. Creates test accounts/reports and exercises real HTTP flows.
"""
import io, re, sys
import requests
from PIL import Image

BASE=(sys.argv[1] if len(sys.argv)>1 else 'http://127.0.0.1:8765').rstrip('/')
PASS='TestPass2026!'
checks=0

def check(ok, label):
 global checks
 assert ok, label
 checks+=1
 print('PASS',label)

def get(s,path='index.php',code=200):
 r=s.get(BASE+'/'+path,timeout=30)
 check(r.status_code==code, f'GET {path} -> {code}')
 return r

def token(s,page='index.php?page=login'):
 r=get(s,page)
 m=re.search(r'name="csrf" value="([a-f0-9]+)"',r.text)
 assert m,'CSRF missing on '+r.url
 return m.group(1)

def post(s,data,page='index.php?page=login',files=None):
 data={'csrf':token(s,page),**data}
 return s.post(BASE+'/'+page,data=data,files=files,timeout=30)

def login(email):
 s=requests.Session()
 r=post(s,{'action':'login','email':email,'password':PASS})
 check('page=dashboard' in r.url,'login '+email)
 return s

def image():
 b=io.BytesIO();Image.new('RGB',(80,80),(80,130,90)).save(b,'PNG')
 return ('test.png',b.getvalue(),'image/png')

def create(s,title,lat='6.8500',category=1):
 r=post(s,{'action':'create_issue','title':title,'description':'A damaged area affecting people in this neighbourhood.','address':'Test Road, Maharagama','latitude':lat,'longitude':'79.9200','category_id':category},'index.php?page=report',{'photo':image()})
 check('page=issue&id=' in r.url,'create '+title)
 return int(re.search(r'id=(\d+)',r.url).group(1))

def update(s,i,status,officer=None,photo=False):
 data={'action':'update_issue','issue_id':i,'status':status,'note':'Integration test progress update.'}
 if officer is not None:data.update(officer_id=officer,priority='High')
 return post(s,data,'index.php?page=issue&id='+str(i),{'resolution_photo':image()} if photo else None)

admin=requests.Session()
r=post(admin,{'name':'Test Admin','email':'admin@test.local','password':PASS},'setup.php')
check('Administrator created' in r.text,'fresh administrator setup')
get(admin,'setup.php',403)
admin=login('admin@test.local')
for name,email,role in [('Officer One','officer1@test.local','officer'),('Officer Two','officer2@test.local','officer')]:
 r=post(admin,{'action':'create_user','name':name,'email':email,'password':PASS,'role':role},'index.php?page=users')
 check('User created' in r.text,'admin creates '+role)
users=get(admin,'index.php?page=users').text
# Form rows identify IDs used by officer access management.
officer_ids={}
for row in re.findall(r'<tr>(.*?)</tr>',users,re.S):
 if 'officer' in row:
  m=re.search(r'name="user_id" value="(\d+)"',row)
  if m:
   for email in ['officer1@test.local','officer2@test.local']:
    if email in row:officer_ids[email]=int(m.group(1))
check(len(officer_ids)==2,'officer IDs available')
for name,email in [('Citizen One','citizen1@test.local'),('Citizen Two','citizen2@test.local')]:
 s=requests.Session()
 r=post(s,{'action':'register','name':name,'email':email,'password':PASS,'role':'admin'},'index.php?page=register')
 check('Account created' in r.text,'citizen registration')
c1=login('citizen1@test.local');c2=login('citizen2@test.local')
o1=login('officer1@test.local');o2=login('officer2@test.local')
get(c1,'index.php?page=users',403);get(c1,'index.php?page=export',403)
get(o1,'index.php?page=audit',403)
r=get(c1,'index.php?page=profile');check('Citizen' in r.text,'role injection ignored')
r=c1.post(BASE+'/index.php?page=report',data={'action':'create_issue','csrf':'bad'},timeout=30)
check('session expired' in r.text,'bad CSRF rejected')
r=post(c1,{'action':'create_issue','title':'Invalid coordinates','description':'This report has invalid coordinates for the map.','category_id':1,'address':'Test Road','latitude':'100','longitude':'80'},'index.php?page=report',{'photo':image()})
check('valid coordinates' in r.text,'invalid coordinates rejected')
r=post(c1,{'action':'create_issue','title':'Invalid photo test','description':'This report includes an invalid uploaded photo.','category_id':1,'address':'Test Road','latitude':'6.85','longitude':'79.92'},'index.php?page=report',{'photo':('bad.jpg',b'<?php echo 1;?>','image/jpeg')})
check('valid JPG' in r.text,'fake image rejected')
i=create(c1,'Damaged road near test junction')
r=get(c1,f'index.php?page=issue&id={i}')
filename=re.search(r'media.php\?file=([a-f0-9]+\.jpg)',r.text).group(1)
guest=requests.Session();r=guest.get(BASE+'/media.php?file='+filename,timeout=30)
check('page=login' in r.url,'photos require authentication')
r=get(c1,'media.php?file='+filename);check(r.headers['Content-Type']=='image/jpeg','photo re-encoded as JPEG')
get(c1,'media.php?file=../../config.php',404)
near=get(c1,'index.php?page=duplicates&category=1&lat=6.8501&lng=79.9200').json()
check(any(x['id']==i for x in near),'nearby duplicate suggestion')
check(not get(c1,'index.php?page=duplicates&category=2&lat=6.8501&lng=79.9200').json(),'different category excluded')
check(not get(c1,'index.php?page=duplicates&category=1&lat=7.85&lng=79.92').json(),'distant issue excluded')
get(c1,'index.php?page=duplicates&category=1&lat=nan&lng=80',422)
r=post(c2,{'action':'support','issue_id':i},f'index.php?page=issue&id={i}')
check('1 community supporters' in r.text,'support report')
r=post(c2,{'action':'comment','issue_id':i,'message':'<script>alert(1)</script>'},f'index.php?page=issue&id={i}')
check('&lt;script&gt;alert(1)&lt;/script&gt;' in r.text,'comments escaped')
r=update(o2,i,'Verified');check('not assigned to you' in r.text,'unassigned officer blocked')
r=update(c1,i,'Verified');check('not assigned to you' in r.text,'citizen status update blocked')
r=update(admin,i,'Resolved',officer_ids['officer1@test.local'],True);check('not allowed' in r.text,'skipping workflow blocked')
r=update(admin,i,'Verified',officer_ids['officer1@test.local']);check('Changes saved' in r.text,'admin verifies')
r=update(admin,i,'Assigned',officer_ids['officer1@test.local']);check('Changes saved' in r.text,'admin assigns')
r=post(admin,{'action':'toggle_user','user_id':officer_ids['officer1@test.local']},'index.php?page=users')
check('Reassign' in r.text,'active workload prevents deactivation')
r=get(o1,'index.php?page=assigned');check('Damaged road' in r.text,'assigned list includes issue')
r=update(o1,i,'In Progress');check('Changes saved' in r.text,'officer starts work')
r=update(o1,i,'Resolved');check('add a photo' in r.text,'resolution requires evidence')
r=update(o1,i,'Resolved',photo=True);check('Changes saved' in r.text,'officer resolves with photo')
check(not get(c1,'index.php?page=duplicates&category=1&lat=6.8500&lng=79.9200').json(),'resolved excluded from suggestions')
r=get(c2,'index.php?page=notifications');check('updated: Resolved' in r.text,'supporter receives status notification')
r=post(c2,{'action':'reopen','issue_id':i,'note':'The issue is still present.'},f'index.php?page=issue&id={i}')
check('Only the reporter' in r.text,'non-owner cannot reopen')
r=post(c1,{'action':'reopen','issue_id':i,'note':'The issue is still present.'},f'index.php?page=issue&id={i}')
check('Changes saved' in r.text,'reporter reopens')
j=create(c2,'Same damaged road nearby','6.8501')
r=post(admin,{'action':'merge','issue_id':j,'target_id':i,'note':'Same physical road damage.'},f'index.php?page=issue&id={j}')
check('linked to' in r.text and 'original report' in r.text,'duplicate linked')
r=post(admin,{'action':'merge','issue_id':i,'target_id':j,'note':'Bad cycle attempt.'},f'index.php?page=issue&id={i}')
check('different original' in r.text,'merge cycle blocked')
r=post(c2,{'action':'support','issue_id':j},f'index.php?page=issue&id={j}')
check('original report instead' in r.text,'duplicate support redirected')
for page in ['dashboard','issues','mine','map','notifications','profile']:
 check('CivicCare' in get(c1,'index.php?page='+page).text,'render '+page)
for page in ['users','audit']:
 check('CivicCare' in get(admin,'index.php?page='+page).text,'render admin '+page)
r=get(admin,'index.php?page=export');check('text/csv' in r.headers['Content-Type'] and 'Damaged road' in r.text,'admin CSV export')
r=post(c1,{'action':'profile','name':'Citizen Renamed','current_password':PASS,'password':'NewPassword2026!'},'index.php?page=profile')
check('Profile updated' in r.text,'password/profile update')
r=post(admin,{'action':'category','name':'Tree damage'},'index.php?page=users');check('Category added' in r.text,'category creation')
r=post(admin,{'action':'toggle_user','user_id':officer_ids['officer2@test.local']},'index.php?page=users');check('User access updated' in r.text,'user disabled')
r=get(o2,'index.php?page=dashboard');check('page=login' in r.url,'disabled session denied')
print(f'\n{checks} checks passed')
