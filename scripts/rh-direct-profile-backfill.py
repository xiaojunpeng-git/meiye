#!/usr/bin/env python3
"""Backfill Ruihao direct employee profiles and store-staff birthdays after schema approval.

Default mode is read-only. --apply requires the approved employee columns, captures
before values locally, then uses one guarded database transaction and audit rows.
"""
import argparse
import base64
import datetime
import hashlib
import json
import os
from pathlib import Path
import subprocess

PLAN_FILE = Path('/Users/xiaojunpeng/Desktop/魔核/美业/任务管理/数据库升级文件/本地备份/20261103-rh-employee-profile/before.json')
BACKUP_DIR = PLAN_FILE.parent


def remote(body):
    """Read or write only the named Ruihao instance through its own application config."""
    php = "<?php set_exception_handler(function($e){fwrite(STDERR,get_class($e).': '.$e->getMessage());exit(1);});$root='/www/wwwroot/rh.cc3798.com';chdir($root);require $root.'/vendor/autoload.php';(new think\\App($root.'/'))->initialize();$c=config('database.connections.mysql');if(($c['database']??'')!=='ruihao'||(int)($c['hostport']??0)!==3306)throw new RuntimeException('instance mismatch');" + body
    result = subprocess.run(['ssh', '-q', '-o', 'BatchMode=yes', 'rh-server', 'php'], input=php, text=True, capture_output=True)
    if result.returncode:
        raise RuntimeError(result.stderr[-1200:])
    if not result.stdout.strip():
        raise RuntimeError('Remote PHP returned no result: ' + result.stderr[-1200:])
    return json.loads(result.stdout)


def encoded(value):
    return "'" + base64.b64encode(json.dumps(value, ensure_ascii=False, separators=(',', ':')).encode()).decode() + "'"


