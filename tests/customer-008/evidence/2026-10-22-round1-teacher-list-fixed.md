# M008-EMERG-R1-STAFF-LIST 固定候选

- 源码基线 HEAD：`58bc3513a0ea94beb09d8ea7460c86bcd5e78e1c`。
- 业务补丁指纹（下列两处会员端源码和一处测试的 `git diff --binary` SHA-256）：`28bc268fa8bdcd386ba040285917a0b9d2328cbee51fa4bcaa58feb38fec6cf0`。
- 状态：本地实现与自测通过；待产品经理对本固定候选确认验收。未本地提交、未部署、未上传小程序。

## 原因与范围

008 线上“广州 雅居乐店”是门店 `129`。旧服务页地址携带 `position_ids=69`，但该店公开员工接口返回的三位老师均属岗位 `19`。2026-10-22 只读请求 `/api/reservation/staff/list?store_id=129&position_ids=69&service_date=2026-10-22&service_duration=120` 返回空列表；同样条件去掉岗位参数返回三位老师。富阳店 `130` 不带岗位参数返回一位不同的老师。

服务老师通用列表现在只按所选门店、关键词、日期/时段及原有预约资格查询。首页员工控件的岗位筛选仍限于控件自身；“查看更多”只带门店 ID。旧菜单地址即使带 `position_ids=69`，列表也不再使用它。原有停用、不可预约和时段资格过滤保持不变。

## 固定文件

| 类别 | 文件 | SHA-256 |
| --- | --- | --- |
| 会员端源码 | `前端代码/uniapp/pages/activity/therapist_list/index.vue` | `29b719781a6f418e3a42784d8146ca6093a96f764ae89e7bb63b02fb223b4657` |
| 会员端源码 | `前端代码/uniapp/pages/index/components/staffList.vue` | `be5368c41343b6d42f2ba80d732954912b620527e18ae14be4efeb56d502ca1f` |
| 自动化测试 | `tests/diy-staff-component/diy-staff-component-contract.mjs` | `e51caba01aee8c83cde975aa6593a6474207628f34410ab18ec7d5005d91cec3` |

本次补充业务注释的源码：上述两个 `.vue` 文件。后端源码、数据库迁移、生产配置均无改动。

## 自测

- `node tests/diy-staff-component/diy-staff-component-contract.mjs`：PASS。验证首页控件仍发送配置岗位，而通用列表及“查看更多”不再发送岗位条件。
- 两个修改后的 Vue2 模板均由 HBuilderX 的 `vue-template-compiler` 编译通过。
- `git -c core.whitespace=cr-at-eol diff --check`：PASS。服务页原文件含 CRLF 行尾。
- HBuilderX 小程序完整候选构建：`/private/tmp/m008-r1-teacher-list-20261022/008-member`，来源为基线 HEAD 加两处业务源码白名单；AppID `wx85d4bfd0b4b6aafb`，接口域名 `https://008.cc3798.com`，构建成功且分包尺寸检查通过。
- 微信开发者工具真实模拟器：使用旧入口参数 `store_id=129&position_ids=69`，雅居乐店显示三位老师；改为 `store_id=130&position_ids=69`，富阳店显示一位对应老师。见 [雅居乐店截图](2026-10-22-r1-teacher-list-store129.png) 与 [富阳店截图](2026-10-22-r1-teacher-list-store130.png)。

当前008线上 H5 仍运行旧包，截图中的空列表未因本地自测而改变。本轮尚未验证手机真机包；开发者工具模拟器结果不等同于已上传体验版。
