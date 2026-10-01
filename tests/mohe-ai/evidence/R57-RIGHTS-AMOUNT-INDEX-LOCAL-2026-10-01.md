# R57 会员当前权益金额：本地固定版本与自测

验收时状态：开发与本地自测完成，待产品经理对本固定版本确认验收；未提交、未部署瑞昊、未推送、未上传小程序。验收时基线 HEAD：`d46de4e295c48cdebb2c7668f06f1db54f276f6a`。本次提交前其他任务已将同一分支推进到 `054c7cf6ea8e87246be3a3bb445875d36e340ffe`；下列本闭环文件的 SHA-256 整体指纹仍为 `10f0d1e30a3283314d604bcbcc67ea5a25ada0753a8a3d4ba9e989bac2168eb3`，内容未变。工作区其他任务的改动未纳入本闭环。

## 范围和口径

- 仅为魔核 AI 的“当前权益金额＝剩余项目金额”筛选建立可重建读模型。客户收款、账户余额、赠送余额不计入该金额；源业务事实不变。源表写入在同一事务标记待刷新会员，未刷新和生效／到期边界由权威权益读数覆盖，不以旧快照漏算。
- 会员筛选、明细和导出共用已签名的完整对象集。页面明细最多展示前 20 条；Excel 保留同一结果的完整行，不以屏幕截断数据导出。
- 详情仅需汇总时读取准确的卡项汇总与余额快照，不读取每张卡的项目明细；真正请求权益卡项时仍走原权威详情。导出权限复核改为一次批量复核。Excel 状态查询临时失败时双端有限重试，不重复提交问答或导出任务。
- 已更新注释：`AiMemberRightsAmountIndex.php`、`CashierV3MemberDetailQueryServices.php`、`AiGatewayServices.php`、`AiExportRuntime.php`、`AiPrivateStorage.php`、`MemberAnalysisObjectServices.php`、双端入口以及安装脚本。未保留临时调试日志或试验索引。

## 本地验证

- 本地 `ruihao` 库（不是瑞昊线上）：3 张派生表为 InnoDB、24 个源表触发器齐全，92,726 位会员回填完成，`ready=1`；本地刷新命令处理 1 个待更新会员。临时触发器试验库 `mohe_ai_r57_20261001` 已核对仅含本轮测试表后删除；正式本地读模型保留供验收。
- 只读全量对账：可见 102 家门店；权益金额大于 50,000 元共 977 人；索引筛选约 0.8 秒；977 个签名对象全部可取；汇总详情约 0.9 秒，权威逐人完整详情约 13.61 秒；逐人的权益金额及汇总字段一致；Excel 来源 5,862 行，其中会员汇总表 977 行，私有证据约 1,922,761 字节。
- 回滚事务覆盖“源数据已改变但索引未刷新”和“权益跨生效／到期时间”两种情形：`R57_RIGHTS_OVERLAY_PASS`，事务回滚后源值不变。私有证据大小边界：`R57_PRIVATE_EVIDENCE_PASS`，仅加大加密证据上限，请求与回答维持原上限。
- 真实本地平台端问答“现在会员权益金额超过50000元的会员详情”：21 秒返回 977 人，页面仅前 20 条；导出任务成功，显示“Excel 已生成”和“下载 Excel”入口。下载文件已在本机 Downloads 出现；不把这一结果冒充线上或实体手机验收。
- `member-analysis.php` 22 项、`gateway-components.php` 140 项、`export-runtime-contract.php` 路由／队列／XLSX 契约均通过；修改的 PHP 文件语法、浏览器 JS 语法、部署脚本 shell 语法和 `git diff --check` 通过。
- `mobile-vue3 npm run check` 未全过：48 项中 26 项通过、22 项失败，失败包含仓库中原已缺失的 `mobile-merchant-v1.contract.json`、`mobile-login-client.uts` 等商家端文件及相关旧静态断言。本轮只修改魔核 AI `.uvue` 的 Excel 状态轮询，未改这些缺失文件；小程序实体机未验证。

## 文件清单与 SHA-256

前端源码：

