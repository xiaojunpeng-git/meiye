"""One upload request per named member app, preserving pending task receipts."""
import pathlib
root=pathlib.Path(__file__).resolve().parents[1]
source=(root/'scripts/r15-booking-upload.py').read_text().replace('tests/r15-booking-release','tests/r15-card-release')
source=source.replace('第15轮：补齐历史已购权益、五类卡分组、时段完整展开及日期主题底色；保留已验收功能','第15轮：卡项详情预约占用与可预约次数、占满禁选及预约提交反馈；保留已验收功能')
exec(compile(source,str(root/'scripts/r15-booking-upload.py'),'exec'),globals())
