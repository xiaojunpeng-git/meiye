<template>
  <!-- 商品-商品列表 -->
  <div class="article-manager">
    <Card :bordered="false" dis-hover class="ivu-mt" :padding="0">
      <div class="new_card_pd">
        <Alert
          v-if="alertShow"
          banner
          closable
          type="warning"
          class="ivu-mt"
          @on-close="closeAlert"
        >
          <router-link :to="`${roterPre}/product/add_product/0`"
            >您有未完成的商品添加操作，点击此处可继续添加操作？</router-link
          >
        </Alert>
        <Alert show-icon closable>
          温馨提示：1.新增商品时可选统一规格或者多规格，满足商品不同销售属性场景；2.商品销售状态分为销售中且库存足够时才可下单购买
        </Alert>
        <!-- 条件筛选 -->
        <Row :gutter="24">
          <Col span="18">
            <Form
              ref="artFrom"
              inline
              :model="artFrom"
              :label-width="96"
              :label-position="labelPosition"
              @submit.native.prevent
            >
              <FormItem label="商品搜索：" label-for="store_name">
                <Input
                  v-model="artFrom.store_name"
                  placeholder="请输入"
                  element-id="name"
                  clearable
                  class="input-add"
                  maxlength="20"
                >
                  <Select
                    v-model="artFrom.field_key"
                    slot="prepend"
                    style="width: 80px"
                    default-label="全部"
                  >
                    <Option value="all">全部</Option>
                    <Option value="product_id">商品ID</Option>
                    <Option value="store_name">商品名称</Option>
                    <Option value="keyword">关键字</Option>
                    <Option value="code">商品编码</Option>
                    <Option value="bar_code">商品条形码</Option>
                  </Select>
                </Input>
              </FormItem>
              <FormItem
                label="商品类型："
                prop="product_type"
                label-for="product_type"
              >
                <Select
                  v-model="artFrom.product_type"
                  @on-change="userSearchs"
                  clearable
                  class="input-add"
                >
                  <Option
                    v-for="item in productType"
                    :value="item.id"
                    :key="item.id"
                    >{{ item.name }}</Option
                  >
                </Select>
              </FormItem>
              <div v-show="collapse">
                <FormItem label="商品分类：" prop="cate_id">
                  <el-cascader
                    placeholder="请选择商品分类"
                    class="input-add"
                    size="mini"
                    v-model="artFrom.cate_id"
                    :options="data1"
                    :props="props"
                    @change="userSearchs"
                    filterable
                    clearable
                  >
                  </el-cascader>
                </FormItem>
                <FormItem label="商品品牌：" prop="brand_id">
                  <Cascader
                    :data="brandData"
                    placeholder="请选择商品品牌"
                    change-on-select
                    v-model="artFrom.brand_id"
                    filterable
                    class="input-add"
                    @on-change="userSearchs"
                  ></Cascader>
                </FormItem>
                <FormItem label="配送方式：" prop="delivery_type">
                  <Select
                    v-model="artFrom.delivery_type"
                    class="input-add"
                    clearable
                    @on-change="userSearchs"
                  >
                    <Option value="1">快递</Option>
                    <Option value="2">到店核销</Option>
                    <Option value="3">门店配送</Option>
                  </Select>
                </FormItem>
                <FormItem label="商品规格：" prop="spec_type">
                  <Select
                    v-model="artFrom.spec_type"
                    class="input-add"
                    clearable
                    @on-change="userSearchs"
                  >
                    <Option value="0">单规格</Option>
                    <Option value="1">多规格</Option>
                  </Select>
                </FormItem>
                <FormItem
                  label="商品标签："
                  prop="store_label_id"
                  class="labelClass"
                >
                  <div class="acea-row row-middle mr14">
                    <div
                      class="labelInput acea-row row-between-wrapper"
                      @click="openGoodsLabel"
                    >
                      <div style="width: 90%">
                        <div v-if="goodsDataLabel.length">
                          <Tag
                            closable
                            v-for="(item, index) in goodsDataLabel"
                            :key="index"
                            @on-close="closeStoreLabel(item)"
                            >{{ item.label_name }}</Tag
                          >
                        </div>
                        <span class="span" v-else>选择商品标签</span>
                      </div>
                      <div class="iconfont iconxiayi"></div>
                    </div>
                  </div>
                </FormItem>
                <FormItem label="付费会员：" prop="is_vip">
                  <Select
                    v-model="artFrom.is_vip"
                    class="input-add"
                    clearable
                    @on-change="userSearchs"
                  >
                    <Option value="0">未开启</Option>
                    <Option value="1">开启</Option>
                  </Select>
                </FormItem>
                <FormItem label="参与库存：">
                  <Select
                    v-model="artFrom.is_inventory"
                    clearable
                    class="input-add"
                    @on-change="userSearchs"
                  >
                    <Option :value="1">开启</Option>
                    <Option :value="0">关闭</Option>
                  </Select>
                </FormItem>
                <FormItem label="允许负库存：">
                  <Select
                    v-model="artFrom.allow_negative_stock"
                    clearable
                    class="input-add"
                    @on-change="userSearchs"
                  >
                    <Option :value="1">开启</Option>
                    <Option :value="0">关闭</Option>
                  </Select>
                </FormItem>
                <FormItem label="选择门店：">
                  <Select
                    v-model="artFrom.store_id"
                    clearable
                    filterable
                    @on-change="userSearchs"
                    class="input-add"
                  >
                    <Option
                      v-for="item in staffData"
                      :value="item.id"
                      :key="item.id"
                      >{{ item.name }}</Option
                    >
                  </Select>
                </FormItem>
                <FormItem
                  label="供应商："
                  prop="pid"
                  label-for="pid"
                  v-if="!isAgentAdmin"
                >
                  <Select
                    v-model="artFrom.supplier_id"
                    @on-change="userSearchs"
                    clearable
                    class="input-add"
                  >
                    <Option
                      v-for="item in supplierList"
                      :value="item.id"
                      :key="item.id"
                      >{{ item.supplier_name }}</Option
                    >
                  </Select>
                </FormItem>
                <FormItem label="创建时间：">
                  <DatePicker
                    :editable="false"
                    :clearable="true"
                    @on-change="onchangeTime"
                    :value="timeVal"
                    format="yyyy/MM/dd HH:mm:ss"
                    type="datetimerange"
                    placement="bottom-start"
                    placeholder="自定义时间"
                    class="input-add"
                    :options="options"
                  ></DatePicker>
                </FormItem>
                <FormItem label="库存：">
                  <InputNumber
                    class="w-118 fs-12"
                    placeholder="开始"
                    :max="9999999999"
                    :min="0"
                    :precision="0"
                    v-model="stockStart"
                  />
                  ~
                  <InputNumber
                    class="w-118 fs-12 mr14"
                    placeholder="结尾"
                    :max="9999999999"
                    :min="0"
                    :precision="0"
                    v-model="stockEnd"
                  />
                </FormItem>
                <FormItem label="销量：">
                  <InputNumber
                    class="w-118 fs-12"
                    placeholder="开始"
                    :max="9999999999"
                    :min="0"
                    :precision="0"
                    v-model="salesStart"
                  />
                  ~
                  <InputNumber
                    class="w-118 fs-12 mr14"
                    placeholder="结尾"
                    :max="9999999999"
                    :min="0"
                    :precision="0"
                    v-model="salesEnd"
                  />
                </FormItem>
                <FormItem label="售价：">
                  <InputNumber
                    class="w-118 fs-12"
                    placeholder="开始"
                    :max="9999999999"
                    :min="0"
                    v-model="priceStart"
                  />
                  ~
                  <InputNumber
                    class="w-118 fs-12 mr14"
                    placeholder="结尾"
                    :max="9999999999"
                    :min="0"
                    v-model="priceEnd"
                  />
                </FormItem>
                <FormItem label="收藏数：">
                  <InputNumber
                    class="w-118 fs-12"
                    placeholder="开始"
                    :max="9999999999"
                    :min="0"
                    :precision="0"
                    v-model="collectStart"
                  />
                  ~
                  <InputNumber
                    class="w-118 fs-12 mr14"
                    placeholder="结尾"
                    :max="9999999999"
                    :min="0"
                    :precision="0"
                    v-model="collectEnd"
                  />
                </FormItem>
              </div>
            </Form>
          </Col>
          <Col span="6">
            <div class="flex items-end justify-end h-full pb-20">
              <div>
                <Button type="primary" @click="userSearchs">查询</Button>
                <Button @click="reset" class="ml10">重置</Button>
                <a v-font="14" class="ivu-ml-8" @click="collapse = !collapse">
                  <template v-if="!collapse">
                    展开 <Icon type="ios-arrow-down" />
                  </template>
                  <template v-else>
                    收起 <Icon type="ios-arrow-up" />
                  </template>
                </a>
              </div>
            </div>
          </Col>
        </Row>
      </div>
    </Card>
    <Card :bordered="false" dis-hover class="ivu-mt">
      <!-- 相关操作 -->
      <div class="new_tab">
        <Tabs v-model="artFrom.type" @on-click="onClickTab">
          <TabPane
            :label="item.name + ' (' + item.count + ')'"
            :name="item.type.toString()"
            v-for="(item, index) in headeNum"
            :key="index"
          />
        </Tabs>
      </div>
      <div class="acea-row row-between-wrapper">
        <div class="Button">
          <Button
            type="primary"
            class="bnt mr15"
            v-auth="['product-product-save']"
            @click="addTypeShow = true"
            >添加商品</Button
          >
          <Button
            v-auth="['product-product-collect']"
            type="success"
            class="bnt mr15"
            @click="onCopy"
            >商品采集</Button
          >
          <Upload
            ref="upload"
            v-if="openErp"
            :show-upload-list="false"
            :action="erpUrl"
            :before-upload="beforeUpload"
            :headers="header"
            :on-success="upFile"
            :format="['xlsx']"
            :on-format-error="handleFormatError"
          >
            <Button>导入ERP商品</Button>
          </Upload>
          <!-- <Tooltip
              content="本页至少选中一项"
              :disabled="!!checkUidList.length && isAll==0"
          >
            <Button
                v-auth="['product-product-batch_process']"
                class="bnt mr15"
                :disabled="!checkUidList.length && isAll==0"
                @click="onDismount"
                v-show="artFrom.type === '1'"
            >批量下架</Button
            >
          </Tooltip> -->
          <Dropdown
            v-auth="['product-product-batch_operate']"
            v-show="batchOptions.length"
            class="mr15"
            @on-click="batchHandler"
          >
            <Button
              type="primary"
              :disabled="!checkUidList.length && isAll == 0"
            >
              批量操作 <Icon type="ios-arrow-down" />
            </Button>
            <DropdownMenu slot="list">
              <DropdownItem
                v-auth="'[' + item.auth + ']'"
                v-for="item in batchOptions"
                :key="item.key"
                :name="item.key"
                :disabled="!checkUidList.length && isAll == 0"
                >{{ item.label }}</DropdownItem
              >
            </DropdownMenu>
          </Dropdown>
          <!-- <Tooltip
              content="本页至少选中一项"
              :disabled="!!checkUidList.length && isAll==0"
          >
            <Button
                v-auth="['product-product-batch_process']"
                class="bnt mr15"
                :disabled="!checkUidList.length && isAll==0"
                @click="onShelves"
                v-show="artFrom.type === '2'"
            >批量上架</Button
            >
          </Tooltip> -->
          <Tooltip
            content="本页至少选中一项"
            :disabled="!!checkUidList.length && isAll == 0"
            v-auth="['product-product-batch_process']"
          >
            <Button
              class="bnt mr15"
              :disabled="!checkUidList.length && isAll == 0"
              @click="openBatch"
              >批量设置</Button
            >
          </Tooltip>
          <Dropdown
            v-auth="['product-product-import', 'product-product-export']"
            class="mr15"
            @on-click="goodsMove"
            v-show="!isSupplier"
          >
            <Button>商品迁移 <Icon type="ios-arrow-down" /> </Button>
            <template #list>
              <DropdownMenu>
                <DropdownItem :name="1" v-auth="['product-product-import']"
                  >商品导入</DropdownItem
                >
                <DropdownItem :name="2" v-auth="['product-product-export']"
                  >商品导出</DropdownItem
                >
              </DropdownMenu>
            </template>
          </Dropdown>
          <Tooltip
            content="本页至少选中一项"
            :disabled="!!checkUidList.length && isAll == 0"
            v-auth="['product-product-data_export']"
          >
            <Button
              class="export mr15"
              :disabled="!checkUidList.length && isAll == 0"
              @click="exports()"
              >运营数据导出</Button
            >
          </Tooltip>
        </div>
        <div>
          <Button v-if="openErp" class="bnt mr15" @click="frontDownload"
            >下载erp商品模板</Button
          >
          <router-link
            :to="`${roterPre}/system/import/record/goods`"
            v-auth="['product-product-view_import']"
            >查看导入记录</router-link
          >
        </div>
      </div>
      <!-- 商品列表表格 -->
      <vxe-table
        ref="xTable"
        class="mt25"
        :loading="loading"
        row-id="id"
        :expand-config="{ accordion: true }"
        :checkbox-config="{ reserve: true }"
        @checkbox-all="checkboxAll"
        @checkbox-change="checkboxItem"
        :data="tableList"
      >
        <vxe-column
          type=""
          width="0"
          v-show="artFrom.type == 1 || artFrom.type == 2"
        ></vxe-column>
        <vxe-column
          type="expand"
          width="35"
          v-if="artFrom.type == 1 || artFrom.type == 2"
        >
          <template #content="{ row }">
            <div class="tdinfo">
              <Row class="expand-row">
                <Col span="6">
                  <span class="expand-key">商品分类：</span>
                  <span class="expand-value">{{ row.cate_name }}</span>
                </Col>
                <Col span="6">
                  <span class="expand-key">商品市场价格：</span>
                  <span class="expand-value">{{ row.ot_price }}</span>
                </Col>
                <Col span="6">
                  <span class="expand-key">成本价：</span>
                  <span class="expand-value">{{ row.cost }}</span>
                </Col>
                <Col span="6">
                  <span class="expand-key">收藏：</span>
                  <span class="expand-value">{{ row.collect }}</span>
                </Col>
              </Row>
              <Row class="expand-row">
                <Col span="6">
                  <span class="expand-key">虚拟销量：</span>
                  <span class="expand-value">{{ row.ficti }} {{ name }}</span>
                </Col>
                <Col span="6">
                  <span class="expand-key">付费会员：</span>
                  <span class="expand-value">{{
                    row.is_vip ? "开启" : "关闭"
                  }}</span>
                </Col>
                <Col span="6">
                  <span class="expand-key">创建时间：</span>
                  <span class="expand-value">{{
                    row.add_time | timeFormat
                  }}</span>
                </Col>
                <Col span="6">
                  <span class="expand-key">商品品牌：</span>
                  <span class="expand-value">{{
                    brandCom(row.brand_com)
                  }}</span>
                </Col>
              </Row>
              <Row class="expand-row">
                <Col span="6">
                  <span class="expand-key">商品标签：</span>
                  <span class="expand-value">{{
                    storeLabel(row.store_label_id)
                  }}</span>
                </Col>
              </Row>
            </div>
          </template>
        </vxe-column>
        <vxe-column type="checkbox" width="100">
          <template #header>
            <div>
              <Dropdown transfer @on-click="allPages">
                <a href="javascript:void(0)" class="acea-row row-middle">
                  <span
                    >全选({{
                      isAll == 1
                        ? total - checkUidList.length
                        : checkUidList.length
                    }})</span
                  >
                  <Icon type="ios-arrow-down"></Icon>
                </a>
                <template #list>
                  <DropdownMenu>
                    <DropdownItem name="0">当前页</DropdownItem>
                    <DropdownItem name="1">所有页</DropdownItem>
                  </DropdownMenu>
                </template>
              </Dropdown>
            </div>
          </template>
        </vxe-column>
        <vxe-column field="id" title="商品ID" width="60"></vxe-column>
        <vxe-column field="image" title="商品图" width="60">
          <template v-slot="{ row }">
            <viewer>
              <div class="tabBox_img">
                <img v-lazy="row.image" />
              </div>
            </viewer>
          </template>
        </vxe-column>
        <vxe-column field="store_name" title="商品名称" min-width="250">
          <template v-slot="{ row }">
            <Tooltip
              :transfer="true"
              theme="dark"
              max-width="300"
              :delay="600"
              :content="row.store_name"
            >
              <div class="line2">
                <span class="text-wlll-2d8cf0"
                  >【{{ row.spec_type ? "多规格" : "单规格" }}】</span
                >{{ row.store_name }}
              </div>
            </Tooltip>
          </template>
        </vxe-column>
        <vxe-column
          field="plate_name"
          title="商品来源"
          min-width="150"
        ></vxe-column>
        <vxe-column field="product_type" title="商品类型" min-width="100">
          <template v-slot="{ row }">
            <span
              v-if="row.product_type == item.id"
              v-for="(item, index) in productType"
              :key="index"
              >{{ item.name }}</span
            >
          </template>
        </vxe-column>
        <vxe-column field="price" title="商品售价" min-width="90"></vxe-column>
        <vxe-column field="sales" title="销量" min-width="90"></vxe-column>
        <vxe-column
          field="stock"
          title="库存"
          min-width="80"
          v-show="artFrom.type != 6"
        ></vxe-column>
        <vxe-column field="is_inventory" title="参与库存管理" min-width="110">
          <template v-slot="{ row }">
            <span v-if="row.product_type != 0">—</span>
            <span v-else>{{ row.is_inventory == 1 ? '开启' : '关闭' }}</span>
          </template>
        </vxe-column>
        <vxe-column field="allow_negative_stock" title="允许负库存" min-width="100">
          <template v-slot="{ row }">
            <span v-if="row.product_type != 0">—</span>
            <span v-else>{{ row.allow_negative_stock == 1 ? '开启' : '关闭' }}</span>
          </template>
        </vxe-column>
        <vxe-column field="sort" title="排序" min-width="70"></vxe-column>
        <vxe-column field="state" title="状态" width="120">
          <template v-slot="{ row }">
            <i-switch
              v-model="row.is_show"
              :value="row.is_show"
              :true-value="1"
              :false-value="0"
              :disabled="
                artFrom.type == 6 || row.is_verify != 1 || isAgentAdmin
                  ? true
                  : false
              "
              @on-change="changeSwitch(row)"
              size="large"
            >
              <span slot="open">上架</span>
              <span slot="close">下架</span>
            </i-switch>
            <div v-if="row.auto_off_time" class="style-add">
              定时下架：<br />{{ row.auto_off_time | timeFormat }}
            </div>
          </template>
        </vxe-column>
        <vxe-column field="action" title="操作" width="190" fixed="right">
          <template #default="{ row, rowIndex }">
            <a @click="details(row)">详情</a>
            <Divider type="vertical" />
            <a @click="edit(row)" v-auth="['product-product-edit']">编辑</a>
            <Divider v-auth="['product-product-edit']" type="vertical" />
            <a @click="lookGoods(row.id)">预览</a>
            <Divider
              type="vertical"
              v-auth="[
                'product-product-level_price',
                'product-product-brokerage_price',
                'product-product-view_reply',
                'product-product-batch_recover',
                'prdouct-product-del',
                'product-product-stock',
                'product-product-verify_remove',
                'product-product-copy',
                'product-product-delete',
              ]"
            />
            <a
              v-auth="['product-product-verify']"
              v-if="artFrom.type === '0'"
              @click="auditGoods(row)"
              >审核</a
            >
            <Divider
              v-auth="['product-product-verify']"
              v-if="artFrom.type === '0'"
              type="vertical"
            />
            <template>
              <Dropdown
                v-auth="[
                  'product-product-level_price',
                  'product-product-brokerage_price',
                  'product-product-view_reply',
                  'product-product-batch_recover',
                  'prdouct-product-del',
                  'product-product-stock',
                  'product-product-verify_remove',
                  'product-product-copy',
                  'product-product-delete',
                ]"
                @on-click="changeMenu(row, $event, rowIndex)"
                :transfer="true"
              >
                <a href="javascript:void(0)" class="acea-row row-middle">
                  <span>更多</span>
                  <Icon type="ios-arrow-down"></Icon>
                </a>
                <DropdownMenu slot="list">
                  <DropdownItem
                    name="8"
                    v-auth="['product-product-level_price']"
                    >会员价</DropdownItem
                  >
                  <DropdownItem
                    name="9"
                    v-auth="['product-product-brokerage_price']"
                    >佣金管理</DropdownItem
                  >
                  <DropdownItem name="1" v-auth="['product-product-view_reply']"
                    >查看评论</DropdownItem
                  >
                  <DropdownItem
                    name="2"
                    v-auth="['product-product-batch_recover']"
                    v-if="artFrom.type === '6'"
                    >恢复商品</DropdownItem
                  >
                  <DropdownItem
                    name="3"
                    v-auth="['prdouct-product-del']"
                    v-if="artFrom.type !== '6' && row.type == 0"
                    >移入回收站</DropdownItem
                  >
                  <DropdownItem
                    name="4"
                    v-auth="['product-product-stock']"
                    v-if="row.product_type != 1 && openErp == false"
                    >库存管理</DropdownItem
                  >
                  <DropdownItem
                    name="5"
                    v-auth="['product-product-verify_remove']"
                    v-if="artFrom.type === '1' || artFrom.type === '2'"
                    >强制下架</DropdownItem
                  >
                  <DropdownItem name="6" v-auth="['product-product-copy']"
                    >复制商品</DropdownItem
                  >
                  <DropdownItem
                    name="7"
                    v-auth="['product-product-delete']"
                    v-if="artFrom.type === '6'"
                    >删除商品</DropdownItem
                  >
                </DropdownMenu>
              </Dropdown>
            </template>
          </template>
        </vxe-column>
      </vxe-table>
      <div class="acea-row row-right page">
        <Page
            :total="total"
            :current="artFrom.page"
            show-elevator
            show-total
            @on-change="pageChange"
            :page-size="artFrom.limit"
            @on-page-size-change="pageChange"
            show-sizer
        />
      </div>
      <attribute
        :attrTemplate="attrTemplate"
        v-on:changeTemplate="changeTemplate"
      ></attribute>
    </Card>
    <!-- 生成淘宝京东表单-->
    <Modal
      v-model="modals"
      :z-index="100"
      class="Box"
      scrollable
      footer-hide
      closable
      title="复制淘宝、天猫、京东、苏宁、1688"
      :mask-closable="false"
      width="1200"
      height="500"
    >
      <tao-bao ref="taobaos" v-if="modals" @on-close="onClose"></tao-bao>
    </Modal>
    <!-- 配送方式 -->
    <Modal
      v-model="modalsType"
      scrollable
      title="配送方式"
      :closable="false"
      class-name="vertical-center-modal"
    >
      <Form :label-width="90" @submit.native.prevent>
        <FormItem label="配送方式：" class="deliveryStyle" required>
          <CheckboxGroup v-model="delivery_type">
            <Checkbox label="1">快递</Checkbox>
            <Checkbox label="2">到店核销</Checkbox>
          </CheckboxGroup>
        </FormItem>
      </Form>
      <div slot="footer">
        <Button type="primary" @click="putDelivery">提交</Button>
        <Button @click="cancelDelivery">取消</Button>
      </div>
    </Modal>
    <!-- 商品弹窗 -->
    <div v-if="isProductBox">
      <div class="bg" @click.stop="isProductBox = false"></div>
      <goodsDetail :goodsId="goodsId" :product="1"></goodsDetail>
    </div>
    <stockEdit
      ref="stock"
      :goodsType="goodsType"
      @stockChange="stockChange"
    ></stockEdit>
    <!-- 商品详情 -->
    <productDetails
      :visible.sync="detailsVisible"
      :product-id="productId"
      :relation-id="relationId"
      @saved="getDataList"
    ></productDetails>
    <!-- 批量设置 -->
    <Modal
      v-model="batchModal"
      title="批量设置"
      width="750"
      class-name="batch-modal"
      @on-visible-change="batchVisibleChange"
    >
      <Alert show-icon>每次只能修改一项，如需修改多项，请多次操作。</Alert>
      <Row type="flex" align="middle">
        <Col span="5">
          <Menu :active-name="menuActive" width="auto" @on-select="menuSelect">
            <MenuItem :name="1">商品分类</MenuItem>
            <MenuItem :name="2">商品标签</MenuItem>
            <MenuItem :name="10">商品品牌</MenuItem>
            <MenuItem :name="3">物流设置</MenuItem>
            <MenuItem :name="8">运费设置</MenuItem>
            <MenuItem :name="4">购买即送</MenuItem>
            <MenuItem :name="5">关联用户标签</MenuItem>
            <MenuItem :name="6">活动推荐</MenuItem>
            <MenuItem :name="7">自定义留言</MenuItem>
          </Menu>
        </Col>
        <Col span="19">
          <Form :model="batchData" :label-width="122">
            <FormItem v-if="menuActive === 1" label="商品分类：">
              <el-cascader
                v-model="batchData.cate_id"
                :options="data1"
                :props="props"
                size="small"
                filterable
                clearable
                :class="{ single: !batchData.cate_id.length }"
              >
              </el-cascader>
            </FormItem>
            <FormItem v-if="menuActive === 2" label="商品标签：">
              <div class="select-tag" @click="openStoreLabel">
                <div v-if="storeDataLabel.length">
                  <Tag
                    v-for="item in storeDataLabel"
                    :key="item.id"
                    closable
                    @on-close="tagClose(item.id)"
                    >{{ item.label_name }}</Tag
                  >
                </div>
                <span v-else class="placeholder">请选择</span>
                <Icon type="ios-arrow-down" />
              </div>
            </FormItem>
            <FormItem v-if="menuActive === 10" label="商品品牌：">
              <el-cascader
                v-model="batchData.brand_id"
                :options="brandDataList"
                size="small"
                filterable
                clearable
              >
              </el-cascader>
            </FormItem>
            <FormItem v-if="menuActive === 3" label="物流方式：">
              <CheckboxGroup v-model="batchData.delivery_type" size="small">
                <Checkbox :label="1">平台配送</Checkbox>
                <Checkbox :label="3">门店配送</Checkbox>
                <Checkbox :label="2">到店自提</Checkbox>
              </CheckboxGroup>
            </FormItem>
            <FormItem v-if="menuActive === 3 && batchData.delivery_type.includes(3)" label="配送类型：">
              <CheckboxGroup v-model="batchData.store_delivery_type" size="small">
                <Checkbox label="1">快递发货</Checkbox>
                <Checkbox label="2">同城配送</Checkbox>
              </CheckboxGroup>
            </FormItem>
            <FormItem v-if="menuActive === 8" label="运费设置：">
              <RadioGroup v-model="batchData.freight">
                <Radio :label="1">包邮</Radio>
                <Radio :label="2">固定邮费</Radio>
                <Radio :label="3">运费模板</Radio>
              </RadioGroup>
            </FormItem>
            <FormItem v-if="menuActive === 8 && batchData.freight === 2">
              <div class="input-number">
                <InputNumber v-model="batchData.postage" :min="0"></InputNumber>
                <span class="suffix">元</span>
              </div>
            </FormItem>
            <FormItem v-if="menuActive === 8 && batchData.freight === 3">
              <Select v-model="batchData.temp_id">
                <Option
                  v-for="item in templateList"
                  :key="item.id"
                  :value="item.id"
                  >{{ item.name }}</Option
                >
              </Select>
            </FormItem>
            <FormItem v-if="menuActive === 4" label="购买送积分：">
              <InputNumber
                v-model="batchData.give_integral"
                :min="0"
              ></InputNumber>
            </FormItem>
            <FormItem v-if="menuActive === 4" label="购买送优惠券：">
              <div class="select-tag" @click="addCoupon">
                <div v-if="couponName.length">
                  <Tag
                    v-for="item in couponName"
                    :key="item.id"
                    closable
                    @on-close="handleClose(item)"
                    >{{ item.title }}</Tag
                  >
                </div>
                <span v-else class="placeholder">请选择</span>
                <Icon type="ios-arrow-down" />
              </div>
            </FormItem>
            <FormItem v-if="menuActive === 5" label="关联用户标签：">
              <div class="select-tag" @click="openLabel">
                <div v-if="dataLabel.length">
                  <Tag
                    v-for="item in dataLabel"
                    :key="item.id"
                    closable
                    @on-close="tagClose(item.id)"
                    >{{ item.label_name }}</Tag
                  >
                </div>
                <span v-else class="placeholder">请选择</span>
                <Icon type="ios-arrow-down" />
              </div>
            </FormItem>
            <FormItem v-if="menuActive === 6" label="商品推荐：">
              <CheckboxGroup v-model="batchData.recommend" size="small">
                <Checkbox label="is_hot">热卖单品</Checkbox>
                <Checkbox label="is_benefit">促销单品</Checkbox>
                <Checkbox label="is_best">精品推荐</Checkbox>
                <Checkbox label="is_new">首发新品</Checkbox>
                <Checkbox label="is_good">优品推荐</Checkbox>
              </CheckboxGroup>
            </FormItem>
            <FormItem v-if="menuActive === 7" label="自定义留言：">
              <i-switch
                v-model="customBtn"
                size="large"
                @on-change="customMessBtn"
              >
                <span slot="open">开启</span>
                <span slot="close">关闭</span>
              </i-switch>
              <div class="mt10" v-if="customBtn">
                <Select
                  v-model="batchData.system_form_id"
                  filterable
                  placeholder="请选择"
                  @on-change="changeForm"
                >
                  <Option
                    v-for="(item, index) in formList"
                    :value="item.id"
                    :key="item.id"
                    >{{ item.name }}</Option
                  >
                </Select>
              </div>
              <div v-if="customBtn && batchData.system_form_id">
                <Table
                  border
                  :columns="formColumns"
                  :data="formTypeList"
                  ref="table"
                  class="customTab"
                  width="100%"
                  max-height="260"
                >
                  <template slot-scope="{ row }" slot="require">
                    <span>{{ row.require ? "必填" : "不必填" }}</span>
                  </template>
                </Table>
              </div>
            </FormItem>
          </Form>
        </Col>
      </Row>
      <div slot="footer">
        <Button @click="cancelBatch">取消</Button>
        <Button type="primary" @click="saveBatch">保存</Button>
      </div>
    </Modal>
    <!-- 用户标签 -->
    <Modal
      v-model="labelShow"
      scrollable
      title="选择用户标签"
      :closable="true"
      width="540"
      :footer-hide="true"
      :mask-closable="false"
    >
      <userLabel
        ref="userLabel"
        @activeData="activeData"
        @close="labelClose"
      ></userLabel>
    </Modal>
    <!-- 商品标签 -->
    <Modal
      v-model="storeLabelShow"
      scrollable
      title="选择商品标签"
      :closable="true"
      width="540"
      :footer-hide="true"
      :mask-closable="false"
    >
      <storeLabelList
        ref="storeLabel"
        @activeData="activeStoreData"
        @close="storeLabelClose"
      ></storeLabelList>
    </Modal>
    <coupon-list
      ref="couponTemplates"
      @nameId="nameId"
      :couponids="coupon_ids"
      :updateIds="updateIds"
      :updateName="updateName"
    ></coupon-list>
    <!-- 审核弹窗 -->
    <Modal
      v-model="verifyFormShow"
      scrollable
      title="商品审核"
      closable
      width="540"
      :mask-closable="false"
    >
      <Form :model="verifyForm" :label-width="80">
        <FormItem label="审核状态：">
          <RadioGroup
            v-model="verifyForm.is_verify"
            @on-change="goodsIsVerifyOn"
          >
            <Radio :label="1">通过</Radio>
            <Radio :label="-1">拒绝</Radio>
          </RadioGroup>
        </FormItem>
        <FormItem label="拒绝原因：" v-if="verifyForm.is_verify == -1">
          <Input
            v-model="verifyForm.refusal"
            type="textarea"
            :autosize="{ minRows: 2, maxRows: 5 }"
            placeholder="请输入拒绝原因"
          ></Input>
        </FormItem>
        <Col
          span="24"
          class="goodsShow"
          v-if="verifyRow.type == 2 && verifyForm.is_verify == 1"
        >
          <FormItem label="上架时间：">
            <RadioGroup v-model="verifyForm.is_show" @on-change="goodsOn">
              <Radio :label="0">
                <Icon type="social-windows"></Icon>
                <span>放入仓库</span>
              </Radio>
              <Radio :label="1">
                <Icon type="social-apple"></Icon>
                <span>立即上架</span>
              </Radio>
              <Radio :label="2">
                <Icon type="social-android"></Icon>
                <span>定时上架</span>
              </Radio>
            </RadioGroup>
          </FormItem>
        </Col>
        <Col span="24" v-if="verifyForm.is_show == 2">
          <FormItem label="">
            <DatePicker
              type="datetime"
              @on-change="onchangeShow"
              :options="startPickOptions"
              :value="verifyForm.auto_on_time"
              v-model="verifyForm.auto_on_time"
              placeholder="请选择上架时间"
              format="yyyy-MM-dd HH:mm"
              style="width: 260px"
            ></DatePicker>
          </FormItem>
        </Col>
      </Form>
      <div slot="footer">
        <Button @click="cancelVerifyForm">取消</Button>
        <Button type="primary" @click="saveVerifyForm">确认</Button>
      </div>
    </Modal>
    <Modal
      class-name="verify-result-modal"
      :closable="false"
      :mask-closable="false"
      v-model="verifyResultShow"
    >
      <p>
        <Icon
          type="ios-alert"
          size="24"
          color="#f90"
          class="mr-12 vertical-middle"
        />商品售价小于结算价，是否通过审核？
      </p>
      <div slot="footer">
        <Button @click="cancelProductSetVerify">取消</Button>
        <Button type="primary" @click="productSetVerify">确认</Button>
      </div>
    </Modal>
    <Modal
      title="强制下架"
      width="700"
      footer-hide
      class-name="vertical-center-modal"
      :mask-closable="false"
      v-model="forcedRemovalShow"
    >
      <Form :model="forcedRemovalForm" :label-width="125">
        <FormItem label="下架原因" required>
          <Input
            v-model="forcedRemovalForm.refusal"
            type="textarea"
            placeholder="请输入下架原因"
          ></Input>
        </FormItem>
      </Form>
      <div class="flex justify-end">
        <Button class="mr-14" @click="forcedRemovalCancel">取消</Button>
        <Button type="primary" @click="forcedRemovalSave">确认</Button>
      </div>
    </Modal>
    <Modal
      v-model="addTypeShow"
      scrollable
      :mask-closable="false"
      title="选择商品类型"
      footer-hide
      width="548"
      class-name="flex-center"
      :styles="{
        top: '0',
      }"
    >
      <div class="flex flex-wrap product-type-wrap">
        <div
          class="productType mr-12 mb-12"
          :class="product_type == item.id ? 'on' : ''"
          v-for="(item, index) in productType"
          :key="index"
          @click="productTypeTap(1, item)"
        >
          <div class="name">{{ item.name }}</div>
