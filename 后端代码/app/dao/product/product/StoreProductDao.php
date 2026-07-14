<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2020 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------

namespace app\dao\product\product;


use app\dao\BaseDao;
use app\model\product\product\StoreProduct;
use think\facade\Config;

/**
 * Class StoreProductDao
 * @package app\dao\product\product
 */
class StoreProductDao extends BaseDao
{
    /**
     * 设置模型
     * @return string
     */
    protected function setModel(): string
    {
        return StoreProduct::class;
    }

    /**
     * 区间写法
     * @param string $key
     * @param array $range
     * @param $query
     * @return void
     */
    protected function rangeQuery(string $key, array $range, $query)
    {
        if (count($range) == 2) {
            if ($range[0] !== '' && $range[1] !== '') {
                $query->whereBetween($key, $range);
            } elseif ($range[0] !== '' && $range[1] === '') {
                $query->where($key, '>=', $range[0]);
            } elseif ($range[0] === '' && $range[1] !== '') {
                $query->where($key, '<=', $range[1]);
            }
        }
    }

    /**
     * @param array $where
     * @return \mohe\basic\BaseModel|mixed|\think\Model
     */
    public function search(array $where = [])
    {
        return parent::search($where)
            ->when(isset($where['not_ids']) && $where['not_ids'], function ($query) use ($where) {
                $query->whereNotIn('id', $where['not_ids']);
            })->when(isset($where['collate_code_id']) && $where['collate_code_id'], function ($query) use ($where) {
                $query->where('product_type', 0)->where('is_presale_product', 0)->where('system_form_id', 0);
            })->when(isset($where['relation_id']) && $where['relation_id'] !== '', function ($query) use ($where) {
                if (is_array($where['relation_id'])) {
                    $query->whereIn('relation_id', $where['relation_id']);
                } else {
                    $query->where('relation_id', $where['relation_id']);
                }
            })->when(isset($where['is_integral']) && $where['is_integral'], function ($query) use ($where) {
                $query->where('product_type', 'in', [0, 1, 2, 3]);
            })->when(isset($where['is_card']) && $where['is_card'], function ($query) use ($where) {
                if (isset($where['product_type']) && $where['product_type']) {
                    $query->where('product_type', $where['product_type']);
                } else {
                    $query->where('product_type', 'in', [0, 6]);
                }
                $query->where('find_in_set(' . 2 . ',`delivery_type`)');
            })
            ->when(isset($where['store_name']) && $where['store_name'] && is_string($where['store_name']), function ($query) use ($where) {
                if (isset($where['field_key']) && $where['field_key'] && in_array($where['field_key'], ['product_id', 'bar_code', 'code', 'store_name', 'keyword'])) {
                    switch ($where['field_key']) {
                        case 'product_id':
                            $query->where('id', trim($where['store_name']));
                            break;
                        case 'store_name':
                            $query->where('store_name', 'like', '%' . trim($where['store_name']) . '%');
                            break;
                        case 'keyword':
                            $query->where('keyword', 'like', '%' . trim($where['store_name']) . '%');
                            break;
                        case 'bar_code':
                            $query->where(function ($query) use ($where) {
                                $query->where('bar_code', trim($where['store_name']))->whereOr('id', 'IN', function ($q) use ($where) {
                                    $q->name('store_product_attr_value')->field('product_id')->where('bar_code', trim($where['store_name']))->select();
                                });
                            });
                            break;
                        case 'code':
                            $query->where(function ($query) use ($where) {
                                $query->where('code', trim($where['store_name']))->whereOr('id', 'IN', function ($q) use ($where) {
                                    $q->name('store_product_attr_value')->field('product_id')->where('code', trim($where['store_name']))->select();
                                });
                            });
                            break;
                    }
                } else {
                    $query->where(function ($q) use ($where) {
                        $q->where('id|keyword|store_name|store_info|bar_code|code', 'LIKE', '%' . trim($where['store_name']) . '%')->whereOr('id', 'IN', function ($q) use ($where) {
                            $q->name('store_product_attr_value')->field('product_id')->where('bar_code', trim($where['store_name']))->select();
                        });
                    });
                }
            })//销量区间
            ->when(isset($where['sales_range']) && $where['sales_range'] !== '', function ($query) use ($where) {
                $this->rangeQuery('sales', explode('-', $where['sales_range']), $query);
            })
            //库存区间
            ->when(isset($where['stock_range']) && $where['stock_range'] !== '', function ($query) use ($where) {
                $this->rangeQuery('stock', explode('-', $where['stock_range']), $query);
            })
            //排除定制卡
            ->when(isset($where['not_dingzhi']) && $where['not_dingzhi'] !== '', function ($query) use ($where) {
                $mainId = 8154; //定制卡id
                $productIds = StoreProduct::where("pid", $mainId)->column("id");
                $productIds[] = $mainId;
                $query->whereNotIn('id', $productIds);
            })
            //售价区间
            ->when(isset($where['price_range']) && $where['price_range'] !== '', function ($query) use ($where) {
                $this->rangeQuery('price', explode('-', $where['price_range']), $query);
            })
            //收藏区间
            ->when(isset($where['collect_range']) && $where['collect_range'] !== '', function ($query) use ($where) {
                $this->rangeQuery('collect', explode('-', $where['collect_range']), $query);
            })
            //添加时间区间
            ->when(isset($where['create_range']) && $where['create_range'] !== '', function ($query) use ($where) {
                $create_range = explode('-', $where['create_range']);
                $start_time = strtotime($create_range[0]);
                $end_time = strtotime($create_range[1]);
                $this->rangeQuery('add_time', [$start_time, $end_time], $query);
            })->when(isset($where['tid']) && $where['tid'], function ($query) use ($where) {//三级
                $query->whereIn('id', function ($query) use ($where) {
                    $query->name('store_product_relation')->where('type', 1)->where('relation_id', $where['tid'])->field('product_id')->select();
                });
            })->when(isset($where['sid']) && $where['sid'], function ($query) use ($where) {//二级
                $query->whereIn('id', function ($query) use ($where) {
                    $query->name('store_product_relation')->where('type', 1)->whereIn('relation_id', function ($query) use ($where) {
                        $query->name('store_product_category')->where('id|pid', $where['sid'])->field('id')->select();
                    })->field('product_id')->select();
                });
            })->when(isset($where['cid']) && $where['cid'], function ($query) use ($where) {//一级
                $query->whereIn('id', function ($query) use ($where) {
                    $query->name('store_product_relation')->where('type', 1)->whereIn('relation_id', function ($query) use ($where) {
                        $query->name('store_product_category')->where(function ($query) use ($where) {
                            $query->whereFindinSet('path', $where['cid'])->whereOr('id', $where['cid']);
                        })->field('id')->select();
                    })->field('product_id')->select();
                });
            })->when(isset($where['brand_id']) && $where['brand_id'], function ($query) use ($where) {
                $query->whereIn('id', function ($query) use ($where) {
                    $query->name('store_product_relation')->where('type', 2)->whereIn('relation_id', $where['brand_id'])->field('product_id')->select();
                });
            })->when(isset($where['store_label_id']) && $where['store_label_id'], function ($query) use ($where) {
                $query->whereIn('id', function ($query) use ($where) {
                    $query->name('store_product_relation')->where('type', 3)->whereIn('relation_id', $where['store_label_id'])->field('product_id')->select();
                });
            })->when(isset($where['is_live']) && $where['is_live'] == 1, function ($query) use ($where) {
                $query->whereNotIn('id', function ($query) {
                    $query->name('live_goods')->where('is_del', 0)->where('audit_status', '<>', 3)->field('product_id')->select();
                });
            })->when(isset($where['is_supplier']) && in_array((int)$where['is_supplier'], [0, 1]), function ($query) use ($where) {
                if ($where['is_supplier'] == 1) {//查询供应商商品
                    $query->where('type', 2);
                } else {
                    $query->where('type', '<>', 2);
                }
            })->when(isset($where['choose_type']) && $where['choose_type'], function ($query) use ($where) {
                $query->where('is_presale_product', 0)->where('is_vip_product', 0);
                switch ($where['choose_type']) {
                    case 1://秒杀
                    case 2://砍价
                    case 3://拼团
                    case 4://积分
                    case 7://新人礼
                    case 8://抽奖奖品
                        $query->whereIn('product_type', [0, 5,6]);
                        break;
                    case 5://套餐
                        $query->where('product_type', 0);
                        break;
                    case 90://卡项关联商品: 普通、预约，支持自提
                        $query->whereIn('product_type', [0, 6])->where('find_in_set(' . 2 . ',`delivery_type`)');
                        break;
                    case 91://添加门店同步商品 平台商品+供应商商品
                        $query->whereIn('applicable_type', [1, 2])->whereIn('type', [0, 2])->whereIn('product_type', [0, 4, 5, 6]);
                        break;
                    case 92://优惠活动参与商品
                        $query->whereIn('type', [0, 2]);
                        break;
                    case 93://优惠活动赠送商品
                        $query->whereIn('type', [0, 2])->where('product_type', 0);
                        break;
                    case 94://出入库选择商品 排除预约、卡密商品
                        $query->whereIn('product_type', [0, 3, 5]);
                        break;
                    default:
                        $query->where('product_type', 0);
                        break;
                }
            });
    }


