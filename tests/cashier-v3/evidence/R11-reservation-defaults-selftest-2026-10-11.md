# R11 新增预约默认时间自测与固定验收版本

- 日期：2026-10-11，Asia/Shanghai。
- 验收版本：`R11-reservation-defaults-20261011-01`。
- 基线 HEAD：`412856626c796cf5fd3aca88900ae5735071944e`。
- 状态：开发与自测完成，待产品经理确认验收；未 commit、未部署、未 push。
- 授权范围：新增预约日期默认今天，开始时间默认下一个整点；产品经理已确认 23 点以后保留今天、开始时间留空。

## 实现与数据边界

1. 后端 `open-reservation-editor` 只读投影仅在 create 分支生成默认草稿，使用上海时区服务器时钟；例如 14:20 和 14:00 均建议 15:00。每次重新新增重新取当前时间。
2. 默认日期/时段进入真实 modelValue，显示、摘要、手艺人选择上下文与提交草稿共用该值，不是只填控件。编辑读取原预约时段，重算、提交不重新按时钟覆盖用户输入。
3. 未填项目沿用一小时建议及当天结束限制。22 点以后建议开始 23:00、结束留空供手调；23 点以后独立保存今天的草稿日期，起止时间留空，不伪造时间或自动跨日。
4. 前端独立日期只用于缺少完整开始时段时回显；用户清空或修改日期同步更新，完整开始时段始终优先。未改变布局、样式、文案、权限或原结束时间联动。
5. 权威事实仍是预约主表正式保存的起止时间。新增默认不写库、不生成事件、不占用房间或权益；正式写入继续沿用原校验、事务、幂等、CAS、快照和数据权限。无迁移、无历史回填、无远程操作。
6. 仅改唯一源码正本；本地后端容器 `mohe-cashier-app` 挂载 `美容源码/后端代码`，前端入口为 18091。热更新已生效，无构建、无重启。远程客户、迁移状态、备份/回滚检查本次不适用；本地可按下列限定补丁撤回，不触碰其他任务。
7. 职责与边界注释已补充到本次两个业务源码文件。未完整替代业务分支，无废弃路径需要删除；默认方法只有 create 分支一处调用。

## 文件分组与 SHA-256

前端源码：
- `前端代码/cashier-v3/src/components/reservation/ReservationEditorOverlay.vue`
  - `6d8eb2af3f6447197ddf44420c6b797bf27cbca41785dabcdf4f07884eb72833`

后端源码：
- `后端代码/app/services/cashier/v3/reservation/CashierV3ReservationModule.php`
  - `061a89583e6110f490196ceee5955020f763d3791d99fcc243408629faa99efa`

自动化测试：
- `tests/cashier-v3/php/r11-reservation-default-schedule.php`
  - `48c9a307c9e2b180b4f856a22bff50d8e8a5cf5f4e22d5c75c7cdc30caab71de`
- `tests/cashier-v3/js/r11-reservation-default-schedule.mjs`
  - `44f607fdc131ecaab12c50ed2c27e5ba916c749d204d68f9f3b7c0ac9a5e5737`

数据库迁移、工程脚本、生成构建产物：无。

验收证据：
- 本记录。
- `tests/cashier-v3/evidence/R11-reservation-defaults-2026-10-11.png`
  - `3b2f17caa8af4a3f1305db230df0761ace93d32004405b6c8bbcd6a5d1edfbfd`

补丁 SHA-256：`f9a9e7f3d1196fa0c6298bd8e2bcb8720df588a846cf5a09abc480a59a8ac2c3`。
计算方式：按上面顺序拼接两个源码文件的 `git diff --binary HEAD -- <files>`，再拼接两个新测试逐个 `git diff --no-index --binary -- /dev/null <file>` 的输出后计算哈希。证据单列，不混入源码补丁。

既有其他任务暂存区指纹前后不变：`dc31b057aca12a17ee75ed7a86e023bfeba79933849b91605faa54e209c95b46`。本次未暂存任何文件，未修改其他脏文件。

## 自测结果

全部 PASS：
- `php tests/cashier-v3/php/r11-reservation-default-schedule.php`：10 个注入时钟，普通时刻、整点、59 分 59 秒、22/23 点、年末、闰日、主机非上海时区；真实命令 schedule 消费默认值及拒绝空时间/跨日；手填时间保留。主机 PHP 8.5 与本地运行容器 PHP 7.4 均通过。
- `TZ=Asia/Shanghai node tests/cashier-v3/js/r11-reservation-default-schedule.mjs`，并使用 `TZ=UTC` 复测：真实后端 fixture 驱动真实组件 setup，控件/提交值一致，午夜空时间、手填、清空、父组件回传、项目时长联动和编辑原值保留。
- `php tests/cashier-v3/php/reservation-time-window-contract.php`。
- `php tests/cashier-v3/php/reservation-lifecycle-contract.php`。
- `node tests/cashier-v3/js/reservation-lifecycle-frontend-contract.mjs`。
- `node tests/cashier-v3/js/reservation-calendar-contract.mjs`。
- `node tests/cashier-v3/js/room-reservation-service-frontend-contract.mjs`。
- 本地 PHP 7.4 模块语法检查、Vue SFC parse/compileScript、`git diff --check`。

## 真人路径联调与限制

1. 本地 18091 预约页面关闭原空白草稿，点击“新增预约”，真实接口返回今天 `2026-10-11`，开始 `21:00`，结束 `22:00`；机器当时 `20:51 CST`，符合下一个整点。
2. 手动修改开始 `21:15`、结束 `22:45`，确认摘要同步为 `2026-10-11 21:15 至 2026-10-11 22:45`。
3. 关闭未提交草稿再新增，恢复今天、`21:00`、`22:00`，保存截图并保留页面供验收。
4. 没有点击“确认预约”，没有为测试新增会员预约/资金/库存事实。午夜与编辑保留由真实逻辑自动化验证，未修改运行环境时间，也未对实际预约执行编辑保存。自测不代替产品经理业务验收。
