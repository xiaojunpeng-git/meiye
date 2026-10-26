# 008 旧商家端体验版兼容补丁：固定验收版本

- 基线 HEAD：`bc8642639543154ac8982d24d22ad907beb4dd54`；本次六文件 SHA-256 清单指纹 `9aa36ec584970250b5d6b31cb9edf84dc4a5900c66ece815de86b322c49456cf`。
- 初版已按授权在 008 完成 3 张只读表及 10,094 条旧流水导入，随后发现此前上传的小程序体验版仍按 V3 的本金／赠送结构展示余额记录。初版后端会向该旧客户端返回旧流水，其本金／赠送字段未知，旧页面可能误显示为零。已立即仅回滚 008 后端读取文件至发布前 SHA `e14bbe01f8d46e57ca1ce210befee70eec97391067f3a8d8a72dc841a8d67cdd`；服务 active；导入表与批次保留并核验，未修改现有余额或 V3 事实。
- 补丁采用明确的 `acceptLegacyBalanceHistory: true` 请求能力：新商家 H5 才读取已导入旧流水并按未知拆分版式展示；旧体验版不传该字段，后端仅返回原有 V3 记录及原 `CASHIER_V3_BALANCE_FACT` 口径。客户可见性检查仍先于两种数据查询，V3 门店范围与旧站跨门店业务选择均不变。无需新增数据库变更、重复导入或上传小程序。

| 分组 | 文件 | SHA-256 |
| --- | --- | --- |
| 后端源码 | `后端代码/app/services/mobile/customer/MobileCustomerAssetRecordServices.php` | `e03178fa0692b39f2f3cce275b36cc3073d80b91919c900fc5aab01c9109dfb6` |
| 前端源码 | `前端代码/mobile-vue3/src/merchant/pages/customer-detail/index.uvue` | `7faefdc7120c2abb1ea9906b1b24bcfa4957b54f506b809dd77ad69786d50009` |
| 前端契约 | `前端代码/mobile-vue3/src/shared/contracts/mobile-customer-v1.contract.json` | `28b75796a3783a6c3c0c78d0edb541a4b2ce279c950429fc8f858dc2cd14525c` |
| 自动化测试 | `tests/customer-008/php/balance-history-import-mysql.php` | `6746b10c5ad7b8e4ea53e8c0b7795d3dd45e73377e29c44c6e91189a2fab17a1` |
| 自动化测试 | `tests/mobile-customer/php/mobile-api-contract.php` | `7ccfcc8bcc97bc120002906f354dedc59ecfecaabfe72a28175ebb208c9ed2e9` |
| 自动化测试 | `tests/mobile-vue3/js/customer-page.test.mjs` | `8cf7f17cb36fb9aeb17dce34c30a8e9730dea3e364d590dec92878c7b055bce5` |

## 自测与交付状态

- PHP 语法、`git diff --check` 通过。MySQL 5.7.44 隔离测试通过：新客户端合并 V3＋旧站历史、跨页与门店范围正确；旧客户端在新表有 47 条旧记录时仍只收到自己的 V3 行。HBuilderX 5.26 商家 H5 编译通过。
- PHP 合同 MC-API-09 与 JS 移动客户契约用例通过；两套完整旧测试分别仍有原有 MC-API-03 和五个无关基线失败，与初版固定证据一致。服务端 PHP 7.4 语法和 008 在线页面需在补验后的发布中再次核验。
- 当前为开发与自测完成、待产品经理对六文件补丁明确验收；未提交本补丁、未再次部署。先前“仅对 008 部署且不上传体验版”的授权范围不变；补验后只替换 008 后端读取文件并验证新旧请求分流，不重新执行数据库迁移。
