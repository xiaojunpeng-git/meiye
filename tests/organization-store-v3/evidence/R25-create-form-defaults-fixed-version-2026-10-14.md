# R25 新建表单默认值固定版本（2026-10-14）

## 范围

- 门店端新增员工、平台端新建人员：手艺人服务业绩类型默认“消耗业绩+手工费”。编辑已有员工仍按详情中的已保存值回显；旧详情缺字段仍按原“消耗业绩”兼容。
- 平台端添加产品：参与库存管理保持原默认开启，“允许负库存”默认关闭，提交时显式发送 `0`。编辑/复制商品回显已有设置，旧商品详情缺字段仍沿用原开启兼容值。
- 不批量修改既有员工、商品或历史事实；不调整后端库存校验、业绩计算及其他终端的创建接口默认值。

## 固定版本

- 基线 HEAD：`c22ac96617bf73da6098b84d04a024950c255ddf`
- 四个业务源码文件的工作区补丁 SHA-256：`d1f933716d38a6b2d28c6d03b7e704a6af3f2b0b4b4c4a008d8969f304dd8f96`
- `前端代码/cashier-v3/src/views/StaffListView.vue`：`56ade89ae47bcf67bbd7ba3c3f4b293ea1754d2558ec3c24d1d98c778f3829ec`
- `前端代码/admin/src/pages/setting/staff/add.vue`：`d510eb9d2ae5c9d6ec6d5b6d83f448ac66e55479b94917f3edd287e4b647263b`
- `前端代码/admin/src/pages/product/productAdd/formModel.js`：`b0d16121f5c479fa6d15e250acced40bc4c803cef0a2fff598b3f80b97d43955`
- `前端代码/admin/src/pages/product/productAdd/index.vue`：`f464e5ca2ad1cfd8bd5631a78891cc487fb80283af70e0a7a10779c9f7a1324c`
- `tests/organization-store-v3/js/r25-create-form-defaults-contract.mjs`：`6b4cae5b94cfa9de768a7cf5772714b52d35d7380281f611398498301f5821db`

## 自测

- `node tests/organization-store-v3/js/r25-create-form-defaults-contract.mjs`：PASS。
- `node tests/organization-store-v3/js/craftsman-performance-type-contract.mjs`：PASS。
- 门店端 `npm run build`：PASS；平台端 `npm run build`：PASS（原有包体积警告）。`git diff --check`：PASS。
- 本地 `18091` 的新增员工表单：双项业绩选中；编辑既有员工孟自文：仍为“消耗业绩”。
- 本地 `18081` 的新建人员表单：双项业绩选中；添加产品的库存设置：“允许负库存”显示关闭。
- 未提交表单，不制造员工、商品或库存业务数据；保存持久化按现有表单写入契约检查，未用真实新建业务替代产品经理验收。

## 数据架构自检

- 权威源仍为员工与商品业务主表；本次仅改变新建表单初值。保存成功后才影响后续人员业绩类型及商品库存控制，事实粒度、成功事件和四类时间均不变。
- 原有保存事务、并发校验、幂等、Outbox、作废/冲销、权限及数据快照路径不变；不新增查询或索引，不做历史迁移和回填。
- 无报表口径或导出修改；可通过新建后读取业务主表与后续服务/出库事实对账，当前自测未写入真实记录。

## 文件分组与注释

- 前端源码：以上门店端一文件、平台端三文件；均已补充或更新新建默认与编辑兼容边界注释。
- 后端源码、数据库迁移、工程脚本：无。
- 自动化测试：`tests/organization-store-v3/js/r25-create-form-defaults-contract.mjs`。
- 生成物：两端本地构建 `dist/`，不作为源码交付。
- 验收证据：本文。状态：开发与自测完成，待产品经理确认固定版本验收；未 commit、未部署、未 push。
