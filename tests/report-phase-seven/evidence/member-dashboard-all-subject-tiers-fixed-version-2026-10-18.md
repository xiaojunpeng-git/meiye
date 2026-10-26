# R13-00002 会员看板顾客净额分层修正固定版本（2026-10-18）

- 基线 HEAD：`a42071d2e1125d42e0ff472d9798e0f729be0533`；这是已部署的上一轮互斥分层版本。本轮尚未提交或部署。
- 本轮口径：统计期内每名会员按会员 ID 汇总全部有符号实际业绩事实；无会员 ID 的游客/未建档记录按门店单据汇总，缺单据时用可追溯事实身份。每个对象的净额只进入一个金额档，负净额归入最低档。主分层六档人数、金额与顾客概况“消费”人数、实际业绩完全一致；新客分层在同样对象口径下排除来源 A，独立给出合计。
- 事实源：后端统一 `RegisteredMetricReadServices::categoryRows('actual_performance')`，沿用原日期与权限范围；不改写生产事实和数据库。前端只显示服务端分档与合计，金额内部仍以分精度对账。
- 原差额解释：线上原六档仅收入本期有正向业绩的会员。未建档/游客事实及仅有零或负向业绩的会员被遗漏，因此原六档金额与完整实际业绩不同。本轮将二者依稳定身份计入对应档位，没有建立零人调整行。

## 文件与指纹

| 类型 | 源码文件 | SHA-256 |
| --- | --- | --- |
| 后端源码 | `后端代码/app/services/report/MemberManagementDashboardServices.php` | `ed15a8a2751c8caa977f6ba90eedbc85d3f25897f163e1484f5454421cd61d97` |
| 前端源码 | `前端代码/cashier-v3/src/views/MemberDashboardView.vue` | `71f0358643513281c1d11e2f59e7dfa567317d8dde107884a3b57d71bd8b1cdd` |
| 自动化测试 | `tests/report-phase-seven/php/member-dashboard-contract.php` | `3cc0d4e2d0491775a4c59e216bc8a95084210a84ec1001ba340fc91e092e5f7e` |
| 自动化测试 | `tests/report-phase-seven/php/member-dashboard-exclusive-tiers-contract.php` | `0562618177b12803b303a77d7f03df347b27b66b97ac04e132440cf70538fe73` |
| 自动化测试 | `tests/report-phase-seven/php/member-dashboard-exclusive-tiers-local-readonly.php` | `5cf8bcc7c45d6348655cc1b27eae3bdea4aa45b17d43ad30d2cb938c0d131b7e` |

- 上述五文件相对基线的二进制补丁 SHA-256：`f976959b56c2f602f8b962f95e7985f8f6741eedf4a58c440051f7b9e7be7272`。
- `member-dashboard-contract.php` 在本轮开始前已有其他任务未提交的“完整实际业绩事实”断言修改；本轮在其上做了本地验证所需的断言更新。该文件含混合改动，本轮提交不暂存它；工作区保留其当前内容和测试通过证据。提交的专项分层合同测试、只读对账测试与源码为本轮完整闭环，不夹带其他任务的断言修改。
- 新增或更新的业务注释：`MemberManagementDashboardServices.php` 的顾客/游客身份、唯一归档、合计守恒说明；`MemberDashboardView.vue` 沿用预览净额分层说明，新增合计和对齐为直观呈现。
- 数据库迁移：无。工程脚本：无。生成物：仅本地 `cashier-v3/dist/` 验证包；不作为发布候选，工作区还有其他未验收改动。

## 自测

- `php -l` 后端服务：通过。
- `member-dashboard-contract.php`：16 项通过。
- `member-dashboard-exclusive-tiers-contract.php`：12 项通过，覆盖仅负向会员、游客同单销售/退款、来源 A 排除、负净额、重叠区间与缺失身份拒绝。
- 本地瑞昊 Docker `ruihao` 数据库只读事务（2026-10-01 至 2026-10-18，102 门店）：顾客概况消费人数 = 主分层合计 `1,126`；概况实际业绩 = 主分层合计 `516,352,500` 分（显示 `¥5,163,525`）；新客分层 `232`、`25,641,400` 分。游客事实 22 行归为 20 个单据，未发生同一销售事实正负记录跨不同单据的样本冲突。
- 本地热更新页面 `http://127.0.0.1:18091/view_cashier_v3/#/platform/member-management-dashboard` 的“本月”浏览器实测：概况 `1,126 / ¥5,163,525`，会员分层合计 `1,126 / ¥5,163,525`；新客分层合计 `232 / ¥256,414`。两张表六列的标题、数值、合计均左对齐。
- `npm run build`：prebuild 70 项通过，Vite 构建通过。产物 `dist/index.html` SHA-256 `ac360fe6ea1b4b750da0da73a8511ac5c5586bf4184672aed51c22f0d246f79b`，主 JS `a19d06cfac26ee5c630c58afe28417b4323828d49cfe5b2c1fd430dc834fbc14`，主 CSS `b15eca75a4f28f1006d686ffc03bc0874abe8fa09dfb6b269228446d20a007a5`。该本地构建包含当前工作区其他未验收文件，禁止直接部署。
- `git diff --check` 对本轮五个源码/测试文件通过。

## 后续门禁

本轮开发与自测完成，待产品经理对上述固定文件指纹与口径明确确认验收。确认后按项目规则精准暂存并提交；本轮尚无新版本的部署、push 或 008 发布授权。