    /**
     * 条件获取数量
     * @param array $where
     * @return int
     */
    public function getCount(array $where)
    {
        if (!empty($where['cate_id']) && is_array($where['cate_id']) || isset($where['store_cate_id']) && !empty($where['store_cate_id']) && is_array($where['store_cate_id'])) {
            if (isset($where['cate_id']) && empty($where['cate_id'])) unset($where['cate_id']);
            if (isset($where['store_cate_id']) && empty($where['store_cate_id'])) unset($where['store_cate_id']);
            if (isset($where['cate_id']) && is_array($where['cate_id']) && isset($where['store_cate_id']) && is_array($where['store_cate_id'])) {
                $cate_id = array_merge($where['cate_id'], $where['store_cate_id']);
            } elseif (isset($where['cate_id']) && !isset($where['store_cate_id'])) {
                $cate_id = $where['cate_id'] ?? [];
            } else {
                $cate_id = $where['store_cate_id'] ?? [];
            }
			unset($where['cate_id'], $where['store_cate_id']);
            return $this->getModel()->alias('a')
                ->join('store_product_relation r', 'r.product_id = a.id')
                ->when(isset($where['store_name']) && $where['store_name'] !== '', function ($query) use ($where) {
                    $query->where(function ($q) use ($where) {
                        $q->where('a.id|a.keyword|a.store_name|a.store_info|a.bar_code|a.code', 'LIKE', '%' . trim($where['store_name']) . '%')->whereOr('a.id', 'IN', function ($q) use ($where) {
                            $q->name('store_product_attr_value')->field('product_id')->where('bar_code', trim($where['store_name']))->select();
                        });
                    });
                })->when(isset($where['unit_name']) && $where['unit_name'] !== '', function ($query) use ($where) {
                    $query->where('a.unit_name', $where['unit_name']);
                })->when(isset($where['ids']) && $where['ids'], function ($query) use ($where) {
                    if (!isset($where['type'])) $query->where('a.id', 'in', $where['ids']);
                })->when(isset($where['not_ids']) && $where['not_ids'], function ($query) use ($where) {
                    $query->whereNotIn('a.id', $where['not_ids']);
                })->when(isset($where['pid']) && $where['pid'] !== '', function ($query) use ($where) {
                    $query->where('a.pid', $where['pid']);
                })->when(isset($where['type']) && $where['type'] !== '', function ($query) use ($where) {
                    $query->where('a.type', $where['type']);
                })->when(isset($where['relation_id']) && $where['relation_id'] !== '', function ($query) use ($where) {
                    if (is_array($where['relation_id'])) {
                        $query->whereIn('a.relation_id', $where['relation_id']);
                    } else {
                        $query->where('a.relation_id', $where['relation_id']);
                    }
                })->when(isset($where['store_id']) && $where['store_id'] !== '', function ($query) use ($where) {
                    if (is_array($where['store_id'])) {
                        if ($where['store_id']) $query->whereIn('relation_id', $where['store_id'])->where('type', 1);
                    } else {
                        if ($where['store_id'] !== '') $query->where('relation_id', $where['store_id'])->where('type', 1);
                    }
                })->when(isset($where['supplier_id']) && $where['supplier_id'] !== '', function ($query) use ($where) {
                    if (is_array($where['supplier_id'])) {
                        if ($where['supplier_id']) $query->whereIn('relation_id', $where['supplier_id'])->where('type', 1);
                    } else {
                        if ($where['supplier_id'] !== '') $query->where('relation_id', $where['supplier_id'])->where('type', 1);
                    }
                })->when(isset($where['is_vip']) && $where['is_vip'], function ($query) use ($where) {
					$query->where(['a.is_vip' => $where['is_vip']]);
				})->when(isset($where['spec_type']) && $where['spec_type'], function ($query) use ($where) {
                    $query->where(['a.spec_type' => $where['spec_type']]);
                })->when(isset($where['is_vip']) && $where['is_vip'], function ($query) use ($where) {
                    $query->where(['a.is_vip' => $where['is_vip']]);
                })->when(isset($where['delivery_type']) && $where['delivery_type'], function ($query) use ($where) {
                    if (in_array($where['delivery_type'], [1, 2, 3])) {
                        $query->where('find_in_set(' . $where['delivery_type'] . ',`delivery_type`)');
                    }
                })->when(isset($where['status']) && '' !== $where['status'], function ($query) use ($where) {
                    $value = $where['status'];
                    switch ((int)$value) {
                        case -2://强制下架
                            $query->where(['a.is_verify' => -2]);
                            break;
                        case -1://审核未通过
                            $query->where(['a.is_verify' => -1]);
                            break;
                        case 0://待审核
                            $query->where(['a.is_verify' => 0, 'a.is_del' => 0]);
                            break;
                        case 1:
                            $query->where(['a.is_show' => 1, 'a.is_del' => 0, 'a.is_verify' => 1]);
                            break;
                        case 2:
                            $query->where(['a.is_show' => 0, 'a.is_del' => 0, 'a.is_verify' => 1]);
                            break;
                        case 3:
                            $query->where(['a.is_del' => 0, 'a.is_verify' => 1]);
                            break;
                        case 4:
                            $query->where(['a.is_show' => 1, 'a.is_del' => 0, 'a.is_verify' => 1])->where(function ($query) {
                                $query->whereOr('a.stock', 0)->whereOr('a.is_sold', 1);
                            });
                            break;
                        case 5:
//							if (isset($data['store_stock']) && $data['store_stock']) {
//								$store_stock = $data['store_stock'];
//								$query->where(['a.is_show' => 1, 'a.is_del' => 0, 'a.is_verify' => 1, 'a.is_police' => 1])->where('a.stock', '<=', $store_stock)->where('a.stock', '>', 0);
//							} else {
                            $query->where(['a.is_show' => 1, 'a.is_del' => 0, 'a.is_verify' => 1, 'a.is_police' => 1])->where('a.stock', '>=', 0);
//							}
                            break;
                        case 6:
                            $query->where(['a.is_del' => 1]);
                            break;
                        case 7://回收站 & 下架商品
                            $query->where(function ($q) {
                                $q->where(['a.is_del' => 1])->whereOr('a.is_show', 0);
                            });
                            break;
                    };
                })->when(isset($where['pids']) && $where['pids'], function ($query) use ($where) {
                    if ((isset($where['priceOrder']) && $where['priceOrder'] != '') || (isset($where['salesOrder']) && $where['salesOrder'] != '')) {
                        $query->whereIn('a.pid', $where['pids']);
                    } else {
                        $query->whereIn('a.pid', $where['pids'])->orderField('pid', $where['pids'], 'asc');
                    }
                })->when(isset($where['not_pids']) && $where['not_pids'], function ($query) use ($where, $cate_id) {
                    $query->whereNotIn('a.pid', $where['not_pids']);
                })->when(isset($where['store_label_id']) && $where['store_label_id'], function ($query) use ($where) {
                    $query->whereIn('a.id', function ($query) use ($where) {
                        $query->name('store_product_relation')->where('type', 3)->whereIn('relation_id', $where['store_label_id'])->field('product_id')->select();
                    });
                })//销量区间
                ->when(isset($where['sales_range']) && $where['sales_range'] !== '', function ($query) use ($where) {
                    $this->rangeQuery('a.sales', explode('-', $where['sales_range']), $query);
                })
                //库存区间
                ->when(isset($where['stock_range']) && $where['stock_range'] !== '', function ($query) use ($where) {
                    $this->rangeQuery('a.stock', explode('-', $where['stock_range']), $query);
                })
                //售价区间
                ->when(isset($where['price_range']) && $where['price_range'] !== '', function ($query) use ($where) {
                    $this->rangeQuery('a.price', explode('-', $where['price_range']), $query);
                })
                //收藏区间
                ->when(isset($where['collect_range']) && $where['collect_range'] !== '', function ($query) use ($where) {
                    $this->rangeQuery('a.collect', explode('-', $where['collect_range']), $query);
                })
                //添加时间区间
                ->when(isset($where['create_range']) && $where['create_range'] !== '', function ($query) use ($where) {
                    $create_range = explode('-', $where['create_range']);
                    $start_time = strtotime($create_range[0]);
                    $end_time = strtotime($create_range[1]);
                    $this->rangeQuery('a.add_time', [$start_time, $end_time], $query);
                })->where('r.type', 1)
                ->where('r.relation_id', 'IN', $cate_id)
				->group('a.id')
                ->count('a.id');
        } else {
            return $this->search($where)
                ->when(isset($where['unit_name']) && $where['unit_name'] !== '', function ($query) use ($where) {
                    $query->where('unit_name', $where['unit_name']);
                })->when(isset($where['ids']) && $where['ids'], function ($query) use ($where) {
                    if (!isset($where['type'])) $query->where('id', 'in', $where['ids']);
                })->when(isset($where['pids']) && $where['pids'], function ($query) use ($where) {
                    if ((isset($where['priceOrder']) && $where['priceOrder'] != '') || (isset($where['salesOrder']) && $where['salesOrder'] != '')) {
                        $query->whereIn('pid', $where['pids']);
                    } else {
                        $query->whereIn('pid', $where['pids'])->orderField('pid', $where['pids'], 'asc');
                    }
                })->when(isset($where['not_pids']) && $where['not_pids'], function ($query) use ($where) {
                    $query->whereNotIn('pid', $where['not_pids']);
                })->count();
        }
    }

