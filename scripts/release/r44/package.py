"""Prepare fixed, scoped R44 payloads locally; never connect to or modify a site.

Business subtrees must exactly match the tested builds. Payload metadata is a
review artifact, not deployment approval: remote before-hashes, the accepted
commit and database backup must still be fixed immediately before installation.
"""
import hashlib
import io
import json
import pathlib
import subprocess
import tarfile

ROOT = pathlib.Path(__file__).resolve().parents[3]
E = ROOT.parent / '任务管理/进行中/第44轮-拼团大屏与推广/发布核对'


def git(*args):
    return subprocess.check_output(['git', *args], cwd=ROOT)


def digest(body):
    return hashlib.sha256(body).hexdigest()


def prepare():
    plan = json.loads((E / 'trees.json').read_text())
    tree = plan['candidateTree']
    # An engineering-only tree change does not require another business build;
    # compare all relevant source subtrees instead of trusting a filename/SHA tag.
    build_names = plan.get('frontendBuilds') or {
        'admin': 'ebd40c349759-candidate-admin-build.json',
        'cashier': '6fe698739f31-candidate-cashier-v3-build.json',
    }
    builds = {k: json.loads((E / v).read_text()) for k, v in build_names.items()}
    for name, build in builds.items():
        assert build['result'] == 'PASS' and build['sourceStable']
        assert not git('diff', '--name-only', build['tree'], tree, '--',
                       '前端代码/admin' if name == 'admin' else '前端代码/cashier-v3',
                       '前端代码/inventory-vue3', '前端代码/shared')
    fixed = json.loads((E.parent / '复核修订候选清单.json').read_text())
    backend = [row['path'] for row in fixed['files']['backend']]
    migrations = [p for p in plan['files'] if p.startswith('后端代码/database/upgrades/') and p.endswith('.sql')]
    release = E / ('prepared-' + tree[:12])
    release.mkdir(exist_ok=False)
    for site in ['rh', '008', '010']:
        member = json.loads((E / ('6fe698739f31-' + site + '-h5-build.json')).read_text())
        assert member['result'] == 'PASS' and member['sourceStable']
        assert member['origin'] == 'https://' + site + '.cc3798.com'
        assert not git('diff', '--name-only', member['tree'], tree, '--',
                       '前端代码/uniapp', '前端代码/shared', 'scripts/build/member-mini-css-dedupe.cjs')
        content = {}
        for p in backend:
            content['formal/' + p.removeprefix('后端代码/')] = git('show', tree + ':' + p)
        for name, build in [*builds.items(), ('member', member)]:
            for relative, expected in build['assets'].items():
                # Admin public resources include copies belonging to other tasks;
                # only this terminal's entry and webpack output are published.
                if name == 'admin' and relative != 'system.html' and not relative.startswith('view_admin/'):
                    continue
                body = (pathlib.Path(build['output']) / relative).read_bytes()
                assert digest(body) == expected, relative
                prefix = 'formal/public/' + ('view_cashier_v3/' if name == 'cashier' else '')
                target = prefix + relative
                assert target not in content or content[target] == body
                content[target] = body
        for path in migrations:
            content['migrations/' + path.removeprefix('后端代码/database/upgrades/')] = git('show', tree + ':' + path)
        entries = {'formal/public/system.html', 'formal/public/index.html', 'formal/public/view_cashier_v3/index.html'}
        # Preserve all existing hashed resources. New entries are switched last.
        content = dict(sorted(content.items(), key=lambda item: (item[0] in entries, item[0])))
        manifest = {'site': site, 'tree': tree, 'status': 'prepared-not-approved-or-deployed',
                    'previewAuthorized': False, 'backendAllowlist': [p.removeprefix('后端代码/') for p in backend],
                    'files': {p: digest(b) for p, b in content.items()},
                    'builds': {k: v['tree'] for k, v in [*builds.items(), ('member', member)]}}
        manifest_bytes = (json.dumps(manifest, ensure_ascii=False, indent=2) + '\n').encode()
        (release / (site + '-payload.json')).write_bytes(manifest_bytes)
        with tarfile.open(release / (site + '-payload.tar.gz'), 'w:gz') as archive:
            for path, body in content.items():
                entry = tarfile.TarInfo('payload/' + path)
                entry.size, entry.mode, entry.mtime = len(body), 0o444, 0
                archive.addfile(entry, io.BytesIO(body))
        print(json.dumps({'site': site, 'tree': tree, 'files': len(content),
                          'manifestSHA256': digest(manifest_bytes)}, ensure_ascii=False))


if __name__ == '__main__':
    prepare()
