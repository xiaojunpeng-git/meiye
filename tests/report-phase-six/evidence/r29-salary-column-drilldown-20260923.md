# R29 工资报表员工列与分类下钻：自测固定版本

- 基线 HEAD：`fbcc3987ab17075a4c5bfd6035d9d2246014f71a`
- 业务与测试代码补丁 SHA-256（不含本记录）：`8b6037dc4c953c4d3cb67506e77286505ae1993c808bf0ba9c2ef6b1b252b049`
- 状态：本地开发与自测完成，待产品经理对本固定版本确认验收；未提交、未部署、未推送。

## 范围与文件 SHA-256

| 类型 | 文件 | SHA-256 |
| --- | --- | --- |
| 后端源码 | `后端代码/app/services/report/StoreUnifiedReportPhaseSixServices.php` | `9a71d501b3f628e0facddda0e295631c06ea8434a56f63f56445b046540194f5` |
| 后端源码 | `后端代码/app/controller/admin/v1/report/UnifiedReport.php` | `27b095ba426aa389c98605bff59a96b1b9866e08145738af30bc9bd8ec924639` |
| 后端源码 | `后端代码/app/controller/cashier/v3/Report.php` | `93ca0519468bcf547acac93f3cf3a88d776ffe83285d716facf43c6a20679ec9` |
| 后端源码 | `后端代码/app/controller/store/report/UnifiedReport.php` | `fbea2700721d46b5767f44bb2153af4cd580c508591ffb1ea02bccf66c6be39d` |
| 自动化测试 | `tests/report-phase-six/php/service-contract.php` | `0d46d85a8a6edcc0871b5bb8bc495ef16a194409827e41eeac0554a7ea993b8d` |
| 自动化测试 | `tests/report-phase-six/php/salary-later-reversal-integration.php` | `3f1d111db3f88abfc90dd5ef140c8bf3bf40780938fddfc70c0b2d9ddf175512` |

前端源码、数据库迁移、工程脚本、生成物：本闭环无修改。上述四个后端业务文件已补充或更新与本次稳定 ID 下钻、权限和金额拆分相关的注释。未动其他任务的脏工作区文件。

## 验证

- PHP 语法检查、`git diff --check`：通过。
- `php tests/report-phase-six/php/service-contract.php`：通过，覆盖两级/历史分类、卡项拆分、未分类余款及端点参数契约。
- `node tests/report-phase-six/js/contract.mjs`：通过，复用现有报表下钻与固定列展示机制。
- `docker exec -i mohe-app php < tests/report-phase-six/php/salary-later-reversal-integration.php`：通过，53 条跨日冲销样本仍正确排除，10 组真实分类下钻明细与汇总金额一致，越权门店被拒绝。
- 本地门店端 `18091`：汇总表显示“员工”且左侧固定；点击一笔分类金额 1000 元跳转明细后，显示同一员工、分类和 1000 元合计；明细员工列计算样式为 `position: sticky; left: 200px`。
- 限制：当前本地门店实例在 2026-09-18 无工资记录，无法在本地页面复现线上截图的该日 2000 元。未操作线上实例。

产品经理随后澄清“合计不用加已经有了”，本次未改动合计行结构或首列文字；仅保留原有合计并确保分类下钻的现金合计与所点金额一致。
