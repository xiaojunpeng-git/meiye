# R8E 未识别名称业绩问题引导：本地固定验收版本

- 基线 HEAD：`2c9a671f77b1cfd7e4be35edcace1f94887636aa`
- 补丁指纹（两个后端 diff 加新测试文件）：`ca986ea5eeeccf63a33346b5575cc7d5f3ef13b627bc709f2534a42bed5c0173`
- 范围：仅平台魔核 AI 的简单“名称＋业绩”问题在无可执行建议时改为具体对象提示；不创建身份绑定、不读经营事实、不改变商家／收银端卡片。

## 文件清单与 SHA-256

| 分组 | 文件 | SHA-256 |
| --- | --- | --- |
| 后端源码 | `后端代码/app/services/ai/AiGatewayServices.php` | `c3a802b4746d4f16d11005d1709ca282215a9bb0515eac058a92da9f063896fd` |
| 后端源码 | `后端代码/app/services/ai/semantic/AiDeterministicSummaryAdmission.php` | `3a25a7c4f6d38f1a591fba2aef6c8a2f897cdb8e82d8fe5c6df2d8d4301dcdd8` |
| 自动化测试 | `tests/mohe-ai/r8e-unresolved-named-performance-guidance.php` | `9121d0cc7dbb5dc632e421a04e89ad621155e879ac98059339a338dd22c6240d` |
| 验收证据 | 本文件 | 本文件不进入自身指纹 |

前端源码、数据库迁移、工程脚本及生成物：无。本次新增／更新业务注释位于两个后端源码文件，说明仅做展示引导及身份绑定边界。

## 本地验证

1. `php -l` 检查两个后端文件与新测试文件，通过；`git diff --check` 通过。
2. `php tests/mohe-ai/r8e-unresolved-named-performance-guidance.php`：10 项通过，含普通问题、复合问题与收银端不变检查。
3. `php tests/mohe-ai/r8d-named-person-period-card.php`：9 项通过；`php tests/mohe-ai/deterministic-summary-admission.php`：60 项通过。
4. 本地 `mohe-platform-app` 与 `mohe-ai-execution-worker` 重启加载当前源码后，在 `http://127.0.0.1:18081/admin/setting/mohe-ai` 真实浏览器工作台重新输入“管慧的业绩是多少？”。页面显示“我无法在当前可查询范围内确认‘管慧’对应的员工或门店。请核对名称；若查询员工，请补充所属门店，若查询门店，请写门店全名。”，选择项为“更正名称或补充所属门店”。未展示虚构业绩。
5. `php tests/mohe-ai/gateway-integration.php` 在原有广泛排行断言（第 219 行，“做得最好的门店是哪家”）处失败；该问题不进入本次名称解析分支，未计作本闭环通过项，需另案排查。

## 限制与发布状态

“管慧”在当前本地授权员工目录中无可绑定记录；本次只改引导，不推断她是否存在于其他实例或未授权范围。代码与自测完成，待产品经理确认此固定版本；未提交、未部署、未 push。
