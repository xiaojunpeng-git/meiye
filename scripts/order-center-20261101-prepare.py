#!/usr/bin/env python3
"""固定本轮候选 Git tree；保留共享工作区及原暂存区，不从服务器复制源码。"""
import hashlib,json,os,pathlib,re,subprocess,sys
root=pathlib.Path.cwd(); out=pathlib.Path(sys.argv[1]); out.mkdir(parents=True,exist_ok=True)
def git(*args,input=None,env=None):
 return subprocess.check_output(['git',*args],input=input,env=env,stderr=subprocess.DEVNULL).decode()
def sha(b):return hashlib.sha256(b).hexdigest()
head=git('rev-parse','HEAD').strip()
release_parent='635243044177a67c177d268a9b13d62e7942d65d'
assert git('merge-base',head,release_parent).strip()==head
base_files={
 '后端代码/app/services/cashier/v3/order/CashierV3OrderCenterRecordQueryServices.php':'f9a8b73c96d366fb5f65bcd1a43dcd5cc215560a',
 '后端代码/app/services/cashier/v3/order/CashierV3HistoricalRechargeDate.php':'f9a8b73c96d366fb5f65bcd1a43dcd5cc215560a'}
files=[
 '前端代码/cashier-v3/src/views/OrderCenterView.vue',
 '前端代码/cashier-v3/src/services/cashierV3Bridge.js',
 '前端代码/cashier-v3/src/services/cashierV3HttpAdapter.js',
 '前端代码/cashier-v3/src/router/index.js',
 '前端代码/admin/src/pages/store/order/center.vue',
 '后端代码/app/controller/admin/v1/store/OrderCenter.php',
 '后端代码/route/admin.php',
 '后端代码/app/services/order/PlatformOrderCenterReadServices.php',
 '后端代码/app/services/order/PlatformOrderCenterActionServices.php',
 '后端代码/app/services/cashier/v3/CashierV3ActionDispatcher.php',
 '后端代码/app/services/cashier/v3/CashierV3DataScopeFactory.php',
 '后端代码/app/services/cashier/v3/CashierV3PermissionSnapshotServices.php',
 '后端代码/app/services/cashier/v3/order/CashierV3OrderQueryModule.php',
 '后端代码/app/services/cashier/v3/order/AbstractCashierV3OrderCenterUnifiedQueryProvider.php',
 '后端代码/app/services/cashier/v3/order/CashierV3SalesOrderQueryServices.php',
 '后端代码/app/services/cashier/v3/order/CashierV3OrderCenterRecordQueryServices.php',
 '后端代码/app/services/cashier/v3/order/CashierV3OrderCenterUnifiedQueryWorkerContextResolver.php',
 '后端代码/app/services/cashier/v3/projection/CashierV3DisplayPolicyServices.php',
 '后端代码/app/services/query/UnifiedQueryExportTaskServices.php',
 '后端代码/database/upgrades/20261101_r63_order_center_global_date_index.sql',
 *['tests/order-center/'+s for s in ['r61-bounded-pagination-contract.php','r62-platform-store-parity-contract.php','r63-authority-pagination-local.php','r64-export-transport.mjs','r65-platform-shared-gateway-local.php']],
 'scripts/order-center-20261101-prepare.py',
 'scripts/order-center-20261101-release.py',
]
def candidate(file,base):
 if file.endswith('/router/index.js'):
  return base.replace('platformReadOnly: true','platformOrderContext: true')
 if file=='后端代码/route/admin.php':
  old="        Route::post('order-center/actions', 'v1.store.OrderCenter/actions')->option(['real_name' => '平台订单中心只读操作网关']);"
  assert base.count(old)==1
  updated=base.replace(old,"        Route::get('order-center/exports/:taskNo/download', 'v1.store.OrderCenter/download')->option(['real_name' => '平台订单中心导出下载']);\n"+old.replace('只读操作','共用操作'))
  lines=[line for line in updated.splitlines() if "Route::" in line and "'order-center/" in line]
  assert len(lines)==5
  for line in lines:updated=updated.replace(line+'\n','')
  block="    // 共用订单功能只验证后台登录，实际组织范围和领域校验由共用服务处理。\n    Route::group('store/order-center', function () {\n"
  block+='\n'.join(line.replace("'order-center/", "'") for line in lines)
  block+="\n    })->middleware(\\app\\http\\middleware\\admin\\AdminAuthTokenMiddleware::class)\n      ->middleware(\\app\\http\\middleware\\SystemLogMiddleware::class, 'admin');\n"
  marker="Route::group('adminapi', function () {"
  return updated.replace(marker,marker+'\n'+block,1)
 work=(root/file).read_text()
 if file.endswith('/cashierV3Bridge.js'):
  # 本轮只改变平台共用传输/操作，不夹带基础报表或其他首帧默认值改动。
  work=base[:base.index('export function canUseCashierV3Feature')]+work[work.index('export function canUseCashierV3Feature'):]
 if file.endswith('/AbstractCashierV3OrderCenterUnifiedQueryProvider.php'):
  # 未提交的旧退款接入属于其他闭环，本轮只优化既有权威源，不能改变退款统计口径。
  work=re.sub(r"\$result = \$type === 'refund'\s*\? \$this->directRefundSourcePage\(\$source, \$operatorScope, \$dataScope\)\s*:", '$result =', work)
  start=work.index('    /** 新退款事实与旧退款表同样做有界数据库分页后归并。 */')
  end=work.index('    /** 两个业务源的时间字段各异',start)
  work=work[:start]+work[end:]
  start=work.index('        // 原系统退款使用独立身份')
  end=work.index('        return $records;',start)
  work=work[:start]+work[end:]
  assert 'CashierV3HistoricalRefundQuery' not in work
 return work
idx=str(out/'candidate.index');env={**os.environ,'GIT_INDEX_FILE':idx}
git('read-tree',release_parent,env=env)
for file,commit in base_files.items():
 blob=git('rev-parse',commit+':'+file).strip();git('update-index','--add','--cacheinfo','100644,'+blob+','+file,env=env)
baseline=git('write-tree',env=env).strip(); entries=[]
for file in files:
 try:before=git('show',baseline+':'+file)
 except subprocess.CalledProcessError:before=''
 body=candidate(file,before);blob=git('hash-object','-w','--stdin',input=body.encode()).strip()
 git('update-index','--add','--cacheinfo','100644,'+blob+','+file,env=env)
 entries.append({'path':file,'sha256':sha(body.encode()),'blob':blob})
tree=git('write-tree',env=env).strip();git('diff','--check',baseline,tree)
(out/'baseline.patch').write_text(git('diff',head,baseline));(out/'round-only.patch').write_text(git('diff',baseline,tree))
manifest={'head':head,'releaseParent':release_parent,'baselineTree':baseline,'candidateTree':tree,'files':entries,'baselineFiles':base_files,
 'sharedIndexSha256':sha(pathlib.Path(git('rev-parse','--git-path','index').strip()).read_bytes())}
(out/'manifest.json').write_text(json.dumps(manifest,ensure_ascii=False,indent=2)+'\n')
print(json.dumps({'head':head,'baseline':baseline,'candidate':tree,'files':len(entries)},ensure_ascii=False))
