# 商家首页充值卡片：固定候选

- 业务口径经产品经理确认：仅余额充值本金；名称为“充值”。卡片在疗程卡左侧，仅金额非零时出现。它不计入疗程卡、单项、产品的销售数量和现金业绩。
- 基线 HEAD：`9f20e88a1aa42cd63aaa6d010889b7d0bb93cade`。本次文件与前一条门店排行导航候选无重叠；未包含共享工作区其他未提交改动。
- 前端源码：`前端代码/mobile-vue3/src/merchant/pages/home/index.uvue`，SHA-256 `89b15c8ae66ff0b32942e69a5f912ea9d6aa02419cabaad9feb3fae772dc540a`。
- 后端源码：`后端代码/app/services/mobile/dashboard/MobileMerchantDashboardServices.php`，SHA-256 `41b3c8a7a7300bdaa4a6dcf37e7625df254a5e5d1827eb3c2c9000bf33eac52f`。两文件补丁 SHA-256 `bb150ff69edacdf6228d60599b63851f07c375cb62c6e7aee5450349e0e696bd`。
- 取值：复用统一注册指标 `recharge_amount`，按商家后端已授权门店及首页当前日期读取已入账本金。后端金额为零时返回空卡片；前端仅展示有值卡片，并在窄屏分两行排布。分类业绩和平台看板的销售类型卡片保持原口径。
- 数据库迁移、工程脚本、生成物：无。自动化测试：复用现有页面合同。
- 自测：PHP 7.4 语法、页面合同 2 项、`git diff --check` 通过；本地数据库只读事务核对 2026-11-02 的注册指标为零且首页返回不显示卡片。完整 H5 重建仍在 `App.uvue` 报 `default is not exported`，未完成页面点击复测。候选待产品经理确认验收；未提交、未部署、未推送。
