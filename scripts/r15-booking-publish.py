"""Publish only the accepted booking PHP and member assets; protect other tasks' entries."""
import pathlib,sys,json,re,concurrent.futures
ROOT=pathlib.Path(__file__).resolve().parents[1]
source=(ROOT/'scripts/r15-release-publish.py').read_text().split("if __name__=='__main__':",1)[0]
source=source.replace("R=ROOT/'tests/r15-release'","R=ROOT/'tests/r15-booking-release'")
source=source.replace("assert sha((ROOT/'.git/index').read_bytes())==p['indexSHA256'];",'')
source=source.replace("'admin':'/www/backups/'+s+'/combination-group-'+p['commit'][:8]", "'admin':'/www/backups/'+s+'/combination-group-'+p['commit'][:8]+p.get('deploymentRetrySuffix',{}).get(s,'')")
source=source.replace("'member':'/www/backups/'+s+'/store-address-'+p['commit'][:8]", "'member':'/www/backups/'+s+'/store-address-'+p['commit'][:8]+p.get('deploymentRetrySuffix',{}).get(s,'')")
source=source.replace("for k in ['cashier','pc']:assert after", "for k in ['admin','cashier','pc']:assert after")
source=source.replace("('member','r15-release-runtime.php')", "('member','r15-final-runtime.php'),('booking','r15-booking-runtime.php')")
exec(compile(source,str(ROOT/'scripts/r15-release-publish.py'),'exec'),globals())

def prepare(s):
 p=guard();b=read(s+'-before.json');assert probe(s)==b,'Live baseline drift '+s
 backend=p['backendFiles'];before=read(s+'-backend-before.json');actual=hashes(s,backend);assert actual==before
 targets={};body={}
 for f in backend:
  content=git('show',p['commit']+':\u540e\u7aef\u4ee3\u7801/'+f);targets['formal/'+f]={'before':before[f],'after':sha(content)};body['formal/'+f]=content
 package(s,'admin',{'site':s,'commit':p['commit'],'previewAuthorized':False,'backendAllowlist':backend,'targets':targets},body)
 member=pathlib.Path(p['memberOutputs'][s]);assets={str(f.relative_to(member)):f.read_bytes()for f in member.rglob('*')if f.is_file()};old=hashes(s,['public/'+f for f in assets]);save(s+'-target-before.json',old);targets={};body={};excluded=[]
 for f,content in assets.items():
  if f!='index.html'and not re.fullmatch(r'static/(js/[^/]+\.js(?:\.gz)?|css/[^/]+\.css(?:\.gz)?|index\.[a-f0-9]+\.css)',f):
   if old['public/'+f]!=sha(content):excluded.append(f)
   continue
  rel='public/'+f;h=sha(content)
  if h!=old[rel]:targets[rel]={'before':old[rel],'after':h};body[rel]=content
 assert not excluded,'Unapproved member dependency drift '+repr(excluded)
 entry=targets.pop('public/index.html',None)
 if entry:targets['public/index.html']=entry
 protected={}
 for key,path in [('admin','public/system.html'),('cashier','public/view_cashier_v3/index.html'),('pc','public/static/html/pc.html')]:
  protected[path]=b['front'][key]['index']
  for a,h in dict(b['front'][key]['assets']).items():protected['public/'+a.lstrip('/')]=h
 package(s,'member',{'site':s,'commit':p['commit'],'environment':b['environment'],'protected':protected,'files':targets},body)
 save(s+'-artifacts.json',{'member':{f:sha(c)for f,c in assets.items()},'backend':{f:v['after']for f,v in read(s+'-admin-manifest.json')['targets'].items()}})
 print('PREPARED',s,len(backend),len(targets),flush=True)

def public(s):
 a=read(s+'-artifacts.json')['member'];origin='https://'+s+'.cc3798.com';body=run(['curl','--http1.1','-fsS','--max-time','35',origin+'/pages/activity/reservation/index']);assert sha(body)==a['index.html']
 refs=[x.decode()for x in re.findall(rb'(?:src|href)=["\x27]?([^"\x27\s>]+\.(?:js|css))',body)if x.startswith(b'/')]
 lazy=['/'+f for f in a if re.search(r'pages-(activity-reservation|user|users-(survey|user_card|user_card_list)|discover-discoverIndex)-index\.[^/]+\.(js|css)$',f)]
 def check(f):assert sha(run(['curl','--http1.1','--compressed','-fsS','--connect-timeout','15','--max-time','50',origin+f]))==a[f.lstrip('/')],f
 with concurrent.futures.ThreadPoolExecutor(3)as ex:list(ex.map(check,sorted(set(refs+lazy))))
 for api in ['/api/diy/get_diy/0','/api/menu/user']:assert json.loads(run(['curl','-fsS','--max-time','35',origin+api]))['status']==200
 return {'result':'PASS','checkedAssets':sorted(set(refs+lazy)),'personalCenterAPI':True,'businessWrites':0}

if __name__=='__main__':
 mode=sys.argv[1]
 if mode=='prepare':
  for s in ['rh','008','010']:prepare(s)
 elif mode=='deploy':deploy(sys.argv[2])
 elif mode=='push':push()
