"""Instance-bound allowlist, backup, verification and rollback for card occupancy.
Preserve all protected platform/cashier/configuration fingerprints."""
import pathlib,sys
root=pathlib.Path(__file__).resolve().parents[1]
source=(root/'scripts/r15-booking-publish.py').read_text().rsplit("\nif __name__=='__main__':",1)[0]
source=source.replace('tests/r15-booking-release','tests/r15-card-release').replace('r15-booking-runtime.php','r15-card-release-runtime.php')
exec(compile(source,str(root/'scripts/r15-booking-publish.py'),'exec'),globals())
if __name__=='__main__':
 mode=sys.argv[1]
 if mode=='prepare':
  for s in ['rh','008','010']:prepare(s)
 elif mode=='deploy':deploy(sys.argv[2])
 elif mode=='push':push()
