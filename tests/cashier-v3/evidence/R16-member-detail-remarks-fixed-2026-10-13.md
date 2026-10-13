# R16 会员详情加载与明细备注固定版

- 当前基线 HEAD：`49650e632afe81f60d6c69e19176b58f1a6d4321`（其他任务在本轮自测期间推进；本轮未提交）。
- 本固定版包含尚未验收的会员详情加载优化，以及本次明确要求的两处“明细备注”列；上一份性能自测见 `R16-member-detail-latency-local-2026-10-13.md`，其文件哈希已由本记录更新。
- 已跟踪目标文件完整补丁 SHA-256：`7e89b33d23f361ab8f566da792d975bfff93e880e5b19036225fa229aee6cd64`。此指纹包含 `CashierShell.vue` 在本轮前已有的专属服务人多选改动；验收与后续暂存须区分其归属。
- 只修改本地唯一源码正本与测试。未改数据库、未连接瑞昊、未提交、部署或 push。

## 实现口径

1. 服务记录在“核销操作人”左侧展示本次服务事实保存的 `detail_remark_snapshot`，无备注显示“—”。
2. 卡操作记录在“门店”左侧展示对应业务原始备注：购卡取发卡凭证对应销售明细；核销取服务事实；卡变更、退款和取消欠款取各自原因快照；欠款补交取该笔记账收款明细的备注。历史业务没有对应备注时显示“—”，不借用当前商品或其他订单的备注。
3. 两处只读投影均维持原会员/卡号与门店会话权限，不改变卡权益、订单、服务、收款或业绩事实。未新增报表统计口径。

## 自测

- 本地浏览器实际操作：服务记录表头为“劳动业绩 → 明细备注 → 核销操作人”；卡操作表头为“金额 → 明细备注 → 门店”，卡历史 8 列及 4 条原记录均可见。
- 本地 MySQL 只读集成对照：当前会员 9 条服务记录、4 条卡历史、12 次来源行对照通过；另抽 1 条非空服务备注和 1 条非空购卡备注，投影均与原始快照一致。
- 前一项按页签加载回归 6/6，概况约 2.38 秒，赠送记录仍为 3 条；单次读取契约通过。
- PHP 会员详情契约 15/15、前端会员详情契约、PHP 语法检查、`git diff --check` 通过；`cashier-v3 npm run build` 通过，仅有既存的大 chunk 提示。构建不是 8080 集成包。

## 数据架构自检

- 权威源：销售明细、服务事实、卡操作/退款/欠款处理权威行；最小展示粒度仍是一条服务或卡历史操作。
- 本次不写事实或事件，因此业务日/成功时点、事务、并发、幂等、冲销和历史快照均保持原状；展示历史保存的备注，不用当前目录数据回填。
- 查询限于原会员与实体 ID，并继续由后端执行数据权限；金额不在前端重算。购卡按发卡凭证的销售明细 ID 查询，补交按还款 ID 汇集收款备注；本地集成已对照来源行。无迁移、缓存或跨实例影响。

## 固定文件

| 类别 | 路径 | SHA-256 |
| --- | --- | --- |
| 前端源码 | `前端代码/cashier-v3/src/layouts/CashierShell.vue` | `7e900e6fe3064854f584ac83d3b87374f68d9819bb477bc01c378c7b02d8f9c2` |
| 前端源码 | `前端代码/cashier-v3/src/views/MemberListView.vue` | `183ad4874c4be154e4515dd217347a7704e3243e326e1ed5bab31bd9fe424e53` |
| 前端源码 | `前端代码/cashier-v3/src/components/member/MemberDetailOverlay.vue` | `f406cf2e8a71662d4a596c6bd5bc659ba9fa913dff97aba218a2526ac5472d95` |
| 后端源码 | `后端代码/app/services/cashier/v3/member/CashierV3MemberDetailQueryServices.php` | `0acdfdc2af0135dd2fc328c04308c5d971d623f554eb9fc0a72f7eb27fe0d6b5` |
| 自动化测试 | `tests/cashier-v3/js/member-detail-v2-contract.mjs` | `ceca032ca04dad7e07c9890d715c5765ad0aa31b53308b112e7f4a505a210030` |
| 自动化测试 | `tests/cashier-v3/js/member-detail-single-read-contract.mjs` | `d6f81f5e24787f2fc677011e9baae3bd6d30d88e4c2bc1e050e4769da59ab910` |
| 自动化测试 | `tests/cashier-v3/php/member-detail-v2-contract.php` | `8d47c8a961d914b4b4790a066d5ae0f839383698810af7fdecb4e503fa9db857` |
| 自动化测试 | `tests/cashier-v3/php/member-detail-lazy-read-mysql.php` | `ad649c6a18f00033bcb52b32c706236e60ab45c4957b6a50d9a7ef226591c7e9` |
| 自动化测试 | `tests/cashier-v3/php/member-detail-remarks-mysql.php` | `6980caec92fe5d006ced0a4477f25ef992b6ce14eccc8707cfc44d6b31699a6e` |

本次业务注释补充于 `MemberDetailOverlay.vue` 的投影契约、`CashierV3MemberDetailQueryServices.php` 的发卡/操作备注来源与服务事实边界；前一项加载优化的注释另见上一份记录。数据库迁移、工程脚本均无；`dist/` 为忽略生成物。

状态：开发与自测完成，待产品经理确认以上固定版验收。