def candidates():
    """Use the previously verified phone/name plan; birthday comes only from valid source IDs."""
    source = json.loads(PLAN_FILE.read_text())['plan']
    result = []
    for entry in source:
        person = entry['source']
        card = person['card']
        birth = card[6:10] + '-' + card[10:12] + '-' + card[12:14] if card else ''
        if birth:
            try:
                datetime.date.fromisoformat(birth)
            except ValueError:
                birth = ''
        result.append({'employee_id': entry['employee']['id'], 'phone': person['phone'],
                       'name': person['name'], 'card': card, 'join_date': person['date'],
                       'birthday_date': birth, 'staff_ids': [s['id'] for s in entry['staff']]})
    return result


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--apply', action='store_true', help='execute approved production backfill')
    parser.add_argument('--staff-only', action='store_true', help='backfill existing store staff birthdays without schema change')
    args = parser.parse_args()
    os.umask(0o077)
    plan = candidates()
    if args.staff_only:
        plan = [item for item in plan if item['staff_ids']]
    counts = {'employee_candidates': len(plan), 'direct_candidates': sum(not p['staff_ids'] for p in plan),
              'staff_candidates': sum(len(p['staff_ids']) for p in plan),
              'valid_birthdays': sum(bool(p['birthday_date']) for p in plan)}
    if not args.apply:
        print(json.dumps({'mode': 'read_only', **counts}, ensure_ascii=False))
        return
    # A before snapshot is saved before any mutation. Existing conflicting values
    # and changed identity/assignment links abort the whole transaction.
    ids = [p['employee_id'] for p in plan]
    staff_ids = [sid for p in plan for sid in p['staff_ids']]
    schema_guard = '' if args.staff_only else "$cols=think\\facade\\Db::query(\"SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='ruihao' AND TABLE_NAME='eb_employee' AND COLUMN_NAME IN ('id_card','join_date','birthday_date')\");if(count($cols)!==3)throw new RuntimeException('profile migration required');"
    employee_fields = 'id,name,phone,is_del' if args.staff_only else 'id,name,phone,is_del,id_card,join_date,birthday_date'
    before = remote(schema_guard + "$e=json_decode(base64_decode(" + encoded(ids) + "),true);$s=json_decode(base64_decode(" + encoded(staff_ids) + "),true);echo json_encode(['employee'=>think\\facade\\Db::name('employee')->whereIn('id',$e)->field('" + employee_fields + "')->select()->toArray(),'staff'=>think\\facade\\Db::name('system_store_staff')->whereIn('id',$s)->field('id,employee_id,phone,staff_name,is_del,birthday_date,birthday_type')->select()->toArray()],JSON_UNESCAPED_UNICODE);")
    snapshot = {'plan': plan, 'before': before, 'source_sha256': hashlib.sha256(PLAN_FILE.read_bytes()).hexdigest()}
    backup = BACKUP_DIR / ('staff-birthday-before.json' if args.staff_only else 'direct-profile-before.json')
    result_file = BACKUP_DIR / ('staff-birthday-result.json' if args.staff_only else 'direct-profile-result.json')
    if backup.exists():
        if json.loads(backup.read_text()) != snapshot or result_file.exists():
            raise RuntimeError('Existing backup differs or backfill already completed')
    else:
        with backup.open('xb') as out:
            out.write(json.dumps(snapshot, ensure_ascii=False).encode())
    print(json.dumps({'backup': str(backup), 'sha256': hashlib.sha256(backup.read_bytes()).hexdigest()}, ensure_ascii=False))
    body = "$x=json_decode(base64_decode(" + encoded(snapshot) + "),true);$be=[];$bs=[];foreach($x['before']['employee'] as $v)$be[$v['id']]=$v;foreach($x['before']['staff'] as $v)$bs[$v['id']]=$v;$db=think\\facade\\Db::class;$db::startTrans();$n=['direct'=>0,'birthday'=>0,'audit'=>0];try{foreach($x['plan'] as $p){$old=$be[$p['employee_id']]??null;$e=$db::name('employee')->where('id',$p['employee_id'])->lock(true)->find();if(!$old||!$e||$e['phone']!==$p['phone']||$e['name']!==$p['name']||(int)$e['is_del']!==0)throw new RuntimeException('employee identity changed');if(!$p['staff_ids']){if($db::name('system_store_staff')->where('employee_id',$e['id'])->where('is_del',0)->where('store_id','>',0)->count()>0)throw new RuntimeException('direct employee gained store assignment');$changes=[];foreach(['id_card'=>'card','join_date'=>'join_date','birthday_date'=>'birthday_date'] as $column=>$key){$v=$p[$key];if($v==='')continue;if((string)($e[$column]??'')!==(string)($old[$column]??''))throw new RuntimeException('direct profile changed');if($e[$column]!==null&&$e[$column]!==''&&(string)$e[$column]!==$v)throw new RuntimeException('direct profile conflict');if((string)($e[$column]??'')!==$v)$changes[$column]=$v;}if($changes){$beforeValues=[];foreach($changes as $k=>$v)$beforeValues[$k]=$e[$k];$db::name('employee')->where('id',$e['id'])->update($changes+['update_time'=>time()]);$db::name('employee_change_log')->insert(['employee_id'=>$e['id'],'action'=>'employee_direct_profile_import','target_type'=>'employee','target_id'=>$e['id'],'source'=>'admin','before_data'=>json_encode($beforeValues,JSON_UNESCAPED_UNICODE),'after_data'=>json_encode($changes,JSON_UNESCAPED_UNICODE),'reason'=>'瑞昊员工采集表手机号匹配补全直属员工档案','operator_type'=>'system','operator_id'=>0,'operator_name'=>'Codex授权导入','request_id'=>'rh-direct-profile-20261103','add_time'=>time()]);$n['direct']++;$n['audit']++;}}foreach($p['staff_ids'] as $sid){$oldS=$bs[$sid]??null;$s=$db::name('system_store_staff')->where('id',$sid)->lock(true)->find();if(!$oldS||!$s||$s['employee_id']!=$e['id']||$s['phone']!==$p['phone']||$s['staff_name']!==$p['name']||(int)$s['is_del']!==0)throw new RuntimeException('staff identity changed');if((string)($s['birthday_date']??'')!==(string)($oldS['birthday_date']??''))throw new RuntimeException('staff birthday changed');$birth=$p['birthday_date'];if($birth==='')continue;if($s['birthday_date']!==null&&$s['birthday_date']!==''&&$s['birthday_date']!==$birth)throw new RuntimeException('staff birthday conflict');if((string)($s['birthday_date']??'')!==$birth){$db::name('system_store_staff')->where('id',$sid)->update(['birthday_date'=>$birth,'birthday_type'=>2]);$db::name('employee_change_log')->insert(['employee_id'=>$e['id'],'action'=>'staff_birthday_import','target_type'=>'system_store_staff','target_id'=>$sid,'source'=>'admin','before_data'=>json_encode(['birthday_date'=>$s['birthday_date'],'birthday_type'=>$s['birthday_type']],JSON_UNESCAPED_UNICODE),'after_data'=>json_encode(['birthday_date'=>$birth,'birthday_type'=>2],JSON_UNESCAPED_UNICODE),'reason'=>'瑞昊员工采集表身份证派生公历生日','operator_type'=>'system','operator_id'=>0,'operator_name'=>'Codex授权导入','request_id'=>'rh-direct-profile-20261103','add_time'=>time()]);$n['birthday']++;$n['audit']++;}}}$db::commit();echo json_encode($n,JSON_UNESCAPED_UNICODE);}catch(Throwable $ex){$db::rollback();throw $ex;}"
    result = remote(body)
    result_file.write_text(json.dumps(result, ensure_ascii=False, indent=2))
    print(json.dumps({'committed': result}, ensure_ascii=False))


if __name__ == '__main__':
    main()