    /**
     * 获取商品列表
     * @param array $where
     * @param int $page
     * @param int $limit
     * @param string $order
     * @param array $with
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getList(array $where, int $page = 0, int $limit = 0, string $order = '', array $with = [])
    {
		if (!empty($where['cate_id']) && is_array($where['cate_id']) || isset($where['store_cate_id']) && !empty($where['store_cate_id']) && is_array($where['store_cate_id'])) {
			if (isset($where['cate_id']) && empty($where['cate_id'])) unset($where['cate_id']);
			if (isset($where['store_cate_id']) && empty($where['store_cate_id'])) unset($where['store_cate_id']);
			if (isset($where['cate_id']) && is_array($where['cate_id']) && isset($where['store_cate_id']) && is_array($where['store_cate_id'])) {
				$cate_id = array_merge($where['cate_id'], $where['store_cate_id']);
			} elseif (isset($where['cate_id']) && !isset($where['store_cate_id'])) {
				$cate_id = $where['cate_id'] ?? [];
			} else {
				$cate_id = $where['store_cate_id'] ?? [];
			}
			unset($where['cate_id'], $where['store_cate_id']);
			return $this->getModel()->alias('a')->field(['a.*', '0 as likes'])
				->join('store_product_relation r', 'r.product_id = a.id')
				->when(isset($where['store_name']) && $where['store_name'] !== '', function ($query) use ($where) {
					$query->where(function ($q) use ($where) {
						$q->where('a.id|a.keyword|a.store_name|a.store_info|a.bar_code|a.code', 'LIKE', '%' . trim($where['store_name']) . '%')->whereOr('a.id', 'IN', function ($q) use ($where) {
							$q->name('store_product_attr_value')->field('product_id')->where('bar_code', trim($where['store_name']))->select();
						});
					});
				})->when(isset($where['unit_name']) && $where['unit_name'] !== '', function ($query) use ($where) {
					$query->where('a.unit_name', $where['unit_name']);
				})->when(isset($where['ids']) && $where['ids'], function ($query) use ($where) {
					if (!isset($where['type'])) $query->where('a.id', 'in', $where['ids']);
				})->when(isset($where['not_ids']) && $where['not_ids'], function ($query) use ($where) {
					$query->whereNotIn('a.id', $where['not_ids']);
				})->when(isset($where['pid']) && $where['pid'] !== '', function ($query) use ($where) {
					$query->where('a.pid', $where['pid']);
				})->when(isset($where['type']) && $where['type'] !== '', function ($query) use ($where) {
					$query->where('a.type', $where['type']);
				})->when(isset($where['relation_id']) && $where['relation_id'] !== '', function ($query) use ($where) {
					if (is_array($where['relation_id'])) {
						$query->whereIn('a.relation_id', $where['relation_id']);
					} else {
						$query->where('a.relation_id', $where['relation_id']);
					}
				})->when(isset($where['store_id']) && $where['store_id'] !== '', function ($query) use ($where) {
					if (is_array($where['store_id'])) {
						if ($where['store_id']) $query->whereIn('relation_id', $where['store_id'])->where('type', 1);
					} else {
						if ($where['store_id'] !== '') $query->where('relation_id', $where['store_id'])->where('type', 1);
					}
				})->when(isset($where['supplier_id']) && $where['supplier_id'] !== '', function ($query) use ($where) {
					if (is_array($where['supplier_id'])) {
						if ($where['supplier_id']) $query->whereIn('relation_id', $where['supplier_id'])->where('type', 1);
					} else {
						if ($where['supplier_id'] !== '') $query->where('relation_id', $where['supplier_id'])->where('type', 1);
					}
				})->when(isset($where['is_vip']) && $where['is_vip'], function ($query) use ($where) {
					$query->where(['a.is_vip' => $where['is_vip']]);
				})->when(isset($where['spec_type']) && $where['spec_type'], function ($query) use ($where) {
					$query->where(['a.spec_type' => $where['spec_type']]);
				})->when(isset($where['is_vip']) && $where['is_vip'], function ($query) use ($where) {
					$query->where(['a.is_vip' => $where['is_vip']]);
				})->when(isset($where['delivery_type']) && $where['delivery_type'], function ($query) use ($where) {
					if (in_array($where['delivery_type'], [1, 2, 3])) {
						$query->where('find_in_set(' . $where['delivery_type'] . ',`delivery_type`)');
					}
				})->when(isset($where['status']) && '' !== $where['status'], function ($query) use ($where) {
					$value = $where['status'];
					switch ((int)$value) {
						case -2://强制下架
							$query->where(['a.is_verify' => -2]);
							break;
						case -1://审核未通过
							$query->where(['a.is_verify' => -1]);
							break;
						case 0://待审核
							$query->where(['a.is_verify' => 0, 'a.is_del' => 0]);
							break;
						case 1:
							$query->where(['a.is_show' => 1, 'a.is_del' => 0, 'a.is_verify' => 1]);
							break;
						case 2:
							$query->where(['a.is_show' => 0, 'a.is_del' => 0, 'a.is_verify' => 1]);
							break;
						case 3:
							$query->where(['a.is_del' => 0, 'a.is_verify' => 1]);
							break;
						case 4:
							$query->where(['a.is_show' => 1, 'a.is_del' => 0, 'a.is_verify' => 1])->where(function ($query) {
								$query->whereOr('a.stock', 0)->whereOr('a.is_sold', 1);
							});
							break;
						case 5:
//							if (isset($data['store_stock']) && $data['store_stock']) {
//								$store_stock = $data['store_stock'];
//								$query->where(['a.is_show' => 1, 'a.is_del' => 0, 'a.is_verify' => 1, 'a.is_police' => 1])->where('a.stock', '<=', $store_stock)->where('a.stock', '>', 0);
//							} else {
							$query->where(['a.is_show' => 1, 'a.is_del' => 0, 'a.is_verify' => 1, 'a.is_police' => 1])->where('a.stock', '>=', 0);
//							}
							break;
						case 6:
							$query->where(['a.is_del' => 1]);
							break;
						case 7://回收站 & 下架商品
							$query->where(function ($q) {
								$q->where(['a.is_del' => 1])->whereOr('a.is_show', 0);
							});
							break;
					};
				})->when(isset($where['pids']) && $where['pids'], function ($query) use ($where) {
					if ((isset($where['priceOrder']) && $where['priceOrder'] != '') || (isset($where['salesOrder']) && $where['salesOrder'] != '')) {
						$query->whereIn('a.pid', $where['pids']);
					} else {
						$query->whereIn('a.pid', $where['pids'])->orderField('pid', $where['pids'], 'asc');
					}
				})->when(isset($where['not_pids']) && $where['not_pids'], function ($query) use ($where, $cate_id) {
					$query->whereNotIn('a.pid', $where['not_pids']);
				})->when(isset($where['store_label_id']) && $where['store_label_id'], function ($query) use ($where) {
					$query->whereIn('a.id', function ($query) use ($where) {
						$query->name('store_product_relation')->where('type', 3)->whereIn('relation_id', $where['store_label_id'])->field('product_id')->select();
					});
				})//销量区间
				->when(isset($where['sales_range']) && $where['sales_range'] !== '', function ($query) use ($where) {
					$this->rangeQuery('a.sales', explode('-', $where['sales_range']), $query);
				})
				//库存区间
				->when(isset($where['stock_range']) && $where['stock_range'] !== '', function ($query) use ($where) {
					$this->rangeQuery('a.stock', explode('-', $where['stock_range']), $query);
				})
				//售价区间
				->when(isset($where['price_range']) && $where['price_range'] !== '', function ($query) use ($where) {
					$this->rangeQuery('a.price', explode('-', $where['price_range']), $query);
				})
				//收藏区间
				->when(isset($where['collect_range']) && $where['collect_range'] !== '', function ($query) use ($where) {
					$this->rangeQuery('a.collect', explode('-', $where['collect_range']), $query);
				})
				//添加时间区间
				->when(isset($where['create_range']) && $where['create_range'] !== '', function ($query) use ($where) {
					$create_range = explode('-', $where['create_range']);
					$start_time = strtotime($create_range[0]);
					$end_time = strtotime($create_range[1]);
					$this->rangeQuery('a.add_time', [$start_time, $end_time], $query);
				})->where('r.type', 1)
				->where('r.relation_id', 'IN', $cate_id)
				->when($page != 0 && $limit != 0, function ($query) use ($page, $limit) {
					$query->page($page, $limit);
				})
				->group('a.id')
				->select()->toArray();
		} else {
			return $this->search($where)->field(['*', '0 as likes'])
				->when(count($with), function ($query) use ($with) {
					$query->with($with);
				})->when($page != 0 && $limit != 0, function ($query) use ($page, $limit) {
					$query->page($page, $limit);
				})->when(isset($where['ids']), function ($query) use ($where) {
					$query->where('id', 'in', $where['ids']);
				})->order(($order ? $order . ' ,' : '') . 'sort desc,id desc')
				->select()->toArray();
		}

    }

    /**
     * 获取门店商品
     * @param $where
     * @return array|\think\Model|null
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getBranchProduct($where)
    {
        return $this->search($where)->find();
    }

    /**
     * 条件获取商品列表
     * @param array $where
     * @param int $page
     * @param int $limit
     * @param array $field
     * @param string $order
     * @param array $with
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getSearchList(array $where, int $page = 0, int $limit = 0, array $field = ['*'], string $order = '', array $with = ['couponId', 'descriptions'])
    {
        return $this->search($where)->with($with)->when($page != 0 && $limit != 0, function ($query) use ($page, $limit) {
            $query->page($page, $limit);
        })->when(isset($where['ids']) && $where['ids'], function ($query) use ($where) {
            if ((isset($where['priceOrder']) && $where['priceOrder'] != '') || (isset($where['salesOrder']) && $where['salesOrder'] != '')) {
                $query->whereIn('id', $where['ids']);
            } else {
                $query->whereIn('id', $where['ids'])->orderField('id', $where['ids'], 'asc')->order('sort desc,id desc');
            }
        })->when(isset($where['is_delivery_type']) && $where['is_delivery_type'], function ($query) use ($where) {
            $query->where(function ($q) {
                $q->whereOr('find_in_set(' . 2 . ',`delivery_type`)')->whereOr('find_in_set(' . 3 . ',`delivery_type`)');
            });
        })->when(isset($where['pids']) && $where['pids'], function ($query) use ($where) {
            if ((isset($where['priceOrder']) && $where['priceOrder'] != '') || (isset($where['salesOrder']) && $where['salesOrder'] != '')) {
                $query->whereIn('pid', $where['pids']);
            } else {
                $query->whereIn('pid', $where['pids'])->orderField('pid', $where['pids'], 'asc')->order('sort desc,id desc');
            }
        })->when(isset($where['not_pids']) && $where['not_pids'], function ($query) use ($where) {
            $query->whereNotIn('pid', $where['not_pids']);
        })->when(isset($where['priceOrder']) && $where['priceOrder'] != '', function ($query) use ($where) {
            if ($where['priceOrder'] === 'desc') {
                $query->order("price desc");
            } else {
                $query->order("price asc");
            }
        })->when(isset($where['salesOrder']) && $where['salesOrder'] != '', function ($query) use ($where) {
            if ($where['salesOrder'] === 'desc') {
                $query->order("sales desc");
            } else {
                $query->order("sales asc");
            }
        })->when(isset($where['defaultOrder']) && in_array($where['defaultOrder'], [0, 1, 2]), function ($query) use ($where) {
            switch ($where['defaultOrder']) {
                case 0://默认排序
                    $query->order('sort desc,id desc');
                    break;
                case 1://好评
                    $query->order('star desc,sort desc');
                    break;
                case 2://新品
                    $query->order('id desc,sort desc');
                    break;
                default:
                    $query->order('sort desc,id desc');
                    break;
            }
        })->when(!isset($where['ids']) || !$where['ids'], function ($query) use ($where, $order) {
            if (isset($where['timeOrder']) && $where['timeOrder'] == 1) {
                $query->order('id desc');
            } else if ($order == 'rand') {
                $query->orderRand();
            } else if ($order) {
                $query->orderRaw($order);
            } else {
                $query->order('sort desc,id desc');
            }
        })->when(isset($where['use_min_price']) && $where['use_min_price'], function ($query) use ($where) {
            if (is_array($where['use_min_price']) && count($where['use_min_price']) == 2) {
                $query->where('price', $where['use_min_price'][0] ?? '=', $where['use_min_price'][1] ?? 0);
            }
        })->when(isset($where['is_stock']) && $where['is_stock'], function ($query) use ($where) {
            $query->where('stock', '>', 0);
        })->when(isset($where['min_price']) && isset($where['max_price']), function ($query) use ($where) {
            $where['min_price'] = $where['min_price'] ?? 0;
            $where['max_price'] = $where['max_price'] ?? 0;
            if ($where['max_price'] > $where['min_price']) {
                $query->where('price', 'between', [$where['min_price'], $where['max_price']]);
            } else if ($where['min_price'] && !$where['max_price']) {
                $query->where('price', '>=', $where['min_price']);
            }
        })->when(!$page && $limit, function ($query) use ($limit) {
            $query->limit($limit);
        })->field($field)->select()->toArray();
    }

    /**商品列表
     * @param array $where
     * @param $limit
     * @param $field
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getProductLimit(array $where, $limit, $field)
    {
        return $this->search($where)->field($field)->order('val', 'desc')->limit($limit)->select()->toArray();

    }

    /**
     * 根据id获取商品数据
     * @param array $ids
     * @param string $field
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function idByProductList(array $ids, string $field)
    {
        return $this->getModel()->whereIn('id', $ids)->field($field)->select()->toArray();
    }

    /**
     * 获取推荐商品
     * @param array $where
     * @param array $field
     * @param int $num
     * @param int $page
     * @param int $limit
     * @param array $with
     * @param string $order
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getRecommendProduct(array $where, array $field = ['*'], int $num = 0, int $page = 0, int $limit = 0, array $with = ['couponId'], string $order = 'sort DESC, id DESC')
    {
        return $this->search($where)->field($field)
            ->when(count($with), function ($query) use ($with) {
                $query->with($with);
            })->when($num, function ($query) use ($num) {
                $query->limit($num);
            })->when($page, function ($query) use ($page, $limit) {
                $query->page($page, $limit);
            })->when($limit, function ($query) use ($limit) {
                $query->limit($limit);
            })->order($order)->select()->toArray();
    }

    /**
     * 获取加入购物车的商品
     * @param array $where
     * @param int $page
     * @param int $limit
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getProductCartList(array $where, int $page, int $limit, array $field = ['*'])
    {
        $where['is_verify'] = 1;
        $where['is_show'] = 1;
        $where['is_del'] = 0;
        return $this->search($where)->when($page, function ($query) use ($page, $limit) {
            $query->page($page, $limit);
        })->field($field)->order('sort DESC,id DESC')->select()->toArray();
    }

    //获取符合条件所有商品
    public function getAllList(array $where,array $field = ['*'])
    {
        $where['is_verify'] = 1;
        $where['is_show'] = 1;
        $where['is_del'] = 0;
        return $this->search($where)->field($field)->order('sort DESC,id DESC')->select()->toArray();
    }
    /**
     * 获取用户购买热销榜单
     * @param array $where
     * @param int $limit
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getUserProductHotSale(array $where, int $limit = 20)
    {
        return $this->search($where)->field(['IFNULL(sales,0) + IFNULL(ficti,0) as sales', 'store_name', 'image', 'id', 'price', 'ot_price', 'stock'])->limit($limit)->order('sales desc')->select()->toArray();
    }

    /**
     * 通过商品id获取商品分类
     * @param array $productIds
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function productIdByCateId(array $productIds)
    {
        return $this->search(['id' => $productIds])->with('cateName')->field('id')->select()->toArray();
    }

    /**
     * @param array $where
     * @param $field
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getProductListByWhere(array $where, $field)
    {
        return $this->search($where)->field($field)->select()->toArray();
    }

    /**
     * 搜索条件获取字段column
     * @param array $where
     * @param string $field
     * @param string $key
     * @return array
     */
    public function getColumnList(array $where, string $field = 'brand_id', string $key = 'id')
    {
        return $this->search($where)
            ->when(isset($where['sid']) && $where['sid'], function ($query) use ($where) {
                $query->whereIn('id', function ($query) use ($where) {
                    $query->name('store_product_relation')->where('type', 1)->where('relation_id', $where['sid'])->field('product_id')->select();
                });
            })->when(isset($where['cid']) && $where['cid'], function ($query) use ($where) {
                $query->whereIn('id', function ($query) use ($where) {
                    $query->name('store_product_relation')->where('type', 1)->whereIn('relation_id', function ($query) use ($where) {
                        $query->name('store_product_category')->where('id|pid', $where['cid'])->field('id')->select();
                    })->field('product_id')->select();
                });
            })->when(isset($where['ids']) && $where['ids'], function ($query) use ($where) {
                $query->whereIn('id', $where['ids']);
            })->field($field)->column($field, $key);
    }

