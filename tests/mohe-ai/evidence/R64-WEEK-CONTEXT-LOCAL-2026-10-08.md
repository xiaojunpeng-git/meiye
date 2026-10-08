# R64 纯时间追问继承：本地自测与待验收版本

- 基线 HEAD：`4c5cf1c39495e8d330aff6df870a216b2958ff14`
- 问题：已验证现金业绩问答后追问“上周呢”，模型可给出周历期，旧合同却把“上周”视作未解析业务残留；网关又只允许本地日期语法进入已验证查询复用路径，导致重复绑定和失败卡。
- 修复范围：理解合同接受完整、纯时间的模型周／季度等历期；网关在同样的完整句校验后仅替换已签名查询的时间；不继承包含新指标、对象或条件的句子。模型负责语义，服务端只核对时间单位、方向和整句无新增业务内容，原有日期解析器仍负责时间转换。
- 注释已随业务变更更新：`后端代码/app/services/ai/AiGatewayServices.php`、`后端代码/app/services/ai/contract/AiIntentUnderstandingContract.php`、`后端代码/app/services/ai/semantic/AiSemanticIntentParser.php`。

## 固定文件指纹（SHA-256）

| 文件 | SHA-256 |
| --- | --- |
| `后端代码/app/services/ai/AiGatewayServices.php` | `ecfccea6324a139d790d3adcfc35223762c74f8438d50ae56716da2db079614c` |
| `后端代码/app/services/ai/contract/AiIntentUnderstandingContract.php` | `ef0fcdfb740382e8ebb80ce541e666f2f19bf7fcaa98368c9edb46d2d06e686c` |
| `后端代码/app/services/ai/semantic/AiSemanticIntentParser.php` | `da1a528238c6bbb4b98727366e9128959c06bd0c871ed1db973f2cde604148de` |
| `tests/mohe-ai/intent-understanding-contract.php` | `ef17360ff214b50f0fa506ab5a0074983b59ca46225f99fb9bce4bc9fd32f6ff` |
| `tests/mohe-ai/r64-model-calendar-followup.php` | `5bf9c4be5f7094dc44d182fd77ef03e468a0ab4f28c17bc9a14b3d1ca671ecdf` |
| `tests/mohe-ai/r64-week-followup-local.php` | `49d1e779c4ec1a7f7ce54e618fe27ce4ccf276399e24363a7ab00240605c16b6` |

已跟踪文件补丁 SHA-256：`df74a823b44728ce47b29343945aaedff1fe7d3bc783430dc954ee7d99d492ad`。其他脏工作区文件不在本轮范围。

## 验证

- 理解与绑定合同：`PASS 262 checks`，含“上周呢／上一周呢／上上上周呢／上个季度呢／前两年呢”、错误单位、相反时间方向及“上周会员有多少”等换话题反例。
- 同会话合成端到端：`PASS 5 checks`；“昨天现金业绩是多少 → 上周呢 → 上个季度呢”只更换日期，保留已验证现金业绩；恶意丢弃新会员话题时未查询。
- 上下文网关：`PASS 102 checks`；R62 历期：`PASS 23 checks`；R63 失败引导：`PASS`；相关 PHP 语法与补丁空白检查通过。
- 真实本地瑞昊实例（已核对数据库实例 UUID、授权 Reader 和当前源码挂载），同一会话：
  1. “昨天收了多少钱”：`COMPLETED`，1,660 ms，Run `54de88968b9be6cd1fd3fbaf11460abd73c1e93b98d02dfe`。
  2. “上周呢”：`COMPLETED`，4,504 ms，继续回答现金业绩，统计期 `2026-09-28 至 2026-10-04`，Run `4ad319875389def6d0d5b3401c1ee7e91dfa9eae57284093`。测试记录不打印金额或会员数据。
- 相同真实业务链路再重复一次：首问 `COMPLETED`，1,352 ms，Run `bcb07c9e86e6c1f66423687d87fcb5377ea1b752281441ac`；“上周呢” `COMPLETED`，4,957 ms，日期再次匹配，Run `f950369ae1e95a88758ee34422da438e344695dc09c6ff10`。
- 首次真实测试在本地执行进程未加载改动时仍复现 `period_only_business_residue`；确认无在途 AI Run 后仅重启本地 `mohe-ai-execution-worker`，随后上述真实测试通过。部署时必须确保执行进程加载同一版本；仅更新 PHP 文件不等于已生效。

## 交付边界

- 前端源码：无。后端源码：上述 3 个 AI 文件。数据库迁移：无。自动化测试：上述 3 个测试文件。工程脚本：无。生成物：无。验收证据：本文件。
- 状态：开发与本地自测完成，待产品经理对上述固定版本确认验收。未提交、未部署瑞昊、未线上验证、未 push；不将本地业务测试冒充线上验收。
- 实例边界：只改统一源码并只读测试本地 `ruihao` 实例；无客户专属分支、跨客户配置或迁移。远程目标版本、生产备份与回滚点本阶段不适用，进入获授权的瑞昊部署前须独立核对。其他客户实例保持原状。
