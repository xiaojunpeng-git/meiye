# R20 发布前置：已上线报表基线归档

- 产品经理于第20轮发布请求中单独授权：先归档两站已运行、尚未完整进入当前 Git 分支的前置报表代码，再单独提交第20轮修复。此提交不代表重新部署既有功能。
- 本地唯一源码基线：`58a555e6`。用独立 Git 暂存区精确组合已有本地源码，未从服务器反向覆盖源码文件，也未暂存其他脏工作区改动。
- 瑞昊与渼约008在归档前的 `RegisteredMetricReadServices.php`、`StoreUnifiedReportServices.php` 指纹一致，分别为 `6496d5561408335aefda61341bae18a0e31b89708e4635c7d8e0d70a286e6564`、`2abbd95c418f563764c3f92ca29303c5e83eca358b92204853a28099ba9c1b3b`。本地待验收文件与其差异仅为第20轮代码。
- 归档的另外三项已上线前置依赖：`StoreUnifiedReportOrganizationDimensionServices.php` 为 `8d9125e120dce9d41888864d18ec741b13a4edfe28031413ba7dcd43c0fcad4e`；`StoreMemberConsumptionRefundReadServices.php` 为 `56993f299e96ba1cecd636df5d9d19cad87e0f23cad1129918f06a3d5595574d`；`StoreSalespersonNetReadServices.php` 为 `f247efe8a6bcce91abcdb34a31129c8e2479cf5e14de8a596e395b4bb63f1319`。五个暂存文件逐一对照线上指纹，且在本地 PHP/Swoole 容器中语法检查通过。
- 本归档不含数据库迁移、前端源码、构建产物或实例配置；对线上服务无写入。第20轮的分类投影、测试和发布结果另行记录。
