# R16 会员详情加载性能本地自测

- 基线 HEAD：`b00e65ffef0016518e419ec9832704955aa51055`。
- 本轮涉及的已跟踪文件完整补丁 SHA-256：`0a7f2a4605b0b21092a44187061671c133dea28b0d7022fd71eb1d0e86af24bf`；该指纹包含下述 `CashierShell.vue` 既有多选改动，新测试文件另以文件哈希固定。
- 范围：本地 `18091` 收银台及会员列表的会员详情，只读投影优化；未连接或修改远程实例，未迁移数据库、提交、部署或 push。
- 工作区原本有其他任务改动；`CashierShell.vue` 的专属服务人多选改动在本轮前已存在，本轮没有改动该段。

## 原因与修复

1. 点击详情原本同时发送 `load-member-detail-tab` 和 `open-member-detail`，两者都执行完整投影。两个前端入口现只派发开窗事件，由壳层发起一次读取。
2. 首屏原本读取所有页签明细。现在显式页签只读取当前明细及共享汇总；未指定页签的旧调用仍返回完整投影。概况保留真实卡项、余额、消费、到店及客情提醒。
3. 历史迁入项目赠送原查询从无会员索引的购物车明细出发。等价查询现从会员订单 `uid` 索引出发，再按订单号关联明细；未改筛选、权限和赠送事实来源。

## 实测与回归

- 修前浏览器网络：点击一次产生 2 条详情 POST，首字节约 16.0 秒；单独切页签约 16.3 秒。
- 分段计时：卡项约 1.66 秒；赠送约 13.51 秒，其中历史迁入项目赠送约 14.51 秒且命中 0 条。旧计划扫描约 15,903 条订单；新计划使用 `uid` 索引，预计 4 条订单，等价查询约 5 毫秒。另抽样有历史迁入赠送的会员，新查询返回 1 条。
- 修后浏览器：收银台详情只发 1 条 POST，首字节约 2.20 秒；会员列表详情只发 1 条，约 2.46 秒；赠送/预售页签约 2.08 秒且原有 3 条记录仍显示。已恢复收银台的会员概况弹层。
- `member-detail-lazy-read-mysql.php` 本地只读对照 6/6：概况汇总、提醒与完整投影相同；赠送页记录与完整投影相同；非当前页签未被误标为已加载。概况调用约 1.69 秒。
- `member-detail-single-read-contract.mjs`、`member-detail-v2-contract.mjs`、`member-detail-v2-contract.php`、PHP 语法检查、`git diff --check` 均通过。`cashier-v3` 的 `npm run build` 通过，只有既有大 chunk 提示；该构建不等于 8080 集成包。

## 数据架构自检

- 权威来源仍为旧卡持卡/订单明细、V3 服务及赠送事实、余额权威快照；仅减少读次数、调整等价只读连接顺序。
- 不创建业务事件或事实；业务日、成功时点、历史名称快照、金额精度、退款/作废/冲销、事务、并发和幂等语义均未更改。
- 后端对每次详情读取继续执行会员与当前门店会话的数据权限检查，客户端页签不能扩大范围。旧未指定页签调用保留兼容。
- 未新增索引、迁移或缓存；本地 `EXPLAIN` 已核对选用 `store_order.uid` 与购物车 `oid` 索引。正式实例的执行计划尚未验证，不以本地性能替代线上结果。

## 固定文件

| 类别 | 路径 | SHA-256 |
| --- | --- | --- |
| 前端源码 | `前端代码/cashier-v3/src/layouts/CashierShell.vue` | `7e900e6fe3064854f584ac83d3b87374f68d9819bb477bc01c378c7b02d8f9c2` |
| 前端源码 | `前端代码/cashier-v3/src/views/MemberListView.vue` | `183ad4874c4be154e4515dd217347a7704e3243e326e1ed5bab31bd9fe424e53` |
| 后端源码 | `后端代码/app/services/cashier/v3/member/CashierV3MemberDetailQueryServices.php` | `f88bbc03d3dee916b2242c3f1179d32b9b9ed0113af2b33d6a91ad0219c6ccd9` |
| 自动化测试 | `tests/cashier-v3/js/member-detail-v2-contract.mjs` | `6e667a8b55f433c60b7a277f7ea38a06555f7e229b6179799a1b6cdb119f090b` |
| 自动化测试 | `tests/cashier-v3/js/member-detail-single-read-contract.mjs` | `d6f81f5e24787f2fc677011e9baae3bd6d30d88e4c2bc1e050e4769da59ab910` |
| 自动化测试 | `tests/cashier-v3/php/member-detail-v2-contract.php` | `d907f88802fa5de0085e3ba1f4a5048090cde20b57ba9746b432b88f54510a61` |
| 自动化测试 | `tests/cashier-v3/php/member-detail-lazy-read-mysql.php` | `ad649c6a18f00033bcb52b32c706236e60ab45c4957b6a50d9a7ef226591c7e9` |

本轮业务代码已在 `CashierShell.vue`、`MemberListView.vue` 和 `CashierV3MemberDetailQueryServices.php` 补充或更新职责、单次读取及索引边界注释。数据库迁移、工程脚本均无；`dist/` 为忽略的构建生成物，不作为源码或提交范围。

状态：开发与自测完成，待产品经理对以上固定范围确认验收。
