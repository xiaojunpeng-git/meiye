<template>
<!-- 商品-商品分类 -->
    <div class="article-manager">
        <Card :bordered="false" dis-hover class="ivu-mt" :padding="0">
            <div class="new_card_pd">
                <!-- 筛选条件 -->
                <Form ref="artFrom" :model="artFrom"  :label-width="labelWidth"
                inline
                 :label-position="labelPosition" @submit.native.prevent>
                    <FormItem label="商品分类：" prop="pid" label-for="pid">
                        <Select v-model="artFrom.id" @on-change="userSearchs" clearable class="input-add">
                            <Option v-for="item in treeSelect" :value="item.id" :key="item.id">{{ item.cate_name }}</Option>
                        </Select>
                    </FormItem>
                    <FormItem label="状态："  label-for="is_show">
                        <Select v-model="artFrom.is_show" placeholder="请选择" clearable  @on-change="userSearchs"  class="input-add">
                            <Option value="1">显示</Option>
                            <Option value="0">隐藏</Option>
                        </Select>
                    </FormItem>
                    <FormItem label="分类名称："  label-for="status2">
                        <Input  placeholder="请输入" v-model="artFrom.cate_name"  class="input-add" />
                        <Button class="ml-14" type="primary" @click="userSearchs()">查询</Button>
                        <Button class="ml-14" @click="reset">重置</Button>
                    </FormItem>
                </Form>
            </div>
        </Card>
        <Card :bordered="false" dis-hover class="ivu-mt">
            <!-- 相关操作 -->
            <div>
                <Button v-auth="['product-save-cate']" type="primary" class="bnt"  @click="addClass">添加分类</Button>
            </div>
            <!-- 商品分类表格 -->
            <vxe-table
                :data="tableData"
                ref="xTable"
                class="ivu-mt"
                highlight-hover-row
                :loading="loading"
                header-row-class-name="false"
                row-id="id"
                :tree-config="{lazy: true, children: 'children',hasChild: 'children',loadMethod: loadChildrenMethod,reserve: true}"
                >
                <vxe-table-column field="id" title="ID"  tooltip width="80"></vxe-table-column>
                <vxe-table-column field="cate_name" tree-node title="分类名称"  min-width="250" ></vxe-table-column>
                <vxe-table-column field="pic" title="分类图标" min-width="100">
                    <template v-slot="{ row }">
                        <viewer>
                            <div class="tabBox_img">
                                <img v-lazy="row.pic">
                            </div>
                        </viewer>
                    </template>
                </vxe-table-column>
                <vxe-table-column field="sort" title="排序" min-width="100" tooltip="true"></vxe-table-column>
                <vxe-table-column field="is_show" title="状态" min-width="120">
                    <template v-slot="{ row }">
                        <i-switch v-model="row.is_show" :value="row.is_show" :true-value="1" :false-value="0" @on-change="onchangeIsShow(row)" size="large">
                            <span slot="open">显示</span>
                            <span slot="close">隐藏</span>
                        </i-switch>
                    </template>
                </vxe-table-column>
                <vxe-table-column field="mobile_card_show" title="手机端卡包分类" min-width="160">
                    <template v-slot="{ row }">
                        <i-switch v-model="row.mobile_card_show" :value="row.mobile_card_show" :true-value="1" :false-value="0" @on-change="onchangeMobileCardShow(row)" size="large">
                            <span slot="open">显示</span>
                            <span slot="close">隐藏</span>
                        </i-switch>
                    </template>
                </vxe-table-column>
                <vxe-table-column field="partner_enabled" title="合作方配置" min-width="170">
                    <template v-slot="{ row }">
                        <div class="partner-config-cell">
                            <i-switch
                                v-model="row.partner_enabled"
                                :true-value="1"
                                :false-value="0"
                                :disabled="Number(row.is_show) !== 1 || row.partnerSaving"
                                @on-change="savePartnerConfig(row, $event)"
                                size="large"
                            >
                                <span slot="open">合作方</span>
                                <span slot="close">非合作方</span>
                            </i-switch>
                        </div>
                    </template>
                </vxe-table-column>
                <vxe-table-column field="date" title="操作" width="250" align="left">
                    <template v-slot="{ row, index }">
                        <a @click="edit(row)">编辑</a>
                        <Divider type="vertical"/>
                        <a @click="del(row,'删除商品分类',index)">删除</a>
                    </template>
                </vxe-table-column>
            </vxe-table>
        </Card>
        <!-- 添加 编辑表单-->
        <edit-from ref="edits" :FromData="FromData" @submitFail="userSearchs"></edit-from>
    </div>
</template>

