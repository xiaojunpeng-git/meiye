# R9C 平台魔核 AI 入口跨内嵌页拖动补验

## 固定版本

- 基线 HEAD：`8ecdbc3cb56b1a77cbb295d8afcb2ed39f8c79c0`（已验收的 R9A、R9B）。
- 前端源码：`前端代码/shared/mohe-ai/browser-entry.mjs`，SHA-256 `abd40fb9f14f4ee331ab2a9e9cb5449d43fdac29ca10de4a713fe45087d37c34`。
- 自动化测试：`tests/mohe-ai/platform-entry-drag-contract.mjs`，SHA-256 `7b40d862207274ec3ace2d27eb257226306d75d630411eb9728bdb0e2952b535`。
- 上述两文件相对基线的 `git diff --binary` SHA-256：`6843191a968fb4dce7f58b4c3735f997642df3422d1a2c8c09e5eec434e40f6f`。
- 后端源码、迁移和工程脚本：无改动。生成包位于本机 `/private/tmp/r9-release-8ecdbc3c/`，不属于源码提交清单。

## 发现与修复

瑞昊线上首次发布 R9A 后，真实看板上的入口穿过内嵌收银看板时，父页面丢失后续 `pointermove` 和 `pointerup`。入口只能移动一小段、保持拖动样式，刷新后不记忆位置。首次线上发布已回滚到原平台入口；008 未部署。

入口在按下时捕获该指针，松开和销毁时释放捕获，使指针经过内嵌页仍由同一入口接收事件。原有 5px 点击阈值、位置边界与本地存储行为不变。修改处补充了指针捕获用途注释；未增加新的并行业务实现。

## 自测

1. `node tests/mohe-ai/platform-entry-drag-contract.mjs` 通过，新增捕获与释放断言。
2. `node tests/mohe-ai/browser-entry-contract.mjs` 通过，覆盖入口及问答原有行为。
3. `git diff --check` 通过。
4. 用固定基线源码和这两文件补丁独立构建平台包，Vue CLI 生产构建通过。两站候选包均仅替换平台主 JS 中的 `56d7` 模块，其余 193 个模块逐字节不变；JS 语法检查通过。瑞昊候选 JS SHA-256 `6ecb5381d22b7370a852ca1f5d0af40956459d7fe327908d2d46accbd6628662`；008 候选 JS SHA-256 `d7ceb081591f9440cbb379054cd1a646a6825941e818c72d4e5118b329b9d943`。
5. 在 `18081` 平台集团看板实际操作：从视口底部把入口拖过内嵌看板至中部，松手后拖动样式消失；刷新页面，入口仍位于落点。随后恢复测试前的本地入口位置。

## 发布边界

此补丁尚未由产品经理对固定版本重新确认验收，因而尚未提交或重新部署。瑞昊仍在原平台入口版本，008 未改。手机端 R9B 是小程序源码，服务器平台静态部署不会更新已上传的手机体验版。