    /**
     * 自动上下架
     * @param int $is_show
     * @return \mohe\basic\BaseModel
     */
    public function overUpperShelves($is_show = 0)
    {
        return $this->getModel()->where(['is_del' => 0])->where('is_verify', 1)
            ->when(in_array($is_show, [0, 1]), function ($query) use ($is_show) {
                if ($is_show == 1) {
                    $query->where('is_show', 0)->where('auto_on_time', '<>', 0)->where('auto_on_time', '<=', time());
                } else {
                    $query->where('is_show', 1)->where('auto_off_time', '<>', 0)->where('auto_off_time', '<', time());
                }
            })->update(['is_show' => $is_show]);
    }

    /**
     * 预约商品:超出可售日期自动下架
     * @param $is_show
     * @return \mohe\basic\BaseModel
     */
    public function unShowReservationProduct($is_show = 0)
    {
        return $this->getModel()->where('product_type', 6)->where('is_del', 0)->where('is_verify', 1)->where('sale_time_type', 3)->where('sale_time_end', '<', time())->update(['is_show' => $is_show]);
    }

    /**
     * 次卡、卡项商品固定核销时间超时后自动下架
     * @return mixed
     */
    public function unShowTimeoutProduct()
    {
        return $this->getModel()->alias('p')->whereIn('p.product_type', [4, 5])->where('p.is_del', 0)->where('p.is_verify', 1)
            ->join('store_product_attr_value v', 'p.id = v.product_id')
            ->where('v.write_valid', 3)->where('write_end', '<', time())
            ->update(['p.is_show' => 0]);
    }


