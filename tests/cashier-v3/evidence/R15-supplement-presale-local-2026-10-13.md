# R15 补交销售人业绩事实与金额展示：本地自测

- 基线 HEAD：`03c5d841c38f2677a7c4789a62a6ca830a2db5cd`。本轮仅本地开发与本地数据库测试；未提交、未推送、未部署。
- 固定文件 SHA-256：结算服务 `aaed67521f9fd41e609e56d6651b4d047b75c5a0d090f5ac30455e98e24a4a08`；列表读取服务 `c7b49022203adaeda9b6cdee91cbd09f5ed1ac154819b326144272b4830a696c`；专项契约 `790ca386f2b1afcf3c0e7007a7e6758b803b5cefa69d0062db8eb599cafc71eb`；本地重开脚本 `ce15fe77f1c1468be26ceefafc944b96b9b6642ca2a7167bd1535b3d6cb4d4fa`。两处后端文件相对 HEAD 的工作区补丁 SHA-256 为 `f9b51c09b60801bfb84acd92aa75f9b9df063ae4c801ff1c778a98587e9664a6`，其中列表读取服务包含下述其他任务改动，不能作为 R15 单独暂存补丁。
- 后端源码：`CashierV3DebtRepaymentServices.php` 在补交业绩事实上保存 `salesperson:presale/postsale`；`CashierV3OrderCenterRecordQueryServices.php` 从未冲销的业绩事实显示 `姓名(售前,金额)` 或 `姓名(金额)`。原单快照本来已保存 `isPreSale=true`，没有修改选择弹窗。两处业务注释说明快照/历史事实边界。
- 自动化测试：`tests/cashier-v3/php/r15-supplement-presale-contract.php` 验证售前/非售前事实和列表金额；`tests/cashier-v3/php/r15-local-supplement-presale-reissue.php` 仅允许本地 `ruihao`、固定原单号/ID，先事务回滚演练，再显式 `--apply`。
- 回归：PHP lint、R15 专项、销售欠款补交契约、独立核算分组契约、订单中心人员展示契约均通过；`git diff --check` 通过。
- 本地真实业务流：`BJ26101300001` 已作废，`BJ26101300002` 成功；原单与新单收款事实依次为 `+9800/-9800/+9800` 分，净额 `9800` 分；欠款已还仍为 `98.00` 元。重开当时新单沈立华正向业绩事实为 `salesperson:presale`、`9800` 分。
- 后续页面真人核对：本地 `18091` 的江苏金湖桓裕店补交记录显示 `BJ26101300003` 为 `闫雨莲(售前,¥88)`，`BJ26101300002` 为 `沈立华(¥98)`；打开后者分配弹窗显示“是否售前=否”、比例 `100%`、业绩金额 `98` 元。`BJ26101300002` 在重开之后已被更正为非售前，本次只读核对和格式修复没有改动该单业务数据。
- 文件边界：`CashierV3OrderCenterRecordQueryServices.php` 同时存在其他任务的退款分类未提交修改；本轮仅其 `supplementSalespeople` 查询和 `supplementSalespersonLabel` 格式化片段，不代为验收或暂存其他改动。无数据库迁移、前端源码或工程脚本变更。
- 待产品经理对本固定版本确认验收；未获确认前不创建本地 commit。部署和 push 未获本轮授权。
