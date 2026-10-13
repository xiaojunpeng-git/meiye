# 008 会员端底部导航失效：固定版与自测

- 基线 HEAD：`a1acb0cd821e084de885826dc3ceca75c2dfdc16`
- 目标：仅 `008.cc3798.com` / `mohe_008`
- 范围：将已导入首页底部的“美疗师”改指向现有“服务老师”页，将“项目”改指向现有“我的项目”权益页。其余 3 个入口与全部会员、订单、权益数据不变。
- 权威配置：008 数据库唯一 `eb_diy` 行，ID 1；本次只改 `value`、`default_value`、`version`、`update_time`。不修改 H5 业务源码或收银页面。
- 源码基线：`pages.json` 的活动分包 `therapist_list/index` 与用户分包 `user_card_list/index`，对应源码文件均存在。旧 `/pages/technician/index` 和 `/pages/goods/item_work/index` 在当前会员端页面清单中均不存在。

## 固定文件

- 工程脚本：`scripts/import/customer_008_member_footer_repair.php`，SHA-256 `5caa9db49b6b52499c69de14d677a66aa7564b1a30bd63af5d506ae6a0455c99`。
- 依赖现有只读版本：`scripts/import/customer_008_morestore_mall_apply.php`，SHA-256 `762ed225b88879192536ac63c797143130babca56a08dc473a306d95190b2675`；`scripts/import/customer_008_morestore_mall_homepage_plan.json`，SHA-256 `714cf2b516e1a030a6aa732ee2f50d5f3bba48fa2bfe9f31dc62e0201a869349`。
- 后端源码、数据库迁移、前端源码、生成物：无。本文件是验收证据，不属于工程脚本清单。
- 新版本 `008-morestore-mall-20261013-r5` 的 `value` SHA-256 为 `cbc5a1267e623027e0f51b9367ed2f277d952371d706bd102fa38183de46c592`。

## 自测与线上只读预检

1. 在 008 会员 H5 电脑预览中实点“美疗师”“项目”，iframe 均留在首页，复现了用户报告；当前导航源分别为不存在的旧路径。
2. `php -l` 通过；本地从 r4 页面计算 r5，仅两条指定链接变化，五个菜单顺序与其他配置保持不变。
3. MySQL 5.7 隔离库以 r4 原文插入唯一 DIY 行，执行相同 CAS 更新，`footerVerify` 核对 r5 完整 JSON；篡改版本后预检拒绝。隔离库已清理。
4. 线上只读核查：008 的 `eb_diy` 只有 ID 1 一行，版本 r4，`value` 与 `default_value` 的 SHA-256 均为 `6ed38d50208a62ce036b6ceede75466e5b4005deafee54174ef53b8fabc2c203`，满足脚本来源守卫。
5. 在同一 008 H5 预览中直接打开新目标页：服务老师列表显示真实美容师“谷爱心”和预约入口；我的项目页显示本次会员的已迁入权益和剩余次数。目标页面本身可加载，尚需配置切换后的底部点击回归。

## 数据架构自检与回滚

- 导航配置为唯一权威源；无业务事件、交易事实、金额、统计时点、事务外业务写入、并发权益扣减或历史快照变化。
- 脚本对 008 环境身份、唯一 DIY 行、旧版本、旧值摘要和原值/默认值一致性做守卫；取得 008 独占锁后在事务内 CAS 更新，清理仅 `008:` 命名空间的 DIY 缓存。
- 执行前只保存该行的私有回滚快照；r4 原文也由已验收的首页计划完整保留。008 外的共机实例不在写入范围。
- 新增业务注释文件：`scripts/import/customer_008_member_footer_repair.php`，说明两路由边界、来源守卫、验证及锁。

## 待完成

- 产品经理对本固定版本确认验收后，本地仅提交脚本与本证据；008 单实例执行并在真实会员 H5 中从“我的”页逐个点击“美疗师”“项目”，确认页面内容、返回及其他三个导航入口。
- 未部署，未执行线上写库，未 push。
