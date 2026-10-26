import { execFileSync } from 'node:child_process'
import { createHash } from 'node:crypto'
import { readFileSync, writeFileSync, existsSync, mkdirSync, realpathSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

// Probe only: no inferred employee IDs are written or treated as existing grants.
const repo=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..')
const summarize=process.argv.length===3&&process.argv[2]==='--summarize-local'
if(realpathSync(repo)!=='/Users/xiaojunpeng/Desktop/魔核/美业/美容源码'||(!summarize&&process.argv.length!==2))throw Error('CANONICAL_PROBE_OR_LOCAL_SUMMARY_REQUIRED')
const folder=path.join(repo,'tests/report-basic/evidence/010-staff-identity')
const output=path.join(folder,'identity-before.json')
const sha=b=>createHash('sha256').update(b).digest('hex')
if(summarize){
  // This mode cannot connect to SSH. Interpret only the immutable ID evidence;
  // source employment identity is not equivalent to an existing person record.
  const summaryPath=path.join(folder,'identity-summary.json')
  if(existsSync(summaryPath))throw Error('SUMMARY_EXISTS_REFUSING_OVERWRITE')
  const bytes=readFileSync(output), before=JSON.parse(bytes), r=before.remote
  const missing=r.store_1_staff.filter(row=>Number(row.employee_id)<1)
  const employees=r.identity_records.mohe_010_app.eb_employee.rows
  const jobs=r.identity_records.mohe_010_app.eb_staff_job_position.rows
  const rows=missing.map(staff=>{
    const source=r.same_primary_key_staff.mohe_010.filter(s=>Number(s.id)===Number(staff.id))
    const target=r.same_primary_key_staff.mohe_010_app.find(s=>Number(s.id)===Number(staff.id))
    const unique=source.length===1&&['id','store_id','add_time','position','status','is_del'].every(k=>source[0][k]===target[k])
    const linkedJobs=jobs.filter(j=>Number(j.staff_id)===Number(staff.id))
    const uidLinks=r.existing_uid_employee_links.filter(x=>Number(x.staff_id)===Number(staff.id))
    return {source_staff_id:Number(staff.id),target_staff_id:Number(staff.id),store_id:Number(staff.store_id),
      source_row_unique:source.length===1,stable_structural_fields_equal:unique,
      existing_employee_id:null,existing_job_mapping_count:linkedJobs.length,existing_uid_employee_links:uidLinks.length,
      same_numeric_employee_id_occupied:employees.some(e=>Number(e.id)===Number(staff.id)),
      source_to_target_employment:'Preserved staff primary key; store/add_time/position/status/deletion match; no names used',
      existing_person_mapping_proven:false,
      possible_new_employee_key:Number(staff.id),
      possible_new_employee_key_status:'Unallocated key consistent with prior manager migration convention; NOT an existing mapping or cross-store natural-person identity proof'}
  })
  const summary={head_at_probe:before.head,source:{path:'identity-before.json',sha256:sha(bytes)},
    local_import_sources:before.local_import_sources,scope:'Store1 active roster only; no source or historical facts changed',
    roster_count:r.store_1_staff.length,missing_employee_count:rows.length,rows,
    conclusion:{unique_source_employment_links:rows.filter(x=>x.stable_structural_fields_equal).length,
      proven_existing_employee_mappings:0,existing_identity_namespace_collisions:rows.filter(x=>x.same_numeric_employee_id_occupied).length,
      direct_mapping_table_found:false,source_employee_table_exists:r.identity_records.mohe_010.eb_employee.exists,
      source_job_table_exists:r.identity_records.mohe_010.eb_staff_job_position.exists,
      ambiguity:'No per-row staff PK ambiguity. Cross-store natural-person deduplication is NOT proven; do not silently merge people or attach these staff rows to one of the existing11 manager identities.',
      provenance_gap:'Recorded migration source_schema is mohe_010_refresh; mohe_010 corroborates same structural rows but is not the exact recorded archive. No unauthorized refresh/007 connection was made.',
      next_boundary:'A controlled identity completion may create new employee IDs using preserved staff IDs after its own preflight and explicitly defined person policy; this probe neither implements nor applies it. Preserve staff IDs, all historical facts, and any existing mappings.'},
    original_manager_ids:[1,2,3,4,5,6,7,8,9,10,106],
    original_manager_mapping_basis:'06 SQL explicitly creates employee.id=s.id and j.employee_id=j.staff_id=s.id only for these11 managers; it does not already map the11 store1 missing staff.',
    remote_writes:false,additional_remote_queries:false,mapping_applied:false}
  writeFileSync(summaryPath,JSON.stringify(summary,null,2)+'\n',{flag:'wx',mode:0o600})
  console.log(JSON.stringify({summaryPath,sha256:sha(readFileSync(summaryPath)),conclusion:summary.conclusion}))
  process.exit(0)
}
if(existsSync(output))throw Error('IDENTITY_EVIDENCE_EXISTS_REFUSING_OVERWRITE')
const php=readFileSync(path.join(repo,'scripts/r51-010-staff-identity-probe.php'))
const sqlRoot='后端代码/database/upgrades/2026-10-23-渼约010测试导入/'
const sources=Object.fromEntries(['01-业务快照导入.sql','06-007原门店管理员登录010.sql'].map(f=>[sqlRoot+f,sha(readFileSync(path.join(repo,sqlRoot+f)))]))
let remote
try{remote=JSON.parse(execFileSync('ssh',['-T','-o','BatchMode=yes','-o','StrictHostKeyChecking=yes','-o','ConnectTimeout=12','mohe-server','/www/server/php/74/bin/php'],
  {input:php,timeout:120000,maxBuffer:24*1024*1024,stdio:['pipe','pipe','pipe']}).toString())}catch{throw Error('010_IDENTITY_READ_ONLY_FAILED')}
if(remote.site!=='010'||remote.remote_writes!==false||Number(remote.database?.ro)!==1)throw Error('IDENTITY_SOURCE_INVALID')
const result={head:execFileSync('git',['rev-parse','HEAD'],{cwd:repo}).toString().trim(),r51_preserved_commit:'dfea59566663c76f0cae00d1fb851c45a262f9f9',
  probe_sha256:sha(php),local_import_sources:sources,remote,remote_writes:false,mapping_applied:false,
  source_boundary:'Import scripts used mohe_010_refresh. This probe reads ONLY authorized mohe_010_app and mohe_010; same PK in mohe_010 alone does not establish equality to the refresh archive.'}
mkdirSync(folder,{recursive:true})
writeFileSync(output,JSON.stringify(result,null,2)+'\n',{flag:'wx',mode:0o600})
console.log(JSON.stringify({output,sha256:sha(readFileSync(output)),roster:remote.store_1_staff.length,missing_employee:remote.store_1_staff.filter(r=>Number(r.employee_id)<1).length,
  schema_tables:Object.fromEntries(Object.entries(remote.schema).map(([s,t])=>[s,Object.keys(t)]))}))
