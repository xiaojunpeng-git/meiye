"""Rebuild the exact R12 candidate exported read-only from the unique Git source.

Exports are immutable build inputs, not editable worktrees.  Native Node avoids
Docker's architecture-emulation memory failure; concurrency is process-local.
"""
import hashlib
import io
import json
import os
import pathlib
import subprocess
import sys
import tarfile

ROOT = pathlib.Path(__file__).resolve().parents[1]
E = ROOT.parent / '_local_evidence/2026-11-06-r12-data-permissions/release'
PLAN = json.loads((E / 'precise-candidate.json').read_text())
TREE = PLAN['candidateTree']
SNAP = pathlib.Path('/private/tmp/mohe-r12-' + TREE[:12] + '-readonly')


def git(*args):
    return subprocess.check_output(['git', '-C', str(ROOT), *args])


def sha(data):
    return hashlib.sha256(data).hexdigest()


def source_hashes(package):
    paths = git('ls-tree', '-rz', '--name-only', TREE + ':' + package).decode().split('\0')
    return {p: sha((SNAP / package / p).read_bytes()) for p in paths if p}


def prepare():
    if SNAP.exists():
        return
    SNAP.mkdir()
    packages = ['前端代码/admin', '前端代码/mobile-vue3', '前端代码/shared', '后端代码/app', 'tests/mobile-warehouse', 'tests/mobile-auth']
    with tarfile.open(fileobj=io.BytesIO(git('archive', TREE, *packages))) as archive:
        archive.extractall(SNAP)
    for p in SNAP.rglob('*'):
        if p.is_file():
            p.chmod(0o444)
    (SNAP / '前端代码/admin/node_modules').symlink_to(ROOT / '前端代码/admin/node_modules', target_is_directory=True)


def build(kind):
    prepare()
    package = '前端代码/' + ('admin' if kind == 'admin' else 'mobile-vue3')
    before = source_hashes(package)
    output = E / (TREE[:12] + '-' + kind)
    env = dict(os.environ, NODE_ENV='production')
    if kind == 'admin':
        helper = E / 'build-concurrency.cjs'
        helper.write_text('// Generated build-only concurrency cap, no business source changes.\nconst os=require("os");const cpus=os.cpus;os.cpus=()=>cpus().slice(0,2);\n')
        env['NODE_OPTIONS'] = '--require=' + str(helper) + ' --max-old-space-size=4096'
        command = ['node', './node_modules/@vue/cli-service/bin/vue-cli-service.js', 'build', '--mode=production', '--dest', str(output)]
        cwd = SNAP / package
    else:
        plugins = pathlib.Path('/Applications/HBuilderX.app/Contents/HBuilderX/plugins')
        env.update(UNI_INPUT_DIR=str(SNAP / package), UNI_OUTPUT_DIR=str(output), UNI_HBUILDERX_PLUGINS=str(plugins), UNI_APP_X='true', UNI_UTS_PLATFORM='web')
        command = [str(plugins / 'node/node'), str(plugins / 'uniapp-cli-vite/node_modules/@dcloudio/vite-plugin-uni/bin/uni.js'), 'build', '-p', 'h5', '--base', '/view_mobile_v3/']
        cwd = plugins / 'uniapp-cli-vite'
    logpath = E / (kind + '-precise-build.log')
    with logpath.open('w') as log:
        completed = subprocess.run(command, cwd=cwd, env=env, stdout=log, stderr=subprocess.STDOUT)
    if completed.returncode:
        raise RuntimeError(kind + ' build failed; see ' + str(logpath))
    if before != source_hashes(package):
        raise RuntimeError('Immutable build input changed')
    assets = {str(p.relative_to(output)): sha(p.read_bytes()) for p in output.rglob('*') if p.is_file()}
    result = {'tree': TREE, 'source': str(SNAP / package), 'output': str(output), 'sourceSHA256': before, 'assets': assets, 'result': 'PASS', 'sourceStable': True, 'remoteSideEffects': False}
    (E / (kind + '-precise-build.json')).write_text(json.dumps(result, ensure_ascii=False, indent=2) + '\n')
    print(json.dumps({'kind': kind, 'tree': TREE, 'inputs': len(before), 'assets': len(assets), 'result': 'PASS'}), flush=True)


if __name__ == '__main__':
    if sys.argv[1] == 'prepare':
        prepare()
    else:
        build(sys.argv[1])
