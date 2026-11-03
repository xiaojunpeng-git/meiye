"""Prepare auditable R44 Git trees without changing the shared HEAD or index.

Published compatibility is a separate baseline tree. Only accepted R44 files enter
the feature tree; stale whole-file routing/notification reversions are excluded.
No production connection, commit, branch switch or editable source copy is made.
"""
import hashlib
import json
import os
import pathlib
import re
import subprocess

ROOT = pathlib.Path(__file__).resolve().parents[3]
EVIDENCE = ROOT.parent / '任务管理/进行中/第44轮-拼团大屏与推广/发布核对'
FIXED = EVIDENCE.parent / '复核修订候选清单.json'


def git(*args, data=None, env=None):
    return subprocess.check_output(['git', *args], cwd=ROOT, input=data, env=env)


def source(ref, path):
    return git('show', ref + ':' + path).decode()


def sha(data):
    return hashlib.sha256(data).hexdigest()


def create_tree(ref, bodies, label):
    index = EVIDENCE / (label + '.index')
    if index.exists():
        index.unlink()
    env = dict(os.environ, GIT_INDEX_FILE=str(index))
    git('read-tree', ref, env=env)
    for path, body in sorted(bodies.items()):
        blob = git('hash-object', '-w', '--stdin', data=body).decode().strip()
        git('update-index', '--add', '--cacheinfo', '100644', blob, path, env=env)
    return git('write-tree', env=env).decode().strip()


