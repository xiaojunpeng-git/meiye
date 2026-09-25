# R44 自然排名快速路径自测记录

日期：2026-09-25
环境：本地 `http://127.0.0.1:18081/admin/setting/mohe-ai`

## 本次范围

- 句首会话连接表达“那 / 那么 / 那就”与日期解析使用同一规范化规则。
- 已注册对象“哪家店”进入门店对象词汇表；“那家店”仍保留给上下文指代路径。
- 单对象宽泛排名仅在指标注册表为该对象声明唯一默认排行口径时，直接编译为统一指标 Reader 查询。
- 模型词汇表继续保留 16 条传输预算；确定性解析使用完整已注册对象别名，避免新增别名挤掉正式对象名称。

## 自动回归

```text
php tests/mohe-ai/exact-ranking-collection-admission.php
exact ranking collection admission: 21 checks PASS

php tests/mohe-ai/intent-understanding-contract.php
PASS intent understanding/binding separation: 144 checks

php tests/mohe-ai/temporal-ranking.php
temporal-ranking: PASS (5 checks; offline fixtures)
```

覆盖：带 / 不带句首“那”的执行计划等价、日期与项目复合排名、最高与最低保持同一指标、门店宽泛排名唯一默认口径、以及“那家店”不进入全门店快速查询。

## 页面真实验证

| 原句 | 结果 | 实际耗时 | 服务端证据 |
| --- | --- | ---: | --- |
| 那这个月的销售额最高是哪天，销售记录最多的项目是哪个，最低是哪个 | 返回销售额最高日期，以及销售记录数最多和最少项目 | 2 秒 | `89eb3eeb8bb2b610da9128b2e863d4b213b75b2d8b2c8b36`；两个 Reader 查询分别 97ms、99ms，无模型调用 |
| 今天业绩最高是哪家店 | 按现金业绩返回第 1 名门店 | 1 秒 | `7e72043c904489694c9dd083338cbe19b40a92e9f1f5132d`；Reader 查询 146ms，无模型调用 |

页面输出已同时展示统计时间、指标口径、对象、数值和名次。

## 数据架构自检

- 权威来源：沿用现有统一指标 Reader 与指标注册表。
- 事实粒度、统计时间、金额口径、权限、事务、幂等、冲销和历史快照：本次未改变。
- 前端：未计算指标，也未改变展示契约。
- 数据库与迁移：无。
- 性能：确定性命中后跳过模型理解、绑定和重试；页面实测由原先模型等待量级降至 1–2 秒。
