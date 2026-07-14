//采购入库
export const purchase = [
  {
    title: "商品ID",
    key: "product_id",
    align: "center",
    minWidth: 80,
  },
  {
    title: "商品名称",
    slot: "store_names",
    align: "center",
    minWidth: 120,
  },
  {
    title: "商品规格",
    slot: "store_name",
    align: "center",
    minWidth: 120,
  },
  {
    title: "商品条形码",
    key: "bar_code",
    width: 120,
  },
  {
    title: "采购入库数量",
    slot: "inbound",
    align: "center",
    minWidth: 120,
  },
  {
    title: '操作',
    slot: 'action',
    fixed: 'right',
    align: 'center',
    minWidth: 60,
  }
];
//退货入库
export const returnGoods = [
  {
    title: "商品ID",
    key: "product_id",
    align: "center",
    minWidth: 80,
  },
  {
    title: "商品名称",
    slot: "store_names",
    align: "center",
    minWidth: 120,
  },
  {
    title: "商品规格",
    slot: "store_name",
    align: "center",
    minWidth: 120,
  },
  {
    title: "商品条形码",
    key: "bar_code",
    width: 120,
  },
  {
    title: "可入库数量",
    key: "stock",
    align: "center",
    minWidth: 80,
  },
  {
    title: "良品入库数量",
    slot: "goodProduct",
    align: "center",
    minWidth: 125,
  },
  {
    title: "残次品入库数量",
    slot: "spoiledGoods",
    align: "center",
    minWidth: 125,
  },
  {
    title: '操作',
    slot: 'action',
    fixed: 'right',
    align: 'center',
    minWidth: 70,
  }
];
// 残次品转良品
export const spoiledGoods = [
  {
    title: "商品ID",
    key: "product_id",
    align: "center",
    minWidth: 80,
  },
  {
    title: "商品名称",
    slot: "store_names",
    align: "center",
    minWidth: 120,
  },
  {
    title: "商品规格",
    slot: "store_name",
    align: "center",
    minWidth: 120,
  },
  {
    title: "商品条形码",
    key: "bar_code",
    width: 120,
  },
  {
    title: "残次品数量",
    key: "defective_stock",
    align: "center",
    minWidth: 120,
  },
  {
    title: "残次品转良品数量",
    slot: "inbound",
    align: "center",
    minWidth: 120,
  },
  {
    title: '操作',
    slot: 'action',
    fixed: 'right',
    align: 'center',
    minWidth: 50,
  }
];
// 其他入库
export const otherGoods = [
  {
    title: "商品ID",
    key: "product_id",
    align: "center",
    minWidth: 80,
  },
  {
    title: "商品名称",
    slot: "store_names",
    align: "center",
    minWidth: 120,
  },
  {
    title: "商品规格",
    slot: "store_name",
    align: "center",
    minWidth: 120,
  },
  {
    title: "商品条形码",
    key: "bar_code",
    width: 120,
  },
  {
    title: "良品入库数量",
    slot: "goodProduct",
    align: "center",
    minWidth: 125,
  },
  {
    title: "残次品入库数量",
    slot: "spoiledGoods",
    align: "center",
    minWidth: 125,
  },
  {
    title: '操作',
    slot: 'action',
    fixed: 'right',
    align: 'center',
    minWidth: 60,
  }
];
// 出库类型展示
export const outGoods = [
  {
    title: "商品ID",
    key: "product_id",
    align: "center",
    minWidth: 80,
  },
  {
    title: "商品名称",
    slot: "store_names",
    align: "center",
    minWidth: 120,
  },
  {
    title: "商品规格",
    slot: "store_name",
    align: "center",
    minWidth: 120,
  },
  {
    title: "商品条形码",
    key: "bar_code",
    width: 120,
  },
  {
    title: "良品库存",
    key: "stock",
    align: "center",
    minWidth: 125,
  },
  {
    title: "良品出库数量",
    slot: "goodProduct",
    align: "center",
    minWidth: 125,
  },
  {
    title: "残次品库存",
    key: "defective_stock",
    align: "center",
    minWidth: 125,
  },
  {
    title: "残次品出库数量",
    slot: "spoiledGoods",
    align: "center",
    minWidth: 125,
  },
  {
    title: '操作',
    slot: 'action',
    fixed: 'right',
    align: 'center',
    minWidth: 60,
  }
];
// 出库类型展示--良品转残次品
export const outGoodProduct = [
  {
    title: "商品ID",
    key: "product_id",
    align: "center",
    minWidth: 80,
  },
  {
    title: "商品名称",
    slot: "store_names",
    align: "center",
    minWidth: 120,
  },
  {
    title: "商品规格",
    slot: "store_name",
    align: "center",
    minWidth: 120,
  },
  {
    title: "商品条形码",
    key: "bar_code",
    width: 120,
  },
  {
    title: "良品库存",
    key: "stock",
    align: "center",
    minWidth: 125,
  },
  {
    title: "良品转残次品数量",
    slot: "goodProduct",
    align: "center",
    minWidth: 125,
  },
  {
    title: '操作',
    slot: 'action',
    fixed: 'right',
    align: 'center',
    minWidth: 60,
  }
];
// 盘点单
export const inventoryCount = [
  {
    title: "商品ID",
    key: "product_id",
    align: "center",
    minWidth: 80,
  },
  {
    title: "商品名称",
    slot: "store_names",
    align: "center",
    minWidth: 200,
  },
  {
    title: "商品规格",
    slot: "store_name",
    align: "center",
    minWidth: 200,
  },
  {
    title: "商品条形码",
    key: "bar_code",
    width: 150,
  },
  {
    title: "良品库存",
    key: "stock",
    align: "center",
    minWidth: 120,
  },
  {
    title: "良品盘点数量",
    slot: "count_stock",
    align: "center",
    minWidth: 125,
  },
  {
    title: "良品盈亏数量",
    slot: "change_stock",
    align: "center",
    minWidth: 125,
  },
  {
    title: "残次品库存",
    key: "defective_stock",
    align: "center",
    minWidth: 125,
  },
  {
    title: "残次品盘点数量",
    slot: "count_defective_stock",
    align: "center",
    minWidth: 125,
  },
  {
    title: "残次品盈亏数量",
    slot: "change_defective_stock",
    align: "center",
    minWidth: 125,
  },
  {
    title: '操作',
    slot: 'action',
    fixed: 'right',
    align: 'center',
    minWidth: 60,
  }
];
// 盘点单详情
export const inventoryCountInfo = [
  {
    title: "ID",
    key: "product_id",
    minWidth: 80,
  },
  {
    title: "商品名称",
    slot: "product_name",
    minWidth: 200,
  },
  {
    title: "商品规格",
    slot: "sku",
    minWidth: 200,
  },
  {
    title: "商品条形码",
    key: "bar_code",
    width: 160,
  },
  {
    title: "良品库存",
    key: "stock",
    minWidth: 120,
  },
  {
    title: "良品盘点数量",
    slot: "count_stock",
    minWidth: 125,
  },
  {
    title: "良品盈亏数量",
    slot: "change_stock",
    minWidth: 125,
  },
  {
    title: "残次品库存",
    slot: "defective_stock",
    minWidth: 125,
  },
  {
    title: "残次品盘点数量",
    slot: "count_defective_stock",
    minWidth: 125,
  },
  {
    title: "残次品盈亏数量",
    slot: "change_defective_stock",
    minWidth: 125,
  }
];
// 出入库明细列表页
export const inventoryDetailsList = [
  {
    title: "商品ID",
    key: "product_id",
    align: "left",
    width: 80,
  },
  {
    title: "商品名称",
    slot: "store_name",
    align: "left",
    minWidth: 200,
  },
  {
    title: "商品规格",
    slot: "suk",
    align: "left",
    minWidth: 200,
  },
  {
    title: "商品条形码",
    key: "bar_code",
    width: 160,
  },
  {
    title: "良品库存",
    key: "stock",
    align: "left",
    minWidth: 120,
  },
  {
    title: "残次品库存",
    key: "defective_stock",
    align: "left",
    minWidth: 120,
  },
  {
    title: '操作',
    slot: 'action',
    fixed: 'right',
    align: 'left',
    width: 100,
  }
];
// 出入库明细详情页面
export const inventoryDetails = [
  {
    title: "单据编号",
    slot: "order_id",
    align: "left",
    minWidth: 200,
  },
  {
    title: "变更类型",
    slot: "order_type",
    align: "left",
    minWidth: 120,
  },
  {
    title: "良品出入库数量",
    slot: "stock",
    align: "left",
    minWidth: 120,
  },
  {
    title: "残次品出入库数量",
    slot: "defective_stock",
    align: "left",
    minWidth: 120,
  },
  {
    title: "业务时间",
    key: "stock_time",
    align: "left",
    minWidth: 120,
  },
  {
    title: "操作员",
    key: "admin_name",
    align: "left",
    minWidth: 120,
  },
  {
    title: "创建时间",
    key: "add_time",
    align: "left",
    minWidth: 150,
  }
];
// 入库统计
export const inboundStatistics = [
  {
    title: "商品ID",
    key: "product_id",
    align: "left",
    width: 80,
  },
  {
    title: "商品名称",
    slot: "product_name",
    align: "left",
    minWidth: 200,
  },
  {
    title: "商品规格",
    slot: "sku",
    align: "left",
    minWidth: 200,
  },
  {
    title: "商品条形码",
    key: "bar_code",
    width: 160,
  },
  {
    title: "总入库数",
    key: "total_stock",
    align: "left",
    minWidth: 100,
  },
  {
    title: "采购入库",
    key: "purchase_stock",
    align: "left",
    minWidth: 100,
  },
  {
    title: "盘盈入库",
    key: "profit_stock",
    align: "left",
    minWidth: 100,
  },
  {
    title: "退货入库",
    key: "return_stock",
    align: "left",
    minWidth: 100,
  },
  {
    title: "其他入库",
    key: "other_in_stock",
    align: "left",
    minWidth: 100,
  },
  {
    title: "残次品转良品",
    key: "defective_to_good_stock",
    align: "left",
    minWidth: 100,
  }
];
// 出库统计
export const outboundStatistics = [
  {
    title: "商品ID",
    key: "product_id",
    align: "left",
    width: 80,
  },
  {
    title: "商品名称",
    slot: "product_name",
    align: "left",
    minWidth: 200,
  },
  {
    title: "商品规格",
    slot: "sku",
    align: "left",
    minWidth: 200,
  },
  {
    title: "商品条形码",
    key: "bar_code",
    width: 160,
  },
  {
    title: "总出库数",
    key: "total_stock",
    align: "left",
    minWidth: 100,
  },
  {
    title: "销售出库",
    key: "sale_stock",
    align: "left",
    minWidth: 100,
  },
  {
    title: "盘亏出库",
    key: "loss_stock",
    align: "left",
    minWidth: 100,
  },
  {
    title: "过期退货",
    key: "expired_return_stock",
    align: "left",
    minWidth: 100,
  },
  {
    title: "试用出库",
    key: "use_out_stock",
    align: "left",
    minWidth: 100,
  },
  {
    title: "报废出库",
    key: "scrap_out_stock",
    align: "left",
    minWidth: 100,
  },
  {
    title: "其他出库",
    key: "other_out_stock",
    align: "left",
    minWidth: 100,
  },
  {
    title: "良品转残次品",
    key: "good_to_defective_stock",
    align: "left",
    minWidth: 100,
  }
];
