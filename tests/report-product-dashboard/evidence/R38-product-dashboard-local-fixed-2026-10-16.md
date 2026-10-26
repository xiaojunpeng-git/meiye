# R38 商品看板本地固定版本与自测

- 基线 HEAD：`a905e9de6e8bb8d768ab2ccdf1c331c32e272735`
- 验收入口：`http://127.0.0.1:18081/admin/report/product-dashboard?menu_entry=2242`
- 本轮源码：`后端代码/app/services/report/ProductManagementDashboardServices.php`、`前端代码/cashier-v3/src/views/ProductDashboardView.vue`
- 本轮测试：`tests/report-product-dashboard/php/static-contract.php`、`tests/report-product-dashboard/php/round38-contract.php`、`tests/report-product-dashboard/run-static.sh`
- 数据库迁移、工程脚本：无；前端构建产物不计入源码。

## 范围与口径

- 经营摘要仍经统一注册指标读取器读取销售与已完成服务消耗，只查询页面使用的两项指标；本期、同比、环比沿用原日期与权限范围。
- 库存和临期共用同一批次余额与当前分类映射；无事实行的毛利分类卡和无有效库存行的库存分类卡不显示，但已启用分类仍保留在筛选项。有正库存、零成本的分类仍显示。
- 临期六档的完整批次数量与库存数量继续由后端统计；每档独立按到期紧急程度取前 10 条。页面默认“已过期”，点击指标只切换已返回的排行，不改变汇总与权限。
- 指标版本：`product-management-current-category-v4`。无成本权限时，批次成本继续返回 `null`。

## 自测

- `bash tests/report-product-dashboard/run-static.sh`：静态契约、R37/R38 定向契约、PHP 语法与 Vue 构建通过。构建仅提示现有大 chunk 告警。
- `git diff --check`：通过。
- 本地只读事务：单门店 2026-10-01 至 2026-10-16 查询约 0.39 秒；102 家门店同范围优化前多次样本约 14–18 秒，优化后多次样本约 11.3–11.9 秒。计时受本地数据库缓存与并行任务影响，不承诺生产耗时。
- 102 店六档批次/数量：已过期 108/3084、30 天内 45/152、31–60 天 45/427、61–90 天 71/547、90 天以上 7422/139616、未知 10/778；与优化前六档汇总一致。
- 本地页面：已过期默认选中并显示 10 条；切换 90 天以上后仍显示 10 条该档批次，指标数量不变。库存区空分类（含六维）已隐藏，筛选项仍可选六维。
- 慢查询排查：现有索引提示收益有限；删除服务事实 `GROUP BY` 在只读对比中反而更慢，因此均未引入源码。

## 固定文件 SHA-256

```text
915c9ae2b8ec49915e9892b9aa2c6706272e71858e953aa5688cbb8b34536992  后端代码/app/services/report/ProductManagementDashboardServices.php
67aa1338496bf34df1caf1638d48f2a5edb5e41c1a6f40c6cb8a899bb9b2e0b5  前端代码/cashier-v3/src/views/ProductDashboardView.vue
21e7b4c868ecbe5f58e6b7d690f54a26bbef8481731a337f381e6bc39812f036  tests/report-product-dashboard/php/static-contract.php
e923cd04852a852caa55ef998509cb049ad0568bc6157c91d2d585be952a2bd9  tests/report-product-dashboard/php/round38-contract.php
c678461c006ee3accdbb9de6ac2929547fdeeb8ec4d61b875f08660e42058a1c  tests/report-product-dashboard/run-static.sh
```

上述四个已跟踪文件的工作区 diff SHA-256：`1b64410a4a22a5e2bebea7c441156f8a5fa9048057fefa25222169ae1dddca53`；新增测试文件以独立 SHA-256 固定。

`ProductDashboardView.vue` 在本轮开始前已有其他任务的日期默认值/数据截至时间改动。本轮没有撤回这些改动；固定文件指纹包含它们，后续提交必须逐 hunk 隔离本轮内容。其他脏工作区文件不属于本轮。

## 状态

开发与本地自测完成，待产品经理对上述固定版本确认验收。未 commit、未部署、未 push。
