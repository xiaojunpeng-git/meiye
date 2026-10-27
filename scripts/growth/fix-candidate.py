"""固定本地待验收文件及指纹；不暂存、不提交，不把共享文件原有改动冒充本轮成果。"""
from pathlib import Path
import hashlib
import json
import subprocess
from datetime import datetime, timezone

root = Path(__file__).resolve().parents[2]
evidence = root.parent / '任务管理/进行中/美业大学成长管理/证据'
baseline = json.loads((evidence / '开工基线.json').read_text())
groups = {'frontend': [], 'backend': [], 'migrations': [], 'tests': [], 'scripts': [], 'generated': []}

def files(group, pattern):
    groups[group].extend(p for p in root.glob(pattern) if p.is_file())

for pattern in ['前端代码/admin/src/pages/growth/**/*', '前端代码/admin/src/api/growth.js', '前端代码/admin/src/router/modules/growth.js', '前端代码/mobile-vue3/src/merchant/pages/growth/**/*', '前端代码/mobile-vue3/src/merchant/api/mobile-growth-client.uts']:
    files('frontend', pattern)
for pattern in ['后端代码/app/services/growth/*.php', '后端代码/app/controller/admin/v1/growth/*.php', '后端代码/app/controller/mobile/merchant/Growth.php', '后端代码/app/command/GrowthProcess.php']:
    files('backend', pattern)
files('migrations', '后端代码/database/upgrades/20261023_growth_*.sql')
files('tests', 'tests/growth/*')
files('scripts', 'scripts/growth/*')
generated = root / '后端代码/app/services/growth/schema-manifest.php'
groups['backend'].remove(generated)
groups['generated'].append(generated)
# 共享入口同时携带原有工作区内容；验收固定全文件，提交时须按本轮补丁边界复核。
for row in baseline['shared_files']:
    path = root / row['path']
    groups['frontend' if row['path'].startswith('前端代码/') else 'backend'].append(path)

head = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=root, text=True).strip()
if head != baseline['head']:
    raise RuntimeError('共享HEAD已改变，须先复核基线再固定验收候选')
records = {}
all_paths = []
for group, paths in groups.items():
    records[group] = []
    for p in sorted(set(paths)):
        relative = p.relative_to(root).as_posix()
        if '__pycache__' in relative or p.suffix == '.pyc':
            continue
        sha = hashlib.sha256(p.read_bytes()).hexdigest()
        records[group].append({'path': relative, 'sha256': sha})
        all_paths.append(relative)

patch = subprocess.check_output(['git', 'diff', '--binary', 'HEAD', '--', *all_paths], cwd=root)
(evidence / 'MYDX-P1-共享已跟踪文件补丁.diff').write_bytes(patch)
tree = hashlib.sha256(json.dumps(records, sort_keys=True, ensure_ascii=False).encode()).hexdigest()
status = subprocess.check_output(['git', 'status', '--short'], cwd=root, text=True).splitlines()
data = {'candidate': 'MYDX-P1-' + tree[:12], 'fixed_at': datetime.now(timezone.utc).isoformat(), 'baseline_head': head, 'source_tree_sha256': tree, 'tracked_patch_sha256': hashlib.sha256(patch).hexdigest(), 'groups': records, 'shared_file_baselines': baseline['shared_files'], 'workspace_status': status, 'acceptance': '开发与自测完成后提交产品经理确认；本文件不代表验收通过', 'remote': '不适用，仅本地隔离环境', 'build': '18081 Vue2当前源 + 18104 HBuilderX当前源；未生成或声明8080集成包', 'note': 'tracked_patch包含共享文件原有改动；新增源码由逐文件SHA固定。任何后续改动都需重新固定并标明补验范围。'}
(evidence / '固定验收候选.json').write_text(json.dumps(data, ensure_ascii=False, indent=2))
print(json.dumps({'candidate': data['candidate'], 'files': sum(len(x) for x in records.values()), 'groups': {k: len(v) for k, v in records.items()}}, ensure_ascii=False))