<script>
    import { mapState } from 'vuex';
    import { productListApi, productCreateApi, productEditApi, setShowApi, setMobileCardShowApi, treeListApi } from '@/api/product';
    import { reportOperationCategories, saveReportOperationCategory } from '@/api/report';
    import editFrom from '../../../components/from/from';
    export default {
        name: 'product_productClassify',
        components: {
            editFrom
        },
        data () {
            return {
                treeSelect: [],
                FromData: null,
                grid: {
                    xl: 7,
                    lg: 7,
                    md: 12,
                    sm: 24,
                    xs: 24
                },
                loading: false,
                artFrom: {
                    pid: 0,
                    is_show: '',
                    page: 1,
                    cate_name: '',
                    limit: 15,
                    id:0
                },
                total: 0,
                tableData: [],
                partnerConfigs: {}
            }
        },
        computed: {
            ...mapState('admin/layout', [
                'isMobile'
            ]),
            ...mapState('admin/userLevel', [
                'categoryId'
            ]),
            labelWidth () {
                return this.isMobile ? undefined : 96;
            },
            labelPosition () {
                return this.isMobile ? 'top' : 'right';
            }
        },
        mounted () {
            this.loadPartnerConfigs();
            this.goodsCategory();
            this.getList();
        },
        methods: {
            loadPartnerConfigs () {
                reportOperationCategories().then((res) => {
                    const configs = {};
                    (res.data || []).forEach((item) => {
                        configs[String(item.category_id)] = item;
                    });
                    this.partnerConfigs = configs;
                    this.tableData = this.tableData.map((row) => this.normalizeCategoryRow(row));
                }).catch((res) => {
                    this.$Message.error(res.msg || '读取合作方配置失败');
                });
            },
            // 商品分类；
            goodsCategory () {
                treeListApi(0).then(res => {
                    this.treeSelect = res.data;
                }).catch(res => {
                    this.$Message.error(res.msg);
                })
            },
            // 列表
            getList () {
                this.loading = true;
                this.artFrom.is_show = this.artFrom.is_show || '';
                productListApi(this.artFrom).then(async res => {
                    let data = res.data
                    this.tableData = (data.list || []).map(item => this.normalizeCategoryRow(item));
                    this.total = data.count;
                    this.loading = false;
                }).catch(res => {
                    this.loading = false;
                    this.$Message.error(res.msg);
                })
            },
            normalizeCategoryRow (row) {
                const config = this.partnerConfigs[String(row.id)] || {};
                const hasPartnerConfig = Object.prototype.hasOwnProperty.call(config, 'enabled');
                return {
                    ...row,
                    mobile_card_show: row.mobile_card_show === undefined || row.mobile_card_show === null ? 1 : row.mobile_card_show,
                    partner_label: row.partner_label !== undefined ? row.partner_label : (config.partner_label || config.category_path_snapshot || ''),
                    partner_enabled: hasPartnerConfig ? Number(config.enabled) : (row.partner_enabled !== undefined ? Number(row.partner_enabled) : 0),
                    partner_default_ratio: hasPartnerConfig
                        ? Number(config.partner_default_ratio !== undefined ? config.partner_default_ratio : (config.partnerDefaultRatio !== undefined ? config.partnerDefaultRatio : 0))
                        : Number(row.partner_default_ratio || 0),
                    partner_version: hasPartnerConfig ? Number(config.version || 0) : Number(row.partner_version || 0),
                    partnerSaving: false
                };
            },
            savePartnerConfig (row, nextEnabled) {
                if (nextEnabled !== undefined) {
                    row.partner_enabled = Number(nextEnabled) === 1 ? 1 : 0;
                }
                if (Number(row.is_show) !== 1) {
                    this.$Message.warning('停用分类不能配置合作方');
                    return;
                }
                const previousEnabled = Number(row.partner_enabled) === 1 ? 1 : 0;
                const previousRatio = Number(row.partner_default_ratio || 0);
                row.partnerSaving = true;
                const idempotencyKey = (typeof crypto !== 'undefined' && crypto.randomUUID)
                    ? crypto.randomUUID()
                    : `category_partner_${row.id}_${Date.now()}_${Math.random().toString(36).slice(2, 8)}`;
                saveReportOperationCategory({
                    category_id: String(row.id),
                    enabled: Number(row.partner_enabled) === 1 ? 1 : 0,
                    partner_default_ratio: Math.max(0, Math.min(100, Math.trunc(Number(row.partner_default_ratio || 0)))),
                    expected_version: Number(row.partner_version || 0),
                    idempotency_key: idempotencyKey,
                }).then((res) => {
                    const saved = res.data || {};
                    row.partner_enabled = Number(saved.enabled || saved.partner_enabled || 0);
                    row.partner_default_ratio = Number(saved.partner_default_ratio !== undefined ? saved.partner_default_ratio : (saved.partnerDefaultRatio !== undefined ? saved.partnerDefaultRatio : 0));
                    row.partner_label = saved.partner_label || row.partner_label || '';
                    row.partner_version = Number(saved.version || 0);
                    this.$set(this.partnerConfigs, String(row.id), saved);
                    this.$Message.success('合作方配置已保存');
                }).catch((res) => {
                    row.partner_enabled = previousEnabled;
                    row.partner_default_ratio = previousRatio;
                    this.$Message.error(res.msg || '合作方配置保存失败');
                }).finally(() => {
                    row.partnerSaving = false;
                });
            },
            loadChildrenMethod ({row}){
                return new Promise((resolve, reject) => {
                    productListApi({pid:row.id}).then(res=>{
                        let arr = (res.data.list || []).map(item => this.normalizeCategoryRow(item));
                        resolve(arr);
                    })
                })
            },
            pageChange (index) {
                this.artFrom.page = index;
                this.getList();
            },
            // 添加
            addClass () {
                this.$modalForm(productCreateApi()).then(() => {
                    this.artFrom.pid = 0;
                    this.getList();
                    this.goodsCategory();
                }
                );
            },
            // 编辑
            edit (row) {
                this.$modalForm(productEditApi(row.id).then((response) => {
                    // 合作方配置属于分类编辑的一部分；列表只负责快速开关，不再显示比例输入。
                    // 仅对启用分类注入编辑字段，停用分类仍只能通过列表恢复后配置。
                    if (Number(row.is_show) === 1 && response && response.data && Array.isArray(response.data.rules)) {
                        response.data.rules.push({
                            type: 'switch',
                            field: 'partner_enabled',
                            title: '合作方：',
                            value: Number(row.partner_enabled) === 1 ? 1 : 0,
                            props: {
                                trueValue: 1,
                                falseValue: 0,
                                size: 'large',
                                open: '开启',
                                close: '关闭'
                            }
                        });
                        response.data.rules.push({
                            type: 'inputNumber',
                            field: 'partner_default_ratio',
                            title: '合作方默认比例：',
                            value: Number(row.partner_default_ratio || 0),
                            props: {
                                min: 0,
                                max: 100,
                                precision: 0,
                                step: 1
                            }
                        });
                    }
                    return response;
                })).then(() => {
                    this.artFrom.pid = 0;
                    this.getList();
                    this.goodsCategory();
				});
            },
						findSupId(data,supplierId,res){
							var fun = function (i,n) {
								if(i && i.length > 0){
									for(let v in i){
										if(i[v].id==n){
											 i[v].cate_name = res.data.cate_name
											 i[v].is_show = res.data.is_show
											 i[v].sort = res.data.sort
											 i[v].pic = res.data.pic
											 return
										}else{
											if(i[v].children && i[v].children.length > 0){
												fun(i[v].children, n)
											}
										}
									}
								}
							}
							fun(data,supplierId);
						},
						
            // 修改状态
            onchangeIsShow (row) {
                let data = {
                    id: row.id,
                    is_show: row.is_show
                }
                setShowApi(data).then(async res => {
                    this.$Message.success(res.msg);
                    this.artFrom.pid = 0;
                    this.getList()
                }).catch(res => {
                    this.$Message.error(res.msg);
                })
            },
            onchangeMobileCardShow (row) {
                const data = {
                    id: row.id,
                    mobile_card_show: row.mobile_card_show
                };
                setMobileCardShowApi(data).then((res) => {
                    this.$Message.success(res.msg);
                }).catch((res) => {
                    this.$Message.error(res.msg);
                    row.mobile_card_show = row.mobile_card_show ? 0 : 1;
                });
            },
            // 下拉树
            handleCheckChange (data) {
                let value = ''
                let title = ''
                this.list = []
                this.artFrom.pid = 0;
                data.forEach((item, index) => {
                    value += `${item.id},`
                    title += `${item.title},`
                });
                value = value.substring(0, value.length - 1)
                title = title.substring(0, title.length - 1)
                this.list.push({
                    value,
                    title
                });
                this.artFrom.pid = value;
                this.getList();
            },
            // 删除
            del (row, tit, num) {
                let delfromData = {
                    title: tit,
                    num: num,
                    url: `product/category/${row.id}`,
                    method: 'DELETE',
                    ids: ''
                };
                this.$modalSure(delfromData).then((res) => {
                    this.$Message.success(res.msg);
                    this.artFrom.pid = 0;
                    this.getList();
                    this.goodsCategory();
                }).catch(res => {
                    this.$Message.error(res.msg);
                });
            },
            // 表格搜索
            userSearchs () {
                this.artFrom.pid = 0;
                this.artFrom.page = 1;
                this.getList();
            },
            // 重置 
            reset () {
                this.artFrom = {
                    pid: 0,
                    is_show: '',
                    page: 1,
                    cate_name: '',
                    limit: 15,
                    id:0
                };
                this.getList();
            }
        }
    }
</script>
<style scoped lang="stylus">
::v-deep .vxe-cell--title {
  font-size: 12px
}
    .treeSel >>>.ivu-select-dropdown-list
        padding 0 10px!important
        box-sizing border-box
    .tabBox_img
        width 36px
        height 36px
        border-radius:4px
        cursor pointer
        img
            width 100%
            height 100%
    /deep/.ivu-input
        font-size 14px !important
</style>
