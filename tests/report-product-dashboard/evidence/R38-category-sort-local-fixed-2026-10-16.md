# 商品看板分类排序补充：本地固定版本

- 基线 HEAD：`9783a0ad`（R38 商品看板统计提速与临期排行闭环）。
- 范围：商品看板筛选项、品项毛利分类卡、库存分类卡共用商品分类配置顺序：一级分类 `sort` 降序，同值按 `id` 降序。原有无数据卡片隐藏、权限与统计口径不变。
- 前端源码：无修改；后端源码：`后端代码/app/services/report/ProductManagementDashboardServices.php`；数据库迁移：无；自动化测试：`tests/report-product-dashboard/php/static-contract.php`；工程脚本与生成物：无。
- 后端 SHA-256：`010cf12b23a30cbcda71ca787076278cf1beeea326d497c4e205bf0b3ca8ac1b`。
- 测试 SHA-256：`0c13ef8b13d90e5a4bbb230c80468bfd0b8a537e0e68f9cad335693536c01119`。
- 上述两个源码文件的 Git 补丁 SHA-256：`e253e9c90afd4023b8adfaab86d2d562a1f3fa92bb0825ab54bd3664c0a76a2e`。
- 自测：`bash tests/report-product-dashboard/run-static.sh` 通过（静态契约、R37/R38 合同、PHP 语法、前端构建）；`git diff --check` 通过。前端构建仅有既存的大包体积警告。
- 本地数据只读核对：`mohe-platform-app` 连接本地 `ruihao` 数据库，反射读取看板分类顺序，与 `sort desc,id desc` 查询结果一致；当前六个一级分类的 `sort` 均为 0，因此按 ID 降序显示。
- 数据架构自检：仅改变当前商品分类配置的展示顺序；权威源仍为 `store_product_category`，不改销售、消耗、库存事实、时间、金额、权限、下钻、导出、事务、并发、幂等、冲销、历史快照、聚合与索引。读取范围未增加，结果数量未改变；排序在既有分类配置查询中完成。
- 状态：开发与自测完成，待产品经理对本固定版本确认验收；未提交、未部署、未推送。
