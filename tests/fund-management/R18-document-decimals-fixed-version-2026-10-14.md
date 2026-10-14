# 第 18 轮：收支单金额显示角分

- 基线 HEAD：`63e215ec3bd826ea913fc1712470393c45b1d1ea`。
- 前端源码：`前端代码/fund-vue3/src/App.vue`，SHA-256 `54c18a4a98600f7f800f0cd158dac861a539e4ac6e69d0e5d69c3bb985ac9b92`。
- 自动化测试：`tests/fund-management/js/fund-vue3-contract.mjs`，SHA-256 `948a64415dfc55d2c0885d74fbf1cf5158ef61455bc5f75d5ef1862ee545216a`。
- 业务边界：仅收支单列表的明细金额与单据合计显示两位小数；接口、保存、账簿及其他费用统计展示不变，继续以分精度计算。
- 验证：`node tests/fund-management/js/fund-vue3-contract.mjs` 通过；`npm run build` 通过；构建主 JS `index-85f04788.js`，SHA-256 `e8c4b463f50b45d728096652963c7a2f6385498f561e62612ad91547a7427895`。
- 产品经理于 2026-10-14 表示本轮测试没有问题，并授权本轮提交、瑞昊及渼约 008 部署和推送。发布须使用本轮固定提交重新构建，不能直接使用含其他未提交改动的工作区产物。
