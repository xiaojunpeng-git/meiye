# R8 集团看板分类统计开关：本地固定版本

- 基线 HEAD：`b670ce2af80ffec80616dc217e3f5afe9e8d022f`
- 范围：集团管理看板“分类业绩”只展示已开启统计的一级商品分类，筛选项同步收窄；关闭统计的旧筛选值被明确拒绝。经营业绩总额继续按统一事实层计算。
- 前端源码：无改动。
- 后端源码：`后端代码/app/services/report/GroupManagementDashboardServices.php`，SHA-256 `0249c6a92d9bcc1f41dc87d7abd1f3974f5a184410db6f70ae8390bb3c026787`。
- 数据库迁移：本闭环无改动；依赖其他任务尚未提交的 `store_product_category.statistics_enabled` 字段及其迁移，本闭环不能单独发布。
- 自动化测试：`tests/report-group-dashboard/php/category-statistics-switch-readonly.php`，SHA-256 `ad66f4c95d46551045788330330d0a2a6fcda53c7dd8d3196002df74eefdbd82`。
- 工程脚本、生成物：无。
- 验收证据：`tests/report-group-dashboard/evidence/R8-category-statistics-switch-local-ui-2026-10-15.jpg`，SHA-256 `e5b77d11976396cad529d8c6b54464217eb2334b483a58469589619bf287c78b`；浏览器默认窄视口中展示已加载的分类区域，完整分类名称及数量以本地页面可访问性记录和只读回归核对。
- 后端补丁 SHA-256：`f5ff7c48a1c9e3181f970345f2726aff4f5aa94897235a544d7fc11c4bcdb8c3`。

## 自测

- PHP 语法检查两文件通过，后端补丁 `git diff --check` 通过。
- 本地 `ruihao` 只读事务回归通过：2 个关闭统计的一级分类不在分类卡与筛选项，6 个开启分类保留；关闭分类的旧筛选值被拒绝；经营现金业绩与统一注册指标相等。
- 本地 18081 页面刷新后实际显示 6 个分类卡与 6 个分类筛选项，关闭的“菡香美雅”“瑞昊采购部”均未显示，经营业绩卡仍显示现金业绩。页面截图如上。
- 业务注释更新于 `GroupManagementDashboardServices.php`：说明分类显隐只作用于一级分类卡和筛选项，不改写历史现金事实及总览金额。

当前状态：开发与自测完成，待产品经理对上述固定版本确认验收；未 commit、未部署、未 push。
