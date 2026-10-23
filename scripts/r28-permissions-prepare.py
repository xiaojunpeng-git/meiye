"""Use a private index over accepted R27/growth baseline; isolate only R28 edits, never touch shared index/source."""
from pathlib import Path
import subprocess,hashlib,json,tempfile,os,re
ROOT=Path(__file__).resolve().parent.parent;E=ROOT/'tests/store-permission-r28/evidence/release'
def git(*args,input=None,env=None):return subprocess.check_output(['git',*args],cwd=ROOT,input=input,env=env)
fixed=json.loads((ROOT/'tests/store-permission-r28/evidence/fixed-files.json').read_text());head=git('rev-parse','HEAD').decode().strip()
for f,h in fixed['files'].items():
 if hashlib.sha256((ROOT/f).read_bytes()).hexdigest()!=h:raise RuntimeError('Accepted fingerprint changed: '+f)
baseline=git('merge-tree','--write-tree',head,'64726854').decode().strip()
if not re.fullmatch('[a-f0-9]{40}',baseline):raise RuntimeError('Baseline conflict')
fd,index=tempfile.mkstemp(prefix='r28-index-');os.close(fd);os.unlink(index);env=dict(os.environ,GIT_INDEX_FILE=index);git('read-tree',baseline,env=env);records=[]
for f,accepted in fixed['files'].items():
 data=(ROOT/f).read_bytes()
 if f.endswith('/CashierShell.vue'):
  s=data.decode();a=s.index('async function selectMemberCreatorServicePerson(');b=s.index('\n}',a)+2;part=s[a:b];part=part.replace('selectedRecords = []','selectedRecord = null').replace('multiple: true','multiple: false').replace('selectedRecords: Array.isArray(selectedRecords) ? selectedRecords : []','selectedRecords: selectedRecord ? [selectedRecord] : []');s=s[:a]+part+s[b:];data=s.encode()
 if f.endswith('/StoreBusinessReportView.vue'):
  # Preserve published report calculations/export/period behavior; only remove the approved duplicate navigation.
  s=git('show',baseline+':'+f).decode().replace('computed, nextTick, onBeforeUnmount, onMounted','computed, onMounted').replace("import ChevronUp from '@lucide/vue/dist/esm/icons/chevron-up.mjs'\n",'');s=s.replace("import { formatMoney }", "import { REPORT_TABS, STORE_REPORT_TABS } from '@/constants/storeBusinessReports'\nimport { formatMoney }")
  a=s.index('const REPORT_TABS = Object.freeze(');b=s.index('const SIX_DIMENSION_REPORT_TABS',a);s=s[:a]+s[b:]
  a=s.index('const STORE_REPORT_TABS = Object.freeze(');b=s.index('const route =',a);s=s[:a]+s[b:]
  a=s.index('const tabsElement =');b=s.index('const editingRow',a);s=s[:a]+s[b:]
  a=s.index('const reportTabs =');b=s.index('const isFirstPhaseReport',a);s=s[:a]+s[b:]
  a=s.index('function selectReport(');b=s.index('function returnToSalarySummary',a);s=s[:a]+s[b:]
  a=s.index('function updateTabsLayout');b=s.index('function query()',a);s=s[:a]+s[b:]
  s=s.replace('  nextTick(updateTabsLayout)\n','').replace("  window.addEventListener('resize', updateTabsLayout)\n",'').replace('    await nextTick()\n    updateTabsLayout()\n','').replace("onBeforeUnmount(() => window.removeEventListener('resize', updateTabsLayout))\n",'')
  a=s.index('    <div v-if="!isPlatformReport" class="store-business-report__tabs-shell"');b=s.index('    <section class="store-business-report__filters"',a);s=s[:a]+'    <!-- 报表切换统一使用顶部数据中心，查询、导出及下钻沿用已上线业务路径。 -->\n'+s[b:]
  s='\n'.join(l for l in s.split('\n') if not l.startswith('.store-business-report__tab'))
  i=s.index('.store-business-report--fixed-table.store-business-report--advanced-open {');s=s[:i]+'''/* 门店移除重复导航后为表格释放高度，平台原布局独立保留。 */
.store-business-report--market-detail:not(.store-business-report--platform), .store-business-report--fixed-table:not(.store-business-report--platform) { grid-template-rows: auto minmax(0, 1fr); }
'''+s[i:];s=s.replace('.store-business-report--fixed-table.store-business-report--advanced-open { grid-template-rows: auto auto auto minmax(0, 1fr); }','.store-business-report--fixed-table.store-business-report--advanced-open { grid-template-rows: auto auto auto minmax(0, 1fr); }\n.store-business-report--fixed-table.store-business-report--advanced-open:not(.store-business-report--platform) { grid-template-rows: auto auto minmax(0, 1fr); }');data=s.encode()
 blob=git('hash-object','-w','--stdin',input=data).decode().strip();git('update-index','--add','--cacheinfo','100644',blob,f,env=env);records.append({'path':f,'acceptedSha256':accepted,'releaseSha256':hashlib.sha256(data).hexdigest(),'isolatedUnacceptedChanges':accepted!=hashlib.sha256(data).hexdigest()})
candidate=git('write-tree',env=env).decode().strip();os.unlink(index)
sharedIndex=git('rev-parse','--git-path','index').decode().strip();sharedPath=ROOT/sharedIndex
out={'head':head,'baselineTree':baseline,'acceptedBaselineCommit':git('rev-parse','64726854').decode().strip(),'candidateTree':candidate,'files':records,'sharedIndexSha256':hashlib.sha256(sharedPath.read_bytes()).hexdigest(),'branch':'release/r28-store-permissions-20261023','pmAccepted':True,'targets':['rh','008','010']};(E/'trees.json').write_text(json.dumps(out,ensure_ascii=False,indent=2)+'\n');(E/'round.patch').write_bytes(git('diff','--binary',baseline,candidate));print(json.dumps({'baseline':baseline,'candidate':candidate,'files':len(records),'isolated':[x['path'] for x in records if x['isolatedUnacceptedChanges']]}))
