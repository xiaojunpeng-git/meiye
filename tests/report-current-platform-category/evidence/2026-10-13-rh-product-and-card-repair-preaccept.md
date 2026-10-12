# 瑞昊商品分类与卡项分摊修复：待验收固定版本

- 基线 HEAD：`3cd381deb38a340b6bd42a84adc485b76652a7fa`
- 范围：当前平台商品分类解析器；瑞昊一次性商品分类与 17 笔卡项分摊事实修复；三条专项测试。未修改收银页面。
- 源码清单指纹：按下列顺序执行 `shasum -a 256 <文件...> | shasum -a 256` 得 `078a9a481ebf6cdd3d359e373234dd4d658e92b4399b927d42b61c6ed2c35d6b`。

| 文件（相对 `美容源码/`） | SHA-256 |
| --- | --- |
| `后端代码/app/services/report/StoreReportCurrentProductCategoryServices.php` | `4d512b821428ee0470846fc5dabea4df96f24cf4822d142a1a5768496b84e910` |
| `后端代码/database/upgrades/2026-10-13-瑞昊商品分类与卡项分摊修复/00-升级说明.md` | `1a423f66b9658e6f975842cc35ce1a3bcb5b5fc67ce343f1171cf4568a465695` |
| `后端代码/database/upgrades/2026-10-13-瑞昊商品分类与卡项分摊修复/01-审计表.sql` | `1052d4418b56099b30038d42148317ebe849f403cf922141202395a915f7751b` |
| `后端代码/database/upgrades/2026-10-13-瑞昊商品分类与卡项分摊修复/02-修复.php` | `db861844acac7f863087568768cf0279a412aafbe89bdb195da2b3224f9a73e2` |
| `后端代码/database/upgrades/2026-10-13-瑞昊商品分类与卡项分摊修复/03-验证.sql` | `e2939480aa7c50260e51fb9913af5ee1277b33c4c69960bf9b6598a7617749fb` |
| `tests/report-current-platform-category/php/rh-repair-contract.php` | `5c1e779e33bbf16da36a604b72ab266c61fc1383c541d3198d32537719db4244` |
| `tests/report-current-platform-category/php/deleted-platform-product-rollback.php` | `63b851b10e19b88e2314beeab5730cd2ad82bd991ec25517e4ff18014d97ccf4` |
| `tests/report-current-platform-category/php/rh-repair-local-insert-rollback.php` | `182a82bfa629e8e08e43635c23aaef1b1319b463a313f6dac846b325bca29940` |

## 自测证据

- `php -l`：5 个 PHP 源码与测试文件均通过。
- `php tests/report-current-platform-category/php/rh-repair-contract.php`：7 项通过，覆盖分位分摊、无效分类和事实自然键。
- `docker exec -i mohe-platform-app env BACKEND_ROOT=/var/www/html php /dev/stdin < tests/report-current-platform-category/php/deleted-platform-product-rollback.php`：3 项通过，本地商品变更已回滚。
- `docker exec -i mohe-platform-app env BACKEND_ROOT=/var/www/html RH_LOCAL_REPAIR_TEST=1 php /dev/stdin < tests/report-current-platform-category/php/rh-repair-local-insert-rollback.php`：2 项通过，本地事实插入已回滚。
- 瑞昊只读预检：5 个商品变更，17 笔有效卡项销售，预计 19 条分类事实与 51 条项目事实；销售额 5,106,800 分、原结账收款 4,146,800 分。2 笔已作废销售排除；成功退款检查无命中。预检数据哈希：`364b5fcee9e4bf5d9521f76247d377b4b6674667549d87da2d4c0fc81deddf1d`。此哈希仅记录当前只读快照，不是执行授权。

## 状态与边界

- 开发与本地自测完成，待产品经理对本固定版本确认验收。线上数据库尚未创建审计表，尚未更新 5 个商品或补写卡项事实；线上报表仍可见旧的空分类。
- 未创建本地 commit、未部署瑞昊、未远程改库、未 push。上线前仍需单独授权、瑞昊独立备份、按实例核对当前 commit 与迁移状态，并在生产执行后核对报表查询/导出和 `03-验证.sql`。
- 前端源码、工程脚本、生成物：无。后端源码、数据库迁移与自动化测试见上表；验收证据即本文件。现有工作区有大量其他任务改动，后续仅可逐文件暂存本闭环清单。
