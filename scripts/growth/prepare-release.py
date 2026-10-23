"""从唯一仓库已上线提交补齐发布基线，并仅暂存验收成长增量；不触碰共享索引及工作区。"""
from pathlib import Path
import subprocess, json, hashlib, os, tempfile
root=Path(__file__).resolve().parents[2]
evidence=root.parent/'任务管理/进行中/美业大学成长管理/证据/发布'
evidence.mkdir(exist_ok=True)
c=json.loads((evidence.parent/'固定验收候选.json').read_text())
base='eaebf24023dc154669ac9186cf8a898ff5350334'
def git(*args,input=None,env=None):return subprocess.check_output(['git',*args],cwd=root,input=input,env=env)
index=Path(tempfile.mkdtemp(prefix='growth-release-index-'))/'index'
env=dict(os.environ,GIT_INDEX_FILE=str(index))
git('read-tree',base,env=env)
records=[]
for row in [x for g in c['groups'].values() for x in g]:
 p=row['path'];data=(root/p).read_bytes()
 if hashlib.sha256(data).hexdigest()!=row['sha256']:raise RuntimeError('Accepted source drift: '+p)
 # 开工前目标手机模块未验收且未在线，按增长量边界从提交中排除，工作区仍保留。
 if p.endswith('/route/api-mobile.php'):data=b'\n'.join(l for l in data.split(b'\n') if b"Route::" not in l or b"target-scheme/" not in l)
 if p.endswith('/workbench/index.uvue'):data=b'\n'.join(l for l in data.split(b'\n') if b"mobile.merchant.target_" not in l)
 if p=='前端代码/mobile-vue3/pages.json':
  doc=json.loads(data);doc['pages']=[x for x in doc['pages'] if '/target-scheme/' not in x['path'] and '/target-analysis/' not in x['path']];data=(json.dumps(doc,ensure_ascii=False,indent=2)+'\n').encode()
 if p.endswith('/MobileMerchantCapabilityCatalog.php'):
  # 新菜单禁止从旧工作台隐式继承，保留同一通用保护，仅移除未验收目标的定义和入口。
  data=b'\n'.join(l for l in data.split(b'\n') if not any(x in l for x in [b'public const RULE_TARGET_',b"['id' => self::RULE_TARGET_",'401011 已存在'.encode()]))
 blob=git('hash-object','-w','--stdin',input=data).decode().strip();git('update-index','--add','--cacheinfo','100644,'+blob+','+p,env=env)
 records.append({'path':p,'sha256':hashlib.sha256(data).hexdigest(),'accepted_sha256':row['sha256'],'isolated_unaccepted_target':hashlib.sha256(data).hexdigest()!=row['sha256']})
# 工程发布脚本在同一正本管理；不打包本地测试账户、env或数据库内容。
for p in sorted((root/'scripts/growth').glob('*release*')):
 relative=str(p.relative_to(root));blob=git('hash-object','-w',relative).decode().strip();git('update-index','--add','--cacheinfo','100644,'+blob+','+relative,env=env)
tree=git('write-tree',env=env).decode().strip()
result={'accepted_candidate':c['candidate'],'shared_head':git('rev-parse','HEAD').decode().strip(),'baseline_commit':base,'baselineTree':git('rev-parse',base+'^{tree}').decode().strip(),'candidateTree':tree,'index':str(index),'files':records,'pmAccepted':False,'supplementary_acceptance':'正式包同源Cookie修复涉及共享手机请求，补测后待产品经理确认此新增范围','authorization':'用户确认测试无问题并授权commit、瑞昊部署、push、渼约008部署','shared_index_sha256':hashlib.sha256((root/'.git/index').read_bytes()).hexdigest()}
(evidence/'trees.json').write_text(json.dumps(result,ensure_ascii=False,indent=2));print(json.dumps({'tree':tree,'files':len(records),'base':base}))
