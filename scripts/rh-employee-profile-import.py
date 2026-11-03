#!/usr/bin/env python3
"""One-time Ruihao profile import: unique name bridge, exact phone/name target, guarded transaction.

The collection sheet has no phone column. A unique name in both supplied Ruihao
books resolves its phone; the target must then match both that phone and name.
Only supplied valid values are written, and existing conflicting values abort.
"""
import base64
import collections
import datetime
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess

import openpyxl

SOURCE = Path('/Users/xiaojunpeng/Desktop/副本系统看板数据采集.xlsx')
CONTACT = Path('/Users/xiaojunpeng/Desktop/魔核/瑞昊集团/合肥瑞昊美容咨询服务有限公司-通讯录.xlsx')
BACKUP = Path('/Users/xiaojunpeng/Desktop/魔核/美业/任务管理/数据库升级文件/本地备份/20261103-rh-employee-profile')
EDUCATION = {'中专': '高中', '初中': '高中以下', '小学': '高中以下'}
VALID_EDUCATION = {'本科以上', '本科', '大专', '高中', '高中以下', '其他'}


def id_valid(value):
    """Reject malformed source ID cards; never replace an existing value with a bad one."""
    if not re.fullmatch(r'\d{17}[\dX]', value):
        return False
    weighted = sum(int(a) * b for a, b in zip(value[:17], [7, 9, 10, 5, 8, 4, 2, 1, 6, 3, 7, 9, 10, 5, 8, 4, 2]))
    return '10X98765432'[weighted % 11] == value[-1]


def source_rows():
    """Use a unique name only as a bridge to the phone book; target matching uses phone and name."""
    rows = list(openpyxl.load_workbook(SOURCE, read_only=True, data_only=True).active.iter_rows(min_row=3, values_only=True))
    contacts = list(openpyxl.load_workbook(CONTACT, read_only=True, data_only=True).active.iter_rows(min_row=3, values_only=True))
    names = collections.Counter(str(r[6]).strip() for r in rows if r[6])
    contact_names = collections.Counter(str(r[0]).strip() for r in contacts if r[0])
    contact_by_name = {str(r[0]).strip(): r for r in contacts if r[0] and contact_names[str(r[0]).strip()] == 1}
    candidates = []
    for row in rows:
        name = str(row[6]).strip() if row[6] else ''
        if not name or names[name] != 1 or name not in contact_by_name:
            continue
        phone = re.sub(r'\D', '', str(contact_by_name[name][1] or ''))[-11:]
        if not re.fullmatch(r'1[3-9]\d{9}', phone):
            continue
        card = str(row[7] or '').strip().upper()
        if not id_valid(card):
            card = ''
        date = str(row[9] or '')[:10]
        try:
            datetime.date.fromisoformat(date)
        except ValueError:
            date = ''
        education = EDUCATION.get(str(row[13] or '').strip(), str(row[13] or '').strip())
        if education not in VALID_EDUCATION:
            education = ''
        candidates.append({'name': name, 'phone': phone, 'card': card, 'date': date, 'education': education})
    counts = collections.Counter(r['phone'] for r in candidates)
    return [r for r in candidates if counts[r['phone']] == 1]


def remote(php):
    result = subprocess.run(['ssh', '-q', '-o', 'BatchMode=yes', 'rh-server', 'php'], input=php, text=True, capture_output=True)
    if result.returncode:
        raise RuntimeError(result.stderr[-1500:])
    return json.loads(result.stdout)


def bootstrap(body):
    """Hard bind the production app and schema so no other customer can receive this import."""
    return "<?php $root='/www/wwwroot/rh.cc3798.com';chdir($root);require $root.'/vendor/autoload.php';(new think\\App($root.'/'))->initialize();$cfg=config('database.connections.mysql');if(($cfg['database']??'')!=='ruihao'||(int)($cfg['hostport']??0)!==3306)throw new RuntimeException('instance mismatch');" + body


def php_value(value):
    return "'" + base64.b64encode(json.dumps(value, ensure_ascii=False, separators=(',', ':')).encode()).decode() + "'"


