# R19 费用周期与收支单分页本地自测

- 基线 HEAD：`238e1101c2080683f66c7b3f9cb9d839f50d54ac`。
- 前端源码：`前端代码/fund-vue3/src/App.vue`、`src/styles.css`、`vite.config.js`、`package.json`、`package-lock.json`。
- 后端源码、数据库迁移：无。收支单继续读取既有 `/documents` 接口的 `list/count/page/limit`；台账和统计继续读取原接口。
- 自动化测试：`tests/fund-management/js/fund-vue3-contract.mjs`。工程脚本：`scripts/start-local.sh` 仅为费用容器增加共享控件源码挂载；该脚本另有任务既存未提交改动，提交时必须只暂存本次挂载行。
- 生成物：本地 `fund-vue3/dist/`，不作为源码提交。验收证据：本文及本地浏览器核对。
- 固定文件 SHA-256（按下列顺序将 `shasum` 输出再计算 SHA-256，清单指纹 `2ce65e1504ba`）：

```text
8b35c87bcca41508e24d17149cb31da6f26d791efe451baf3c4ff3c45b69c367  前端代码/fund-vue3/package.json
3b5aa1e7901f210f6be15737dff8ab88b24dbedb2f2704c6de1745cc3e4c49c7  前端代码/fund-vue3/package-lock.json
1442400864d6a4a56c6aca611322b06935825f132c35b5706a60678cb48759d1  前端代码/fund-vue3/vite.config.js
bb2a8e10e27f6e5ddf2fe3fe3ad419379d89d4bd43852ef18b84fab017885114  前端代码/fund-vue3/src/App.vue
8e71c3db85992a0bbc578ea359041b4cfbef9c4d00977ebb9cfbd0f28615cef5  前端代码/fund-vue3/src/styles.css
6e2dc7a8a247f2c55d9a750432cb03da00e4335b3d7a8ab5366d7b01b3b94bf3  scripts/start-local.sh
cef5a7ce0efad1d44d4ec548829501ce8ef0808be62659752dc6cd8f4fbfd180  tests/fund-management/js/fund-vue3-contract.mjs
```

## 数据架构自检

费用业务主表与已审核收支单仍是原权威源，单据/明细粒度、审核生效事件、业务日期、真实操作时间、冲销和历史科目快照不变。本次仅缩小默认查询时间范围并接通既有服务端分页，不新增写入、事实、金额算法或数据迁移。门店及平台权限仍由后端原接口强制执行；`count` 与列表来自同一筛选和权限查询。分页每页最多 100 条，减少一次加载的单据行；台账与统计、导出的完整查询口径不变。

## 验证结果

- `npm run build`：通过。
- `node tests/fund-management/js/fund-vue3-contract.mjs`：通过。
- `php tests/fund-management/php/fund-service-contract.php`：通过。
- `git diff --check`：通过。
- 本地浏览器 `http://127.0.0.1:18091/view_cashier_v3/#/fund`：三个视图均显示 `2026-10-01 至 2026-10-06` 周期；收支单周期弹层显示快捷周期与日历；当前门店本月返回 5 条，页面显示 `共 5 条，第 1 / 1 页`，无额外历史单据混入；每页条数切换至 50 后仍返回 5 条，再恢复默认 20。
- 跨页真人点击：当前门店本月不足一页，尚未完成；需要产品经理用多页日期范围核对第 2 页记录。静态契约已检查页码、每页条数及总数绑定，但不把它冒充跨页业务验收。

本记录为本地自测，不代表产品经理已确认验收；未 commit、未部署、未 push。
