# R28 报表手动金额单位回归（待产品经理验收）

- 基线：`a6eb4612af83c3109a126be273f92090b8754eab`。本记录对应未提交的本地工作区固定文件版本；提交、瑞昊部署和推送尚未执行。
- 原因：会员消费明细的体验现金业绩按元输入、按分保存，但补充记录读回直接显示分值，导致 `891` 元显示为 `89100`。其他手动金额字段逐一核对后，第六阶段的月主推目标和其他多收款单价还缺少正确金额类型与稳定读回。
- 修复：会员消费明细的保存值按分校验，页面读回换算为整数元，导出保留分精度；第六阶段手动列按字段声明金额、日期和数量类型，按租户、门店及稳定明细键读回，保留清空和版本号。统一事实记录不修改，历史数据不批量处理。
- 本地真实页面：政和朝阳店会员消费明细中，2026-09-21 的“头部放松”测试行原值 `0` 元；录入 `891` 元并保存，列表与刷新后均显示 `891`；随后保存 `0` 元并再次核对列表已恢复 `0`。这两次补充记录写入会留下本地审计，瑞昊线上未写入。
- 自动化：`tests/cashier-v3/php/member-consumption-experience-cash-unit.php`、`tests/cashier-v3/php/store-operations-report-contract.php`、`tests/report-phase-six/php/service-contract.php`、`tests/report-phase-six/js/contract.mjs`、`tests/cashier-v3/js/store-business-report-contract.mjs` 全部通过；相关 PHP 文件 `php -l` 通过；`git diff --check` 通过。
- 固定提交候选的暂存区 SHA-256（均相对 Git 根目录；与本轮无关的工作区改动未暂存）：
  - `后端代码/app/services/query/metric/MetricMoneyFormatter.php`: `b9bbfb79cc96545ace388d4f9f13073962d3a4dc242e2ac4cf0ec7b9bf1d125a`
  - `后端代码/app/services/report/StoreOperationsReportAnnotationServices.php`: `dd80a6d7de8726764910cc6a71bf62ea35fb9379da6364eb7f3430b39363b185`
  - `后端代码/app/services/report/StoreUnifiedReportServices.php`: `7571ae71ab18dab6bf5d5bd7b032f6f54844d87c173ea23804a96d16b069cb21`
  - `后端代码/app/services/report/StoreUnifiedReportPhaseSixServices.php`: `5d78bfae76bed6a29752352ce45d06d8f890c83ab926912402845be5cc7f86bb`
  - `tests/cashier-v3/php/member-consumption-experience-cash-unit.php`: `3cb62d893a5fb9b3e570785420197c9b0c48a262dceed829a8beadf6370936eb`
  - `tests/cashier-v3/php/store-operations-report-contract.php`: `f7c3d4b0114596bbf40b532ed9ec1b60c5df84707a111902b6a56137dc16e5d6`
  - `tests/report-phase-six/php/service-contract.php`: `b656a0ed9963be84a5e8fff50368d1b840fcb891ba8da8dc35347e49420a18f9`
  - `tests/report-phase-six/js/contract.mjs`: `383a510d78ea9c638b8db8fd355f556c52461ad2999930263288abd6576e8e38`
- 尚待：产品经理对本固定版本明确验收；其后仅按本闭环补丁提交，再部署指定的瑞昊实例、线上验证并推送。第六阶段其他多收款报表在本地当前日期范围无数据，真实保存/刷新仅由隔离的服务投影测试覆盖，不能冒充该报表的真人业务验收。
