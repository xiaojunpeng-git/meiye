"""Archive accepted R32 scope and per-site receipts without modifying the shared index or HEAD."""
from pathlib import Path
import subprocess,json,tempfile,os,hashlib
E=Path('tests/cashier-v3/evidence/R32-report-scroll');R=E/'release'
def git(*args,**kw):return subprocess.check_output(['git',*args],**kw)
def read(n):return json.loads((R/n).read_text())
i=read('commit.json');source=i['commit'];branch=i['branch'];receipts=[]
for s in ['rh','008','010']:
 v=read(s+'-verified.json');assert v['result']=='PASS' and v['commit']==source
 assert read(s+'-all-artifacts.json')['result']=='PASS'
 assert all(x['result']=='PASS' for x in read(s+'-public-browser.json'))
 assert all(x['result']=='PASS' for x in read(s+'-production-css.json'))
 receipts.append(v)
summary=f'''# R32-00001 数据中心报表滚动条发布结果

产品经理已明确验收并授权发布。按仅本轮commit、瑞昊部署验证、push、008部署验证、010部署验证顺序完成。

业务提交：`{source}`；发布分支：`{branch}`。

## 本轮优化

共用报表结果容器恢复独立横纵滚动与可见轨道，分页栏不被压缩。只修改StoreBusinessReportView.vue的CSS及职责注释。没有改变数据查询、合计、金额、列定义、权限或导出口径。

## 已上线功能保留核对

三站发布前门店入口与R31固定构建主JS/CSS指纹一致。源码以R31归档de78fbd4fc189b44648b68e9235ed33de26ce9f6为基线，只应用本轮round.patch。源码差异仅一个Vue文件的两个CSS块；R31单价区间、前轮菜单权限及其他已上线代码均保留，不计为本轮新增。共享工作区其他未验收改动未夹带。

固定候选tree：`{i['candidateTree']}`。该树只读导出构建，构建前后全部源文件指纹一致。真实固定Vue组件在合成20行22列接口下完成三个入口、两个视口横纵鼠标滚轮及固定表头/合计/首列、分页/查询区、末行与空结果验证。生产CSS另用真实候选渲染DOM和合成行验证；逐站从公网下载的CSS字节与候选指纹一致。此为布局验证，不冒充生产真实金额/查询业务验收。公网实际JS登录加载无脚本错误。

## 逐实例结果与回滚点

'''
for v in receipts:
 summary+=f"- {v['site']}：正式门店PASS；5个部署文件全部SHA-256匹配；公网登录加载及两视口生产CSS横纵滚动通过。备份：`{v['backup']}`。\n"
summary+='''
每站manifest.json与prepared.json记录原/新指纹及逐文件备份。回滚需先核对无并发修改，再由该备份目录install.php执行rollback。只更新正式门店静态包，旧资源未删除；平台端、各站后端、客户配置、其他终端入口、岗位权限定义和迁移记录核验保持原样。无数据库迁移或业务写入，无服务重启。瑞昊preview-8080保持原前端版本并单独验证指纹与可加载性。

## 文件分组与边界

- 前端源码：前端代码/cashier-v3/src/views/StoreBusinessReportView.vue。
- 后端源码/数据库迁移：无。
- 自动化测试：tests/cashier-v3/js/r32-report-scroll-browser.cjs。
- 工程脚本：scripts/r32-report-scroll-*，负责只读构建、文件备份切换与验证/归档。
- 生成物：外部临时只读输入与dist不作为业务源码提交，固定树、输入/输出指纹、构建日志留在release清单。
- 验收与发布证据：本目录下的固定指纹、补丁、布局截图、实例前后盘点、manifest、验证JSON及日志。

共享HEAD与暂存区在业务提交和归档时保持原样。发布分支独立提交；未合并或清理其他任务的工作区改动。
'''
(R/'发布结果.md').write_text(summary)
p=E/'验收说明.md';text=p.read_text();p.write_text(text+'\n## 最终发布状态\n\n产品经理已验收。业务提交`'+source+'`已push，瑞昊/008/010正式门店完成部署验证；瑞昊预览前端保持原版本。详见release/发布结果.md。\n')
idx=Path(git('rev-parse','--git-path','index').decode().strip());before=hashlib.sha256(idx.read_bytes()).hexdigest();head=git('rev-parse','HEAD');fd,temp=tempfile.mkstemp(prefix='r32-archive-');os.close(fd);os.unlink(temp);env=dict(os.environ,GIT_INDEX_FILE=temp)
try:
 git('read-tree',source,env=env)
 for p in list(E.rglob('*'))+list(Path('scripts').glob('r32-report-scroll-*')):
  if p.is_file():
   blob=git('hash-object','-w','--stdin',input=p.read_bytes()).decode().strip();git('update-index','--add','--cacheinfo','100644',blob,str(p),env=env)
 tree=git('write-tree',env=env).decode().strip();paths=git('diff','--name-only',source,tree).decode().splitlines();assert all(p.startswith(str(E)+'/')or p.startswith('scripts/r32-report-scroll-')for p in paths)
 c=git('commit-tree',tree,'-p',source,input='R32-00001 三站发布验证与回滚证据归档\n'.encode()).decode().strip();git('update-ref','refs/heads/'+branch,c,source)
 assert git('rev-parse','HEAD')==head and hashlib.sha256(idx.read_bytes()).hexdigest()==before
 git('push','origin','refs/heads/'+branch+':refs/heads/'+branch,stderr=subprocess.STDOUT);assert git('ls-remote','origin','refs/heads/'+branch).decode().split()[0]==c
 (R/'archive-proof.json').write_text(json.dumps({'sourceCommit':source,'archiveCommit':c,'remoteVerified':True,'sharedIndexUntouched':True,'changedPaths':paths},ensure_ascii=False,indent=2)+'\n');print('ARCHIVED_PUSH_VERIFIED',c)
finally:Path(temp).unlink(missing_ok=True)
