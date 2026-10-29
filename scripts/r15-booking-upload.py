"""One upload request per customer. Preserve unknown/pending tasks and require an
explicit resume/result check before any further request; never report pending as success."""
import pathlib,json,subprocess,hashlib,sys
r=pathlib.Path(__file__).resolve().parents[1]/'tests/r15-booking-release';site=sys.argv[1];assert site in ['008','010'];plan=json.loads((r/'plan.json').read_text());pack=json.loads((r/(site+'-mini-package.json')).read_text());root=pathlib.Path(pack['output']);sha=lambda b:hashlib.sha256(b).hexdigest()
assert all(json.loads((r/(s+'-verified.json')).read_text())['result']=='PASS'for s in ['rh','008','010'])
# Import normalizes IDE-owned project metadata, never compiled application bytes.
changed=[f for f,h in pack['files'].items()if sha((root/f).read_bytes())!=h];assert set(changed)<= {'project.config.json','project.private.config.json'},changed
conf=json.loads((root/'project.config.json').read_text());assert conf['appid']==plan['apps'][site]['appid']and conf['compileType']=='miniprogram';(r/(site+'-ide-metadata.json')).write_text(json.dumps({'changedByProjectImport':changed,'appid':conf['appid'],'businessFilesUnchanged':True,'configuration':conf},ensure_ascii=False,indent=2))
marker=r/(site+'-upload-attempt.json');state={'site':site,'version':pack['version'],'appid':pack['appid'],'project':str(root),'state':'REQUEST_STARTED'}
with marker.open('x')as f:json.dump(state,f,indent=2)
a=['/Applications/wechatwebdevtools.app/Contents/MacOS/wechatide','-c','Codex','upload','--project',str(root),'--upload-version',pack['version'],'--desc','第15轮：补齐历史已购权益、五类卡分组、时段完整展开及日期主题底色；保留已验收功能'];o=subprocess.run(a,capture_output=True,timeout=150);(r/(site+'-upload-stdout.txt')).write_bytes(o.stdout);(r/(site+'-upload-stderr.txt')).write_bytes(o.stderr)
raw=o.stdout.decode();start=raw.find('{');reply=json.loads(raw[start:]);(r/(site+'-upload-response.json')).write_text(json.dumps(reply,ensure_ascii=False,indent=2));print(json.dumps(reply,ensure_ascii=False));print('EXIT',o.returncode)
