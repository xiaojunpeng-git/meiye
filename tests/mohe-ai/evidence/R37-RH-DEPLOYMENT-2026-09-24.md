# R37 瑞昊发布与线上验证

## 授权与固定版本

- 产品经理确认本地成果并要求 commit、部署瑞昊、push。
- 功能提交：`7124e7fd`，包含前序 `3ecdbe47` 的 R37 会员条件与追问闭环。
- 只从该提交 Git archive 提取下列12个后端文件，不从脏工作区同步。
- 目标：`rh.cc3798.com`，`/www/wwwroot/rh.cc3798.com`，数据库只读核对为 `ruihao`。其他实例未修改。
- 不涉及数据库迁移、前端构建、小程序上传或实例配置变更。

## 备份与回滚

- 备份目录：`/www/backups/rh.cc3798.com/20260924-r37-7124e7fd-qYCmKY/`。
- `backend-before.tar.gz` 包含8个原有覆盖文件；归档目录可读取核对。
- SHA-256：`1f073948d7226e3c529eeb4402756e31ed53ffee0a1146bb1ccb912b5ca406c7`。
- 新增4个文件：MemberDetailContinuationResolver、AiMemberDetailAnswerRenderer、AiMemberRightsExportProjection、AiConditionResponseFormResolver。回滚时恢复8个旧文件，并将这4个精确路径的新增文件移出运行目录留存，再成组重启服务。
- 未做业务写库或迁移，代码回滚不恢复/覆盖生产业务数据库。
- 发布前 active_ai_runs=0、active_exports=0，磁盘可用225GB。
- 最近迁移登记包括 `20260923-002-employee-departure-records`、`20260923-001-employee-education-six-levels`、`20260923-001-mohe-ai-member-name-index`；本轮未改迁移状态。

## 发布文件（相对后端目录）

1. app/services/ai/AiGatewayServices.php
2. app/services/ai/context/MemberDetailContinuationResolver.php
3. app/services/ai/contract/AiIntentResultContract.php
4. app/services/ai/contract/AiIntentUnderstandingContract.php
5. app/services/ai/execution/AiRunStore.php
6. app/services/ai/execution/AiExportRuntime.php
7. app/services/ai/presentation/AiMemberDetailAnswerRenderer.php
8. app/services/ai/presentation/AiMemberRightsExportProjection.php
9. app/services/ai/semantic/AiConditionResponseFormResolver.php
10. app/services/metric/MetricDictionaryServices.php
11. app/services/query/metric/MetricSemanticCatalog.php
12. app/services/query/metric/MetricReadViewExportProvider.php

12个文件逐一 SHA-256 与提交归档一致。发布前 Gateway 指纹为 `eddccb00e81d2056f0256d374d454dd0fb6f59ef7863eaa4f68b7a584e8df0ae`；发布后为 `03b4a05ee96c287e0c76813741ab9d28ddd3f9a15ef0e2b4f46accf103640954`。

共享会员读取文件没有覆盖：线上已包含本轮需要的跨门店 cards() 逻辑，另有其他任务已上线的 assertVisible() 差异。本轮保留该文件，发布前后 SHA-256 均为 `36542e90f3d1bf34f79843b4361a635288b9801694f6489fa0fb3d905ba53e11`；AI 仍通过自身 MemberAnalysisObjectServices 校验会员可见关系，不借该差异放宽 AI 权限。不能宣称全站所有文件完全等于本轮 commit。

## 服务与线上页面实测

- PHP语法检查通过；Swoole重启后 manager_count=1。
- execution worker、独立Excel worker、supervisor均active。
- 管理页面HTTP 200。
- 在已登录瑞昊工作台新建验收会话，均勾选同时生成Excel：
  1. 最近30天到店恰好2次，而且实际收款销售额超过1万元的会员权益明细：18秒，返回1位会员、筛选依据与权益；Excel已生成。
  2. 第一位会员的权益明细给我看一下：8秒，正确延续该会员权益；Excel已生成。
  3. 这个月现金业绩多少：19秒，现金业绩148006元，期间2026-09-01至2026-09-24；Excel已生成，没有继承为权益输出。
- 点击了第一条下载按钮；权益后台实体文件均使用只读脚本实际回读，不以按钮状态替代内容核对。

| 任务 | 逐格回读 | 行数 | SHA-256 |
| --- | --- | --- | --- |
| uqe_a07646743c6805c77d2b24ba27342325 | 通过 | 40，含2行筛选依据 | edb6bcc64b41e5bd9c50ed73903777c0737bc965ae64e69d574ed3f89510ece1 |
| uqe_602463ab617e9ebda41939b680647494 | 通过 | 38，纯权益 | 2c0e03063fb3672d2192a1859bd00b6a00f5eb46861d129b94a558a7b02b92d5 |

会话保留，导出开关恢复关闭。以上是实际页面问答、后台文件一致性测试，不是逐笔业务账务审计或微信真机下载验收；不将单次耗时视为P95。线上验证通过，可以按用户授权push。

## 提交和推送边界

本记录属于部署结果归档，不增加业务改动。其他任务未提交的收银、预约、登录、配置、迁移等继续留在工作区。推送采用普通fast-forward，不force，不改服务器Git工作区。
