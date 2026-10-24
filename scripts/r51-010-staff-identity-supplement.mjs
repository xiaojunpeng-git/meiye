import { execFileSync } from 'node:child_process'
import { createHash } from 'node:crypto'
import { existsSync, readFileSync, realpathSync, writeFileSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

// Separate supplemental evidence; never overwrite the first restricted-source
// probe. A conservative candidate range is not an authorization to apply it.
const repo=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..')
if(realpathSync(repo)!=='/Users/xiaojunpeng/Desktop/魔核/美业/美容源码'||process.argv.length!==2)throw Error('CANONICAL_NO_ARGUMENTS_REQUIRED')
const folder=path.join(repo,'tests/report-basic/evidence/010-staff-identity')
const output=path.join(folder,'identity-refresh-supplement.json')
if(existsSync(output))throw Error('SUPPLEMENT_EXISTS_REFUSING_OVERWRITE')
const sha=b=>createHash('sha256').update(b).digest('hex')
const before=readFileSync(path.join(folder,'identity-before.json'))
const php=readFileSync(path.join(repo,'scripts/r51-010-staff-identity-supplement.php'))
let remote
try{remote=JSON.parse(execFileSync('ssh',['-T','-o','BatchMode=yes','-o','StrictHostKeyChecking=yes','-o','ConnectTimeout=12',
  '-o','ServerAliveInterval=15','-o','ServerAliveCountMax=2','mohe-server','/www/server/php/74/bin/php'],
  {input:php,timeout:240000,maxBuffer:24*1024*1024,stdio:['pipe','pipe','pipe']}).toString())}catch{throw Error('010_READ_ONLY_REFRESH_SUPPLEMENT_FAILED')}
if(remote.site!=='010'||!remote.source_whitelist_verified_before_access||remote.migration_batch?.source_schema!=='mohe_010_refresh'||Number(remote.database?.ro)!==1)throw Error('SOURCE_BOUNDARY_FAILED')
const flags=['store_equal','status_equal','deletion_equal','position_equal','add_time_equal','uid_import_map_equal','phone_preserved']
const assessed=remote.staff.map(row=>{
  const active=Number(row.status)===1&&Number(row.is_del)===0
  const missing=Number(row.employee_id)<1||!Number(row.linked_employee_exists)
  const reasons=[]
  if(!row.source_staff_id)reasons.push('NO_EXACT_SOURCE_STAFF')
  for(const flag of flags)if(!Number(row[flag]))reasons.push(flag.toUpperCase()+'_MISMATCH')
  if(missing&&Number(row.employee_id)>0)reasons.push('DANGLING_PREEXISTING_EMPLOYEE_LINK')
  if(missing&&Number(row.same_id_employee_exists))reasons.push('SAME_ID_EMPLOYEE_ALREADY_OCCUPIED')
  if(missing&&Number(row.same_id_employee_other_staff_links))reasons.push('SAME_ID_LINKED_TO_OTHER_STAFF')
  if(missing&&Number(row.existing_job_count))reasons.push('PREEXISTING_JOB_REQUIRES_REVIEW')
  if(missing&&Number(row.employee_phone_match_count))reasons.push('PHONE_ALREADY_PRESENT_ON_EMPLOYEE')
  if(missing&&Number(row.source_other_staff_phone_count))reasons.push('PHONE_SHARED_WITH_OTHER_SOURCE_STAFF')
  if(missing&&Number(row.source_phone_blank))reasons.push('SOURCE_PHONE_BLANK')
  if(missing&&!Number(row.source_phone_mobile_valid))reasons.push('SOURCE_PHONE_NOT_VALID_MOBILE')
  return {...row,active,missing_identity:missing,isolation_reasons:reasons,
    preserved_id_creation_candidate:missing&&reasons.length===0,
    candidate_employee_id:missing&&reasons.length===0?Number(row.staff_id):null}
})
const group=rows=>({staff:rows.length,missing_identity:rows.filter(r=>r.missing_identity).length,
  missing_employee_id:rows.filter(r=>Number(r.employee_id)<1).length,
  preserved_id_creation_candidates:rows.filter(r=>r.preserved_id_creation_candidate).length,
  isolated_missing:rows.filter(r=>r.missing_identity&&r.isolation_reasons.length).length,
  employee_id_collisions:rows.filter(r=>r.missing_identity&&Number(r.same_id_employee_exists)).length,
  employee_phone_match_staff:rows.filter(r=>r.missing_identity&&Number(r.employee_phone_match_count)>0).length,
  source_duplicate_phone_staff:rows.filter(r=>r.missing_identity&&Number(r.source_other_staff_phone_count)>0).length,
  blank_phone_staff:rows.filter(r=>r.missing_identity&&Number(r.source_phone_blank)>0).length,
  structural_mismatch_staff:rows.filter(r=>flags.some(k=>!Number(r[k]))).length})
const groups={all_target:group(assessed),imported:group(assessed.filter(r=>r.source_staff_id)),active:group(assessed.filter(r=>r.active)),
  inactive_or_deleted:group(assessed.filter(r=>!r.active)),store_1_active:group(assessed.filter(r=>r.active&&Number(r.store_id)===1))}
const history=Object.values(remote.historical_staff_references)
const historicalReferencesPreserved=history.every(r=>r.checked&&Number(r.missing_target_rows)===0&&Number(r.changed_staff_reference_rows)===0&&Number(r.extra_target_rows)===0)
const result={head:execFileSync('git',['rev-parse','HEAD'],{cwd:repo}).toString().trim(),r51_preserved_commit:'dfea59566663c76f0cae00d1fb851c45a262f9f9',
  originalProbe:{path:'identity-before.json',sha256:sha(before)},probe_sha256:sha(php),remote:{...remote,staff:assessed},groups,
  historical_staff_reference_check_passed:historicalReferencesPreserved,
  candidate_policy:'Extend existing06 staff.id -> new employee.id convention; preserve every staff ID and fact reference. Never attach by name or merge natural persons. All duplicate-phone/preexisting-ID/job/structural conflicts are isolated; no provisioning is executed.',
  source_phone_count_semantics:'Exact source phone equality, excluding NULL/blank/zero; only counts/booleans returned. Duplicate counts are not proof of same natural person. Conservative isolation includes matches to deleted/inactive source staff or employees.',
  candidate_ids:assessed.filter(r=>r.preserved_id_creation_candidate).map(r=>Number(r.staff_id)),
  active_candidate_ids:assessed.filter(r=>r.preserved_id_creation_candidate&&r.active).map(r=>Number(r.staff_id)),
  isolated:assessed.filter(r=>r.missing_identity&&r.isolation_reasons.length).map(r=>({staff_id:Number(r.staff_id),store_id:Number(r.store_id),active:r.active,reasons:r.isolation_reasons})),
  remote_writes:false,mapping_applied:false}
if(sha(readFileSync(path.join(folder,'identity-before.json')))!==sha(before))throw Error('ORIGINAL_PROBE_CHANGED')
writeFileSync(output,JSON.stringify(result,null,2)+'\n',{flag:'wx',mode:0o600})
console.log(JSON.stringify({output,sha256:sha(readFileSync(output)),groups,historicalReferencesPreserved,sourceStaff:remote.source_staff_count,
  sourceMissingTarget:remote.source_missing_target_staff_count,candidates:result.candidate_ids.length,isolated:result.isolated.length}))
