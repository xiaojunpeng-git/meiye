# -*- coding: utf-8 -*-
"""Build only the canonical local merchant source; record all inputs before/after, with no remote side effects."""
from pathlib import Path
import hashlib
import json
import os
import subprocess

canonical = Path(__file__).resolve().parents[1]
source = canonical / '前端代码/mobile-vue3'
evidence = canonical.parent / '_local_evidence/2026-11-06-r12-management-board'
plugins = Path('/Applications/HBuilderX.app/Contents/HBuilderX/plugins')


def fingerprints():
    return {str(p.relative_to(source)): hashlib.sha256(p.read_bytes()).hexdigest()
            for p in source.rglob('*') if p.is_file()
            and not set(p.relative_to(source).parts).intersection({'node_modules', 'dist', 'unpackage', '.git'})}


before = fingerprints()
env = dict(os.environ, UNI_INPUT_DIR=str(source), UNI_OUTPUT_DIR=str(evidence / 'mobile-build'),
           UNI_HBUILDERX_PLUGINS=str(plugins), UNI_APP_X='true', UNI_UTS_PLATFORM='web', NODE_ENV='production')
with (evidence / 'build.log').open('w') as log:
    result = subprocess.run([str(plugins / 'node/node'), str(plugins / 'uniapp-cli-vite/node_modules/@dcloudio/vite-plugin-uni/bin/uni.js'),
                             'build', '-p', 'h5', '--base', '/view_mobile_v3/'], cwd=plugins / 'uniapp-cli-vite', env=env,
                            stdout=log, stderr=subprocess.STDOUT)
after = fingerprints()
record = {'source': str(source), 'head': subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=canonical, text=True).strip(),
          'sources': before, 'sourceStable': before == after, 'exitCode': result.returncode,
          'output': str(evidence / 'mobile-build'), 'remote': 'not applicable'}
if result.returncode == 0:
    record['assets'] = {str(p.relative_to(evidence / 'mobile-build')): hashlib.sha256(p.read_bytes()).hexdigest()
                        for p in (evidence / 'mobile-build').rglob('*') if p.is_file()}
(evidence / 'build-manifest.json').write_text(json.dumps(record, ensure_ascii=False, indent=2))
if result.returncode != 0 or before != after:
    raise RuntimeError('Build failed or source changed during compilation; see build.log')
print('BUILD PASS, stable canonical inputs:', len(before))