```text
2ad0a64578c9f35d42be0a7d21c8f897a92c82acc7d0758de17e4f3bd3090443  前端代码/mobile-vue3/src/shared/components/mohe-ai-entry.uvue
5f80fe826f9ff4a79d7055b2e3e23098c501bacf95f78b4fa0e76d9a296ff81b  前端代码/shared/mohe-ai/browser-entry.mjs
```

后端源码与迁移：

```text
220e38d688cbdf0ac69bdb33d036065e5c2dadd509f3890ffbdae3144b793a6d  后端代码/app/command/MoheAiRightsIndex.php
2c6447ab2fb5c3e01fb665987cf8c955d9c035f03f67d1d6ae6c8e89d9039f82  后端代码/app/services/ai/AiGatewayServices.php
83705d2dea9fffd7b953a97589fcd14bcde23f732732c8b01661c1ce996b6388  后端代码/app/services/ai/config/AiPrivateStorage.php
2684f118f595a0f5b3d1c8fa0016c16c94584efca43698e30603c27975d585bf  后端代码/app/services/ai/execution/AiExportRuntime.php
5fe2d5a7f45010d05d97e45369d64bc3ac7d09b3114753de240f6c3fe44c2e3e  后端代码/app/services/cashier/v3/member/CashierV3MemberDetailQueryServices.php
bf33a74a16d3bcf688a109dbe20766ae9abc9f3113d87773f8cc418c0a4fa776  后端代码/app/services/query/metric/AiMemberRightsAmountIndex.php
ccb11c4dc12416ad615bf482330754105fa12f2bacc9bfaee58c88e9ce907b52  后端代码/app/services/query/metric/GroupPerformanceMetricReadServices.php
4405c38767da07c55169df29bbd1ce682c73ef6c1ba0eb170493609e90b474ea  后端代码/app/services/query/metric/MemberAnalysisObjectServices.php
634a825800bec81ff52e148a82fb17e6823ba043dec1a17be7fcf9989da97bf3  后端代码/app/services/query/metric/MetricReadTransaction.php
c49ba6e7ba9c9de8faf846bc3215556248413240c2d4eb6102e5c84ef0cf7dcf  后端代码/app/services/query/metric/RegisteredMetricReadServices.php
4db6a98a928eb2762357cdb00e2ecdfe96f530b7a9edaf81ecb7b777ecd94f6b  后端代码/config/console.php
3c1d22deb8b61f261ec1c47e6af6faba9886bc60335fd11927621b1db1bcf764  后端代码/database/upgrades/2026-10-01-魔核AI会员权益金额读模型/00-升级清单.md
45da9f7877b62e656b92a7312d4c53a29e434f7076be8787d62292494975e362  后端代码/database/upgrades/2026-10-01-魔核AI会员权益金额读模型/01-正式升级.sql
573877c65daab210777975b11fb00b18b9a89066d1d1aab0e71a5791fc66a5ce  后端代码/database/upgrades/2026-10-01-魔核AI会员权益金额读模型/02-升级后验证.sql
```

自动化测试与工程脚本：

```text
fccb5dae96729cc01ab221d4b26cf8c79db1f01581615b240ee19594fc28af3e  tests/mohe-ai/r57-full-scope-probe-readonly.php
cdf2491de32deccf259d2d79514ef39f80db8058ba958932fb816629344a0a14  tests/mohe-ai/r57-private-evidence-size.php
22283d63d4da02eb7af2f5aae9eefd64597bf5ca98042714890aae6fd8b07649  tests/mohe-ai/r57-rights-index-overlay-local.php
eb83a9ca5344c64a6579422ceca48a102f5a8bd2ab7bbf5b0c943791699f9d27  scripts/deploy-backend.sh
8e3390029acc2534ecc710b35d66d560fd9ceb5c39cb590d15d1889c9aa5f46d  scripts/install-mohe-ai-systemd.sh
ae2b061f8d1a29602656f9b19f98fb04717c135c2194c64e4545bcf13c44c823  scripts/systemd/mohe-ai-rights-index.service
```

生成物：本机下载目录中的 Excel，仅供本地自测，不入 Git。验收证据：本文件。下一门禁：产品经理对上述固定版本验收；提交后若另获瑞昊部署授权，逐实例核对备份、迁移与回滚并验证线上，再按授权推送。