def main():
    os.umask(0o077)
    source = source_rows()
    phones = [r['phone'] for r in source]
    before = remote(bootstrap("$p=json_decode(base64_decode(" + php_value(phones) + "),true);echo json_encode(['employee'=>think\\facade\\Db::name('employee')->whereIn('phone',$p)->field('id,name,phone,is_del,education,education_version')->select()->toArray(),'staff'=>think\\facade\\Db::name('system_store_staff')->whereIn('phone',$p)->field('id,employee_id,phone,staff_name,is_del,id_card,join_date')->select()->toArray()],JSON_UNESCAPED_UNICODE);"))
    emp_by_phone = collections.defaultdict(list)
    staff_by_phone = collections.defaultdict(list)
    for row in before['employee']:
        emp_by_phone[row['phone']].append(row)
    for row in before['staff']:
        staff_by_phone[row['phone']].append(row)
    plan = []
    counts = collections.Counter()
    for row in source:
        matches = emp_by_phone[row['phone']]
        if len(matches) != 1 or matches[0]['name'] != row['name'] or matches[0]['is_del']:
            counts['employee_skipped'] += 1
            continue
        employee = matches[0]
        staff = [s for s in staff_by_phone[row['phone']] if s['employee_id'] == employee['id'] and s['staff_name'] == row['name'] and not s['is_del']]
        for s in staff:
            if row['card'] and s['id_card'] and str(s['id_card']).upper() != row['card']:
                raise RuntimeError('Existing ID card conflict; no writes performed')
            if row['date'] and s['join_date'] and str(s['join_date'])[:10] != row['date']:
                raise RuntimeError('Existing join date conflict; no writes performed')
        counts['employee_matched'] += 1
        counts['staff_matched'] += len(staff)
        plan.append({'source': row, 'employee': employee, 'staff': staff})
    BACKUP.mkdir(parents=True, exist_ok=True, mode=0o700)
    backup_file = BACKUP / 'before.json'
    backup_bytes = json.dumps({'target': 'rh/ruihao', 'source_sha256': hashlib.sha256(SOURCE.read_bytes()).hexdigest(), 'contacts_sha256': hashlib.sha256(CONTACT.read_bytes()).hexdigest(), 'plan': plan}, ensure_ascii=False, default=str).encode()
    with backup_file.open('xb') as out:
        out.write(backup_bytes)
    print(json.dumps({'preflight': counts, 'backup_sha256': hashlib.sha256(backup_bytes).hexdigest(), 'backup_path': str(backup_file)}, ensure_ascii=False))
    # The remote transaction checks every captured old value before any update, so
    # concurrent edits or an altered target cause a full rollback.
    body = "$plan=json_decode(base64_decode(" + php_value(plan) + "),true);$db=think\\facade\\Db::class;$db::startTrans();$n=['education'=>0,'staff'=>0,'audit'=>0];try{foreach($plan as $item){$old=$item['employee'];$src=$item['source'];$e=$db::name('employee')->where('id',$old['id'])->lock(true)->find();if(!$e||$e['phone']!==$src['phone']||$e['name']!==$src['name']||(int)$e['is_del']!==0||(string)$e['education']!==(string)$old['education']||(int)$e['education_version']!==(int)$old['education_version'])throw new RuntimeException('employee changed');if($src['education']!==''&&$src['education']!==(string)$e['education']){$db::name('employee')->where('id',$e['id'])->where('education_version',$old['education_version'])->update(['education'=>$src['education'],'education_version'=>(int)$old['education_version']+1,'update_time'=>time()]);$db::name('employee_change_log')->insert(['employee_id'=>$e['id'],'action'=>'employee_education_update','target_type'=>'employee','target_id'=>$e['id'],'source'=>'admin','before_data'=>json_encode(['education'=>$old['education'],'education_version'=>(int)$old['education_version']],JSON_UNESCAPED_UNICODE),'after_data'=>json_encode(['education'=>$src['education'],'education_version'=>(int)$old['education_version']+1],JSON_UNESCAPED_UNICODE),'reason'=>'瑞昊员工资料采集表手机号匹配更新','operator_type'=>'system','operator_id'=>0,'operator_name'=>'Codex授权导入','request_id'=>'rh-profile-20261103','add_time'=>time()]);$n['education']++;$n['audit']++;}foreach($item['staff'] as $sOld){$s=$db::name('system_store_staff')->where('id',$sOld['id'])->lock(true)->find();if(!$s||$s['employee_id']!=$e['id']||$s['phone']!==$src['phone']||$s['staff_name']!==$src['name']||(int)$s['is_del']!==0||(string)$s['id_card']!==(string)$sOld['id_card']||(string)$s['join_date']!==(string)$sOld['join_date'])throw new RuntimeException('staff changed');$changes=[];if($src['card']!==''&&$src['card']!==(string)$s['id_card'])$changes['id_card']=$src['card'];if($src['date']!==''&&$src['date']!==(string)$s['join_date'])$changes['join_date']=$src['date'];if($changes){$db::name('system_store_staff')->where('id',$s['id'])->update($changes);$before=[];foreach($changes as $k=>$v)$before[$k]=$s[$k];$db::name('employee_change_log')->insert(['employee_id'=>$e['id'],'action'=>'staff_profile_import','target_type'=>'system_store_staff','target_id'=>$s['id'],'source'=>'admin','before_data'=>json_encode($before,JSON_UNESCAPED_UNICODE),'after_data'=>json_encode($changes,JSON_UNESCAPED_UNICODE),'reason'=>'瑞昊员工资料采集表手机号匹配更新','operator_type'=>'system','operator_id'=>0,'operator_name'=>'Codex授权导入','request_id'=>'rh-profile-20261103','add_time'=>time()]);$n['staff']++;$n['audit']++;}}}$db::commit();echo json_encode($n,JSON_UNESCAPED_UNICODE);}catch(Throwable $ex){$db::rollback();throw $ex;}"
    result = remote(bootstrap(body))
    (BACKUP / 'result.json').write_text(json.dumps(result, ensure_ascii=False, indent=2))
    print(json.dumps({'committed': result}, ensure_ascii=False))


if __name__ == '__main__':
    main()
