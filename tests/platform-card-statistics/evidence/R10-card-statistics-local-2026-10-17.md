# 第 10 轮：卡项统计类型本地自测与固定版本

- 源码正本：`美容源码/`；记录时 HEAD：`14c90131a48ac018164562567f111979c88e013f`。
- 范围：平台卡项商品新增、编辑页，在“卡项规则”下增加统计类型单选；`0` 普通卡、`1` 体验卡，默认 `0`。现阶段只保存配置，不改变已售卡、收银、核销和报表口径。
- 数据库：`eb_store_product.card_statistics_type` 非空、默认 `0`；本地 MySQL 已执行幂等升级脚本，历史卡项 `10096/10096` 条为普通卡，没有非零旧值。事务内将一条卡项暂改为 `1` 后读回为 `1`，回滚后读回为 `0`。
- 页面：本地 `18081` 新增卡项页显示“统计类型：普通卡 / 体验卡”，默认普通卡；点击体验卡后单选状态切换，再恢复普通卡。未提交商品表单。
- 校验：两处 PHP 文件 `php -l` 通过；平台端 `npm run build` 成功（仅现有资源大小警告）；`git diff --check` 通过。
- 注释：在 `formModel.js`、`index.vue`、控制器和商品服务中说明数值口径、编辑回显和旧客户端保值边界。
- 现网：未连接、未升级、未部署；各独立客户实例应在获授权发布时逐站备份并执行迁移。

## 本闭环文件与 SHA-256

| 类型 | 文件 | SHA-256 |
| --- | --- | --- |
| 前端源码 | `前端代码/admin/src/pages/product/productAdd/formModel.js` | `ce1587c642bdc556144feb5e00a02578e0a4d75761cd4c2d67179f384fe4f3c6` |
| 前端源码 | `前端代码/admin/src/pages/product/productAdd/index.vue` | `439a25d0a644748e573e903b06bfdd9ecebbffc64d53d1799473ad2873223491` |
| 后端源码 | `后端代码/app/controller/admin/v1/product/StoreProduct.php` | `c0f5eb5718908557de740396acefb25e272814b0b933af95250d4aa374c589cf` |
| 后端源码 | `后端代码/app/services/product/product/StoreProductServices.php` | `f2235d44ec375d9605772b347c570b8c707f2ea959ed1072d8b0a5bb3fab49b8` |
| 数据库迁移 | `后端代码/database/upgrades/2026-10-17-卡项统计类型/00-升级说明.md` | `246b05568bf32090b9c595a85215082b6eca2b9ec482e8ee7afb124054bd2490` |
| 数据库迁移 | `后端代码/database/upgrades/2026-10-17-卡项统计类型/01-正式升级.sql` | `10c7838ee0e750d0d1e89a650994787f47f4d67d26a03b39f3c346fbf1a17d03` |
| 数据库迁移 | `后端代码/database/upgrades/2026-10-17-卡项统计类型/02-升级后验证.sql` | `d18021c46c6fc8af986e45006017962a1a20bf4d454ba5e56ae5f5a60b51270d` |

四个已跟踪文件的工作区 diff SHA-256：`e0fd31684003bd2f63cf98f0edf82379be81a594be0a4936e14e9c671279654f`。`StoreProductServices.php` 在本闭环开始前已有其他任务的未提交修改；该 diff 指纹包含它们，不能把整份 diff 当作本轮新增范围。提交时须逐项审查与暂存。

生成物：平台端本地 `dist/view_admin/`，未纳入源码清单。自动化测试：未新增；本轮使用真实本地表结构、事务回滚读写和页面单选检查验证。
