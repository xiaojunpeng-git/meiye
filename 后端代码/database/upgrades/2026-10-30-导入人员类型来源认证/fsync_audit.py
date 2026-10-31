"""Fsync the already-written audit and parent directory before proof commit."""
import os,sys,stat
path=sys.argv[1]
fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW)
try:
    if not stat.S_ISREG(os.fstat(fd).st_mode):raise RuntimeError('AUDIT_NOT_REGULAR')
    os.fsync(fd)
finally:os.close(fd)
fd=os.open(os.path.dirname(os.path.abspath(path)),os.O_RDONLY|os.O_DIRECTORY)
try:os.fsync(fd)
finally:os.close(fd)
