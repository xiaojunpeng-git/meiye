"""核对每站公网入口、主资源及登录边界，不提交业务命令。"""
import hashlib
import json
import re
from pathlib import Path
import sys
import urllib.request
import urllib.parse

site = sys.argv[1]
domain = {'rh': 'rh.cc3798.com', '008': '008.cc3798.com'}[site]
base = Path('/private/tmp/mohe-r26-member-transfer-20261022')
manifest = json.loads((base / 'payload/manifest.json').read_text())
def get(path):
    req = urllib.request.Request('https://' + domain + path, headers={'Cache-Control': 'no-cache', 'Accept-Encoding': 'identity'})
    with urllib.request.urlopen(req, timeout=45) as reply:
        if reply.status != 200:
            raise RuntimeError('HTTP failure')
        return reply.read()

html = get('/admin/login')
if hashlib.sha256(html).hexdigest() != manifest['files']['public/system.html']:
    raise RuntimeError('Public admin entry mismatch')
assets = {}
for ref in set(re.findall(r'''(?:src|href)=["']?([^"'\s>]+\.(?:js|css))''', html.decode())):
    parsed = urllib.parse.urlparse(urllib.parse.urljoin('https://' + domain + '/admin/login', ref))
    if parsed.hostname != domain:
        continue  # 第三方播放器引用属于原入口，不能把它误当本站发布资源。
    path = parsed.path
    expected = manifest['files'].get('public' + path)
    digest = hashlib.sha256(get(path)).hexdigest()
    if digest != expected:
        raise RuntimeError('Public asset mismatch: ' + path)
    assets[path] = digest
if len(assets) < 4:
    raise RuntimeError('Missing main assets')
gate = json.loads(get('/adminapi/user/user/store_transfer_batch/preview/1'))
if int(gate['status']) not in (410000, 410001, 410002):
    raise RuntimeError('Login protection failed')
result = {'instance':site,'result':'PASS','entrySha256':hashlib.sha256(html).hexdigest(),'assets':assets,'unauthenticatedPreview':'login required','sourceCommit':manifest['commit']}
(base / (site + '-public-verified.json')).write_text(json.dumps(result,ensure_ascii=False,indent=2)+'\n')
print(json.dumps({'instance':site,'result':'PASS','mainAssets':len(assets),'loginProtection':'PASS'}))