<!--          <div class="title">({{ item.title }})</div>-->
          <div v-if="product_type == item.id" class="jiao"></div>
          <div v-if="product_type == item.id" class="iconfont iconduihao"></div>
        </div>
      </div>
      <div class="acea-row row-right row-middle mt-30">
        <Button @click="addTypeShow = false">取消</Button>
        <Button type="primary" @click="productTypeMenu" class="ml-14"
          >确认</Button
        >
      </div>
    </Modal>
    <brokerageSet
      :visible="showBrokerage"
      :productId="productId"
      @close="
        () => {
          showBrokerage = false;
        }
      "
    ></brokerageSet>
    <vipPriceSet
      :visible="showVipPrice"
      :productId="productId"
      @close="
        () => {
          showVipPrice = false;
        }
      "
    ></vipPriceSet>
    <Modal
      v-model="importShow"
      scrollable
      :mask-closable="false"
      title="商品导入"
      footer-hide
      width="900"
    >
      <goodsImport v-if="importShow" @close="importShow = false"></goodsImport>
    </Modal>
  </div>
</template>

<script>
import goodsDetail from "@/pages/kefu/pc/components/goods_detail";
import stockEdit from "../components/stockEdit.vue";
import expandRow from "./tableExpand.vue";
import productDetails from "../components/productDetails.vue";
import storeLabelList from "@/components/storeLabelList";
import userLabel from "@/components/labelList";
import couponList from "@/components/couponList";
import brokerageSet from "../components/brokerageSet";
import vipPriceSet from "../components/vipPriceSet";
import goodsImport from "../components/goodsImport";
import attribute from "./attribute";
import toExcel from "../../../utils/Excel.js";
import { mapState } from "vuex";
import taoBao from "./taoBao";
import dayjs from "dayjs";
import Setting from "@/setting";
import util from "@/libs/util";
import { staffListInfo } from "@/api/store";
import { mapMutations } from "vuex";
import {
  getGoodHeade,
  getGoods,
  PostgoodsIsShow,
  treeListApi,
  productShowApi,
  productUnshowApi,
  storeProductApi,
  cascaderListApi,
  productCache,
  cacheDelete,
  setDeliveryType,
  productReviewApi,
  forcedRemovalApi,
  batchProcess,
  productGetTemplateApi,
  brandList,
  allSystemForm,
  productSetVerify,
  productInfoApi,
  productExportApi,
} from "@/api/product";
import { systemFormInfo } from "@/api/setting";
import { getSupplierList } from "@/api/supplier";
import { erpConfig, erpProduct } from "@/api/erp";
import exportExcel from "@/utils/newToExcel.js";
import timeOptions from "@/utils/timeOptions";