    /**
     * 获取预售结束商品
     * @param string $field
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getEndPresaleProduct(string $field = 'id,is_show,presale_status')
    {
        return $this->getModel()->field($field)
            ->where(['is_del' => 0])
            ->where('is_verify', 1)
            ->where('is_presale_product', 1)
            ->where('is_show', 1)
            ->where('presale_end_time', '<', time())
            ->select()->toArray();
    }

    /**
     * 获取预售列表
     * @param array $where
     * @param int $page
     * @param int $limit
     * @param string $field
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getPresaleList(array $where, int $page, int $limit, string $field = '*')
    {
        $model = $this->getModel()->field($field)
            ->where('is_presale_product', 1)->where('is_del', 0)->where('is_show', 1)
            ->where(function ($query) use ($where) {
                switch ($where['time_type']) {
                    case 1:
                        $query->where('presale_start_time', '>', time());
                        break;
                    case 2:
                        $query->where('presale_start_time', '<=', time())->where('presale_end_time', '>=', time());
                        break;
                    case 3:
                        $query->where('presale_end_time', '<', time());
                        break;
                }
                if ($where['type']) $query->whereIn('type', $where['type']);
            })->when(isset($where['applicable_store_id']) && $where['applicable_store_id'], function ($query) use ($where) {
                $query->where(function ($q) use ($where) {
                    $q->where('applicable_type', 1)->whereOr(function ($qe) use ($where) {
                        $qe->where('applicable_type', 2)->whereFindinSet('applicable_store_id', $where['applicable_store_id']);
                    });
                });
            });
        $count = $model->count();
        $list = $model->when($page && $limit, function ($query) use ($page, $limit) {
            $query->page($page, $limit);
        })->when(!$page && $limit, function ($query) use ($limit) {
            $query->limit($limit);
        })->order('add_time desc')->select()->toArray();
        return compact('list', 'count');
    }

    /**
     * 获取使用某服务保障商品数量
     * @param int $ensure_id
     * @return int
     */
    public function getUseEnsureCount(int $ensure_id)
    {
        return $this->getModel()->whereFindInSet('ensure_id', $ensure_id)->count();
    }

    /**
     * 保存数据
     * @param array $data
     * @return mixed|\think\Collection
     * @throws \Exception
     */
    public function saveAll(array $data)
    {
        return $this->getModel()->saveAll($data);
    }

    /**
     * 同步商品保存获取id
     * @param $data
     * @return int|string
     */
    public function ErpProductSave($data)
    {
        return $this->getModel()->insertGetId($data);
    }
}
