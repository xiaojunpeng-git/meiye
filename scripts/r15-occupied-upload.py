"""One customer-bound upload of the fixed occupied-right release; pending requests
must be confirmed/resumed rather than issued again."""
import pathlib
root=pathlib.Path(__file__).resolve().parents[1]
source=(root/'scripts/r15-booking-upload.py').read_text()
source=source.replace('tests/r15-booking-release','tests/r15-occupied-release')
source=source.replace('第15轮：补齐历史已购权益、五类卡分组、时段完整展开及日期主题底色；保留已验收功能','第15轮：已预约占用权益保留显示、置灰说明原因；保留卡项分组、预约校验及已上线功能')
exec(compile(source,str(root/'scripts/r15-booking-upload.py'),'exec'),globals())
