# R14 员工业绩详情返回按钮：本地固定版本

## 复现与原因

- 2026-10-13，商家 H5 `18104` 的员工 922 详情页直接打开后，点击左上角返回，URL 和页面均不变。
- 原返回方法仅调用 `uni.navigateBack()`。数仓排行使用 `navigateTo` 进入时有上一页；H5 直达详情和个人数仓入口使用 `redirectTo` 时无上一页，所以不能依赖页面栈。

## 修复范围与业务边界

- 基线 HEAD：`b628158989b8b6d4e18b00ed65551f02a867f77b`。有上一页时仍调用 `navigateBack`，失败时也有固定回退。无上一页的普通详情只回商家“数据”页；个人数仓入口替换页面时给详情传固定 `returnTarget=home`，无上一页则回商家“经营”页，避免回到数仓后再次被重定向进同一详情。
- `returnTarget` 仅接受 `home` 或默认 `warehouse`，不接受任意 URL。商家主 Tab 仍经已有功能权限映射；无新增权限、业务接口、指标计算、数据库迁移或服务端变更。
- 变更后的 SHA-256：`mobile-navigation.uts` 为 `0ad7ea8dfa41c7a28745260b1ccc8f7a96edca5faf5c79addd1e26b04fd24b53`，`employee-performance/index.uvue` 为 `1657665e0454862caccdf3a5c627ca0042ff607e6af736262251948121a13a81`；两文件未暂存补丁 SHA-256 为 `c698997568b16fbcb5da3dc0cf6531872577006fc957b34f9d0defcd13348ff9`。

## 本地自测

- 真实 H5 热更新页：详情直达且无上一页时，点击返回进入 `#/src/merchant/pages/warehouse/index`，数据 Tab 选中；从“本月 → 员工销售 → 陆丹丹”进入详情再返回，原“本月员工销售”排行恢复；带固定 `returnTarget=home` 的个人入口测试返回 `#/src/merchant/pages/home/index`。截图：`/tmp/opt5-r14-return-warehouse-h5-2026-10-13.png`。
- `node --test tests/mobile-warehouse/js/page-contract.mjs` 通过；两文件 `git diff --check` 通过。曾运行旧版 `tests/mobile-vue3/js/*.test.mjs` 全量测试，出现与本变更无关的既存失败，包括读取已迁移的 `src/merchant/contracts/mobile-merchant-v1.contract.json`、`src/merchant/api/mobile-login-client.uts` 等路径失败；不得报告为全量通过。
- 本次补充了共享导航方法和个人入口分支的职责与回退边界注释。前端源码：上述两文件。后端源码、数据库迁移、自动化测试、工程脚本、生成物：无修改。验收证据：本文与浏览器截图。

## 验收与发布状态

- 开发和本地自测完成，待产品经理对上述固定版本明确确认验收。尚未提交、部署瑞昊、`push` 或上传体验版；本次反馈仅授权本地修复和验证。
