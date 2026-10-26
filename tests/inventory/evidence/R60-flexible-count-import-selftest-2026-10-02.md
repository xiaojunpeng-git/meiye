# R60 自制盘点表导入（第16轮追加）

状态：2026-10-02 产品经理明确“我测试没有问题,commit,部署瑞昊,push”，对应下列源码指纹；发布结果另行登记。

## 已确认业务范围

- 不要求本页导出的文件，不检查工作表名称、固定列顺序或完整模板。
- 当前一商品一规格，只按商品 ID 匹配权限目录；不判断多规格，不比较名称/规格/单位/条码。
- Excel xlsx 至少有商品ID和实盘库存/实盘数量列。其他列可选，支持标题、说明页、空行、额外列。
- 账面及盈亏仍只供参考；实际导入只检查输入数值/日期合法性和明确的批次粒度，重复同粒度记录不静默合并。
- 文件出错显示原始行号，整份通过后才替换对应行，未导入行保留。不提交正式盘点。

## 固定版本

基线 HEAD：dc79f72651821a05e2e9f9c8d317ef8cbb0439b7（其他任务可能继续推进 HEAD，验收以文件指纹为准）。

| 分组 | 文件 | SHA-256 |
| --- | --- | --- |
| 前端 | 前端代码/inventory-vue3/src/components/countWorksheet.js | 9619ad0ff4b40728a056caa0f815acdaf3c1eb73f787589c42ba70e0e0dc1941 |
| 前端 | 前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue | e010e78eef092e42ea2d90d0e0ea95acb036e60c42731b84a86d9613ef500ee8 |
| 测试 | tests/inventory/js/count-worksheet-contract.mjs | f3ebb17e2b148bc33fe081d7aea5be491356c3bfbd525e1befa1b17b567d0456 |
| 测试 | tests/inventory/js/count-flexible-import-contract.mjs | ca898e14a1adbe2275e22d8a4c0ef384824ce473b45b99fb9f7f4c779b65cfed |

后端、数据库迁移、工程脚本、发布生成物：无变更。证据为本记录。

## 验证

- count-worksheet-contract：1001行往返、隐藏批次粒度、重排列、自制工作表、说明页/标题/空行、原行号、错误商品ID、日期解码、非法日期拒绝、参考公式不阻断，PASS。
- count-flexible-import-contract：直接执行组件导入方法（仅模拟只读目录），商品ID匹配、不同名称单位不阻断、零值、重复导入更新、不丢未导入行、无效商品/负数/重复行失败不半导入、不同批次可导入，PASS。
- count-physical-authority-contract、count-batch-entry-contract、count-submission-contract：PASS。
- git diff --check：PASS。
- 本地 18086 源码绑定路径核对为唯一正本；重启该开发容器清除失效的 Vite 缓存，已核对返回最新模块。18081 使用该服务，但18091是直接挂载库存源码的独立Vite服务，不能用18086验证替代18091验证。
- 用户反馈18091仍显示旧提示后，核对 `/view_cashier_v3/@fs/inventory-vue3/src/components/countWorksheet.js` 确认仍返回旧缓存，容器内挂载文件则为新源码。已单独重启本地 mohe-cashier-v3-src 并核对该实际模块URL返回 matchCountProduct，不再含旧模板限制文字。未修改生产服务或库存。
- 尚未进行用户实际文件的浏览器导入验收（用户未提供文件），不以模拟目录测试冒充真实业务验收。

## 数据架构自检

商品/SKU身份及权限取现有后端目录，不信任文件描述，不扩大权限。文件输入仅变更浏览器待提交盘点行；正式库存事实仍由既有完成盘点事务生成。最小粒度保持SKU总量或显式BATCH批次，不能按批号猜粒度；不改业务日期、操作时间、事实快照、幂等键、并发版本、冲销规则、后端对账或报表。参考盈亏未作为正式指标或库存数量写入。目录按商品ID建立索引避免逐行全目录扫描。无迁移及生产操作。

## 注释与旧分支治理

两个前端文件已更新匹配职责、模板边界、参考列、原始行号、目录索引及原子导入说明。全路径扫描 countRowKey/catalogByKey，仅旧导入路径调用；新匹配路径覆盖后已删除旧描述拼接及单位一致校验，不保留两套逻辑。
