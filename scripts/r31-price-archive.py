"""Archive per-instance release evidence with a private index; preserve all shared edits."""
from pathlib import Path
import subprocess,json,tempfile,os,hashlib
E=Path('tests/cashier-v3/evidence/R31-sale-price-range');R=E/'release'
def git(*a,**kw):return subprocess.check_output(['git',*a],**kw)
def read(n):return json.loads((R/n).read_text())
i=read('commit.json');source=i['commit'];branch=i['branch'];receipts=[]
for site in ['rh','008','010']:
 assert read(site+'-all-artifacts.json')['result']=='PASS'
 v=read(site+'-verified.json');assert v['result']=='PASS' and v['commit']==source;assert all(x['result']=='PASS' for x in read(site+'-public-browser.json'));receipts.append(v)
summary='''# R31-00001 可售价区间发布归档

本轮业务提交：`%s`。产品经理明确“我测试没有问题”，授权提交、瑞昊部署验证、push、008部署验证、010部署验证；按该顺序完成。

## 本轮优化

只将可售价区间接入卡项/产品/项目的单价改价校验、统一字段名与越界提示。两件按单价边界换算整行金额；优惠券抵扣及多卡升级补差不冒充商品售价。原金额、权限、审计、成本及整数规则保留。

## 既有上线基线（不计作本轮新增）

三站正式前端对应已验收R51构建tree45bd1d6231d76669e3449cd9dcd065ba3ce51246。先合入已验收R51/R29/R30提交链，四个触及的后端文件逐一与现网一致；平台/门店源子树与原构建一致。私有索引只应用round.patch及本轮新增文件，避免夹带共享文件中其他任务改动。合成候选重新进行真实本地数据库回滚业务测试及真实前端保存函数/SFC编译；所有发布输入是此固定Git树的只读导出。

## 逐实例验证

'''%source
for v in receipts:
 log=(R/(v['site']+'-deployment.log')).read_text();stats=next(json.loads(x) for x in log.splitlines() if x.startswith('{'))
 summary+=f"- {v['site']}：PASS；17项单价边界检查，真实SKU读取{stats['realSkuRead']}条，无生产草稿/资金写入；备份 `{v['backup']}`。公网平台、门店主资源SHA256及登录页加载通过。\n"
summary+='''
三站配置、岗位、迁移记录和非本轮后端文件核验未变。无数据库迁移。发布仅更新正式平台/门店入口；瑞昊预览入口前端未更新，仍为发布前旧版本，单独验证其旧指纹及可加载性。四个PHP服务为正式站点共享后端，预览若请求该后端会使用新校验，但预览前端没有新即时提示。

## 可回滚与提交边界

每站备份prepared.json逐目标记录原/新指纹及before文件；需回滚先检查无并发更新，再执行install.php的rollback并重启该实例服务。未删除旧静态资源、未覆盖客户配置或数据库。共享HEAD和暂存区未修改。源码、测试、工程脚本、构建清单/日志及证据分组见验收说明.md；实际dist未混入源码提交。

本轮针对性测试通过；扩展旧静态脚本的5条不匹配开工前后完全一致，未冒充其通过。线上验证没有执行真实结账或付款。
'''
(R/'发布结果.md').write_text(summary)
p=E/'验收说明.md';s=p.read_text();s=s.replace('## 本轮范围','## 最新完成状态\n\n产品经理已明确验收并授权发布。业务提交`'+source+'`已push，瑞昊、008、010正式入口均完成部署验证；瑞昊预览前端保持原版本。详见release/发布结果.md。以下开发交付段保留其当时状态。\n\n## 本轮范围',1);p.write_text(s)
head=git('rev-parse','HEAD');idx=Path(git('rev-parse','--git-path','index').decode().strip());ih=hashlib.sha256(idx.read_bytes()).hexdigest();fd,temp=tempfile.mkstemp(prefix='r31-archive-');os.close(fd);os.unlink(temp);env=dict(os.environ,GIT_INDEX_FILE=temp)
try:
 git('read-tree',source,env=env)
 for p in list(E.rglob('*'))+list(Path('scripts').glob('r31-price-*')):
  if p.is_file():
   blob=git('hash-object','-w','--stdin',input=p.read_bytes()).decode().strip();git('update-index','--add','--cacheinfo','100644',blob,str(p),env=env)
 tree=git('write-tree',env=env).decode().strip();changes=git('diff','--name-only',source,tree).decode().splitlines();assert all(x.startswith(str(E)+'/')or x.startswith('scripts/r31-price-')for x in changes)
 c=git('commit-tree',tree,'-p',source,input='R31-00001 三站发布验证与回滚记录归档\n'.encode()).decode().strip();git('update-ref','refs/heads/'+branch,c,source)
 assert git('rev-parse','HEAD')==head and hashlib.sha256(idx.read_bytes()).hexdigest()==ih
 git('push','origin','refs/heads/'+branch+':refs/heads/'+branch,stderr=subprocess.STDOUT);assert git('ls-remote','origin','refs/heads/'+branch).decode().split()[0]==c
 (R/'archive-proof.json').write_text(json.dumps({'sourceCommit':source,'archiveCommit':c,'remoteVerified':True,'sharedIndexUntouched':True},indent=2)+'\n');print('ARCHIVED_PUSH_VERIFIED',c)
finally:Path(temp).unlink(missing_ok=True)