const allOptions = [
  {
    label: "批量上架",
    value: ["2"],
    key: "1",
    auth: "product-product-batch_show",
  },
  {
    label: "批量下架",
    value: ["1"],
    key: "2",
    auth: "product-product-batch_unshow",
  },
  {
    label: "强制下架",
    value: ["1", "2"],
    key: "3",
    auth: "product-product-batch_verify_remove",
  },
  {
    label: "移入回收站",
    value: ["1", "2", "4", "5", "-2"],
    key: "4",
    auth: "product-product-batch_del",
  },
  {
    label: "恢复商品",
    value: ["6"],
    key: "5",
  },
  {
    label: "删除商品",
    value: ["6"],
    key: "6",
  },
];

export default {
  name: "product_productList",
  components: {
    expandRow,
    attribute,
    taoBao,
    goodsDetail,
    stockEdit,
    productDetails,
    storeLabelList,
    userLabel,
    couponList,
    brokerageSet,
    vipPriceSet,
    goodsImport,
  },
  filters: {
    timeFormat: (value) => dayjs(value * 1000).format("YYYY-MM-DD HH:mm"),
  },
  computed: {
    ...mapState("admin/layout", ["isMobile"]),
    ...mapState("admin/userLevel", ["categoryId"]),
    labelWidth() {
      return this.isMobile ? undefined : 75;
    },
    labelPosition() {
      return this.isMobile ? "top" : "right";
    },
    brandDataList() {
      const valueToString = (item) => {
        item.value = item.value.toString();
        if (item.children) {
          item.children.map(valueToString);
        }
        return item;
      };
      return this.brandData.map(valueToString);
    },
    startPickOptions() {
      const that = this;
      return {
        disabledDate(time) {
          if (that.verifyForm.auto_off_time) {
            return (
              time.getTime() > new Date(that.verifyForm.auto_off_time).getTime()
            );
          }
          return "";
        },
      };
    },
    salesRange() {
      return (
        (this.salesStart != null ? this.salesStart : "") +
        "-" +
        (this.salesEnd != null ? this.salesEnd : "")
      );
    },
    priceRange() {
      return (
        (this.priceStart != null ? this.priceStart : "") +
        "-" +
        (this.priceEnd != null ? this.priceEnd : "")
      );
    },
    stockRange() {
      return (
        (this.stockStart != null ? this.stockStart : "") +
        "-" +
        (this.stockEnd != null ? this.stockEnd : "")
      );
    },
    collectRange() {
      return (
        (this.collectStart != null ? this.collectStart : "") +
        "-" +
        (this.collectEnd != null ? this.collectEnd : "")
      );
    },
    batchOptions() {
      return allOptions.filter((item) => {
        return item.value.includes(this.artFrom.type);
      });
    },
  },
  data() {
    return {
      stockStart: null,
      stockEnd: null,
      salesStart: null,
      salesEnd: null,
      priceStart: null,
      priceEnd: null,
      collectStart: null,
      collectEnd: null,
      timeVal: "",
      options: timeOptions,
      // 商品类型
      productType: [
        {
          name: "产品",
          id: 0,
          title: "物流发货",
        },
        // {
        //   name: "卡密/网盘",
        //   id: 1,
        //   title: "自动发货",
        // },
        // {
        //   name: "虚拟商品",
        //   id: 3,
        //   title: "虚拟发货",
        // },
        // {
        //   name: "次卡商品",
        //   id: 4,
        //   title: "到店核销",
        // },
        {
          name: "卡项",
          id: 5,
          title: "到店核销",
        },
        {
          name: "项目",
          id: 6,
          title: "到店+上门",
        },
      ],
      formTypeList: [],
      formColumns: [
        {
          title: "表单标题",
          key: "title",
          minWidth: 100,
        },
        {
          title: "表单类型",
          key: "name",
          minWidth: 100,
        },
        {
          title: "是否必填",
          slot: "require",
          minWidth: 100,
        },
      ],
      roterPre: Setting.roterPre,
      supplierList: [],
      header: {}, //请求头部信息
      erpUrl: Setting.apiBaseURL + "/file/upload/1",
      template: false,
      modals: false,
      modalsType: false,
      delivery_type: [],
      grid: {
        xl: 7,
        lg: 8,
        md: 12,
        sm: 24,
        xs: 24,
      },
      // 订单列表
      orderData: {
        page: 1,
        limit: 10,
        type: 6,
        status: "",
        time: "",
        real_name: "",
        store_id: "",
      },
      artFrom: {
        page: 1,
        limit: 15,
        cate_id: "",
        type: "1",
        store_name: "",
        excel: 0,
        supplier_id: "",
        store_id: "",
        brand_id: [],
        store_label_id: [],
        field_key: "all",
        product_type: "",
        delivery_type: "",
        spec_type: "",
        is_vip: "",
        create_range: "",
        sales_range: "",
        price_range: "",
        stock_range: "",
        collect_range: "",
        is_inventory: "",
        allow_negative_stock: "",
      },
      list: [],
      tableList: [],
      headeNum: [],
      treeSelect: [],
      isProductBox: false,
      loading: false,
      data: [],
      total: 0,
      props: { emitPath: false, multiple: true, checkStrictly: true },
      attrTemplate: false,
      ids: [],
      display: "none",
      formSelection: [],
      selectionCopy: [],
      checkBox: false,
      isAll: 0,
      data1: [],
      value1: [],
      alertShow: false,
      goodsId: "",
      columns3: [],
      openErp: false,
      productId: 0,
      detailsVisible: false,
      batchModal: false,
      menuActive: 1,
      storeLabelShow: false,
      storeDataLabel: [],
      labelShow: false,
      dataLabel: [],
      coupon_ids: [],
      updateIds: [],
      updateName: [],
      couponName: [],
      //自定义留言下拉选择
      customList: [
        {
          value: "text",
          label: "文本框",
        },
        {
          value: "number",
          label: "数字",
        },
        {
          value: "email",
          label: "邮件",
        },
        {
          value: "data",
          label: "日期",
        },
        {
          value: "time",
          label: "时间",
        },
        {
          value: "id",
          label: "身份证",
        },
        {
          value: "phone",
          label: "手机号",
        },
        {
          value: "img",
          label: "图片",
        },
      ],
      customBtn: false,
      batchData: {
        system_form_id: 0, //自定义表单id
        cate_id: [],
        store_label_id: [],
        delivery_type: [],
        store_delivery_type: [],
        freight: 1,
        postage: 0,
        temp_id: 0,
        give_integral: 0,
        coupon_ids: [],
        label_id: [],
        recommend: [],
        custom_form: [],
        brand_id: [],
      },
      templateList: [],
      brandData: [],
      goodsDataLabel: [],
      isLabel: 0,
      checkUidList: [],
      isCheckBox: false,
      staffData: [],
      formList: [],
      relationId: 0,
      verifyFormShow: false,
      verifyForm: {
        is_verify: 1,
        refusal: "",
        is_show: 0,
        auto_on_time: "",
      },
      verifyRow: {}, // 当前审核商品
      verifyResultShow: false,
      showVipPrice: false,
      showBrokerage: false,
      product_type: 0,
      addTypeShow: false,
      goodsType: 0, //点击当前商品列表获取商品类型
      collapse: false,
      forcedRemovalShow: false,
      forcedRemovalForm: {
        is_verify: "-2",
        refusal: "",
      },
      isAgentAdmin: this.__isAgentPath(),
      importShow: false,
      isSupplier: Number(localStorage.getItem("isSupplier")) || false,
    };
  },
  watch: {
    tableList: {
      deep: true,
      handler(value) {
        value.forEach((item) => {
          this.formSelection.forEach((itm) => {
            if (itm.id === item.id) {
              item.checkBox = true;
            }
          });
        });
        const arr = this.tableList.filter((item) => item.checkBox);
        if (this.tableList.length) {
          this.checkBox = this.tableList.length === arr.length;
        } else {
          this.checkBox = false;
        }
      },
    },
    storeDataLabel(value) {
      this.batchData.store_label_id = value.map((item) => item.id);
    },
    couponName(value) {
      this.batchData.coupon_ids = value.map((item) => item.id);
    },
    dataLabel(value) {
      this.batchData.label_id = value.map((item) => item.id);
    },
    "batchData.system_form_id"(value) {
      this.customBtn = !!value;
    },
    "batchData.freight"(value) {
      switch (value) {
        case 1:
          this.batchData.postage = 0;
          this.batchData.temp_id = 0;
          break;
        case 2:
          this.batchData.temp_id = 0;
          break;
        case 3:
          this.batchData.postage = 0;
          break;
      }
    },
  },
  created() {
    this.artFrom.type = this.$route.query.type || "1";
    this.getToken();
    this.staffList();
    productCache()
      .then((res) => {
        const info = res.data.info;
        if (!Array.isArray(info)) {
          this.alertShow = true;
        }
      })
      .catch((err) => {
        this.$Message.error(err.msg);
      });
    this.getErpConfig();
    this.getBrandList();
    this.allFormList();
  },
  mounted() {
    this.setCopyrightShow({ value: true });
    this.goodsCategory();
    this.getSupplierList();
    this.getDataList();
    this.productGetTemplate();
  },
  activated(e) {
    this.artFrom.type = this.$route.query.type || "1";
    this.getDataList();
  },
  beforeRouteEnter(to, from, next) {
    next((vm) => {
      if (from.path.indexOf("/admin/product/add_product") != -1) {
        document.documentElement.scrollTop = to.meta.scollTopPosition;
      } else {
        if (
          vm.artFrom.page != 1 ||
          vm.artFrom.cate_id != "" ||
          vm.artFrom.type != "1" ||
          vm.artFrom.store_name != "" ||
          vm.timeVal != "" ||
          vm.artFrom.field_key != "all" ||
          vm.artFrom.product_type != "" ||
          vm.artFrom.delivery_type != "" ||
          vm.artFrom.spec_type != "" ||
          vm.artFrom.is_vip != "" ||
          vm.collectEnd > 0 ||
          vm.collectStart > 0 ||
          vm.priceEnd > 0 ||
          vm.priceStart > 0 ||
          vm.stockStart > 0 ||
          vm.stockEnd > 0 ||
          vm.salesStart > 0 ||
          vm.salesEnd > 0 ||
          vm.artFrom.create_range != "" ||
          vm.artFrom.supplier_id != "" ||
          vm.artFrom.store_id != "" ||
          vm.artFrom.brand_id.length != 0 ||
          vm.goodsDataLabel.length != 0
        ) {
          vm.artFrom = {
            page: 1,
            limit: 15,
            cate_id: "",
            type: vm.$route.query.type || "1",
            store_name: "",
            excel: 0,
            supplier_id: "",
            store_id: "",
            brand_id: [],
            store_label_id: [],
            create_range: "",
            field_key: "all",
            product_type: "",
            delivery_type: "",
            spec_type: "",
            is_vip: "",
            is_inventory: "",
            allow_negative_stock: "",
          };
          vm.stockStart = null;
          vm.stockEnd = null;
          vm.salesStart = null;
          vm.salesEnd = null;
          vm.priceStart = null;
          vm.priceEnd = null;
          vm.collectStart = null;
          vm.collectEnd = null;
          vm.timeVal = "";
          vm.goodsDataLabel = [];
          let that = vm;
          setTimeout(function () {
            that.userSearchs();
          }, 500);
        } else {
          vm.userSearchs();
        }
      }
    });
  },
  beforeRouteLeave(to, from, next) {
    if (from.meta.keepAlive) {
      from.meta.scollTopPosition = document.documentElement.scrollTop;
    }
    next();
  },
  methods: {
    ...mapMutations("admin/layout", ["setCopyrightShow"]),
    // 具体日期
    onchangeTime(e) {
      if (e[1].slice(-8) === "00:00:00") {
        e[1] = e[1].slice(0, -8) + "23:59:59";
        this.timeVal = e;
      } else {
        this.timeVal = e;
      }
      this.artFrom.create_range = this.timeVal[0] ? this.timeVal.join("-") : "";
      this.userSearchs();
    },
    allReset() {
      this.isAll = 0;
      this.isCheckBox = false;
      this.$refs.xTable.setAllCheckboxRow(false);
      this.checkUidList = [];
    },
    changeForm(e) {
      this.getSystemFormInfo(e, { type: 1 });
    },
    getSystemFormInfo(e, data) {
      systemFormInfo(e, data)
        .then((res) => {
          this.formTypeList = res.data.info;
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    allFormList() {
      allSystemForm()
        .then((res) => {
          this.formList = res.data;
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    checkboxItem(e) {
      let id = parseInt(e.rowid);
      let index = this.checkUidList.indexOf(id);
      if (index !== -1) {
        this.checkUidList = this.checkUidList.filter((item) => item !== id);
      } else {
        this.checkUidList.push(id);
      }
    },
    checkboxAll() {
      // 获取选中当前值
      let obj2 = this.$refs.xTable.getCheckboxRecords(true);
      // 获取之前选中值
      let obj = this.$refs.xTable.getCheckboxReserveRecords(true);
      if (
        this.isAll == 0 &&
        this.checkUidList.length <= obj.length &&
        !this.isCheckBox
      ) {
        obj = [];
      }
      obj = obj.concat(obj2);
      let ids = [];
      obj.forEach((item) => {
        ids.push(parseInt(item.id));
      });
      this.checkUidList = ids;
      if (!obj2.length) {
        this.isCheckBox = false;
      }
    },
    allPages(e) {
      this.isAll = e;
      if (e == 0) {
        this.$refs.xTable.toggleAllCheckboxRow();
        // this.checkboxAll();
      } else {
        if (!this.isCheckBox) {
          this.$refs.xTable.setAllCheckboxRow(true);
          this.isCheckBox = true;
          this.isAll = 1;
        } else {
          this.$refs.xTable.setAllCheckboxRow(false);
          this.isCheckBox = false;
          this.isAll = 0;
        }
        this.checkUidList = [];
      }
    },
    closeStoreLabel(label) {
      let index = this.goodsDataLabel.indexOf(
        this.goodsDataLabel.filter((d) => d.id == label.id)[0]
      );
      this.goodsDataLabel.splice(index, 1);
      // 商品标签id
      let storeActiveIds = [];
      this.goodsDataLabel.forEach((item) => {
        storeActiveIds.push(item.id);
      });
      this.artFrom.store_label_id = storeActiveIds;
      this.userSearchs();
    },
    // 品牌列表
    getBrandList() {
      brandList()
        .then((res) => {
          this.initBran(res.data);
          this.brandData = res.data;
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    initBran(data) {
      data.map((item) => {
        item.value = item.value.toString();
        if (item.children && item.children.length) {
          this.initBran(item.children);
        }
      });
    },
    //获取供应商列表；
    getSupplierList() {
      getSupplierList()
        .then(async (res) => {
          this.supplierList = res.data;
        })
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
    // 审核
    auditGoods(row) {
      this.verifyRow = row;
      this.verifyFormShow = true;
    },
    // 强制下架
    forcedRemoval(row) {
      this.$modalForm(forcedRemovalApi(row.id)).then(() => this.getDataList());
    },
    frontDownload() {
      let a = document.createElement("a"); //创建一个<a></a>标签
      a.href = "/statics/ERP商品导入模板.xlsx"; // 给a标签的href属性值加上地址，注意，这里是绝对路径，不用加 点.
      a.download = "ERP商品导入模板.xlsx"; //设置下载文件文件名，这里加上.xlsx指定文件类型，pdf文件就指定.fpd即可
      a.style.display = "none"; // 障眼法藏起来a标签
      document.body.appendChild(a); // 将a标签追加到文档对象中
      a.click(); // 模拟点击了a标签，会触发a标签的href的读取，浏览器就会自动下载了
      a.remove(); // 一次性的，用完就删除a标签
    },
    handleFormatError(file) {
      return this.$Message.error("必须上传xlsx格式文件");
    },
    // 上传头部token
    getToken() {
      this.header["Authori-zation"] = "Bearer " + util.cookies.get("token");
    },
    upFile(res) {
      erpProduct({ path: res.data.src })
        .then((res) => {
          this.$Message.success(res.msg);
          this.getDataList();
        })
        .catch((err) => {
          return this.$Message.error(err.msg);
        });
    },
    beforeUpload() {
      let promise = new Promise((resolve) => {
        this.$nextTick(function () {
          resolve(true);
        });
      });
      return promise;
    },
    //erp配置
    getErpConfig() {
      erpConfig()
        .then((res) => {
          this.openErp = res.data.open_erp;
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    stockChange(stock) {
      this.tableList.forEach((item) => {
        if (this.goodsId == item.id) {
          item.stock = stock;
        }
      });
    },
    // 库存管理
    stockControl(row) {
      this.goodsId = row.id;
      this.goodsType = row.product_type;
      this.$refs.stock.modals = true;
      this.$refs.stock.productAttrs(row);
    },
    cancelDelivery() {
      this.modalsType = false;
      this.delivery_type = [];
    },
    deliveryType() {
      this.modalsType = true;
    },
    putDelivery() {
      if (this.delivery_type.length === 0) {
        this.$Message.error("请选择要配送的商品");
      } else {
        let data = {
          all: this.isAll,
          delivery_type: this.delivery_type,
          ids: this.checkUidList,
        };
        // if (this.isAll == 0) {
        //   data.ids = this.checkUidList;
        // }
        setDeliveryType(data)
          .then((res) => {
            this.$Message.success(res.msg);
            this.modalsType = false;
            this.delivery_type = [];
            this.isAll = 0;
            this.getDataList();
          })
          .catch((res) => {
            this.$Message.error(res.msg);
          });
      }
    },
    // 商品详情
    lookGoods(id) {
      this.goodsId = id;
      this.isProductBox = true;
    },
    closeAlert() {
      cacheDelete()
        .then((res) => {
          this.$Message.success(res.msg);
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    changeMenu(row, name, index) {
      switch (name) {
        case "1":
          let path =
            `${this.__isAgentPath() ? "/agent" : this.roterPre}` +
            "/product/product_reply/" +
            row.id;
          this.$router.push({
            path,
          });
          break;
        case "2":
          this.del(row, "恢复商品", index, name);
          break;
        case "3":
          this.del(row, "移入回收站", index, name);
          break;
        case "4":
          this.stockControl(row);
          break;
        case "5":
          this.$modalForm(forcedRemovalApi(row.id)).then(() => {
            this.getDataList();
          });
          break;
        case "6":
          this.$router.push({
            path: this.roterPre + "/product/add_product",
            query: { copy: row.id },
          });
          break;
        case "7":
          this.trueDel(row, "删除商品", index);
          break;
        case "8":
          this.productId = row.id;
          this.showVipPrice = true;
          break;
        case "9":
          this.productId = row.id;
          this.showBrokerage = true;
          break;
      }
    },
    // 数据导出；
    async exports(type) {
      if (!this.checkUidList.length && !this.isAll)
        return this.$Message.error("本页至少选中一项");
      let [th, filekey, data, fileName] = [[], [], [], ""];
      let excelData = {
        store_name: this.artFrom.store_name,
        cate_id: this.artFrom.cate_id,
        type: this.artFrom.type,
        store_id: this.artFrom.store_id,
        supplier_id: this.artFrom.supplier_id,
        sales: this.artFrom.sales_range,
        store_label_id: this.artFrom.store_label_id,
        brand_id: this.artFrom.brand_id,
        ids: this.checkUidList.join(),
      };
      if (this.isAll == 1) {
        Object.assign(excelData, this.artFrom);
        excelData.all = 1;
        delete excelData.limit;
      }
      excelData.page = 1;
      let apiFun = type ? this.getExportData : this.getExcelData;
      for (let i = 0; i < excelData.page; i++) {
        let lebData = await apiFun(excelData);
        if (!fileName) {
          fileName = lebData.filename;
        }
        if (!filekey.length) {
          filekey = lebData.filekey;
        }
        if (!th.length) {
          th = lebData.header;
        }
        if (lebData.export.length) {
          data = data.concat(lebData.export);
          excelData.page++;
        }
      }
      exportExcel(th, filekey, fileName, data);
    },
    getExportData(excelData) {
      return new Promise((resolve, reject) => {
        productExportApi(excelData).then((res) => {
          return resolve(res.data);
        });
      });
    },
    getExcelData(excelData) {
      return new Promise((resolve, reject) => {
        storeProductApi(excelData).then((res) => {
          return resolve(res.data);
        });
      });
    },
    freight() {
      this.$refs.template.isTemplate = true;
    },
    // 批量上架
    onShelves() {
      if (this.isAll != 1 && this.checkUidList.length === 0) {
        this.$Message.warning("请选择要上架的商品");
      } else {
        let data = {
          all: this.isAll,
          ids: this.checkUidList,
        };
        if (this.isAll == 1) {
          data.where = this.artFrom;
        }
        productShowApi(data)
          .then((res) => {
            this.$Message.success(res.msg);
            this.getDataList();
            this.allReset();
          })
          .catch((res) => {
            this.$Message.error(res.msg);
          });
      }
    },
    // 批量下架
    onDismount() {
      if (this.isAll != 1 && this.checkUidList.length === 0) {
        this.$Message.warning("请选择要下架的商品");
      } else {
        let data = {
          all: this.isAll,
          ids: this.checkUidList,
        };
        if (this.isAll == 1) {
          data.where = this.artFrom;
        }
        productUnshowApi(data)
          .then((res) => {
            this.$Message.success(res.msg);
            this.getDataList();
            this.allReset();
          })
          .catch((res) => {
            this.$Message.error(res.msg);
          });
      }
    },
    // 添加淘宝商品成功
    onClose() {
      this.modals = false;
    },
    // 复制淘宝
    onCopy() {
      this.$router.push({
        path: this.roterPre + "/product/add_product",
        query: { type: -1 },
      });
      // this.modals = true;
    },
    // tab选择
    onClickTab(name) {
      this.allReset();
      this.artFrom.type = name;
      this.artFrom.page = 1;
      this.getDataList();
    },
    // 下拉树
    handleCheckChange(data) {
      let value = "";
      let title = "";
      this.list = [];
      this.artFrom.cate_id = 0;
      data.forEach((item, index) => {
        value += `${item.id},`;
        title += `${item.title},`;
      });
      value = value.substring(0, value.length - 1);
      title = title.substring(0, title.length - 1);
      this.list.push({
        value,
        title,
      });
      this.artFrom.cate_id = value;
      this.getDataList();
    },
    // 获取商品表单头数量
    goodHeade() {
      getGoodHeade(this.artFrom)
        .then((res) => {
          this.headeNum = res.data.list;
        })
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
    // 商品分类；
    goodsCategory() {
      cascaderListApi({
        type: 0,
        relation_id: 0,
      })
        .then((res) => {
          this.data1 = res.data;
        })
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
    // 商品列表；
    getDataList() {
      this.loading = true;
      this.artFrom.cate_id = this.artFrom.cate_id || "";
      this.artFrom.sales_range = this.salesRange;
      this.artFrom.price_range = this.priceRange;
      this.artFrom.stock_range = this.stockRange;
      this.artFrom.collect_range = this.collectRange;
      getGoods(this.artFrom)
        .then((res) => {
          let data = res.data;
          if (this.artFrom.type == 0) {
            data.list.forEach((item) => {
              if (item.type == 2) {
                item.is_show = 0;
              }
            });
          }
          this.tableList = data.list;
          this.total = data.count;
          this.loading = false;
          this.$nextTick(function () {
            if (this.isAll == 1) {
              if (this.isCheckBox) {
                let flag = false;
                data.list.forEach((item) => {
                  this.checkUidList.forEach((j) => {
                    if (item.id == j) {
                      flag = true;
                    }
                  });
                });
                if (!flag) {
                  this.$refs.xTable.setAllCheckboxRow(true);
                }
              } else {
                this.$refs.xTable.setAllCheckboxRow(false);
              }
            } else {
              let obj = this.$refs.xTable.getCheckboxReserveRecords(true);
              if (
                !this.checkUidList.length ||
                this.checkUidList.length <= obj.length
              ) {
                this.$refs.xTable.setAllCheckboxRow(false);
              }
            }
          });
          this.goodHeade();
        })
        .catch((res) => {
          this.loading = false;
          this.$Message.error(res.msg);
        });
    },
    pageChange(index) {
      this.artFrom.page = index;
      this.getDataList();
    },
    // 重置
    reset() {
      this.artFrom = {
        page: 1,
        limit: 15,
        cate_id: "",
        type: "1",
        store_name: "",
        excel: 0,
        supplier_id: "",
        store_id: "",
        brand_id: [],
        store_label_id: [],
        field_key: "all",
        product_type: "",
        delivery_type: "",
        spec_type: "",
        is_vip: "",
        create_range: "",
        sales_range: "",
        price_range: "",
        stock_range: "",
        collect_range: "",
        is_inventory: "",
        allow_negative_stock: "",
      };
      this.goodsDataLabel = [];
      this.timeVal = "";
      this.salesStart = null;
      this.salesEnd = null;
      this.priceStart = null;
      this.priceEnd = null;
      this.stockStart = null;
      this.stockEnd = null;
      this.collectStart = null;
      this.collectEnd = null;
      this.userSearchs();
    },
    // 表格搜索
    userSearchs() {
      this.allReset();
      this.artFrom.page = 1;
      this.formSelection = [];
      this.getDataList();
    },
    // 上下架
    changeSwitch(row) {
      PostgoodsIsShow(row.id, row.is_show)
        .then((res) => {
          this.$Message.success(res.msg);
          this.getDataList();
          this.allReset();
        })
        .catch((res) => {
          this.$Message.error(res.msg);
          this.getDataList();
        });
    },
    // 数据导出；
    exportData: function () {
      let th = [
        "商品名称",
        "商品简介",
        "商品分类",
        "价格",
        "库存",
        "销量",
        "收藏人数",
      ];
      let filterVal = [
        "store_name",
        "store_info",
        "cate_name",
        "price",
        "stock",
        "sales",
        "collect",
      ];
      this.where.page = "nopage";
      getGoods(this.where).then((res) => {
        let data = res.data.map((v) => filterVal.map((k) => v[k]));
        let fileTime = Date.parse(new Date());
        let [fileName, fileType, sheetName] = [
          "商户数据_" + fileTime,
          "xlsx",
          "商户数据",
        ];
        toExcel({ th, data, fileName, fileType, sheetName });
      });
    },
    // 属性弹出；
    attrTap() {
      this.attrTemplate = true;
    },
    changeTemplate(msg) {
      this.attrTemplate = msg;
    },
    // 编辑
    edit(row) {
      this.$router.push({
        path: this.roterPre + "/product/add_product/" + row.id,
      });
    },
    // 确认
    del(row, tit, num, name) {
      let delfromData = {
        title: tit,
        num: num,
        url: `product/product/${row.id}`,
        method: "DELETE",
        ids: "",
        tips: `确定要移${name == 2 ? "出" : "入"}回收站吗？`,
      };
      this.$modalSure(delfromData)
        .then((res) => {
          this.$Message.success(res.msg);
          this.tableList.splice(num, 1);
          this.goodHeade();
          this.allReset();
        })
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
    // 真删除商品
    trueDel(row, tit, num) {
      let delfromData = {
        title: tit,
        num: num,
        url: `product/product/del/${row.id}`,
        method: "DELETE",
        ids: "",
        tips: `确定要删除商品吗？`,
      };
      this.$modalSure(delfromData)
        .then((res) => {
          this.$Message.success(res.msg);
          this.tableList.splice(num, 1);
          this.goodHeade();
          this.allReset();
        })
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
    staffList() {
      staffListInfo()
        .then((res) => {
          this.staffData = res.data;
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    // 商品详情
    details(row) {
      this.productId = row.id;
      this.relationId = row.relation_id;
      this.detailsVisible = true;
    },
    openBatch() {
      this.isLabel = 0;
      this.getBrandList();
      this.goodsCategory();
      this.productGetTemplate();
      this.batchModal = true;
    },
    menuSelect(name) {
      this.menuActive = name;
    },
    activeStoreData(storeDataLabel) {
      this.storeLabelShow = false;
      if (this.isLabel) {
        this.goodsDataLabel = storeDataLabel;
        // 商品标签id
        let storeActiveIds = [];
        storeDataLabel.forEach((item) => {
          storeActiveIds.push(item.id);
        });
        this.artFrom.store_label_id = storeActiveIds;
        this.userSearchs();
      } else {
        this.storeDataLabel = storeDataLabel;
      }
    },
    // 标签弹窗关闭
    storeLabelClose() {
      this.storeLabelShow = false;
    },
    openStoreLabel(row) {
      this.storeLabelShow = true;
      this.$refs.storeLabel.storeLabel(
        JSON.parse(JSON.stringify(this.storeDataLabel))
      );
    },
    openGoodsLabel(row) {
      this.storeLabelShow = true;
      this.$refs.storeLabel.storeLabel(
        JSON.parse(JSON.stringify(this.goodsDataLabel))
      );
      this.isLabel = 1;
    },
    tagClose(id) {
      if (this.menuActive == 2) {
        let index = this.storeDataLabel.findIndex((item) => item.id === id);
        this.storeDataLabel.splice(index, 1);
      } else {
        let index = this.dataLabel.findIndex((item) => item.id === id);
        this.dataLabel.splice(index, 1);
      }
    },
    activeData(dataLabel) {
      this.labelShow = false;
      this.dataLabel = dataLabel;
    },
    // 标签弹窗关闭
    labelClose() {
      this.labelShow = false;
    },
    openLabel() {
      this.labelShow = true;
      this.$refs.userLabel.userLabel(
        JSON.parse(JSON.stringify(this.dataLabel))
      );
    },
    // 添加优惠券
    addCoupon() {
      this.$refs.couponTemplates.isTemplate = true;
      this.$refs.couponTemplates.tableList();
    },
    nameId(id, names) {
      this.coupon_ids = id;
      this.couponName = this.unique(names);
    },
    handleClose(name) {
      let index = this.couponName.indexOf(name);
      this.couponName.splice(index, 1);
      let couponIds = this.coupon_ids;
      couponIds.splice(index, 1);
      this.updateIds = couponIds;
      this.updateName = this.couponName;
    },
    //对象数组去重；
    unique(arr) {
      const res = new Map();
      return arr.filter((arr) => !res.has(arr.id) && res.set(arr.id, 1));
    },
    // 添加表单
    addForm() {
      this.batchData.custom_form.push({
        key: Date.now(),
        title: "",
        label: "",
        status: 0,
      });
    },
    // 删除表单
    delForm(item) {
      let index = this.batchData.custom_form.findIndex((val) => val === item);
      if (index !== -1) {
        this.batchData.custom_form.splice(index, 1);
      }
    },
    cancelBatch() {
      this.batchModal = false;
    },
    saveBatch() {
      if (this.customBtn && this.batchData.system_form_id == 0) {
        return this.$Message.warning("请选择自定义表单模板");
      }
      let data = {
        type: this.menuActive,
        ids: this.checkUidList,
        all: this.isAll,
        where: this.artFrom,
        data: this.batchData,
      };
      batchProcess(data)
        .then((res) => {
          this.$Message.success(res.msg);
          this.batchModal = false;
        })
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
    // 获取运费模板；
    productGetTemplate() {
      productGetTemplateApi().then((res) => {
        this.templateList = res.data;
      });
    },
    customMessBtn(e) {
      if (!e) {
        this.batchData.system_form_id = 0;
      }
    },
    batchVisibleChange() {
      this.batchData = {
        cate_id: [],
        store_label_id: [],
        delivery_type: [],
        freight: 1,
        postage: 0,
        temp_id: 0,
        give_integral: 0,
        coupon_ids: [],
        label_id: [],
        recommend: [],
        custom_form: [],
        system_form_id: 0,
      };
      this.storeDataLabel = [];
      this.couponName = [];
      this.dataLabel = [];
      this.menuActive = 1;
    },
    // 取消审核
    cancelVerifyForm() {
      this.verifyFormShow = false;
      this.verifyForm.is_verify = 1;
      this.verifyForm.refusal = "";
    },
    // 提交审核
    saveVerifyForm() {
      // 非供应商商品或拒绝供应商商品
      if (this.verifyRow.type != 2 || this.verifyForm.is_verify != 1) {
        this.productSetVerify();
        return;
      }
      if (this.verifyRow.spec_type) {
        // 多规格
        this.getInfo();
      } else {
        // 单规格
        if (
          Number(this.verifyRow.price) < Number(this.verifyRow.settle_price)
        ) {
          // 售价小于结算价
          this.verifyResultShow = true;
        } else {
          this.productSetVerify();
        }
      }
    },
    goodsIsVerifyOn(e) {
      this.verifyForm.refusal = "";
      this.verifyForm.is_show = 0;
      this.verifyForm.auto_on_time = "";
    },
    goodsOn(e) {
      if (e == 0 || e == 1) {
        this.verifyForm.auto_on_time = "";
      }
    },
    //定时上架
    onchangeShow(e) {
      this.verifyForm.auto_on_time = e;
    },
    // 商品详情
    getInfo() {
      productInfoApi(this.verifyRow.id).then((res) => {
        let attrs = res.data.productInfo.attrs;
        // 判断是否存在售价小于结算价的规格
        let result = attrs.some((attr) => {
          return attr.price < attr.settle_price;
        });
        if (result) {
          // 售价小于结算价
          this.verifyResultShow = true;
        } else {
          this.productSetVerify();
        }
      });
    },
    // 确认提交审核数据
    productSetVerify() {
      productSetVerify(this.verifyRow.id, this.verifyForm)
        .then((res) => {
          this.verifyResultShow = false;
          this.verifyFormShow = false;
          this.verifyForm.is_verify = 1;
          this.verifyForm.refusal = "";
          this.verifyForm.is_show = 0;
          this.verifyForm.auto_on_time = "";
          this.$Message.success(res.msg);
          this.artFrom.page = 1;
          this.getDataList();
        })
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
    // 取消审核
    cancelProductSetVerify() {
      this.verifyResultShow = false;
    },
    productTypeMenu() {
      this.addTypeShow = false;
      this.$router.push({
        path: `${this.roterPre}/product/add_product`,
        query: { productType: this.product_type },
      });
      this.product_type = 0;
    },
    productTypeTap(num, item) {
      this.product_type = item.id;
    },
    batchHandler(type) {
      if (type == 1) {
        // 批量上架
        this.onShelves();
      } else if (type == 2) {
        // 批量下架
        this.onDismount();
      } else if (type == 3) {
        // 强制下架
        this.forcedRemovalShow = true;
      } else if (type == 4) {
        // 移入回收站
        let data = {
          data: {
            is_del: 1,
          },
          type: 11,
          ids: this.checkUidList,
          all: this.isAll,
        };
        if (this.isAll == 1) {
          data.where = this.artFrom;
        }
        batchProcess(data)
          .then((res) => {
            this.$Message.success(res.msg);
            this.getDataList();
            this.allReset();
          })
          .catch((res) => {
            this.$Message.error(res.msg);
          });
      } else if (type == 5) {
        // 恢复商品
        let data = {
          data: {
            is_del: 0,
          },
          type: 11,
          ids: this.checkUidList,
          all: this.isAll,
        };
        if (this.isAll == 1) {
          data.where = this.artFrom;
        }
        batchProcess(data)
          .then((res) => {
            this.$Message.success(res.msg);
            this.getDataList();
            this.allReset();
          })
          .catch((res) => {
            this.$Message.error(res.msg);
          });
      } else if (type == 6) {
        // 删除商品
        let data = {
          type: 12,
          ids: this.checkUidList,
          all: this.isAll,
        };
        if (this.isAll == 1) {
          data.where = this.artFrom;
        }
        batchProcess(data)
          .then((res) => {
            this.$Message.success(res.msg);
            this.getDataList();
            this.allReset();
          })
          .catch((res) => {
            this.$Message.error(res.msg);
          });
      }
    },
    // 批量操作-强制下架-取消
    forcedRemovalCancel() {
      this.forcedRemovalShow = false;
    },
    // 批量操作-强制下架-确认
    forcedRemovalSave() {
      let data = {
        data: this.forcedRemovalForm,
        type: 13,
        ids: this.checkUidList,
        all: this.isAll,
      };
      if (this.isAll == 1) {
        data.where = this.artFrom;
      }
      batchProcess(data)
        .then((res) => {
          this.forcedRemovalShow = false;
          this.$Message.success(res.msg);
          this.getDataList();
        })
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
    // 商品迁移
    goodsMove(val) {
      if (val === 1) {
        this.importShow = true;
      } else {
        this.exports(1);
      }
    },
    brandCom(brand_com) {
      let brandArr = brand_com.split(",");
      let brandData = this.brandData;
      let brandList = [];
      for (let i = 0; i < brandArr.length; i++) {
        let brandItem = brandData.find((item) => item.value == brandArr[i]);
        if (brandItem) {
          brandList.push(brandItem.label);
          brandData = brandItem.children || [];
        }
      }
      return brandList.join();
    },
    storeLabel(store_label_id) {
      let brandList = [];
      for (let i = 0; i < store_label_id.length; i++) {
        brandList.push(store_label_id[i].label_name);
      }
      return brandList.join();
    },
  },
};
</script>
<style scoped lang="stylus">
.mr14{
	margin-right: 14px !important;
}
.customTab{
  margin-top 20px;
  /deep/.ivu-table-header thead tr th{
    padding 0 10px !important
    .ivu-table-cell{
      padding 5px 0 !important
    }
  }
  /deep/.ivu-table td{
    height 30px !important;
    padding 0 10px !important;
    .ivu-table-cell{
      padding 2px 0 !important;
    }
  }
}
/deep/.el-cascader .el-cascader__search-input{
  font-size: 12px !important;
}
/deep/.ivu-dropdown-item{
  font-size: 12px!important;
}
/deep/.vxe-table--render-default .vxe-cell{
  font-size: 12px;
}
.tdinfo{
  margin-left: 75px;
  margin-top: 16px;
}
.expand-row{
  margin-bottom: 16px;
  font-size: 12px;
}
/deep/.ivu-checkbox-wrapper{
  font-size: 12px;
}
.labelClass{
  /deep/.ivu-form-item-content{
    line-height: unset;
  }
}
.labelInput{
  border: 1px solid #dcdee2;
  width :250px;
  padding: 0 5px;
  border-radius: 5px;
  min-height: 30px;
  cursor: pointer;
  .span{
    color: #c5c8ce;
  }
  .iconxiayi{
    font-size: 12px
  }
}

.input-add {
  width: 250px;
  margin-right: 14px;
}

.style-add {
  margin-top: 10px;
  line-height: 1.2;
}

.line2 {
  max-height: 40px;
}

.bg {
  z-index: 100;
  position: fixed;
  left: 0;
  top: 0;
  width: 100%;
  height: 100%;
  background: rgba(0, 0, 0, 0.5);
}

.deliveryStyle /deep/.ivu-checkbox-wrapper {
  margin-right: 14px;
}

.Button /deep/.ivu-upload {
  width: 105px;
  display: inline-block;
  margin-right: 10px;
}

/deep/.ivu-modal-mask {
  z-index: 999 !important;
}

/deep/.ivu-modal-wrap {
  z-index: 999 !important;
}

/deep/.ivu-alert {
  margin-bottom: 20px;
}

.Box {
  >>> .ivu-modal-body {
    height: 700px;
    overflow: auto;
  }
}

.tabBox_img {
  width: 40px;
  height: 40px;
  border-radius: 4px;
  cursor: pointer;

  img {
    width: 100%;
    height: 100%;
  }
}

/deep/.ivu-table-cell-expand-expanded {
  margin-top: -6px;
  margin-right: 33px;
  transition: none;

  .ivu-icon {
    vertical-align: 2px;
  }
}

/deep/.ivu-table-header {
  // overflow visible
}

/deep/.ivu-table th {
  overflow: visible;
}

/deep/.select-item:hover {
  background-color: #f3f3f3;
}

/deep/.select-on {
  display: block;
}

/deep/.select-item.on {
  /* background: #f3f3f3; */
}

.new_tab {
  >>>.ivu-tabs-nav .ivu-tabs-tab {
    padding: 4px 16px 20px !important;
    font-weight: 500;
  }
}
.select-tag {
  position: relative;
  min-height: 32px;
  padding: 0 24px 0 4px;
  border: 1px solid #dcdee2;
  border-radius: 4px;
  line-height: normal;
  user-select: none;
  cursor: pointer;

  &:hover {
    border-color: #57a3f3;
  }

  .ivu-icon {
    position: absolute;
    top: 50%;
    right: 8px;
    line-height: 1;
    transform: translateY(-50%);
    font-size: 14px;
    color: #808695;
    transition: all .2s ease-in-out;
  }

  .ivu-tag {
    position: relative;
    max-width: 99%;
    height: 24px;
    margin: 3px 4px 3px 0;
    line-height: 22px;
  }

  .placeholder {
    display: block;
    height: 30px;
    line-height: 30px;
    color: #c5c8ce;
    font-size: 14px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    padding-left: 4px;
    padding-right: 22px;
  }
}
.input-number {
  position: relative;
  display: inline-block;
  vertical-align: middle;

  >>> .ivu-input-number-handler-wrap {
    right: 32px;
  }

  .ivu-input-number {
    width: 144px;
    margin-right: 32px;
  }

  .suffix {
    position: absolute;
    top: 0;
    right: 0;
    z-index: 1;
    width: 32px;
    height: 100%;
    text-align: center;
  }
}
.ivu-checkbox-wrapper, .ivu-radio-wrapper {
  margin-right: 30px;
}

>>> .batch-modal {
  .ivu-modal-body {
    padding: 0;
  }

  .ivu-alert {
    margin: 12px 24px;
  }

  .ivu-col-span-5 {
    flex: none;
    width: 130px;
  }

  .ivu-col-span-19 {
    padding-right: 37px;
  }

  .ivu-input-number {
    width: 100%;
  }

  .ivu-menu-light.ivu-menu-vertical .ivu-menu-item-active:not(.ivu-menu-submenu) {
    z-index: auto;
  }

  .ivu-menu-light.ivu-menu-vertical .ivu-menu-item-active:not(.ivu-menu-submenu):after {
    right: auto;
    left: 0;
  }

  .el-cascader {
    width: 100%;
  }

  .ivu-btn-text {
    color: #2D8CF0;
  }

  .ivu-btn-text:focus {
    box-shadow: none;
  }

  .ivu-menu-item {
    padding-right: 0;
  }
}

>>>.el-cascader {
  &.el-cascader--small {
    vertical-align: bottom;
    line-height: 30px;
  }

  &.single {
    .el-input__inner {
      height: 32px !important;
    }
  }

  .el-input__inner {
    padding-left: 7px;
    font-size: 14px;
  }

  .el-cascader__search-input {
    margin-left: 9px;
    font-size: 14px;
  }

  .el-input__suffix {
    right: 4px;
  }

  .el-input__icon {
    color: #808695;
	font-weight:bold;
	font-size: 12px;
    // display: inline-block;
    // font-family: "Ionicons" !important;
    // speak: none;
    // font-style: normal;
    // font-weight: normal;
    // font-variant: normal;
    // text-transform: none;
    // text-rendering: optimizeLegibility;
    // line-height: 1;
    // -webkit-font-smoothing: antialiased;
    // -moz-osx-font-smoothing: grayscale;
    // vertical-align: -0.125em;
    // text-align: center;
    // line-height: 32px;
  }

  // .el-icon-arrow-down:before {
  //   content: "\F116";
  // }
}
/deep/.verify-result-modal .ivu-modal-body {
  padding: 32px;
}
.productType {
  width: 120px;
  height: 60px;
  background: #FFFFFF;
  border-radius: 3px;
  border: 1px solid #E7E7E7;
  text-align: center;
  padding-top: 8px;
  position: relative;
  cursor: pointer;
  line-height: 23px;

  &.on{
	  border-color: #1890FF;
  }

  .name {
    font-size: 14px;
    font-weight: 600;
    color: rgba(0, 0, 0, 0.85);
  }

  .title {
    font-size: 12px;
    font-weight: 400;
    color: #999999;
  }

  .jiao {
    position: absolute;
    bottom: 0;
    right: 0;
    width: 0;
    height: 0;
    border-bottom: 26px solid #1890FF;
    border-left: 26px solid transparent;
  }

  .iconfont {
    position: absolute;
    bottom: -3px;
    right: 1px;
    color: #FFFFFF;
	  font-size: 12px;
  }

}
.product-type-wrap {
  margin: 0 -12px -12px 0;
}
</style>
