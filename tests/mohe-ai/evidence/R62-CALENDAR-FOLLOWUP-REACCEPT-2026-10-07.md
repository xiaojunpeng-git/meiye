# R62 上下文周几追问补验（待产品经理确认）

- 基线/已部署瑞昊提交：`5e9e301d2566d9e1bc190aae06568b74371175ea`；该提交尚未 push。
- 线上真人路径：新会话“上个季度营业额总的是多少”返回 2026-07-01 至 2026-09-30；同会话追问“上周营业额最高是周几，最低是周几”在理解契约阶段失败。新会话单独问第二句则返回 2026-09-28 至 2026-10-04 的周几排行。这是原验收版本的线上新发现，不得视作线上通过。
- 原因：模型在有上一问时，偶尔将最高、最低、上周拆成三条要求，或将共同日期放在顶层。原契约只接受每个排行要求各自携带完整日期，故取数前被拒绝。补丁只把一个当前轮已识别的、与两端排行不冲突的日期归并；业务指标、对象、权限和日期语义均不从关键词推断。
- 受影响源码：`后端代码/app/services/ai/contract/AiIntentUnderstandingContract.php`。新增/更新注释说明共享日期归并的适用边界、冲突拒绝条件。
- 自动化测试：`tests/mohe-ai/r62-calendar-period.php` 新增拆分日期、顶层日期和冲突用例，23 项 PASS；`tests/mohe-ai/r62-model-calendar-local.php` 增加带上一问的真实模型探针；新增 `tests/mohe-ai/r62-calendar-followup-local.php` 运行同会话两轮真实模型与 Reader。PHP lint 4 文件 PASS，`git diff --check` PASS。
- 本地实际两轮链路，含取数：加入两种日期载体修复后连续三次均完成。第一问耗时 26.357、25.027、23.420 秒；第二问耗时 46.158、27.136、24.701 秒。模型/查询时延仍不稳定，不能声称每次低于 25 秒。第二问均核对 2026-09-28 至 2026-10-04，表格 3 行（最高与最低并列）；测试未修改业务数据。
- 本地固定文件 SHA-256：
  - `后端代码/app/services/ai/contract/AiIntentUnderstandingContract.php` `9cd6bdf0c7fd32fbc7115606f2a4980bf92992b27786298b32bd92902416ff03`
  - `tests/mohe-ai/r62-calendar-period.php` `994d4402799a069dda8cc9e769ca43dc322203ec6d1bf60375d5ac5b54f9e727`
  - `tests/mohe-ai/r62-model-calendar-local.php` `b4b3815af493a980012720faf214bdfaba3de0ffe004d3b853a8f6a884ad9fef`
  - `tests/mohe-ai/r62-calendar-followup-local.php` `8f71a8b5e1369549701a524de5a9f9552ccd29725280e0f1804ee146db33a848`
- 状态：新补丁未提交、未重新部署、未线上验证、未 push。需要产品经理对上述固定版本补验确认；其后方可提交，按已授权的瑞昊部署、线上验证和 push 顺序继续。原瑞昊预部署备份：`/www/backups/rh.cc3798.com/r62-5e9e301d.9sH4by65/predeploy-ai-files.tar.gz`。