def prepare():
    head = git('rev-parse', 'HEAD').decode().strip()
    shared_index = pathlib.Path(git('rev-parse', '--git-path', 'index').decode().strip())
    if not shared_index.is_absolute():
        shared_index = ROOT / shared_index
    index_hash = sha(shared_index.read_bytes())
    accepted = json.loads(FIXED.read_text())
    baseline = {}
    provenance = {}
    # R15 member login/session/profile behavior is already released, but absent in
    # the shared platform branch. Retain it independently of promotion features.
    member_ref = '416e8d272630cf5a9d581b0bf61b4d4b62906a44'
    paths = git('diff', '--name-only', '-z', head, member_ref, '--', '前端代码/uniapp').decode().split('\0')
    for path in filter(None, paths):
        baseline[path] = git('show', member_ref + ':' + path)
        provenance[path] = member_ref
    existing = {
        # RH/008/010 member H5 receipt names f06a2752 and matches the live entry.
        '前端代码/uniapp/pages/index/components/hotspot.vue': 'f06a27526b8215b3464280fdcea3f7377613d27e',
        '前端代码/uniapp/pages/store_list/index.vue': 'f06a27526b8215b3464280fdcea3f7377613d27e',
        '后端代码/app/controller/admin/v1/marketing/combination/StoreCombination.php': 'a42c4227d607cd40c51937c3327b6ffc0b13bd2c',
        '后端代码/app/services/activity/combination/StoreCombinationServices.php': 'a42c4227d607cd40c51937c3327b6ffc0b13bd2c',
        '后端代码/app/services/cashier/v3/member/CashierV3MemberModule.php': '9631515d4852099b2f630926eabffa764b2405d7',
        # Do not activate another task's not-yet-published command registration.
        '后端代码/config/console.php': '6472685463a1483756069a687a727003f63f95d9',
    }
    for path, ref in existing.items():
        baseline[path] = git('show', ref + ':' + path)
        provenance[path] = ref
    baseline_tree = create_tree(head, baseline, 'baseline')
    bodies = {}
    for category, rows in accepted['files'].items():
        if category == 'evidence':
            continue
        for row in rows:
            path = row if isinstance(row, str) else row['path']
            bodies[path] = (ROOT / path).read_bytes()

    path = '后端代码/route/admin.php'
    working = bodies[path].decode()
    block = re.search(r'        // 第44轮：.*?(?=        //拼团商品详情)', working, re.S).group()
    bodies[path] = source(head, path).replace('        //拼团商品详情', block + '        //拼团商品详情', 1).encode()
    path = '后端代码/route/api.php'
    block = re.search(r'        // 登录会员推广明细.*?(?=        Route::get\(\x27combination/member/records)', bodies[path].decode(), re.S).group()
    bodies[path] = source(head, path).replace('        //活动---拼团\n', '        //活动---拼团\n' + block, 1).encode()
    path = '后端代码/app/services/activity/combination/StorePinkServices.php'
    old = source(head, path)
    working = bodies[path].decode()
    # These three hooks are the complete R44 delta. Payment-time deadlines and
    # after-commit notification isolation remain the deployed authoritative code.
    for marker, end in [
        ('                // 接任沿用原开团奖励快照；', '\n            }'),
        ('                // 发奖独立于通知状态；', '\n                if (in_array'),
        ('                // 新团与奖励配置同事务冻结，', '\n                $res2 ='),
    ]:
        start = working.index(marker)
        finish = working.index(end, start)
        block = working[start:finish]
        preceding = working[:start].splitlines()[-1]
        assert old.count(preceding) == 1, marker
        old = old.replace(preceding + '\n', preceding + '\n' + block + '\n', 1)
    bodies[path] = old.encode()
    path = '后端代码/app/services/cashier/v3/member/CashierV3MemberModule.php'
    old = source(head, path)
    published = baseline[path].decode()
    start = old.index("        if ($keyword !== '') {", old.index('member_name_initial_search name_search'))
    end = old.index('\n        }', start) + len('\n        }')
    pub_start = published.index("        if ($keyword !== '') {", published.index('member_name_initial_search name_search'))
    pub_end = published.index('\n        }', pub_start) + len('\n        }')
    assert old[start:end] in bodies[path].decode()
    bodies[path] = bodies[path].decode().replace(old[start:end], published[pub_start:pub_end], 1).encode()
    path = '后端代码/config/console.php'
    line = next(line for line in bodies[path].decode().splitlines(True) if "'combination:leader-rewards'" in line)
    bodies[path] = baseline[path].decode().replace("    'commands' => [\n", "    'commands' => [\n" + line, 1).encode()
    assert line.encode() in bodies[path]
    path = '前端代码/admin/src/components/memberSurveyReward/index.vue'
    bodies[path] = bodies[path].replace(b':chooseType="99"', b':giftPickerTypes="projectOnly ? [6] : [0, 6]"')
    # Release tooling is itself canonical, reviewable source, never a server patch.
    for script in sorted(pathlib.Path(__file__).parent.glob('*')):
        if script.is_file():
            bodies[str(script.relative_to(ROOT))] = script.read_bytes()
    candidate_tree = create_tree(baseline_tree, bodies, 'candidate')
    result = {'head': head, 'baselineTree': baseline_tree, 'candidateTree': candidate_tree,
              'acceptedManifestSHA256': sha(FIXED.read_bytes()), 'sharedIndexSHA256': index_hash,
              'baselineProvenance': provenance, 'files': {p: sha(b) for p, b in bodies.items()},
              'reconciledFiles': [p for p, b in bodies.items() if (ROOT / p).read_bytes() != b]}
    assert git('rev-parse', 'HEAD').decode().strip() == head
    assert sha(shared_index.read_bytes()) == index_hash
    (EVIDENCE / 'trees.json').write_text(json.dumps(result, ensure_ascii=False, indent=2) + '\n')
    (EVIDENCE / 'baseline.patch').write_bytes(git('diff', '--binary', head, baseline_tree))
    (EVIDENCE / 'r44.patch').write_bytes(git('diff', '--binary', baseline_tree, candidate_tree))
    print(json.dumps({k: result[k] for k in ['head', 'baselineTree', 'candidateTree', 'reconciledFiles']}, ensure_ascii=False))


if __name__ == '__main__':
    prepare()
