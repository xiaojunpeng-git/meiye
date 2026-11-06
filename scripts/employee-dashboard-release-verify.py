"""Verify deployed business readers and publicly served content before the next release step."""
import importlib.util,pathlib,sys,json,urllib.request,urllib.parse,re
sp=importlib.util.spec_from_file_location('release',pathlib.Path(__file__).with_name('employee-dashboard-release.py'));p=importlib.util.module_from_spec(sp);sp.loader.exec_module(p)
s=sys.argv[1];body=(p.R/'scripts/employee-dashboard-release-verify.php').read_text().replace('<?php','',1);r=p.php(s,body);assert r['result']=='PASS';p.save(s+'-runtime.json',r)
manifest=p.read(s+'-manifest.json');allfiles=p.payload();http={}
for path in ['public/system.html','public/view_cashier_v3/index.html']:
 u='https://'+s+'.cc3798.com/'+path[7:]+'?employee_release=334d91a2';b=urllib.request.urlopen(u,timeout=30).read();assert p.sha(b)==p.sha(allfiles[path]),path;http[path]=p.sha(b)
 for asset in re.findall(r'(?:src|href)=[\"\']([^\"\']+\.(?:js|css))[\"\']',b.decode()):
  url=urllib.parse.urljoin(u,asset);rel='public/'+urllib.parse.urlparse(url).path.lstrip('/')
  if rel not in allfiles:continue
  data=urllib.request.urlopen(url,timeout=30).read();assert p.sha(data)==p.sha(allfiles[rel]),rel;http[rel]=p.sha(data)
p.save(s+'-verified.json',{'result':'PASS','commit':p.read('commit.json')['commit'],'runtime':r,'publicHTTP':http,'backup':p.read(s+'-installed.json')['backup']});print(json.dumps({'site':s,**r,'verifiedPublicAssets':len(http)},ensure_ascii=False))
