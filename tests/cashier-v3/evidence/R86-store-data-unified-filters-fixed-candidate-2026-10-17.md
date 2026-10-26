# R86 门店数据报表统一筛选固定候选（2026-10-17）

## 边界

- 源码正本：`美容源码/`；基线 HEAD `651fd078a53a96b9bda707294be7570a32d460b6`。
- 本轮新增：门店“数据”下的 25 张报表从后端列元数据加载静态文本/月度维度筛选。筛选作用于原统计结果，不改原始事实口径、权限、金额计算或导出来源；数值和动态生成列不进入筛选。
- 本轮界面收口：筛选默认收起。展开后只显示统一字段；移除旧商品分类、合作方、人员选择控件，且隐藏门店端旧 `filter_schema` 输入。日期和权限范围仍在主查询区。旧 URL/下钻条件继续传递，统一筛选的“清空筛选”只清除可见条件。平台端原有筛选保持原样。
- 已上线且须保留：原报表统计、分页、下钻、导出、动态列和门店权限；本轮不把这些既有功能作为新增成果。
- 工作区有其他任务对共享报表文件的未提交改动。提交前必须逐 hunk 隔离本轮变更，并重新核对文件指纹与已验收候选；不得整文件暂存或夹带其他任务内容。

## 文件指纹（SHA-256）

| 分组 | 文件 | SHA-256 |
| --- | --- | --- |
| 前端源码 | `前端代码/cashier-v3/src/views/StoreBusinessReportView.vue` | `e3ab368d58121c1883c45cea2ba8afc17eaab0cdbb0e29dcf2934f2b5ab79c87` |
| 后端源码 | `后端代码/app/services/report/StoreReportResultRowFilterServices.php` | `4d46664555430c041baa1e2694bc32e136956888b22d843f8c18f1f1c39fc334` |
| 后端源码 | `后端代码/app/services/report/StoreUnifiedReportServices.php` | `4f9235e8ec036c138871add8b580dbc2a880249e421bc1a169af8aec358aa5b3` |
| 后端源码 | `后端代码/app/services/report/StoreUnifiedReportPhaseTwoServices.php` | `2ff36665f7a6009e91d7551299c652f991f62fa8b3e0bd2543a156c55b7ec38c` |
| 后端源码 | `后端代码/app/services/report/StoreUnifiedReportPhaseFourServices.php` | `6a59893c61569519a2e5049419d3d1b38a3adfa3b34c37191241e67e9f7c4d69` |
| 后端源码 | `后端代码/app/services/report/StoreUnifiedReportPhaseSixServices.php` | `21db4f8c193b5271bb2fca6bc54be083e1d5f588c9ea1a3b7510493741a03005` |
| 自动化测试 | `tests/cashier-v3/php/report-result-row-filter-contract.php` | `7aebfef0eb4adf23e65f4fa9ac32b181bf610e207fbda00ad47e16c6b4bc9b83` |
| 自动化测试 | `tests/cashier-v3/php/store-data-result-filters-local-readonly.php` | `0d8fc682d5f3ad5eb84dbb8bdf5924d3c09c42640dedc422804e830c5242b33d` |

上表记录验收时的完整工作区文件。产品经理确认后逐 hunk 隔离本轮提交，三个含其他任务改动的暂存文件 SHA-256 分别为：`StoreBusinessReportView.vue` `1f94ffa821ab05903c508c9c233b327b5ef8e9666fae924f31e367b8c3a35ad2`、`StoreUnifiedReportPhaseTwoServices.php` `d53d02a6433d7c37e65b97d2823960e1021a87e2c8db022d13cb251839ee78d9`、`StoreUnifiedReportPhaseSixServices.php` `294281261704e0bf8ce58b373fd901310b2f8e3a90d5727fb3b800fd82292e0e`。最终暂存代码与测试补丁（不含本文）SHA-256 为 `9738c65d5c3e6025c4f3d5e03f80f545f639407c6aeb55f883fb3837973d4627`。

数据库迁移：无。工程脚本：无。生成物：本地 Vite `dist`，仅作编译验证，不作为 8080 集成包或发布源。验收证据：本文。

## 自测

- PHP 7.4.33 筛选合同 13 项通过，覆盖白名单、非法数值列/隐藏字段拒绝、月度值校验、AND 组合与 16 条上限。
- 瑞昊本地镜像库只读事务：25 张报表逐表通过；有记录的报表核对结果行、合计、分页与特殊金额口径；无记录的报表核对列契约，并有六维和异业汇总的合成结果测试。
- 本地浏览器核对：合作方品项明细、会员消费明细均默认收起，展开后仅见统一固定字段；截图所指的旧筛选整排及人员弹窗入口已移除。切换报表后筛选重新收起。
- 门店端 Vite 生产构建通过，2012 模块，1m33s；仅有现有大 chunk 提示。
- `git diff --check` 对本轮涉及的已跟踪文件通过。
- 业务注释：新增/更新 `StoreBusinessReportView.vue`、`StoreReportResultRowFilterServices.php` 和相关报表服务中对筛选作用时点、列白名单、统计保护与隐藏旧参数用途的说明。

状态：产品经理已确认固定候选验收通过，仅授权本地提交。暂存版 Vue 组件脚本/模板和后端 PHP 语法复核通过；未部署、未 push，未连接正式实例。
