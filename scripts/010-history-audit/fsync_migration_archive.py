#!/usr/bin/env python3
"""Verify and fsync this one migration archive before its DB commit marker."""
import hashlib
import json
import os
from pathlib import Path
import stat
import sys

if len(sys.argv)!=2:
    raise SystemExit('ONE_ARCHIVE_DIRECTORY_REQUIRED')
folder=Path(sys.argv[1])
if not folder.is_absolute() or folder.is_symlink() or not folder.is_dir():
    raise SystemExit('REAL_ABSOLUTE_ARCHIVE_REQUIRED')
manifest_path=folder/'010-history-execution-manifest.json'
manifest=json.loads(manifest_path.read_text())
seal_path=folder/'sealed-archive.json'
seal=json.loads(seal_path.read_text())
if seal['manifestSHA256']!=hashlib.sha256(manifest_path.read_bytes()).hexdigest() or seal['inputs']!=manifest['inputs']:
    raise SystemExit('SEALED_MANIFEST_MISMATCH')
files={**manifest['inputs'],
 '010-history-execution-manifest.json':seal['manifestSHA256'],
 'execute_order_graph_migration.php':manifest['executorSHA256'],
 'fsync_migration_archive.py':manifest['auditSyncSHA256'],
 'audit.ndjson':seal['auditSHA256'],
 'sealed-archive.json':hashlib.sha256(seal_path.read_bytes()).hexdigest()}
for name,expected in files.items():
    if Path(name).name!=name:
        raise SystemExit('INVALID_ARCHIVE_NAME')
    fd=os.open(str(folder/name),os.O_RDONLY|os.O_NOFOLLOW)
    try:
        if not stat.S_ISREG(os.fstat(fd).st_mode):raise RuntimeError('REGULAR_FILE_REQUIRED')
        h=hashlib.sha256()
        while True:
            chunk=os.read(fd,1024*1024)
            if not chunk:break
            h.update(chunk)
        if h.hexdigest()!=expected:raise RuntimeError('ARCHIVE_FILE_CHANGED:'+name)
        os.fsync(fd)
    finally:os.close(fd)
for directory in [folder,folder.parent]:
    fd=os.open(str(directory),os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
    try:os.fsync(fd)
    finally:os.close(fd)
print(json.dumps({'syncedFiles':len(files),'archiveVerified':True}))
