# 第52轮客户品相分析发布记录

产品经理已确认隔离候选 `3c5f476e4c66589cad564bbdb1be326bee7059f4`，并授权按瑞昊 → push → 008 → 010发布。

## 本轮范围

只统计当前生美分类树；品项按去除首尾空白后的同名分组；批量读取统一事实以减少重复查询；图例品名与百分比相邻。查询、合计与导出共用数据口径。

本轮业务提交：`e9100bef3af800d5003bdbdb3425b1b85d7b870e`。
前置基线保留提交：`e0a20b9f6143b852121a1150ed19e7e5a19808ab`，恢复已在线的 PhaseFour 正本代码，不算本轮新功能。
分支：`release/r52-beauty-item-20261106`；已推送 Gitee origin 与 GitHub github。

## 文件分组

- 前端源码：CustomerAnalyticsView.vue。
- 后端源码：CustomerAnalyticsServices.php、StoreUnifiedReportPhaseFourServices.php。
- 自动化测试：beauty-item-regression.php、item-analysis-benchmark.php。
- 工程脚本：customer-beauty-item-release-prepare.py、customer-beauty-item-release.py。
- 数据库迁移：无。
- 生成物：固定树 compiled-cashier，逐站 manifest.json列出实际更换资源。
- 验收证据：本目录；构建日志和包位于任务管理/第52轮-客户品相分析/release。

## 验证和实际限制

固定树前端构建、16项回归通过；本地完整月份原始现金和服务事实多重集与优化读取一致。完整月份性能样本从18.535秒降至12.451秒，约33%，仅代表该本地样本。

三站分别备份、部署、重启本站进程。部署前后相关既有读取器结果一致；单店单日线上只读抽样验证报表版本、同名唯一、查询/导出一致和有效XLSX；公网入口与新JS/CSS哈希一致，环境配置及平台/库存入口保持原样。008及010该抽样范围无生美命中，空结果和导出正常，不宣称全量数据已逐笔验收。UI本轮已由产品经理测试确认。

各站使用独立回滚包：
- 瑞昊 `/www/backup/r52-beauty-item-e9100bef-rh/before.tar.gz`
- 008 `/www/backup/r52-beauty-item-e9100bef-008/before.tar.gz`
- 010 `/www/backup/r52-beauty-item-e9100bef-010/before.tar.gz`

未删除旧哈希资源，回滚脚本仅恢复本轮受影响文件并重启对应实例。未执行数据库迁移、支付方式调整或其他任务发布。
