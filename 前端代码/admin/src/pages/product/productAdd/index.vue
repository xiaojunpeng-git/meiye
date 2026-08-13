<template>
  <div>
    <div class="i-layout-page-header">
      <PageHeader class="product_tabs" hidden-breadcrumb>
        <div slot="title" class="acea-row row-middle">
          <router-link :to="{ path: `${roterPre}/product/product_list` }">
            <div class="font-sm after-line">
              <span class="iconfont iconfanhui"></span>
              <span class="pl10">返回</span>
            </div>
          </router-link>
          <span
            v-text="$route.params.id ? '编辑商品' : '添加商品'"
            class="mr20 ml16"
          ></span>
        </div>
      </PageHeader>
    </div>
    <Card :bordered="false" dis-hover class="ivu-mt">
      <div class="new_tab">
        <Tabs v-model="currentTab">
          <TabPane
            v-for="(item, index) in filterHeadTab"
            :key="index"
            :label="item.title"
            :name="item.name"
          ></TabPane>
        </Tabs>
      </div>
      <Form
        class="formData mt20"
        ref="formData"
        :model="formData"
        :label-width="170"
        label-position="right"
        @submit.native.prevent
      >
        <div v-show="currentTab === '1'">
          <!-- 商品基础信息的设置 -->
          <FormItem label="商品类型：">
            <Select
              :value="formData.product_type"
              disabled
              v-width="'50%'"
            >
              <Option :value="0">产品</Option>
              <Option :value="1">卡密/网盘</Option>
              <Option :value="3">虚拟商品</Option>
              <Option :value="4">次卡商品</Option>
              <Option :value="5">卡项</Option>
              <Option :value="6">项目</Option>
            </Select>
          </FormItem>
          <productBaseSet
            ref="productBaseSet"
            :successData="success"
            :baseInfo="formData"
            :currentTab="currentTab"
            @modalPicTap="modalPicTap"
            @sourceChange="sourceChange"
          ></productBaseSet>
          <div v-if="Number(formData.product_type) === 6" class="performance-rule-panel">
            <FormItem label="手工费：">
              <InputNumber
                v-model="performanceRule.labor_configured_unit_amount"
                :min="0"
                :precision="0"
                style="width: 220px"
              />
            </FormItem>
            <FormItem v-if="Number($route.params.id || 0) > 0" label="">
              <Button type="primary" :loading="performanceRuleSaving" @click="savePerformanceRule">保存手工费</Button>
              <span class="tips ml10">版本 {{ performanceRule.version || 1 }}</span>
            </FormItem>
            <div v-else class="tips performance-rule-tip">请先保存商品，再进入编辑页配置手工费。</div>
          </div>
        </div>
        <div v-show="currentTab === '2'">
          <!-- 商品规格的设置 -->
          <FormItem label="商品规格：" v-if="formData.product_type != 0 && formData.product_type != 4 && formData.product_type != 5 && formData.product_type != 6">
            <div class="flex-y-center">
              <RadioGroup v-model="formData.spec_type">
                <Radio :disabled="disabledSpecType" :label="0" class="radio"
                  >单规格</Radio
                >
                <Radio :disabled="disabledSpecType" :label="1">多规格</Radio>
              </RadioGroup>
              <Dropdown v-if="formData.spec_type == 1 && ruleList.length" transfer @on-click="confirm">
                <span class="pl-14 text-blue pointer">
                  选择规格模板
                  <Icon type="ios-arrow-down"></Icon>
                </span>
                <template #list>
                  <DropdownMenu>
                    <DropdownItem
                      v-for="(item, index) in ruleList"
                      :key="index"
                      :name="item.rule_name"
                      >{{ item.rule_name }}</DropdownItem
                    >
                  </DropdownMenu>
                </template>
              </Dropdown>
            </div>
            <div class="tips" v-show="disabledSpecType">
              商品有活动开启，无法切换商品规格
            </div>
          </FormItem>

          <!-- 多规格设置 -->
          <div v-if="formData.spec_type == 1">
            <FormItem label="商品规格：">
              <div class="specifications" v-show="attrs.length">
                <draggable
                  group="specifications"
                  :list="attrs"
                  handle=".move-icon"
                  @end="onMoveSpec"
                  animation="300"
                >
                  <div
                    class="specifications-item pointer active"
                    v-for="(item, index) in attrs"
                    :key="index"
                    @click="changeCurrentIndex(index)"
                  >
                    <div class="move-icon">
                      <span class="iconfont icondrag2"></span>
                    </div>
                    <i
                      class="del ivu-icon ivu-icon-md-close-circle"
                      @click="handleRemoveRole(index)"
                    />
                    <div class="specifications-item-box">
                      <div class="lineBox"></div>
                      <div class="specifications-item-name mb18">
                        <Input
                          v-model="item.value"
                          placeholder="规格名称"
                          :maxlength="30"
                          show-word-limit
                          @on-change="attrChangeValue(index, item.value)"
                          @on-focus="handleFocus(item.value)"
                          class="specifications-item-name-input"
                        ></Input>
                        <Checkbox
                          class="ml20"
                          v-model="item.add_pic"
                          :disabled="!item.add_pic && !canSel"
                          :true-value="1"
                          :false-value="0"
                          @on-change="(e) => addPic(e, index)"
                          >添加规格图</Checkbox
                        >
                        <el-tooltip
                          class="item"
                          effect="dark"
                          content="添加规格图片, 仅支持打开一个(建议尺寸:800*800)"
                          placement="right"
                        >
                          <Icon type="md-information-circle" />
                        </el-tooltip>
                      </div>
                      <div class="rulesBox ml30">
                        <draggable
                          class="item"
                          :list="item.detail"
                          handle=".icondragVal"
                          @end="onMoveSpec"
                        >
                          <div
                            v-for="(j, indexn) in item.detail"
                            :key="indexn"
                            class="mr10 spec drag relative"
                          >
                            <i
                              class="del2 ivu-icon ivu-icon-md-close-circle"
                              @click="
                                handleRemove2(item.detail, indexn, j.value)
                              "
                            />
                            <Input
                              v-model="j.value"
                              placeholder="规格值"
                              :maxlength="30"
                              show-word-limit
                              @on-change="attrDetailChangeValue(j.value, index)"
                              @on-focus="handleFocus(j.value)"
                              @on-blur="handleBlur()"
							  class="specifications-item-val-input"
                            >
                              <template slot="prefix">
                                <span class="icondragVal iconfont icondrag2"></span>
                              </template>
                            </Input>
                            <div class="img-popover" v-if="item.add_pic">
                              <div class="popper-arrow"></div>
                              <div
                                class="popper"
                                @click="handleSelImg(j, indexn, index)"
                              >
                                <img class="img" v-if="j.pic" :src="j.pic" />
                                <i v-else class="el-icon-plus"></i>
                              </div>
                              <i
                                v-if="j.pic"
                                class="img-del el-icon-error"
                                @click="handleRemoveImg(j)"
                              ></i>
                            </div>
                          </div>
                          <el-popover
                            :ref="'popoverRef_' + index"
                            placement=""
                            width="210"
                            trigger="click"
                            @after-enter="handleShowPop(index)"
							style="z-index: 9;"
                            :style="{'min-height': (item.add_pic == 1 && item.detail.length) ? '121px' : ''}"
                          >
                            <Input
                              :ref="'inputRef_' + index"
                              placeholder="请输入规格值"
                              :maxlength="30"
                              show-word-limit
                              v-model="formDynamic.attrsVal"
                              @keyup.enter.native="
                                createAttr(formDynamic.attrsVal, index)
                              "
                              @on-blur="createAttr(formDynamic.attrsVal, index)"
                            >
                            </Input>
                            <a class="addfont" slot="reference">添加规格值</a>
                          </el-popover>
                        </draggable>
                      </div>
                    </div>
                  </div>
                </draggable>
              </div>
              <Button v-if="attrs.length < (formData.product_type == 6?1:4)" @click="handleAddRole()"
                >添加新规格</Button
              >
              <Button
                v-if="attrs.length"
                class="save-btn text-wlll-2d8cf0"
                @click="handleSaveAsTemplate()"
                >另存为模板</Button
              >
            </FormItem>
			<FormItem label="时段划分：" required prop="reservation_time_type" v-if="false">
			  <RadioGroup v-model="formData.reservation_time_type" @on-change='timeDivide'>
			    <Radio :label="1">
			      <Icon type="social-apple"></Icon>
			      <span>自动划分</span>
			    </Radio>
			    <Radio :label="2">
			      <Icon type="social-android"></Icon>
			      <span>自定义划分</span>
			    </Radio>
			  </RadioGroup>
			  <div class="fs-12 text--w111-999" v-if="formData.reservation_time_type==2">
			    请依照时间的先后顺序添加时段，并且时段的开始时间不得早于上一个时段的结束时间。
			  </div>
			  <div class="w-full pt-24 pb-24 pl-20 pr-20 bg-w111-F9F9F9 mt-10 rd-4px" v-if="formData.reservation_time_type == 1">
				<span>起止时间：</span>
				<TimePicker v-model="formData.reservation_times" format="HH:mm" type="timerange" placement="bottom-end" placeholder="请选择时间" class="w-160"/>
				<span class="ml20">时间跨度：</span>
				<Input v-model="formData.reservation_time_interval" type='number' class="w-160" @on-change="handleInputChange" @on-keypress="handleKeyPress">
					<template #suffix>
					    <i class="fs-12 text-wlll-909399 fs-normal">分钟</i>
					</template>
				</Input>
				<span class="ml-20px">支持设置10-1440分钟</span>
				<Button class="ml-20px" @click="setTime">设置</Button>
				<div class="mt-14 acea-row" v-if="reservationTime.length">
					<Checkbox
					    size="small"
						v-model="timeCheckAll"
					    @on-change="handleCheckAll">全选</Checkbox>
					<CheckboxGroup class='flex-1' v-model="timeCheckAllGroup" size="small" @on-change="checkAllGroupChange">
					    <Checkbox class="ml-20px" :label="item.start" v-for="(item,index) in reservationTime">
							{{item.start}}-{{item.end}}
						</Checkbox>
					</CheckboxGroup>
				</div>
			  </div>
			  <div class="w-full pt-24 pb-4 pl-20 pr-20 bg-w111-F9F9F9 mt-10 rd-4px acea-row row-middle" v-else>
				<div class="customize-time relative w-160 mr-20 mb-20" v-for="(item,index) in formData.customize_time_period" :key="index">
					<TimePicker v-model="formData.customize_time_period[index]" @on-change='customizeTime' :clearable='false' format="HH:mm" type="timerange" placement="bottom-end" placeholder="请选择时间" class="w-160"/>
					<div @click.stop="closeTime(index)" v-if="formData.customize_time_period.length>1" class="hidden w-14 lh-14px bg--w111-ccc rd-7px absolute text-center t-f5 r-f5 z-1">
						<span class="iconfont iconguanbi fs-12 text--w111-fff"></span>
					</div>
				</div>
			    <span class="ml-10px fs-12 text-wlll-2d8cf0 cup mb-20" @click="addTime" v-if="formData.customize_time_period.length<24">添加时段（{{formData.customize_time_period.length}}/24）</span>
				<Button class="ml-20px mb-20" @click="setCustomizeTime">设置</Button>
			  </div>
			</FormItem>
            <FormItem
              label="商品属性："
              prop=""
              v-show="manyFormValidate.length"
            >
              <el-table
                size="small"
                :data="manyFormValidate"
                style="width: 100%"
                :cell-class-name="tableCellClassName"
                :span-method="objectSpanMethod"
                :header-row-class-name="headerRowClassName"
                border
              >
                <el-table-column
                  v-for="(item, index) in specTableHeader"
                  :key="item.slot || item.key || item.title || index"
                  :label="item.title"
                  :min-width="item.minWidth || '100'"
                  :fixed="item.fixed"
                  :label-class-name="item.labelClassName"
                >
				  <template slot="header" slot-scope="scope">
					<div class="acea-row row-middle">
						<div>
							<span>{{item.title}}</span>
							<!-- <div class="fs-10 mt-f5" v-if="item.tips">{{item.tips}}</div> -->
						</div>
						<el-popover
						  v-if="item.slot == 'price_range_min' || item.slot == 'price_range_max'"
						  :ref="'popoverRef_'+item.slot"
						  placement="top"
						  width="254"
						  trigger="click"
						>
						  <div class="pop-title">批量设置</div>
						  <div class="mt-14">
						    <RadioGroup v-model="priceSetType">
						      <Radio :label="0">指定金额</Radio>
							  <Radio :label="3" v-if="item.slot == 'price_range_max'">加价</Radio>
							  <Radio :label="2" v-if="item.slot == 'price_range_min'">减价</Radio>
						      <Radio :label="1" v-if="item.slot == 'price_range_min'">折扣</Radio>
						    </RadioGroup>
						  </div>
						  <div class="mt-14 flex-between-center">
						    <Input
						      type="number"
						      @on-change="priceReplace"
						      class="w-85"
						      v-model="priceSetNum"
						    >
						      <template #suffix>
						        <span class="inline-block lh-32px" v-show="priceSetType != 1">元</span>
								<span class="inline-block lh-32px" v-show="priceSetType == 1">%</span>
						      </template>
						    </Input>
						    <div class="flex-1 acea-row row-right row-middle">
						      <Button @click="closePriceSet(item.slot)">取消</Button>
						      <Button
						        type="primary"
						        class="ml-12"
						        @click="priceSetConfirm(item.slot)"
						        >确认</Button
						      >
						    </div>
						  </div>
						  <span
						    class="iconfont iconbianji1"
						    slot="reference"
						  ></span>
						</el-popover>
					</div>
				  </template>
                  <template slot-scope="scope">
                    <!-- 批量设置 -->
                    <template v-if="scope.$index == 0">
                      <template v-if="item.key">
                        <div
                          v-if="
                            attrs.length &&
                            attrs[scope.column.index] &&
                            manyFormValidate.length
                          "
                        >
                          <el-select
                            size="small"
                            v-model="oneFormBatch[0][item.title]"
                            :placeholder="`请选择${item.title}`"
                            clearable
                          >
                            <el-option
                              v-for="val in attrs[scope.column.index].detail"
                              :key="val.value"
                              :label="val.value"
                              :value="val.value"
                            >
                            </el-option>
                          </el-select>
                        </div>
                      </template>
                      <template v-else-if="item.slot === 'pic'">
                        <div
                          class="pictrueBox small flex-center"
                          @click="setAllPic()"
                        >
                          <div class="pictrue" v-if="oneFormBatch[0].pic">
                            <img v-lazy="oneFormBatch[0].pic" />
                          </div>
                          <div
                            class="upLoad acea-row row-center-wrapper"
                            v-else
                          >
                            <Icon type="ios-camera-outline" size="26" />
                          </div>
                        </div>
                      </template>
                      <template v-else-if="item.slot === 'price'">
                        <InputNumber
                          :controls="false"
                          v-model="oneFormBatch[0].price"
                          :min="0"
                          :max="9999999999"
                          :step="1"
                          class="priceBox"
                          clearable
                        ></InputNumber>
                      </template>
					  <template v-if="item.slot === 'price_range_min'">--</template>
					  <template v-if="item.slot === 'price_range_max'">--</template>
                      <template v-if="item.slot === 'settle_price'">
						<div v-if="$route.params.id">--</div>
                        <InputNumber
						  v-else
                          :controls="false"
                          v-model="oneFormBatch[0].settle_price"
                          :min="0"
                          :max="9999999999"
                          :step="1"
                          class="priceBox"
                        ></InputNumber>
                      </template>
                      <template v-else-if="item.slot === 'cost'">
                        <InputNumber
                          :controls="false"
                          v-model="oneFormBatch[0].cost"
                          :min="0"
                          :max="9999999999"
                          :step="1"
                          class="priceBox"
                          clearable
                        ></InputNumber>
                      </template>
                      <template v-else-if="item.slot === 'ot_price'">
                        <InputNumber
                          :controls="false"
                          v-model="oneFormBatch[0].ot_price"
                          :min="0"
                          :step="1"
                          class="priceBox"
                          clearable
                        ></InputNumber>
                      </template>
                      <template v-else-if="item.slot === 'stock'">
						<div v-if="formData.product_type == 6 || (formData.product_type != 1 && formData.id)">--</div>
                        <InputNumber
						  v-else
                          :controls="false"
                          v-model="oneFormBatch[0].stock"
                          :disabled="formData.virtual_type == 1"
                          :min="0"
                          :max="9999999999"
                          class="priceBox"
                          clearable
                        ></InputNumber>
                      </template>
					  <template v-else-if="item.slot === 'inventory'">
						<Input v-model="oneFormBatch[0].inventory" type="number" @on-change="handleChange($event)" @on-keypress="handleKeyPress">
							<Select v-model="oneFormBatch[0].pm" slot="prepend" style="width: 70px" transfer>
								<Option :value="1" class="fs-12">入库</Option>
								<Option :value="0" class="fs-12">出库</Option>
							</Select>
						</Input>
					  </template>
                      <template v-else-if="item.slot === 'fictitious'">
                        --
                      </template>
                      <template v-else-if="item.slot === 'code'">
                        <Input v-model="oneFormBatch[0].code"></Input>
                      </template>
                      <template v-else-if="item.slot === 'bar_code'">
                        <Input
                          v-model="oneFormBatch[0].bar_code"
                        ></Input>
                      </template>
                      <template v-else-if="item.slot === 'weight'">
                        <InputNumber
                          :controls="false"
                          v-model="oneFormBatch[0].weight"
                          :step="0.1"
                          :min="0"
                          :max="9999999999"
                          class="priceBox"
                          clearable
                        ></InputNumber>
                      </template>
                      <template v-else-if="item.slot === 'volume'">
                        <InputNumber
                          :controls="false"
                          v-model="oneFormBatch[0].volume"
                          :step="0.1"
                          :min="0"
                          :max="9999999999"
                          class="priceBox"
                          clearable
                        ></InputNumber>
                      </template>
                      <template v-else-if="item.slot === 'stock_unit'">
                        <Input
                          v-model="oneFormBatch[0].stock_unit"
                          placeholder="如 片/ml/g"
                        ></Input>
                      </template>
                      <template v-else-if="item.slot === 'sale_unit'">
                        <Input
                          v-model="oneFormBatch[0].sale_unit"
                          placeholder="如 盒/瓶"
                        ></Input>
                      </template>
                      <template v-else-if="item.slot === 'unit_convert'">
                        <InputNumber
                          :controls="false"
                          v-model="oneFormBatch[0].unit_convert"
                          :min="1"
                          :max="99"
                          :precision="0"
                          class="priceBox"
                        ></InputNumber>
                      </template>
                      <template v-else-if="item.slot === 'decimal_scale'">
                        <InputNumber
                          :controls="false"
                          v-model="oneFormBatch[0].decimal_scale"
                          :min="0"
                          :max="4"
                          :precision="0"
                          class="priceBox"
                        ></InputNumber>
                      </template>
                      <template v-else-if="item.slot === 'selected_spec'">
                        --
                      </template>
                      <template v-else-if="item.slot === 'action'">
                        <a @click="batchAdd">批量修改</a>
                        <Divider type="vertical" />
                        <a @click="batchDel">清空</a>
                      </template>
                    </template>
                    <template v-else>
                      <template v-if="item.key">
                        <div>
                          <span>{{ scope.row.detail[item.key] }}</span>
                        </div>
                      </template>
                      <template v-if="item.slot === 'pic'">
                        <div
                          class="pictrueBox small flex-center"
                          @click="setAttrPic(scope.$index)"
                        >
                          <div
                            class="pictrue"
                            v-if="manyFormValidate[scope.$index].pic"
                          >
                            <img v-lazy="manyFormValidate[scope.$index].pic" />
                          </div>
                          <div
                            class="upLoad acea-row row-center-wrapper"
                            v-else
                          >
                            <Icon type="ios-camera-outline" size="26" />
                          </div>
                        </div>
                      </template>
                      <template v-if="item.slot === 'price'">
                        <InputNumber
                          :controls="false"
                          v-model="manyFormValidate[scope.$index].price"
                          :min="0"
                          :max="9999999999"
                          :step="1"
                          class="priceBox"
						  @on-change='changeManyPrice(scope.$index,$event)'
                        ></InputNumber>
                      </template>
					  <template v-if="item.slot === 'price_range_min'">
					    <InputNumber
					      :controls="false"
					      v-model="manyFormValidate[scope.$index].price_range_min"
					      :min="0"
					      :max="9999999999"
					      class="priceBox"
					    ></InputNumber>
						<div
						  class="flex-x-center red"
						  v-if="(manyFormValidate[scope.$index].price_range_max == null ||
						  manyFormValidate[scope.$index].price_range_max == 0) &&
						  manyFormValidate[scope.$index].price_range_min > 0 &&
						  manyFormValidate[scope.$index].price < manyFormValidate[scope.$index].price_range_min
						  "
						>
						  最小值应小于等于售价
						</div>
						<div
						  class="flex-x-center red"
						  v-if="manyFormValidate[scope.$index].price_range_min > 0 &&
						  manyFormValidate[scope.$index].price_range_max > 0 &&
						  manyFormValidate[scope.$index].price < manyFormValidate[scope.$index].price_range_min
						  "
						>
						  最小值应小于等于售价
						</div>
					  </template>
					  <template v-if="item.slot === 'price_range_max'">
					    <InputNumber
					      :controls="false"
					      v-model="manyFormValidate[scope.$index].price_range_max"
					      :min="0"
					      :max="9999999999"
					      class="priceBox"
					    ></InputNumber>
						<div
						  class="flex-x-center red"
						  v-if="(manyFormValidate[scope.$index].price_range_min==null ||
						  manyFormValidate[scope.$index].price_range_min==0) &&
						  manyFormValidate[scope.$index].price_range_max > 0 &&
						  manyFormValidate[scope.$index].price > manyFormValidate[scope.$index].price_range_max
						  "
						>
						  最大值应大于等于售价
						</div>
						<div
						  class="flex-x-center red"
						  v-if="manyFormValidate[scope.$index].price_range_min > 0 &&
						  manyFormValidate[scope.$index].price_range_max > 0 &&
						  manyFormValidate[scope.$index].price > manyFormValidate[scope.$index].price_range_max
						  "
						>
						  最大值应大于等于售价
						</div>
					  </template>
                      <template v-if="item.slot === 'settle_price'">
                        <InputNumber
						  :disabled="$route.params.id"
                          :controls="false"
                          v-model="manyFormValidate[scope.$index].settle_price"
                          :min="0"
                          :max="9999999999"
                          :step="1"
                          class="priceBox"
                        ></InputNumber>
                      </template>
                      <template v-else-if="item.slot === 'cost'">
                        <InputNumber
                          :controls="false"
                          v-model="manyFormValidate[scope.$index].cost"
                          :min="0"
                          :max="9999999999"
                          :step="1"
                          class="priceBox"
                        ></InputNumber>
                      </template>
                      <template v-else-if="item.slot === 'ot_price'">
                        <InputNumber
                          :controls="false"
                          v-model="manyFormValidate[scope.$index].ot_price"
                          :min="0"
                          :max="9999999999"
                          :step="1"
                          class="priceBox"
                        ></InputNumber>
                      </template>
                      <template v-else-if="item.slot === 'stock'">
						<div v-if="formData.product_type == 6" @click="setTimeStock(scope.$index)" class="text-wlll-2d8cf0 cup">设置预约数量</div>
						<div v-else-if="formData.product_type == 0 && formData.id">
							<div class="stock-input-box on">{{ manyFormValidate[scope.$index].stock }}</div>
						</div>
						<div v-else-if="canEditInitialStock">
							<InputNumber
							  :controls="false"
							  v-model="manyFormValidate[scope.$index].stock"
							  :min="0"
							  :max="9999999999"
							  :precision="stockInputPrecision"
							  class="priceBox"
							></InputNumber>
						</div>
						<div v-else-if="isCopyMode && formData.product_type == 0">
							<div class="stock-input-box on">0</div>
						</div>
						<div v-else>
							<InputNumber
							  :controls="false"
							  v-model="manyFormValidate[scope.$index].stock"
							  :disabled="formData.product_type == 1"
							  :min="0"
							  :max="9999999999"
							  :precision="0"
							  class="priceBox"
							></InputNumber>
						</div>
                      </template>
					 <template v-else-if="item.slot === 'inventory'">
						  <Input v-model="manyFormValidate[scope.$index].inventory" type="number" @on-change="handleChange($event,scope.$index,1)" @on-keypress="handleKeyPress">
						  	<Select v-model="manyFormValidate[scope.$index].pm" slot="prepend" style="width: 70px" transfer>
						  		<Option :value="1" class="fs-12">入库</Option>
						  		<Option :value="0" class="fs-12">出库</Option>
						  	</Select>
						  </Input>
					  </template>
                      <template v-else-if="item.slot === 'code'">
                        <Input
                          v-model="manyFormValidate[scope.$index].code"
                        ></Input>
                      </template>
                      <template v-else-if="item.slot === 'bar_code'">
                        <Input
                          v-model="
                            manyFormValidate[scope.$index].bar_code
                          "
                        ></Input>
                      </template>
                      <template v-else-if="item.slot === 'weight'">
                        <InputNumber
                          :controls="false"
                          v-model="manyFormValidate[scope.$index].weight"
                          :min="0"
                          :max="9999999999"
                          class="priceBox"
                        ></InputNumber>
                      </template>
                      <template v-else-if="item.slot === 'volume'">
                        <InputNumber
                          :controls="false"
                          v-model="manyFormValidate[scope.$index].volume"
                          :min="0"
                          :max="9999999999"
                          class="priceBox"
                        ></InputNumber>
                      </template>
                      <template v-else-if="item.slot === 'stock_unit'">
                        <Input
                          v-model="manyFormValidate[scope.$index].stock_unit"
                          placeholder="如 片/ml/g"
                        ></Input>
                      </template>
                      <template v-else-if="item.slot === 'sale_unit'">
                        <Input
                          v-model="manyFormValidate[scope.$index].sale_unit"
                          placeholder="如 盒/瓶"
                        ></Input>
                      </template>
                      <template v-else-if="item.slot === 'unit_convert'">
                        <InputNumber
                          :controls="false"
                          v-model="manyFormValidate[scope.$index].unit_convert"
                          :min="1"
                          :max="99"
                          :precision="0"
                          class="priceBox"
                        ></InputNumber>
                      </template>
                      <template v-else-if="item.slot === 'decimal_scale'">
                        <InputNumber
                          :controls="false"
                          v-model="manyFormValidate[scope.$index].decimal_scale"
                          :min="0"
                          :max="4"
                          :precision="0"
                          class="priceBox"
                        ></InputNumber>
                      </template>
                      <template v-else-if="item.slot === 'fictitious'">
                        <Button
                          v-if="
                            (!scope.row.virtual_list ||
                              !scope.row.virtual_list.length) &&
                            !scope.row.stock
                          "
                          @click="addVirtual(scope.$index, 'manyFormValidate')"
                          >添加卡密</Button
                        >
                        <span
                          v-else
                          class="seeCatMy"
                          @click="
                            seeVirtual(
                              manyFormValidate[scope.$index],
                              'manyFormValidate',
                              scope.$index
                            )
                          "
                          >已设置</span
                        >
                      </template>

                      <template v-else-if="item.slot === 'selected_spec'">
                        <Switch
                          v-model="
                            manyFormValidate[scope.$index].is_default_select
                          "
                          :true-value="1"
                          :false-value="0"
                          @on-change="changeDefaultSelect(scope.$index)"
                        />
                      </template>
                      <template v-else-if="item.slot === 'action'">
                        <Switch
                          size="large"
                          v-model="manyFormValidate[scope.$index].is_show"
						  :disabled='manyFormValidate[scope.$index].is_default_select'
                          :true-value="1"
                          :false-value="0"
                          @on-change="changeDefaultShow(scope.$index)"
                        >
                          <template #open>
                            <span>显示</span>
                          </template>
                          <template #close>
                            <span>隐藏</span>
                          </template>
                        </Switch>
                      </template>
                    </template>
                  </template>
                </el-table-column>
              </el-table>
            </FormItem>
          </div>
          <!-- 单规格设置 -->
          <div
            v-if="
              formData.spec_type === 0 &&
              [0, 1, 3].includes(formData.product_type)
            "
          >
            <FormItem v-if="formData.product_type === 0" label="规格名称：">
              <Input
                v-model.trim="formData.single_spec_name"
                :maxlength="30"
                placeholder="请输入规格名称"
                v-width="'50%'"
              ></Input>
            </FormItem>
            <FormItem label="图片：" required>
              <div class="pictrueBox inline-block" @click="attrPicTap()">
                <div class="pictrue" v-if="formData.attr.pic">
                  <img v-lazy="formData.attr.pic" />
                </div>
                <div class="upLoad acea-row row-center-wrapper" v-else>
                  <Icon type="ios-camera-outline" size="26" />
                </div>
              </div>
            </FormItem>
            <FormItem label="售价：" prop="price" :rules="ruleValidate.price" key="price1">
              <InputNumber
                v-model="formData.attr.price"
                :min="0"
                :max="99999999"
                :step="1"
                v-width="'50%'"
				@on-change='changePrice'
              ></InputNumber>
            </FormItem>
			<FormItem label="调价区间：" v-if="formData.product_type == 0 && merchantType !=1">
			  <div class="acea-row row-middle">
				  <InputNumber
				    v-model="formData.attr.price_range_min"
				    :min="0"
				    :max="99999999"
				    v-width="'23%'"
				  ></InputNumber>
				  <div class="w-4-full text-center">~</div>
				  <InputNumber
				    v-model="formData.attr.price_range_max"
				    :min="0"
				    :max="99999999"
				    v-width="'23%'"
				  ></InputNumber>
			  </div>
			  <div class="tips">若最低价=售价=最高价，则不允许门店改价；若单边值为0，则仅限制最高价或者最低价；其余情况，门店可在区间内调价。</div>
			</FormItem>
            <FormItem label="结算价：" v-if="merchantType == 2 || goodsSource == 2" prop="settle_price" :rules="ruleValidate.settle_price">
              <InputNumber
                v-model="formData.attr.settle_price"
					:disabled="$route.params.id"
                :min="0"
                :max="99999999"
                :step="1"
                v-width="'50%'"
              ></InputNumber>
            </FormItem>
            <FormItem label="成本价：">
              <InputNumber
                v-model="formData.attr.cost"
                :min="0"
                :max="99999999"
                :step="1"
                v-width="'50%'"
              ></InputNumber>
            </FormItem>
            <FormItem label="划线价：">
              <InputNumber
                v-model="formData.attr.ot_price"
                :min="0"
                :max="99999999"
                :step="1"
                v-width="'50%'"
              ></InputNumber>
            </FormItem>
			<FormItem label="当前库存：" v-if="formData.id && formData.product_type == 0" prop="stock">
				<div class="stock-input-box">{{ formData.attr.stock }}</div>
			</FormItem>
            <FormItem
              label="初始库存："
              v-else-if="canEditInitialStock"
              prop="stock"
              key="stock1"
            >
              <InputNumber
                v-model="formData.attr.stock"
                :min="0"
                :max="99999999"
                :precision="stockInputPrecision"
                v-width="'50%'"
              ></InputNumber>
              <div class="tips">仅新建产品可填；保存后通过「初始入库」入账，编辑商品不能改库存</div>
            </FormItem>
            <FormItem
              label="库存："
              v-else-if="formData.product_type != 0 && formData.product_type != 1 && formData.product_type != 5 && formData.product_type != 6"
              prop="stock"
              :rules="ruleValidate.stock"
              key="stock1b"
            >
              <InputNumber
                v-model="formData.attr.stock"
                :min="0"
                :max="99999999"
                :disabled="openErp"
                :precision="0"
                v-width="'50%'"
              ></InputNumber>
            </FormItem>
            <FormItem label="商品编号：">
              <Input
                v-model.trim="formData.attr.code"
                v-width="'50%'"
                placeholder="请输入商品编码"
              ></Input>
            </FormItem>
            <FormItem label="商品条形码：">
              <Input
                v-model.trim="formData.attr.bar_code"
                v-width="'50%'"
                placeholder="请输入商品条形码"
              ></Input>
            </FormItem>
            <FormItem label="重量（KG）：" v-if="formData.product_type == 0">
              <InputNumber
                v-model="formData.attr.weight"
                :min="0"
                :max="99999999"
                v-width="'50%'"
              ></InputNumber>
              <div class="tips">该信息将影响同城配送中配送费的计算，务必准确填写</div>
            </FormItem>
            <FormItem label="体积(m³)：" v-if="formData.product_type == 0">
              <InputNumber
                v-model="formData.attr.volume"
                :min="0"
                :max="99999999"
                v-width="'50%'"
              ></InputNumber>
            </FormItem>
            <FormItem label="院装耗材单位：" v-if="showSalonUnitFields">
              <Input
                v-model.trim="formData.attr.stock_unit"
                v-width="'50%'"
                placeholder="如 片/ml/g，空则默认与销售/包装单位相同"
              ></Input>
              <div class="tips">库存与院装配方按此单位记账；为空时默认等于销售/包装单位</div>
            </FormItem>
            <FormItem label="销售单位换算数：" v-if="showSalonUnitFields">
              <InputNumber
                v-model="formData.attr.unit_convert"
                :min="1"
                :max="99"
                :precision="0"
                v-width="'50%'"
              ></InputNumber>
              <div class="tips">示例：1 盒 = 10 片则填 10；不用换算填 1。销售/包装单位取基本信息中的设置。最小单位的换算不能超过99，意思就是一盒里面最多只能有99片。</div>
            </FormItem>
            <template v-if="formData.product_type == 1">
              <FormItem label="卡密设置：">
                <Button v-if="!formData.attr.virtual_list.length && !formData.attr.stock"
                  @click="addVirtual(0, 'attr')">添加卡密</Button>
                <span
                  v-else
                  class="seeCatMy"
                  @click="seeVirtual(formData.attr, 'attr')"
                  >已设置</span
                >
              </FormItem>
            </template>
          </div>
          <div v-if="formData.product_type == 5 || (formData.product_type == 4 && formData.spec_type == 0)">
            <FormItem v-if="formData.product_type == 5" label="卡项规则：" required key="card_rule_type">
              <RadioGroup :value="formData.card_rule_type" @on-change="requestCardRuleChange">
                <Radio label="normal">普通卡</Radio>
                <Radio label="choice_kind">任选种数卡</Radio>
                <Radio label="choice_count">任选次数卡</Radio>
                <Radio label="time">时间卡</Radio>
              </RadioGroup>
              <div class="card-rule-summary" v-if="cardRuleSummary">{{ cardRuleSummary }}</div>
            </FormItem>
            <FormItem v-if="formData.product_type == 5" label="图片：" required>
              <div class="pictrueBox inline-block" @click="attrPicTap()">
                <div class="pictrue" v-if="formData.attr.pic">
                  <img v-lazy="formData.attr.pic" />
                </div>
                <div class="upLoad acea-row row-center-wrapper" v-else>
                  <Icon type="ios-camera-outline" size="26" />
                </div>
              </div>
            </FormItem>
            <FormItem v-if="formData.product_type != 5" label="核销次数：" prop="write_times" :rules="ruleValidate.write_times" key="write_times">
              <InputNumber
                v-model="formData.attr.write_times"
                :min="1"
                :max="99999999"
                :precision="0"
                v-width="'50%'"
                placeholder="请输入核销次数"
              ></InputNumber>
            </FormItem>
            <FormItem label="核销时效：" key="write_valid">
              <RadioGroup v-model="formData.attr.write_valid">
                <Radio :label="1" v-if="formData.product_type != 5 || formData.card_rule_type != 'time'">永久有效</Radio>
                <Radio :label="2">{{ formData.product_type == 5 ? '开卡后若干天有效' : '购买后几天有效' }}</Radio>
                <Radio :label="3">固定有效期</Radio>
              </RadioGroup>
              <div class="tips" v-if="formData.product_type == 5 && formData.card_rule_type == 'time'">时间卡不允许永久有效</div>
              <div class="tips" v-if="formData.attr.write_valid == 3">超过有效期后，商品会自动下架放入仓库</div>
            </FormItem>
            <FormItem
              label=""
              prop="freight"
              v-if="formData.attr.write_valid == 2"
            >
              <div class="acea-row row-middle">
                <InputNumber
                  :min="1"
                  :precision="0"
                  v-model="formData.attr.days"
                  placeholder="请输入有效天数"
                  v-width="'50%'"
                />
                <span class="ml10">天</span>
              </div>
            </FormItem>
            <FormItem
              label=""
              prop="freight"
              v-if="formData.attr.write_valid == 3"
            >
              <div class="acea-row row-middle">
                <DatePicker
                  :editable="false"
                  type="datetimerange"
                  format="yyyy-MM-dd HH:mm:ss"
                  placeholder="请选择固定有效期"
                  v-width="'50%'"
                  @on-change="onchangeTime"
                  v-model="section_time"
                ></DatePicker>
              </div>
            </FormItem>
            <FormItem label="售价：" prop="price" :rules="ruleValidate.price" key="price2">
              <InputNumber
                v-model="formData.attr.price"
                :min="0"
                :max="99999999"
                :step="1"
                v-width="'50%'"
				@on-change='changePrice'
              ></InputNumber>
            </FormItem>
			<FormItem label="调价区间：" v-if="merchantType !=1">
			  <div class="acea-row row-middle">
				  <InputNumber
				    v-model="formData.attr.price_range_min"
				    :min="0"
				    :max="99999999"
				    v-width="'23%'"
				  ></InputNumber>
				  <div class="w-4-full text-center">~</div>
				  <InputNumber
				    v-model="formData.attr.price_range_max"
				    :min="0"
				    :max="99999999"
				    v-width="'23%'"
				  ></InputNumber>
			  </div>
			  <div class="tips">若最低价=售价=最高价，则不允许门店改价；若单边值为0，则仅限制最高价或者最低价；其余情况，门店可在区间内调价。</div>
			</FormItem>
            <FormItem label="成本价：">
              <InputNumber
                v-model="formData.attr.cost"
                :min="0"
                :max="99999999"
                :step="1"
                v-width="'50%'"
              ></InputNumber>
            </FormItem>
            <FormItem label="划线价：">
              <InputNumber
                v-model="formData.attr.ot_price"
                :min="0"
                :max="99999999"
                :step="1"
                v-width="'50%'"
              ></InputNumber>
            </FormItem>
			<FormItem label="当前库存：" v-if="formData.id && formData.product_type == 0" prop="stock">
				<div class="stock-input-box">{{ formData.attr.stock }}</div>
			</FormItem>
            <FormItem
              label="初始库存："
              v-else-if="canEditInitialStock"
              prop="stock"
              key="stock2"
            >
              <InputNumber
                v-model="formData.attr.stock"
                :min="0"
                :max="99999999"
                :precision="stockInputPrecision"
                v-width="'50%'"
              ></InputNumber>
              <div class="tips">仅新建产品可填；保存后通过「初始入库」入账</div>
            </FormItem>
            <FormItem
              label="库存："
              v-else-if="formData.product_type != 0 && formData.product_type != 1 && formData.product_type != 5 && formData.product_type != 6"
              prop="stock"
              :rules="ruleValidate.stock"
              key="stock2b"
            >
              <InputNumber
                v-model="formData.attr.stock"
                :min="0"
                :max="99999999"
                :disabled="openErp"
                :precision="0"
                v-width="'50%'"
              ></InputNumber>
            </FormItem>
            <FormItem v-if="formData.product_type == 5 && formData.card_rule_type == 'choice_kind'" label="最多可选项目种数：" required>
              <InputNumber
                  v-model="formData.card_choice_limit"
                  :min="1"
                  :max="99999999"
                  :precision="0"
                  v-width="'50%'"
              ></InputNumber>
              <div class="tips">会员首次核销某个项目后，该项目会占用一个可选种类名额</div>
            </FormItem>
            <FormItem v-if="formData.product_type == 5 && formData.card_rule_type == 'choice_count'" label="共享总次数：" required>
              <InputNumber
                  v-model="formData.card_shared_times"
                  :min="1"
                  :max="99999999"
                  :precision="0"
                  v-width="'50%'"
              ></InputNumber>
              <div class="tips">核销任意卡内项目都会扣减同一个共享次数池</div>
            </FormItem>
            <FormItem v-if="formData.product_type == 5" label="卡内项目：" required key="cardData">
              <Button type="primary" @click="goodsModal = true">添加项目</Button>
              <Button type="primary" class="ml-10" :disabled="!cardDataSelection.length" @click="cardDataDelete">批量删除</Button>
              <Table class="mt-20" :columns="cardRuleColumns" :data="cardData" @on-selection-change="cardDataChange">
                <template slot-scope="{ row }" slot="product">
                  <div class="flex-y-center">
                    <div v-viewer>
                      <img :src="row.image" class="block w-36 h-36">
                    </div>
                    <div class="line1 ml-10" v-if="row.store_names && row.store_names != ''">{{ row.store_names }}</div>
                    <div class="line1 ml-10" v-else>{{ row.store_name }}</div>
                  </div>
                </template>
                <template slot-scope="{ row }" slot="price">
                  {{ formatWholeYuan(row.price) }}
                </template>
                <template slot-scope="{ row, index }" slot="write_times">
                  <InputNumber v-model="cardData[index].write_times" :max="99999999" :min="1" :precision="0"></InputNumber>
                </template>
                <template slot-scope="{ index }" slot="writeoff_amount">
                  <InputNumber v-model="cardData[index].writeoff_amount" :min="0" :max="9999999999" :precision="0"></InputNumber>
                </template>
                <template slot-scope="{ row, index }" slot="action">
                  <a @click="cardDataRowDelete(index)">删除</a>
                </template>
              </Table>
              <div class="card-rule-preview" v-if="cardData.length">{{ cardRulePreview }}</div>
            </FormItem>
          </div>
		  <div v-if="formData.spec_type === 0 && formData.product_type == 6">
			<FormItem label="图片：" required>
			  <div class="pictrueBox inline-block" @click="attrPicTap()">
			    <div class="pictrue" v-if="formData.attr.pic">
			      <img v-lazy="formData.attr.pic" />
			    </div>
			    <div class="upLoad acea-row row-center-wrapper" v-else>
			      <Icon type="ios-camera-outline" size="26" />
			    </div>
			  </div>
			</FormItem>
			<FormItem label="售价：" prop="price" :rules="ruleValidate.price" key="price3">
			  <InputNumber
			    v-model="formData.attr.price"
			    :min="0"
			    :max="99999999"
			    :step="1"
			    v-width="'50%'"
				@on-change='changePrice'
			  ></InputNumber>
			</FormItem>
			<FormItem label="调价区间：" v-if="merchantType !=1">
			  <div class="acea-row row-middle">
				  <InputNumber
				    v-model="formData.attr.price_range_min"
				    :min="0"
				    :max="99999999"
				    v-width="'23%'"
				  ></InputNumber>
				  <div class="w-4-full text-center">~</div>
				  <InputNumber
				    v-model="formData.attr.price_range_max"
				    :min="0"
				    :max="99999999"
				    v-width="'23%'"
				  ></InputNumber>
			  </div>
			  <div class="tips">若最低价=售价=最高价，则不允许门店改价；若单边值为0，则仅限制最高价或者最低价；其余情况，门店可在区间内调价。</div>
			</FormItem>
			<FormItem label="成本价：">
			  <InputNumber
			    v-model="formData.attr.cost"
			    :min="0"
			    :max="99999999"
			    :step="1"
			    v-width="'50%'"
			  ></InputNumber>
			</FormItem>
			<FormItem label="划线价：">
			  <InputNumber
			    v-model="formData.attr.ot_price"
			    :min="0"
			    :max="99999999"
			    :step="1"
			    v-width="'50%'"
			  ></InputNumber>
			</FormItem>
			<FormItem label="时段划分：" required prop="reservation_time_type" v-if="false">
			  <RadioGroup v-model="formData.reservation_time_type" @on-change='timeDivide'>
			    <Radio :label="1">
			      <Icon type="social-apple"></Icon>
			      <span>自动划分</span>
			    </Radio>
			    <Radio :label="2">
			      <Icon type="social-android"></Icon>
			      <span>自定义划分</span>
			    </Radio>
			  </RadioGroup>
			  <div class="fs-12 text--w111-999" v-if="formData.reservation_time_type==2">
			    请依照时间的先后顺序添加时段，并且时段的开始时间不得早于上一个时段的结束时间。
			  </div>
			  <div class="w-full pt-24 pb-24 pl-20 pr-20 bg-w111-F9F9F9 mt-10 rd-4px" v-if="formData.reservation_time_type == 1">
				<span>起止时间：</span>
				<TimePicker v-model="formData.reservation_times" format="HH:mm" type="timerange" placement="bottom-end" placeholder="请选择时间" class="w-160"/>
				<span class="ml20">时间跨度：</span>
				<Input v-model="formData.reservation_time_interval" type='number' class="w-160" @on-change="handleInputChange" @on-keypress="handleKeyPress">
					<template #suffix>
					    <i class="fs-12 text-wlll-909399 fs-normal">分钟</i>
					</template>
				</Input>
				<span class="ml-20px">支持设置10-1440分钟</span>
				<Button class="ml-20px" @click="setTime">设置</Button>
				<div class="mt-14 acea-row" v-if="reservationTime.length">
					<Checkbox
					    size="small"
						v-model="timeCheckAll"
					    @on-change="handleCheckAll">全选</Checkbox>
					<CheckboxGroup class='flex-1' v-model="timeCheckAllGroup" size="small" @on-change="checkAllGroupChange">
					    <Checkbox class="ml-20px" :label="item.start" v-for="(item,index) in reservationTime">
							{{item.start}}-{{item.end}}
						</Checkbox>
					</CheckboxGroup>
				</div>
			  </div>
			  <div class="w-full pt-24 pb-4 pl-20 pr-20 bg-w111-F9F9F9 mt-10 rd-4px acea-row row-middle" v-else>
				<div class="customize-time relative w-160 mr-20 mb-20" v-for="(item,index) in formData.customize_time_period" :key="index">
					<TimePicker v-model="formData.customize_time_period[index]" @on-change='customizeTime' :clearable='false' format="HH:mm" type="timerange" placement="bottom-end" placeholder="请选择时间" class="w-160"/>
					<div @click.stop="closeTime(index)" v-if="formData.customize_time_period.length>1" class="hidden w-14 lh-14px bg--w111-ccc rd-7px absolute text-center t-f5 r-f5 z-1">
						<span class="iconfont iconguanbi fs-12 text--w111-fff"></span>
					</div>
				</div>
			    <span class="ml-10px fs-12 text-wlll-2d8cf0 cup mb-20 mr-20" @click="addTime" v-if="formData.customize_time_period.length<24">添加时段（{{formData.customize_time_period.length}}/24）</span>
				<Button class="mb-20" @click="setCustomizeTime">设置</Button>
			  </div>
			</FormItem>
			<FormItem label="库存：" required v-if="false">
				<Table :columns="timeColumns" :data="formData.attr.reservation_time_data" class="timeTable" border no-data-text="暂无数据"
				       highlight-row no-filtered-data-text="暂无筛选结果" max-height="710" width='343'>
					<template slot-scope="{ row, index }" slot="time">
						<div class="ml-19">{{row.start}}-{{row.end}}</div>
					</template>
					<template slot-scope="{ row, index }" slot="stock">
						<InputNumber
						  v-model="formData.attr.reservation_time_data[index].stock"
						  :min="0"
						  :max="99999999"
						  :precision="0"
						  v-width="129"
						  class="ml-30"
						></InputNumber>
					</template>
				</Table>
			</FormItem>
		  </div>
        </div>
        <div v-show="currentTab === '11'">
          <!-- 库存设置（仅产品类型） -->
          <inventorySet :baseInfo="formData"></inventorySet>
        </div>
        <div v-show="currentTab === '8'">
			<!-- 预约设置 -->
			<reservationSet
			  ref="reservationSet"
			  :baseInfo="formData"
			  @weekData="weekData"
			></reservationSet>
		</div>
		<div v-show="currentTab === '3'">
          <!-- 商品详情的设置 -->
          <Row class="mb10">
            <Col span="16">
              <wangeditor
                style="width: 100%"
                :content="description"
                @editorContent="getEditorContent"
              ></wangeditor>
            </Col>
            <Col span="6" style="width: 33%">
              <div class="ifam">
                <div class="content" v-html="formData.description"></div>
              </div>
            </Col>
          </Row>
        </div>
        <div v-show="currentTab === '4'">
          <!-- 物流设置 -->
          <FormItem label="配送方式：" prop="delivery_type" :rules="ruleValidate.delivery_type">
            <CheckboxGroup v-model="formData.delivery_type" @on-change="onDeliveryTypeChange">
              <Checkbox label="1" v-if="merchantType != 1">平台配送</Checkbox>
              <Checkbox label="3">门店配送</Checkbox>
              <Checkbox label="2">到店自提</Checkbox>
            </CheckboxGroup>
          </FormItem>
          <FormItem label="配送类型：" prop="store_delivery_type" v-show="formData.delivery_type.includes('3')" :rules="ruleValidate.store_delivery_type">
            <CheckboxGroup v-model="formData.store_delivery_type">
              <Checkbox label="1">快递发货</Checkbox>
              <Checkbox label="2">同城配送</Checkbox>
            </CheckboxGroup>
            <div class="fs-12 text--w111-999 mt10">快递发货需设置运费信息。同城配送需各门店管理员与门店后台设置配送数据</div>
          </FormItem>
          <FormItem label="运费设置：">
            <RadioGroup v-model="formData.freight">
              <Radio :label="1">包邮</Radio>
              <Radio :label="2">固定邮费</Radio>
              <Radio :label="3">运费模板</Radio>
            </RadioGroup>
            <div class="fs-12 text--w111-999 mt10">勾选快递发货时，需于此处设置运费信息</div>
          </FormItem>
          <FormItem label="" prop="freight" v-if="formData.freight == 2" key="postage">
            <div class="acea-row row-middle">
              <InputNumber
                :min="0"
                v-model="formData.postage"
                placeholder="请输入金额"
                class="perW20 maxW"
              />
              <span class="ml10">元</span>
            </div>
          </FormItem>
          <FormItem label="" v-if="formData.freight == 3" key="temp_id">
            <div class="acea-row">
              <Select v-model="formData.temp_id" clearable class="perW20 maxW">
                <Option
                  v-for="(item, index) in templateList"
                  :value="item.id"
                  :key="index"
                  >{{ item.name }}</Option
                >
              </Select>
              <Button @click="editTemp" class="ml15" v-if="formData.temp_id"
                >查看运费模板</Button
              >
              <Button @click="addTemp" class="ml15" v-else>添加运费模板</Button>
            </div>
          </FormItem>
        </div>
        <div v-show="currentTab === '5'">
          <marketingSet
            ref="marketingSet"
            :successData="success"
            :baseInfo="formData"
            :productId="$route.params.id || 0"
          ></marketingSet>
        </div>
        <div v-show="currentTab === '6'">
          <otherSet
            ref="otherSet"
            :successData="success"
            :baseInfo="formData"
            @modalPicTap="modalPicTap"
          ></otherSet>
        </div>
        <div v-show="currentTab === '7'">
          <Row>
            <Col span="24">
              <FormItem label="适用门店：" :label-width="100">
                <RadioGroup v-model="formData.applicable_type" class="radioGroup">
                  <Radio :label="1">全部门店</Radio>
                  <Radio :label="2">部分门店</Radio>
                  <Radio
                    :label="0"
                    v-if="
                      formData.product_type != 4 && formData.product_type != 5 && formData.product_type != 6 &&
                      formData.delivery_type.indexOf('1') != -1
                    "
                    >仅平台适用</Radio
                  >
                </RadioGroup>
                <div class="fs-12 text--w111-999 mt10">
                  可选择将商品同步到哪些门店使用{{
                    [4, 5, 6].includes(formData.product_type) ? '' : '，选择“仅平台适用“则商品不同步任何门店'
                  }}
                </div>
              </FormItem>
              <FormItem label="库存同步：" :label-width="100" v-show="Number(formData.product_type) === 0 && formData.applicable_type && (!formData.id || $route.query.copy)">
                <Switch
                  v-model="formData.is_sync_stock"
                  :true-value="1"
                  :false-value="0"
                  size="large"
                >
                  <span slot="open">开启</span>
                  <span slot="close">关闭</span>
                </Switch>
                <div class="fs-12 text--w111-999 mt10">开启：商品创建时，您填写的库存数量将同步至所有关联门店；关闭：商品创建时，门店库存将自动设为0（需门店手动修改）。</div>
              </FormItem>
              <FormItem label="状态同步：" :label-width="100" v-show="formData.applicable_type && (!formData.id || $route.query.copy)">
                <Switch
                  v-model="formData.is_sync_show"
                  :true-value="1"
                  :false-value="0"
                  size="large"
                >
                  <span slot="open">开启</span>
                  <span slot="close">关闭</span>
                </Switch>
                <div class="fs-12 text--w111-999 mt10">开启：商品保存时的当前状态（上架/下架）将实时同步至所有关联门店；关闭：商品在门店的初始状态将强制设为「下架」，需手动在门店管理后台上架。</div>
              </FormItem>
            </Col>
            <Col
              span="24"
              class="ml10"
              v-if="formData.applicable_type == 2"
            >
              <Button type="primary" @click="addStore">添加门店</Button>
            </Col>
            <Col span="24" class="ml10">
              <div class="storeTable" v-if="formData.applicable_type == 2">
                <Table
                  :columns="StoreTableHead"
                  :data="storesList"
                  ref="table"
                  class="ivu-mt"
                  highlight-row
                  no-userFrom-text="暂无数据"
                  no-filtered-userFrom-text="暂无筛选结果"
                >
                  <template slot-scope="{ row }" slot="image">
                    <img :src="row.image" />
                  </template>
                  <template slot-scope="{ row, index }" slot="action">
                    <a @click="delte(index)">删除</a>
                  </template>
                </Table>
              </div>
            </Col>
          </Row>
        </div>
        <div v-show="currentTab === '9'">
          <cardFaceSet
            ref="cardFaceSet"
            :successData="success"
            :baseInfo="formData"
            @modalPicTap="modalPicTap"
            @handleRemove="handleRemoveCardCoverImage"
          ></cardFaceSet>
        </div>
        <div v-show="currentTab === '10'">
          <vipPriceBrokerageSet
            ref="vipPriceBrokerageSet"
            :attrs="formData.spec_type ? manyFormValidate : formData.attr"
            :attrValue="attrValue"
            :levelList="levelList"
            :levelType="levelType"
            :isVip="isVip"
            :isBrokerage="isBrokerage"
            :isSub="isSub"
            :storeBrokerageRatio="storeBrokerageRatio"
            :storeBrokerageTwo="storeBrokerageTwo"
            :successData="success"
            :productType="formData.product_type"
          ></vipPriceBrokerageSet>
        </div>
      </Form>
    </Card>
    <Card
      :bordered="false"
      dis-hover
      class="fixed-card"
      :style="{ left: `${!menuCollapse ? '200px' : isMobile ? '0' : '80px'}` }"
    >
      <div class="flex-center">
        <Button v-if="currentTab !== '1'" @click="upTab">上一步</Button>
        <Button
          type="primary"
          class="submission"
          v-if="currentTab !== '6'"
          @click="downTab('formData')"
          >下一步</Button
        >
        <Button
          type="primary"
          :disabled="openSubimit"
          class="submission"
          @click="handleSubmit()"
          v-if="$route.params.id || currentTab === '6'"
          >保存</Button
        >
      </div>
    </Card>
    <add-attr ref="addattr" @getList="productGetRule"></add-attr>
    <Modal
      v-model="carMyShow"
      scrollable
      title="添加卡密"
      closable
      width="700"
      :footer-hide="true"
      :mask-closable="false"
    >
      <add-carMy
        ref="addCarMy"
        :virtualList="virtualList"
        @changeVirtual="changeVirtual"
        @fixdBtn="fixdBtn"
        @closeCarMy="closeCarMy"
      ></add-carMy>
    </Modal>
    <freightTemplate
      :template="template"
      :merchantType="merchantType"
      v-on:changeTemplate="changeTemplate"
      ref="templates"
    ></freightTemplate>
    <!-- 门店列表弹窗 -->
    <Modal
      v-model="storeModals"
      :mask-closable="false"
      title="门店列表"
      width="900"
      closable
      scrollable
      footer-hide
    >
      <store-list @getStoreId="getStoreId"></store-list>
    </Modal>
    <!-- 图库弹窗 -->
    <Modal
      v-model="modalPic"
      :title="picTit == 'video' ? '上传视频' : '上传商品图'"
      :mask-closable="false"
      :z-index="8"
      width="960"
      closable
      scrollable
      footer-hide
    >
      <uploadPictures
        v-if="modalPic"
        :isType="picTit == 'video' ? 2 : 1"
        :isChoice="isChoice"
        @getPic="getPic"
        @getPicD="getPicD"
      ></uploadPictures>
    </Modal>
    <!-- 生成淘宝京东表单 -->
    <Modal
      v-model="modals"
      @on-cancel="cancel"
      class="Box"
      class-name="vertical-center-modal"
      scrollable
      footer-hide
      closable
      title="复制淘宝、天猫、京东、苏宁、1688"
      :mask-closable="false"
      width="800"
      height="500"
    >
      <tao-bao ref="taobaos" v-if="modals" @on-close="onClose"></tao-bao>
    </Modal>
	<stockSet ref="stockSet" :timeData='specTimeData' @modalStockSet='modalStockSet'></stockSet>
  <!-- 商品列表弹窗 -->
  <Modal v-model="goodsModal" title="项目列表" footerHide  class="paymentFooter" scrollable width="900">
    <goods-attr :chooseType="97" ref="goodSattr" v-if="goodsModal" @getProductId="getAtterId"></goods-attr>
  </Modal>
  </div>
</template>
<script>
import { mapState, mapMutations } from 'vuex';
import {
  productInfoApi,
  performanceRuleApi,
  savePerformanceRuleApi,
  checkActivityApi,
  productGetRuleApi,
  productAddApi,
  productGetTemplateApi,
  ruleAddApi,
  productBrokerage,
} from '@/api/product';
import { arraysEqual } from '@/utils';
import { erpConfig } from '@/api/erp';
import {
  defaultObj,
  GoodsTableHead,
  VirtualTableHead,
  VirtualTableHead2,
  StoreTableHead,
  ReservationTableHead
} from './formModel.js';
import freightTemplate from '@/components/freightTemplate';
import stockSet from './components/stockSet.vue';
import reservationSet from './components/reservationSet.vue';
import inventorySet from './components/inventorySet.vue';
import productBaseSet from './components/productBaseSet.vue';
import marketingSet from './components/marketingSet.vue';
import otherSet from './components/otherSet.vue';
import cardFaceSet from './components/cardstyleSet.vue';
import vipPriceBrokerageSet from './components/vipPriceBrokerageSet.vue';
import addAttr from '../productAttr/addAttr';
import wangeditor from '@/components/wangEditor/index.vue';
import addCarMy from '../components/addCarMy';
import taoBao from './taoBao';
import vuedraggable from 'vuedraggable';
import storeList from '@/components/storeList';
import uploadPictures from '@/components/uploadPictures';
import goodsAttr from '@/components/goodsAttr';
import Setting from '@/setting';
export default {
  name: 'productAdd',
  data() {
    return {
	  priceSetType:0,//区间改价类型
	  priceSetNum:0,//区间改价输入的价格
      ruleValidate: {
        price: [
          {
            validator: (rule, value, callback) => {
              if (this.currentTab == '2' && this.formData.attr.price === null) {
                callback(new Error('请输入售价'));
              } else {
                callback();
              }
            },
            required: true,
            trigger: 'blur'
          },
        ],
        stock: [
          {
            validator: (rule, value, callback) => {
              // 产品初始库存允许为 0；非产品类型仍按原规则校验
              if (
                this.formData.product_type != 0 &&
                this.formData.product_type != 1 &&
                !this.$route.params.id &&
                this.currentTab == '2' &&
                (this.formData.attr.stock === null || this.formData.attr.stock === '')
              ) {
                callback(new Error('请输入库存'));
              } else {
                callback();
              }
            },
            required: true,
            trigger: 'blur'
          },
        ],
        write_times: {
          validator: (rule, value, callback) => {
            if (this.formData.product_type == 4 && !this.formData.attr.write_times && this.currentTab == '2') {
              callback(new Error('请输入核销次数'));
            } else {
              callback();
            }
          },
          required: true,
          trigger: 'blur'
        },
        settle_price: {
          validator: (rule, value, callback) => {
            if (this.currentTab == '2' && (this.merchantType == 2 || this.goodsSource == 2) && this.formData.attr.settle_price == null) {
              callback(new Error('请输入结算价'));
            } else {
              callback();
            }
          },
          required: true,
        },
        delivery_type: {
          validator: (rule, value, callback) => {
            if (this.formData.product_type != 0 || Array.isArray(value) && value.length) {
              callback();
            } else {
              if (this.currentTab == 4) {
                callback(new Error('请选择配送方式'));
              } else {
                callback();
              }
            }
          },
          required: true,
          type: 'array',
        },
        store_delivery_type: {
          validator: (rule, value, callback) => {
            if (this.formData.product_type != 0 || !this.formData.delivery_type.includes('3') || Array.isArray(value) && value.length) {
              callback();
            } else {
              if (this.currentTab == 4) {
                callback(new Error('请选择配送类型'));
              } else {
                callback();
              }
            }
          },
          required: true,
          type: 'array',
        },
      },
      currentTab: '1',
      spinShow: false,
      openSubimit: false,
      ruleList: [],
      attrs: [],
      formData: structuredClone(defaultObj),
      oneFormBatch: [
        {
          bar_code: '',
          code: '',
          cost: null,
          detail: {},
          settle_price: null,
          ot_price: null,
          pic: '',
          price: null,
          stock: null,
          weight: null,
          volume: null,
          stock_unit: '',
          sale_unit: '',
          unit_convert: null,
          decimal_scale: null,
          virtual_list: [],
		  pm: 1,
		  inventory: 0
        },
      ],
      openErp: false,
      currentIndex: 0,
      merchantType: 0, //0:平台商品；1:门店商品；2:供应商商品
      columnsInstalM: [],
      manyFormValidate: [],
      oldVal: [],
      disabledSpecType: false,
      createBnt: true,
      // 规格数据
      formDynamic: {
        attrsName: '',
        attrsVal: '',
      },
      carMyShow: false, //是否开启卡密弹窗
      virtualList: [],
      tabIndex: 0,
      tabName: '',
      success: false,
      changeAttrValue: '',
      canSel: true, // 规格图片添加判断
      templateList: [],
      template: false,
      templateName: '',
      roterPre: Setting.roterPre,
      StoreTableHead,
      storesList: [],
      storeModals: false,
      modalPic: false,
      isChoice: '',
      picTit: '',
      tableIndex: '',
      modals: false,
      type: 0,
      create_request_key: '',
      performanceRule: {
        labor_mode: 'project_configured_amount',
        labor_configured_unit_amount: 0,
        consumption_mode: 'actual_entitlement_amount',
        consumption_configured_unit_amount: 0,
        version: 1,
      },
      performanceRuleSaving: false,
	  timeoutId: null, //定时器
	  reservationTime: [],//时间区域
	  timeCheckAll:true, //自动划分控制全选
	  timeCheckAllGroup:[],//自动划分当前选中的元素
	  timeCheckAllClone:[],//自动划分克隆全部选中的元素
	  timeDataClone:[],//自定义划分时的库存（为了切换时段划分时，可以复原之前选中的数据）
	  timeInputNumberValue:0, //批量设置单规格库存值
	  tooltipVisible:false,//控制气泡的显示和隐藏
	  timeColumns:[
		{
			title: "时段",
			slot: "time",
			align: "left",
			width: 142
		},
		{
			title: "预约数量",
			slot: "stock",
			align: "left",
			minWidth: 180,
			renderHeader: (h, row) => {
				return h('div', [
				  h('Poptip',{
					props:{
						transfer:true,
						placement: 'top',
						trigger:'click',
						value: this.tooltipVisible,
					},
					on: {
						'on-popper-hide': () => {
							this.tooltipVisible = false; // 气泡隐藏时设置 visible 为 false
						}
					},
					scopedSlots:{
						default: () => h('span', {
							on: {
							  click: ($event) => {
								$event.stopPropagation();
							    this.tooltipVisible = true; // 点击单元格时显示气泡
								this.timeInputNumberValue = 0;
							  },
							}
						},[
							h('span', '预约数量'),
							h('span', {
								class: ['iconfont iconbianji11'],
								style: {
								  marginLeft: '6px',
								  color:'#AAAAAA',
								  fontSize:'12px'
								}
							})
						]),
					  content:()=>h('div',[
						h('div',{
							class:['fs-12 text-wlll-515A6E mb-12']
						},'批量修改'),
						h('InputNumber',{
							props:{
							  min:0,
							  max:99999999,
							  precision:0,
							  value: this.timeInputNumberValue
							},
							class:['w-85'],
							 on: {
								 'on-change': (value) => {
								   this.timeInputNumberValue = value;
								 }
							 }
						}),
						h('Button',{
							props:{
							  size:'small',
							},
							class:['ml-8'],
							on: {
							  click: () => {
								this.tooltipVisible = false;
							  }
							}
						},'取消'),
						h('Button',{
							props:{
							  type:'primary',
							  size:'small'
							},
							class:['ml-8'],
							on: {
							  click: () => {
								this.tooltipVisible = false;
							    this.handleButtonClick();
							  }
							}
						},'确定'),
					  ])
					},
				  })
				]);
			}
		}
	  ],
	  customizeTimeData:[],//自定义划分时的库存（为了切换时段划分时，可以复原之前选中的数据）
	  specTimeData:[], //多规格对应属性库存数据
	  specIndex:0, //多规格对应索引值
    goodsModal: false,
    cardColumns:[
      {
          type: 'selection',
          width: 60,
          align: 'center'
      },
      {
          title: '商品信息',
          slot: 'product',
          width: 210,
      },
      {
          title: '商品规格',
          key: 'suk'
      },
      {
          title: '商品类型',
          render: (h, params) => {
            return h('div', params.row.product_type ? '预约商品' : '产品');
          }
      },
      {
          title: '售价',
          key: 'price'
      },
      {
          slot: 'write_times',
          renderHeader: (h, params) => {
            return h('div', [
              h('span', '可核销次数'),
              h('Poptip', {
                props: {
                  transfer: true,
                  value: this.poptipVisible,
                },
                on: {
                  'on-popper-show': () => {
                    this.poptipVisible = true;
                  },
                  'on-popper-hide': () => {
                    this.poptipVisible = false;
                  },
                },
                scopedSlots: {
                  content: () => {
                    return h('div', [
                      h('div', {
                        'class': {
                          'mb-12': true,
                          'fs-12': true,
                        },
                      }, '批量操作'),
                      h('div', [
                        h('InputNumber', {
                          props: {
                            min: 1,
                            precision: 0,
                          },
                          on: {
                            'on-change': (value) => {
                              this.batchWriteTimes = value;
                            },
                          },
                        }),
                        h('Button', {
                          props: {
                            size: 'small',
                          },
                          'class': {
                            'ml-10': true,
                          },
                          on: {
                            click: () => {
                              this.poptipVisible = false;
                            },
                          },
                        }, '取消'),
                        h('Button', {
                          props: {
                            type: 'primary',
                            size: 'small',
                          },
                          'class': {
                            'ml-10': true,
                          },
                          on: {
                            click: () => {
                              this.poptipVisible = false;
                              this.handleBatch();
                            },
                          },
                        }, '确认'),
                      ]),
                    ]);
                  }
                },
              }, [
                h('Icon', {
                  props: {
                    type: 'ios-create-outline'
                  },
                  'class': {
                    'ml-3': true,
                    'cup': true,
                    'fs-14': true,
                  },
                  style: {
                    display: this.cardData.length ? 'inline-block' : 'none'
                  }
                }),
              ]),
            ]);
          },
      },
      {
          title: '操作',
          slot: 'action',
      },
    ],
    cardData:[],
    poptipVisible: false,
    batchWriteTimes: 1,
	// batchStock:null,
	description:'',
  section_time: [],
  cardDataSelection: [],
  goodsSource: 1,
  isVip: 0, //自定义会员价-付费会员
  levelType: 1, //自定义会员价-等级会员
  isBrokerage: 0, //自定义会员价-等级会员
  isSub: 0, //自定义会员价-等级会员
  storeBrokerageRatio: 0, //自定义会员价-等级会员
  storeBrokerageTwo: 0, //自定义会员价-等级会员
  attrValue: [], //自定义会员价数据
  levelList: [], //自定义会员价数据
    };
  },
  components: {
	stockSet,
    freightTemplate,
    productBaseSet,
	reservationSet,
	inventorySet,
    marketingSet,
    otherSet,
    cardFaceSet,
    vipPriceBrokerageSet,
    addAttr,
    wangeditor,
    addCarMy,
    taoBao,
    draggable: vuedraggable,
    storeList,
    uploadPictures,
    goodsAttr,
  },
  computed: {
    ...mapState('admin/layout', ['isMobile', 'menuCollapse']),
    filterHeadTab() {
      let headTab = [];
      const productType = this.formData.product_type;
      if (productType == 1 || productType == 3) {
        // 卡密/网盘 或 虚拟商品
        headTab = [
          { title: '基础信息', name: '1' },
          { title: '规格库存', name: '2' },
          { title: '商品详情', name: '3' },
          { title: '会员价/佣金', name: '10' },
          { title: '营销设置', name: '5' },
          { title: '其他设置', name: '6' },
        ];
        this.formData.postage = 0;
      } else if (productType == 4) {
        // 次卡商品
        headTab = [
          { title: '基础信息', name: '1' },
          { title: '规格库存', name: '2' },
          { title: '商品详情', name: '3' },
          { title: '会员价/佣金', name: '10' },
          { title: '适用门店', name: '7' },
          { title: '营销设置', name: '5' },
          { title: '其他设置', name: '6' },
        ];
      }  else if (productType == 5) {
        // 卡项
        headTab = [
          { title: '基础信息', name: '1' },
          { title: '卡项信息', name: '2' },
          { title: '卡面设置', name: '9' },
          { title: '商品详情', name: '3' },
          { title: '会员价/佣金', name: '10' },
          { title: '适用门店', name: '7' },
          { title: '营销设置', name: '5' },
          { title: '其他设置', name: '6' },
        ];
      } else if (productType == 6) {
        // 预约商品
        headTab = [
          { title: '基础信息', name: '1' },
          { title: '预约服务', name: '2' },
          { title: '预约设置', name: '8' },
          { title: '商品详情', name: '3' },
          { title: '会员价/佣金', name: '10' },
          { title: '适用门店', name: '7' },
          { title: '营销设置', name: '5' },
          { title: '其他设置', name: '6' },
        ];
	  } else {
      // 产品等其他类型：先库存设置，再规格库存
        headTab = [
          { title: '基础信息', name: '1' },
          { title: '库存设置', name: '11' },
          { title: '规格库存', name: '2' },
          { title: '商品详情', name: '3' },
          { title: '会员价/佣金', name: '10' },
          { title: '适用门店', name: '7' },
          { title: '营销设置', name: '5' },
          { title: '其他设置', name: '6' },
        ];
      }
      // 仅平台商品显示“适用门店”tab
      if (this.merchantType == 1) {
        headTab = headTab.filter(item => item.name != 7);
      }
      return headTab;
    },
    isCopyMode() {
      return !!this.$route.query.copy || this.type === 1 || this.type === -1;
    },
    canEditInitialStock() {
      return (
        !this.$route.params.id &&
        !this.$route.query.copy &&
        this.type === 0 &&
        Number(this.formData.product_type) === 0 &&
        Number(this.formData.is_inventory) === 1
      );
    },
    stockInputPrecision() {
      return Number(this.formData.salon_stock_enabled) === 1 ? 2 : 0;
    },
    // 院装耗材开启时才展示院装耗材单位/换算数；关闭仅隐藏，不清空已有值
    showSalonUnitFields() {
      return Number(this.formData.product_type) === 0 && Number(this.formData.salon_stock_enabled) === 1;
    },
    specTableHeader() {
      const header = this.formData.header || [];
      if (this.showSalonUnitFields) return header;
      return header.filter((col) => col.slot !== 'stock_unit' && col.slot !== 'unit_convert');
    },
    cardRuleSummary() {
      const summaries = {
        normal: '每个项目分别配置次数，各项目次数互不共享。',
        choice_kind: '使用期间最多选择指定种数，首次核销即锁定该项目。',
        choice_count: '卡内所有项目共享总次数，每次可以任选其中一个项目。',
        time: '卡内项目在有效期内不限次，每个项目单独配置单次核销金额。',
      };
      return summaries[this.formData.card_rule_type] || '';
    },
    cardRulePreview() {
      const projectCount = this.cardData.length;
      if (this.formData.card_rule_type === 'normal') {
        const total = this.cardData.reduce((sum, item) => sum + Number(item.write_times || 0), 0);
        return `本卡包含${projectCount}个项目，各项目独立使用，共${total}次。`;
      }
      if (this.formData.card_rule_type === 'choice_kind') {
        return `本卡包含${projectCount}个候选项目，使用期间最多选择其中${Number(this.formData.card_choice_limit || 0)}种。`;
      }
      if (this.formData.card_rule_type === 'choice_count') {
        return `本卡包含${projectCount}个可任选项目，所有项目累计最多核销${Number(this.formData.card_shared_times || 0)}次。`;
      }
      if (this.formData.card_rule_type === 'time') {
        return `本卡包含${projectCount}个项目，有效期内不限次数使用。`;
      }
      return '';
    },
    cardRuleColumns() {
      const columns = [
        { type: 'selection', width: 60, align: 'center' },
        { title: '项目信息', slot: 'product', minWidth: 210 },
        { title: '项目规格', key: 'suk', minWidth: 100 },
        {
          title: '项目类型',
          minWidth: 100,
          render: (h, params) => h('div', Number(params.row.product_type) === 6 ? '项目' : '产品'),
        },
        { title: '项目原价', slot: 'price', minWidth: 100 },
      ];
      if (['normal', 'choice_kind'].includes(this.formData.card_rule_type)) {
        columns.push({
          title: this.formData.card_rule_type === 'choice_kind' ? '选中后可使用次数' : '可使用次数',
          slot: 'write_times',
          minWidth: 160,
        });
      } else if (this.formData.card_rule_type === 'time') {
        columns.push({ title: '单次核销金额', slot: 'writeoff_amount', minWidth: 160 });
      }
      columns.push({ title: '操作', slot: 'action', minWidth: 80 });
      return columns;
    },
  },
  destroyed() {
    this.setCopyrightShow({ value: true });
  },
  created() {
    this.create_request_key = this.genCreateRequestKey();
  },
  mounted() {
    this.productGetRule();
    this.productGetTemplate();
    this.getErpConfig();
    this.getProductBrokerage();
    if (this.$route.query.productType) {
      this.formData.product_type = Number(this.$route.query.productType);
    }
    if (this.$route.query.type && this.$route.query.type == -1) {
      this.modals = true;
      this.type = -1;
    } else if (this.$route.query.copy) {
      this.type = 1;
    }
	// window.addEventListener('click', this.handlePageClick);
  },
  methods: {
    ...mapMutations('admin/layout', ['setCopyrightShow']),
    genCreateRequestKey() {
      if (typeof crypto !== 'undefined' && crypto.randomUUID) {
        return crypto.randomUUID();
      }
      return 'crk_' + Date.now() + '_' + Math.random().toString(36).slice(2, 12);
    },
    clearSkuStockFields() {
      if (this.formData.attr) {
        this.$set(this.formData.attr, 'stock', 0);
        this.$set(this.formData.attr, 'sum_stock', 0);
        this.$set(this.formData.attr, 'defective_stock', 0);
        this.$set(this.formData.attr, 'old_stock', 0);
        this.$set(this.formData.attr, 'inventory', 0);
        this.$set(this.formData.attr, 'pm', 1);
      }
      (this.manyFormValidate || []).forEach((item) => {
        if (!item) return;
        this.$set(item, 'stock', 0);
        this.$set(item, 'sum_stock', 0);
        this.$set(item, 'defective_stock', 0);
        this.$set(item, 'old_stock', 0);
        this.$set(item, 'inventory', 0);
        this.$set(item, 'pm', 1);
      });
    },
	// 单规格-调价区间
	changePrice(e) {
		if(!this.$route.params.id){
			this.formData.attr.price_range_min = e
			this.formData.attr.price_range_max = e
		}
	},
	// 多规格-调价区间
	changeManyPrice(index,e){
		if(!this.$route.params.id){
			this.manyFormValidate[index].price_range_min = e;
			this.manyFormValidate[index].price_range_max = e;
		}
	},
	// 多规格-调价区间-关闭弹窗
	closePriceSet(i) {
	  document.body.click()
	  this.$refs['popoverRef_' + i][0].doClose(); //关闭的
	  this.priceSetType = 0;
	  this.priceSetNum = 0;
	},
	priceSetConfirm(i) {
		let priceSetNum = this.priceSetNum;
		if (this.priceSetType != 0){
			if(priceSetNum<=0){
				return this.$Message.error('调价价格必须大于0');
			}
		}
		this.manyFormValidate.map((item) => {
		  if (this.priceSetType == 0) {
		    item[i] = parseFloat(priceSetNum)>=0 ? parseFloat(priceSetNum):null;
		  } else if (this.priceSetType == 1) {
		    item[i] = parseFloat(this.$computes.Mul(this.$computes.Div(priceSetNum,100),item.price).toFixed(2));
		  } else if (this.priceSetType == 2){
			let val = this.$computes.Sub(item.price,priceSetNum);
		    item[i] = val>=0?parseFloat(val):0;
		  }else {
			item[i] = this.$computes.Add(item.price,priceSetNum);
		  }
		});
		this.closePriceSet(i);
	},
	// 多规格-调价区间-获取输入的调价价格
	priceReplace(event) {
	  this.priceSetNum = this.cleanPrice(event.target.value);
	},
	// 多规格-调价区间-价格处理
	cleanPrice(value) {
	  // 移除非数字和非小数点的字符
	  let cleanedValue = value.replace(/[^\d.]/g, '');
	  // 确保只有一个小数点
	  let parts = cleanedValue.split('.');
	  if (parts.length > 2) {
	    cleanedValue = parts[0] + '.' + parts.slice(1).join('');
	  }
	  // 确保小数点后最多有两位数字
	  if (cleanedValue.includes('.')) {
	    let [integerPart, decimalPart] = cleanedValue.split('.');
	    cleanedValue = integerPart + '.' + decimalPart.slice(0, 2);
	  }
	  return cleanedValue;
	},
	// 可售日期选择每周时数据更新
	weekData(data){
	   this.weekList = data;
	},
	modalStockSet(data){
		this.manyFormValidate[this.specIndex].reservation_time_data = data;
	},
	//点击多规格设置库存按钮；
	setTimeStock(index){
		this.specIndex = index;
		let data = this.manyFormValidate[index].reservation_time_data;
		if(!data){
			this.$Message.error('请点击时段划分中的设置按钮');
		}
		// if(this.batchStock != null){
		// 	data.forEach(item=>{
		// 		item.stock = this.batchStock
		// 	})
		// }
		this.specTimeData = JSON.parse(JSON.stringify(data));
		this.$refs.stockSet.stockModals = true;
		this.$refs.stockSet.timeInputNumberValue = 0;
	},
	// 点击切换自动和自定义划分
	timeDivide(e){
		this.formData.attr.reservation_time_data = [];
		if(e==1){
			this.formData.attr.reservation_time_data = this.timeDataClone
		}else{
			this.formData.attr.reservation_time_data = this.customizeTimeData
		}
		if(this.attrs.length){
		   this.generateAttr(this.attrs);
		}
	},
	closeTime(index){
	  this.formData.customize_time_period.splice(index, 1)
	},
	// 判断交集和递增；
	intersection(customizeTime){
		let intersection = this.$hasIntersection(customizeTime); //是否有交集
		let Incremental = this.$isTimeRangesIncreasing(customizeTime); //是否递增
		return (intersection || !Incremental)
	},
	// 自定义添加时段；
	addTime(){
		let customizeTime = this.formData.customize_time_period;
		for(let i =0; i<customizeTime.length;i++){
			if(!customizeTime[i][0]){
				return this.$Message.error('请选择时段');
			}
		}
		if(this.intersection(customizeTime)){
			return this.$Message.error('时段必须递增无交集');
		}
		customizeTime.push([]);
	},
	customizeTime(e){
		let customizeTime = this.formData.customize_time_period;
		customizeTime[customizeTime.length-1]=e;
	},
	// 自定义划分设置按钮
	setCustomizeTime(){
		let customizeTime = this.formData.customize_time_period;
		for(let i =0; i<customizeTime.length;i++){
			if(!customizeTime[i][0]){
				return this.$Message.error('请选择时段');
			}
		}
		if(this.intersection(customizeTime)){
		   return this.$Message.error('时段必须递增无交集');
		}
		let customizeTimeData = []
		customizeTime.forEach(item=>{
			let data = {
				start:item[0],
				end:item[1],
				stock:0
			}
			customizeTimeData.push(data);
		})
		this.customizeTimeData = customizeTimeData;
		this.formData.attr.reservation_time_data = customizeTimeData;
		if(this.formData.spec_type == 1 && !this.attrs.length){
		  return this.$Message.error('请设置商品规格');
		}
		if(this.attrs.length){
		   this.$Message.success('设置成功');
		   this.generateAttr(this.attrs);
		}
	},
	// 单规格批量设置库存;
	handleButtonClick(){
		this.formData.attr.reservation_time_data.forEach(item=>{
			item.stock = this.timeInputNumberValue;
		})
	},
	// 设置自动划分时间；
	setTime(){
		let timeCheckAllGroup = [],that = this;
		let reservationTimes = this.formData.reservation_times;
		let time = this.formData.reservation_time_interval;
		if(!reservationTimes[0] || time <= 0){
		   return this.$Message.error('请输入起止时间或时间跨度');
		}
		this.reservationTime = this.$splitTimeRange(reservationTimes,time);
		this.reservationTime.forEach((item,index)=>{
			timeCheckAllGroup.push(item.start);
		})
		setTimeout(function(){
			that.timeCheckAllGroup = timeCheckAllGroup;
		},100)
		this.timeCheckAllClone = timeCheckAllGroup;
		this.timeCheckAll = true;
		this.formData.attr.reservation_time_data = this.reservationTime;
		this.timeDataClone = this.reservationTime;
		if(this.attrs.length){
		   this.generateAttr(this.attrs);
		}
	},
	handleCheckAll(e){
		let data = []
		if(e){
			this.timeCheckAllGroup = this.timeCheckAllClone;
			data = this.reservationTime;
		}else{
			this.timeCheckAllGroup = [];
			data = [];
		}
		this.formData.attr.reservation_time_data = data;
		this.timeDataClone = data;
		if(this.attrs.length){
		   this.generateAttr(this.attrs);
		}
	},
	checkAllGroupChange(e){
		if(e.length == this.timeCheckAllClone.length){
			this.timeCheckAll = true;
		}else{
			this.timeCheckAll = false;
		}
		let data = this.reservationTime.filter(obj => e.includes(obj.start));
		this.formData.attr.reservation_time_data = data;
		this.timeDataClone = data;
		if(this.attrs.length){
		   this.generateAttr(this.attrs);
		}
	},
	// 禁止输入小数点
	handleKeyPress(event){
		const key = event.key;
		if (key === '.') {
			event.preventDefault();
		}
	},
	//处理预约时段间隔数
	handleInputChange(event){
		let value = event.target.value;
		value = Number(value);
		let that = this
		if(value < 10){
			clearTimeout(that.timeoutId)
			that.timeoutId = setTimeout(function(){
				that.formData.reservation_time_interval = 10;
			},1200)
		}else{
			clearTimeout(that.timeoutId)
			that.timeoutId = null;
			if (value > 1440) {
				setTimeout(function(){
					that.formData.reservation_time_interval = 1440;
				})
			}
		}
	},
    // 改变规格
    changeSpec() {
      // this.formData.is_sub = [];
      let id = this.$route.params.id;
      if (id) {
        checkActivityApi(id).catch((res) => {
          this.disabledSpecType = true;
        });
      }
    },
    // 规格图片添加开关
    addPic(e, i) {
      if (e) {
        this.attrs.map((item, ii) => {
          if (ii !== i) {
            this.$set(item, 'add_pic', 0);
          }
        });
        this.canSel = false;
      } else {
        this.canSel = true;
      }
    },
    // 规格拖拽排序后
    onMoveSpec() {
      this.generateAttr(this.attrs);
    },
    changeCurrentIndex(i) {
      this.currentIndex = i;
    },
    generateAttr(data) {
      this.generateHeader(data);
      const combinations = this.generateCombinations(data);
      let rows = combinations.map((combination) => {
        const row = {
          attr_arr: combination,
          detail: {},
          title: '',
          key: '',
          price: 0,
		  price_range_min: 0,
		  price_range_max: 0,
          pic: '',
          ot_price: 0,
          cost: 0,
          stock: 0,
          is_show: 1,
          is_default_select: 0,
          unique: '',
          weight: 0,
          brokerage: 0,
          brokerage_two: 0,
          vip_price: 0,
          vip_proportion: 0,
          code: '',
          bar_code: '',
          volume: 0,
          stock_unit: '',
          sale_unit: '',
          unit_convert: 1,
          decimal_scale: 0,
        };
        // 判断商品类型是卡密
        if (this.formData.product_type == 1) {
          this.$set(row, 'virtual_list', []);
          this.$set(row, 'disk_info', '');
        }else if (this.formData.product_type == 6) {
			this.$set(row, 'reservation_time_data', this.formData.attr.reservation_time_data);
		}
        for (let i = 0; i < combination.length; i++) {
          const value = combination[i];
          this.$set(row, data[i].value, value);
          this.$set(row, 'title', data[i].value);
          this.$set(row, 'key', data[i].value);
          this.$set(row.detail, data[i].value, value);
		  if(this.$route.params.id && [1,6].indexOf(this.formData.product_type) == -1){
		    this.$set(row,'pm', 1);
		    this.$set(row,'inventory', 0);
		  }
          // 如果manyFormValidate中存在该属性值，则赋值
          for (let k = 0; k < this.manyFormValidate.length; k++) {
            const manyItem = this.manyFormValidate[k];
            // 对比两个数组是否完全相等
            let attrDetail = Object.values(manyItem.detail);
            if (k > 0 && attrDetail && arraysEqual(attrDetail, combination)) {
              Object.assign(row, {
                price: manyItem.price,
				price_range_min: manyItem.price_range_min,
				price_range_max: manyItem.price_range_max,
                cost: manyItem.cost,
                ot_price: manyItem.ot_price,
                stock: manyItem.stock,
                pic: manyItem.pic,
                unique: manyItem.unique || '',
                weight: manyItem.weight || 0,
                is_show: manyItem.is_show || 1,
                is_default_select: manyItem.is_default_select || 0,
                volume: manyItem.volume || 0,
                code: manyItem.code || '',
                bar_code: manyItem.bar_code || '',
                stock_unit: manyItem.stock_unit || '',
                sale_unit: manyItem.sale_unit || '',
                unit_convert: manyItem.unit_convert != null ? manyItem.unit_convert : 1,
                decimal_scale: manyItem.decimal_scale != null ? manyItem.decimal_scale : 0,
                is_virtual: manyItem.is_virtual,
                brokerage: manyItem.brokerage,
                brokerage_two: manyItem.brokerage_two,
                vip_price: manyItem.vip_price,
                vip_proportion: manyItem.vip_proportion,
				inventory: 0,
				pm: 1
              });
              if (this.formData.product_type == 1) {
                row.virtual_list = manyItem.virtual_list;
                row.disk_info = manyItem.disk_info;
              } else if (this.formData.product_type == 6) {
				  row.reservation_time_data = this.formData.attr.reservation_time_data;
			  }
            }
          }
        }
        return row;
      });
      this.$nextTick(() => {
        // rows数组第一项 新增默认数据 oneFormBatch
        this.manyFormValidate = [...this.oneFormBatch, ...rows];
      });
    },
    handleRemoveRole(index) {
      this.attrs.splice(index, 1);
      this.manyFormValidate.splice(index, 1);
      if (!this.attrs.length) {
        this.formData.header = [];
        this.manyFormValidate = [];
      } else {
        this.generateAttr(this.attrs);
      }
    },
    // 删除表格中 对应属性
    delAttrTable(val) {
      for (let i = 0; i < this.manyFormValidate.length; i++) {
        let item = this.manyFormValidate[i];
        if (
          Object.values(item.detail) &&
          Object.values(item.detail).includes(val)
        ) {
          this.manyFormValidate.splice(i, 1);
          i--;
        }
      }
    },
    handleRemove2(item, index, val) {
      item.splice(index, 1);
      this.delAttrTable(val);
    },
    // 规格名称改变
    attrChangeValue(i, val) {
      if (val.trim().length && this.attrs[i].detail.length) {
        this.generateHeader(this.attrs);
        if (this.manyFormValidate.length) {
          this.manyFormValidate.map((item, i) => {
            if (i > 0) {
              if (Object.keys(item.detail).includes(this.changeAttrValue)) {
                item.detail[val] = item.detail[this.changeAttrValue];
                item[val] = item[this.changeAttrValue];
                delete item.detail[this.changeAttrValue];
                delete item[this.changeAttrValue];
              }
            }
          });
          this.changeAttrValue = val;
        }
      } else {
        this.generateAttr(this.attrs);
      }
    },
    attrDetailChangeValue(val, i) {
      if (this.manyFormValidate.length) {
        let key = this.attrs[i].value;
        this.manyFormValidate.map((item, i) => {
          if (i > 0) {
            if (
              Object.keys(item.detail).includes(key) &&
              item.detail[key] === this.changeAttrValue
            ) {
              item.detail[key] = val;
              item.attr_arr = [];
              for (const attrValue of this.attrs) {
                item.attr_arr.push(item.detail[attrValue.value]);
              }
            }
          }
        });
        this.changeAttrValue = val;
      } else {
        this.generateAttr(this.attrs, 1);
      }
    },
    handleShowPop(index) {
      this.$refs['inputRef_' + index][0].focus();
    },
    // 新增规格
    handleAddRole() {
      let data = {
        value: this.formDynamic.attrsName,
        add_pic: 0,
        detail: [],
      };
      this.attrs.push(data);
    },
    // 新增一条属性
    addOneAttr() {
      this.generateAttr(this.attrs);
    },
    changeSpecImg(arr, img) {
      this.$Modal.confirm({
        title: '提示',
        content: '可以同步修改商品属性中该规格图片，确定使用吗？',
        onOk: () => {
          for (let val of this.manyFormValidate) {
            if (this.isSubset(Object.values(val.detail), arr)) {
              this.$set(val, 'pic', img);
            }
          }
        },
      });
    },
    handleFocus(val) {
      this.changeAttrValue = val;
    },
    handleBlur() {
      this.changeAttrValue = '';
    },
    handleSelImg(item, i, index) {
      this.modalPicTap('dan', 'attrs', [index, i]);
    },
    handleRemoveImg(item) {
      item.pic = '';
    },
    // 生成规格组合
    generateCombinations(arr, prefix = []) {
      if (arr.length === 0) {
        return [prefix];
      }
      const [first, ...rest] = arr;
      return first.detail.flatMap((detail) =>
        this.generateCombinations(rest, [...prefix, detail.value])
      );
    },
    createAttr(num, idx) {
      if (num) {
        var isExist = this.attrs[idx].detail.some((item) => item.value === num);
        if (isExist) {
          this.$Message.error('规格值已存在');
          return;
        }
        var hash = {};
        this.attrs[idx].detail.push({ value: num, pic: '' });
        if (this.manyFormValidate.length) {
          this.addOneAttr(this.attrs[idx].value, num);
        } else {
          this.generateAttr(this.attrs);
        }
        this.$refs['popoverRef_' + idx][0].doClose(); //关闭的
        //清除刚才输入的内容
        this.formDynamic.attrsName = '';
        this.formDynamic.attrsVal = '';
        setTimeout(() => {
          if (this.$refs['popoverRef_' + idx]) {
            //重点是以下两句
            this.$refs['popoverRef_' + idx][0].doShow(); //打开的
            //重点是以上两句
          }
        }, 20);
      } else {
        // this.$Message.warning('请添加属性');
        this.$refs['popoverRef_' + idx][0].doClose(); //关闭的
      }
    },
    // 获取商品属性模板；
    productGetRule() {
      productGetRuleApi().then((res) => {
        this.ruleList = res.data;
      });
    },
    // 切换默认选中规格
    changeDefaultSelect(index) {
      // 一个开启 其他关闭
      this.manyFormValidate.map((item, i) => {
        if (i !== index) {
          item.is_default_select = 0;
        }
      });
	  if(this.manyFormValidate[index].is_default_select){
		  this.manyFormValidate[index].is_show = 1;
	  }
    },
    // 改变是否显示
    changeDefaultShow(index) {
      // 如果默认选中开启 则不可隐藏
      if (this.manyFormValidate[index].is_default_select === 1) {
        this.manyFormValidate[index].is_show = 1;
        this.$message.error('默认规格不可隐藏');
      }
    },
    // 生成列表 行 列 数据
    tableCellClassName({ row, column, rowIndex, columnIndex }) {
      //注意这里是解构
      //利用单元格的 className 的回调方法，给行列索引赋值
      row.index = rowIndex || '';
      column.index = columnIndex;
    },
    // 合并单元格
    objectSpanMethod({ row, column, rowIndex, columnIndex }) {
      if (columnIndex === 0 && rowIndex > 0) {
        let lable = column.label;
        //这里判断第几列需要合并
        const tagFamily = this.manyFormValidate[rowIndex].detail[lable];
        const index = this.manyFormValidate.findIndex((item, index) => {
          if (index > 0) return item.detail[lable] == tagFamily;
        });
        if (rowIndex == index) {
          let len = 1;
          for (let i = index + 1; i < this.manyFormValidate.length; i++) {
            if (this.manyFormValidate[i].detail[lable] !== tagFamily) {
              break;
            }
            len++;
          }
          return {
            rowspan: len,
            colspan: 1,
          };
        } else {
          return {
            rowspan: 0,
            colspan: 0,
          };
        }
      }
    },
    headerRowClassName() {
      return 'custom-header-class'; // 返回自定义的 CSS 类名
    },
    // 清空批量规格信息
    batchDel() {
      this.oneFormBatch = [
        {
          bar_code: '',
          code: '',
          cost: null,
          detail: {},
          ot_price: null,
          settle_price: null,
          pic: '',
          price: null,
          stock: null,
          weight: null,
          volume: null,
          stock_unit: '',
          sale_unit: '',
          unit_convert: null,
          decimal_scale: null,
        },
      ];
    },
    isSubset(arr1, arr2) {
      // 将数组转换为 Set，以便进行高效的包含检查
      const set1 = new Set(arr1);
      const set2 = new Set(arr2);

      // 检查 set2 中的每个元素是否都在 set1 中
      for (let elem of set2) {
        if (!set1.has(elem)) {
          return false;
        }
      }
      return true;
    },
    // 批量添加
    batchAdd() {
      let arr = [];
      for (let val of this.attrs) {
        if (this.oneFormBatch[0][val.value]) {
          arr.push(this.oneFormBatch[0][val.value]);
        }
      }
      for (let val of this.manyFormValidate) {
        if (arr.length) {
          if (this.isSubset(val.attr_arr, arr)) {
            if (this.oneFormBatch[0].pic) {
              this.$set(val, 'pic', this.oneFormBatch[0].pic);
            }
			if(this.oneFormBatch[0].price !== null){
				this.$set(val, 'price', this.oneFormBatch[0].price);
			}
			if(!this.$route.params.id){
				this.$set(val, 'price_range_min', this.oneFormBatch[0].price);
				this.$set(val, 'price_range_max', this.oneFormBatch[0].price);
			}
            if (this.oneFormBatch[0].price !== null) {
            }
            if (this.oneFormBatch[0].settle_price !== null) {
              this.$set(val, 'settle_price', this.oneFormBatch[0].settle_price);
            }
            if (this.oneFormBatch[0].cost !== null) {
              this.$set(val, 'cost', this.oneFormBatch[0].cost);
            }
            if (this.oneFormBatch[0].ot_price !== null) {
              this.$set(val, 'ot_price', this.oneFormBatch[0].ot_price);
            }
            if (this.oneFormBatch[0].stock !== null) {
              this.$set(val, 'stock', this.oneFormBatch[0].stock);
			  // this.batchStock = null;
			  // let time = JSON.parse(JSON.stringify(val.reservation_time_data));
			  // time.forEach(k=>{
				 //  k.stock = this.oneFormBatch[0].stock
			  // })
			  // this.$set(val, 'reservation_time_data', time);
            }
			if (this.oneFormBatch[0].inventory !== null) {
			  this.$set(val, 'inventory', this.oneFormBatch[0].inventory);
			}
			if (this.oneFormBatch[0].pm !== null) {
			  this.$set(val, 'pm', this.oneFormBatch[0].pm);
			}
            this.$set(val, 'bar_code', this.oneFormBatch[0].bar_code);
            this.$set(
              val,
              'code',
              this.oneFormBatch[0].code
            );
            if (this.oneFormBatch[0].weight !== null) {
              this.$set(val, 'weight', this.oneFormBatch[0].weight);
            }
            if (this.oneFormBatch[0].volume !== null) {
              this.$set(val, 'volume', this.oneFormBatch[0].volume);
            }
            if (this.oneFormBatch[0].stock_unit) {
              this.$set(val, 'stock_unit', this.oneFormBatch[0].stock_unit);
            }
            if (this.oneFormBatch[0].sale_unit) {
              this.$set(val, 'sale_unit', this.oneFormBatch[0].sale_unit);
            }
            if (this.oneFormBatch[0].unit_convert !== null) {
              this.$set(val, 'unit_convert', this.oneFormBatch[0].unit_convert);
            }
            if (this.oneFormBatch[0].decimal_scale !== null) {
              this.$set(val, 'decimal_scale', this.oneFormBatch[0].decimal_scale);
            }
          }
        } else {
          if (this.oneFormBatch[0].pic) {
            this.$set(val, 'pic', this.oneFormBatch[0].pic);
          }
          if (this.oneFormBatch[0].price !== null) {
            this.$set(val, 'price', this.oneFormBatch[0].price);
			if(!this.$route.params.id){
				this.$set(val, 'price_range_min', this.oneFormBatch[0].price);
				this.$set(val, 'price_range_max', this.oneFormBatch[0].price);
			}
          }
          if (this.oneFormBatch[0].settle_price !== null) {
            this.$set(val, 'settle_price', this.oneFormBatch[0].settle_price);
          }
          if (this.oneFormBatch[0].cost !== null) {
            this.$set(val, 'cost', this.oneFormBatch[0].cost);
          }
          if (this.oneFormBatch[0].ot_price !== null) {
            this.$set(val, 'ot_price', this.oneFormBatch[0].ot_price);
          }
          if (this.oneFormBatch[0].stock !== null) {
            this.$set(val, 'stock', this.oneFormBatch[0].stock);
			// this.batchStock = this.oneFormBatch[0].stock;
          }
		  if (this.oneFormBatch[0].inventory !== null) {
		    this.$set(val, 'inventory', this.oneFormBatch[0].inventory);
		  }
		  if (this.oneFormBatch[0].pm !== null) {
		    this.$set(val, 'pm', this.oneFormBatch[0].pm);
		  }
          if (this.oneFormBatch[0].weight !== null) {
            this.$set(val, 'weight', this.oneFormBatch[0].weight);
          }
          if (this.oneFormBatch[0].volume !== null) {
            this.$set(val, 'volume', this.oneFormBatch[0].volume);
          }
          if (this.oneFormBatch[0].stock_unit) {
            this.$set(val, 'stock_unit', this.oneFormBatch[0].stock_unit);
          }
          if (this.oneFormBatch[0].sale_unit) {
            this.$set(val, 'sale_unit', this.oneFormBatch[0].sale_unit);
          }
          if (this.oneFormBatch[0].unit_convert !== null) {
            this.$set(val, 'unit_convert', this.oneFormBatch[0].unit_convert);
          }
          if (this.oneFormBatch[0].decimal_scale !== null) {
            this.$set(val, 'decimal_scale', this.oneFormBatch[0].decimal_scale);
          }
          this.$set(val, 'bar_code', this.oneFormBatch[0].bar_code);
          this.$set(
            val,
            'code',
            this.oneFormBatch[0].code
          );
        }
      }
    },
    confirm(name) {
      this.createBnt = true;
      this.formData.selectRule = name;
      if (this.formData.selectRule.trim().length <= 0) {
        return this.$Message.error('请选择属性');
      }
      this.ruleList.forEach((item, index) => {
        if (item.rule_name === this.formData.selectRule) {
          this.attrs = this.formData.product_type == 6?item.rule_value.slice(0, 1):item.rule_value;
          this.attrs.map((item) => {
            this.$set(item, 'add_pic', 0);
          });
        }
      });
      this.canSel = true;
      this.generateAttr(this.attrs);
    },
    // 添加规则；
    addRule() {
      this.$refs.addattr.modal = true;
    },
    getEditorContent(data) {
      this.formData.description = data;
    },
    //添加卡密
    addVirtual(index, name) {
      this.tabIndex = index;
      this.tabName = name;
      this.virtualListClear();
      this.$refs.addCarMy.fixedCar = {
        disk_info: '',
        stock: 0,
      };
      this.$refs.addCarMy.cartMyType = 1;
      this.carMyShow = true;
    },
    seeVirtual(data, name, index) {
      this.tabName = name;
      this.tabIndex = index;
      this.virtualListClear();
      this.$refs.addCarMy.fixedCar = {
        disk_info: '',
        stock: 0,
      };
      if (data.virtual_list && data.virtual_list.length) {
        this.$refs.addCarMy.cartMyType = 2;
        this.virtualList = data.virtual_list;
      } else if (data.disk_info) {
        this.$refs.addCarMy.cartMyType = 1;
        this.$refs.addCarMy.fixedCar.disk_info = data.disk_info;
        this.$refs.addCarMy.fixedCar.stock = data.stock;
      }
      this.carMyShow = true;
    },
    closeCarMy() {
      this.carMyShow = false;
    },
    //确认提交卡密
    fixdBtn(e) {
      if (e.cartMyType == 1) {
        if (this.tabName == 'attr') {
          this.formData.attr.disk_info = e.disk_info;
          this.formData.attr.stock = e.stock;
          this.formData.attr.virtual_list = [];
        } else {
          this.$set(this[this.tabName][this.tabIndex], 'disk_info', e.disk_info);
          this.$set(this[this.tabName][this.tabIndex], 'stock', Number(e.stock));
          this[this.tabName][this.tabIndex].virtual_list = [];
        }
      } else {
        if(this.tabName == 'attr'){
          this.formData.attr.virtual_list = e.virtualList;
          this.formData.attr.stock =  e.virtualList.length;
          this.formData.attr.disk_info = "";
        }else{
          this.$set( this[this.tabName][this.tabIndex], "virtual_list",e.virtualList);
          this.$set( this[this.tabName][this.tabIndex],"stock", e.virtualList.length);
          this[this.tabName][this.tabIndex].disk_info = "";
        }
      }
      this.carMyShow = false;
    },
    //添加倒入卡密的值
    changeVirtual(e) {
      this.virtualList = e;
    },
    //清空卡密
    virtualListClear() {
      this.virtualList = [
        {
          key: '',
          value: '',
        },
      ];
    },
    getInfo() {
      let that = this;
      that.spinShow = true;
      productInfoApi(that.$route.params.id || this.$route.query.copy)
        .then(async (res) => {
          let data = res.data.productInfo;
          this.infoData(data);
          this.loadPerformanceRule(data.id || this.$route.params.id);
          // 生成规格
          this.spinShow = false;
          this.success = true;
        })
        .catch((res) => {
          this.spinShow = false;
          this.$Message.error(res.msg);
        });
    },
    loadPerformanceRule(id) {
      if (!id) return;
      performanceRuleApi(Number(id)).then((res) => {
        this.performanceRule = { ...this.performanceRule, ...(res.data || {}) };
      }).catch((res) => {
        this.$Message.error(res.msg || '读取项目业绩配置失败');
      });
    },
    savePerformanceRule() {
      const id = Number(this.$route.params.id || 0);
      if (!id) return;
      this.performanceRuleSaving = true;
      savePerformanceRuleApi(id, {
        labor_mode: 'project_configured_amount',
        labor_configured_unit_amount: this.performanceRule.labor_configured_unit_amount,
        consumption_mode: this.performanceRule.consumption_mode,
        consumption_configured_unit_amount: this.performanceRule.consumption_configured_unit_amount,
      }).then((res) => {
        this.performanceRule = { ...this.performanceRule, ...(res.data || {}) };
        this.$Message.success(res.msg || '保存成功');
      }).catch((res) => {
        this.$Message.error(res.msg || '保存项目业绩配置失败');
      }).finally(() => {
        this.performanceRuleSaving = false;
      });
    },
    infoData(data) {
      // 初始化会员价/佣金数据
      // 多规格
      if (data.spec_type) {
        for (const item of data.attrs) {
          const suk = this.attrValue.find((value) => {
            return value.suk === item.attr_arr.join(',');
          });
          if (suk) {
            item.suk = suk.suk;
            item.vip_price = suk.vip_price;
            item.brokerage = suk.brokerage;
            item.brokerage_two = suk.brokerage_two;
            item.level_price = this.levelList.map((level) => {
              const levelValue = suk.level_price.find((value) => {
                return value.id == level.id;
              });
              if (levelValue) {
                return {
                  ...level,
                  price: levelValue.price,
                  inputPrice: levelValue.price,
                };
              } else {
                const price = this.$computes.Div(
                  this.$computes.Mul(item.price, level.discount),
                  100
                );
                return {
                  ...level,
                  price,
                  inputPrice: price,
                };
              }
            });
          }
        }
      } else {
        const suk = this.attrValue[0];
        data.attr.suk = suk.suk;
        data.attr.vip_price = suk.vip_price;
        data.attr.brokerage = suk.brokerage;
        data.attr.brokerage_two = suk.brokerage_two;
        data.attr.level_price = this.levelList.map((level) => {
          const levelValue = suk.level_price.find((value) => {
            return value.id == level.id;
          });
          if (levelValue) {
            return {
              ...level,
              price: levelValue.price,
              inputPrice: levelValue.price,
            };
          } else {
            const price = this.$computes.Div(
              this.$computes.Mul(data.attr.price, level.discount),
              100
            );
            return {
              ...level,
              price,
              inputPrice: price,
            };
          }
        });
      }
      // 初始化会员价/佣金数据结束

      this.storesList = data.stores || [];
      this.merchantType = parseInt(data.type);
	  this.formData.attr.reservation_time_data = [];
      let keys = Object.keys(this.formData);
      keys.forEach((key) => {
        if (data[key] !== undefined) {
          this.formData[key] = data[key];
        }
      });
      // this.formData.supplier_id = data.relation_id;
      //次卡商品和卡项将核销日期时间段回显
      if(data.product_type == 4 || data.product_type == 5){
        this.section_time = data.attr.section_time;
      }
      this.description = data.description;
      //截取轮播图
      // this.formData.slider_image = this.formData.slider_image.splice(0, 10);
      //多规格 SKU 赋值
      this.attrs = data.items || [];
      if (Number(data.product_type) === 0 && Number(data.spec_type) === 0 && this.attrs[0] && this.attrs[0].value) {
        this.formData.single_spec_name = this.attrs[0].value;
      }
      this.attrs.map((item) => {
        if (item.add_pic) this.canSel = false;
      });
	  this.formData.attr.price_range_min = data.attr && data.attr.price_range_min ? data.attr.price_range_min : 0;
	  this.formData.attr.price_range_max = data.attr && data.attr.price_range_max ? data.attr.price_range_max : 0;
      this.formData.off_show = data.auto_off_time ? 1 : 0;
      this.formData.coupon_ids = this.formData.coupons.map((item) => item.id); //提取优惠券id
      this.formData.couponName = data.coupons;
      this.formData.brand_id = this.formData.brand_id.map(String); //提取品牌 id
      this.formData.is_limit = this.formData.is_limit ? 1 : 0;
      this.formData.limit_type = parseInt(data.limit_type);
      this.formData.system_form_id = data.system_form_id;
      if (this.$route.params.id && this.formData.delivery_type.includes('3') && !this.formData.store_delivery_type.length) {
        this.formData.store_delivery_type = ['1', '2'];
      }
      if (Array.isArray(data.sale_time_week)) {
        this.$refs.reservationSet.weekList.forEach(item=>{
          data.sale_time_week.forEach(j=>{
            if(item.id == j){
              item.selected = true
            }else{
              item.selected = false
            }
          })
        })
      }
      // 生成规格表头
      this.generateHeader(this.attrs);
      if (data.spec_type) {
        data.attrs.map((item) => {
          this.$set(item, 'price', Number(item.price));
          if(this.merchantType == 2){
            this.$set(item, "cost", Number(item.settle_price));
          }else{
            this.$set(item, "cost", Number(item.cost));
          }
          this.$set(item, 'ot_price', Number(item.ot_price));
          this.$set(item, 'weight', Number(item.weight));
          this.$set(item, 'volume', Number(item.volume));
          this.$set(item, 'stock', Number(item.stock));
		  if(this.$route.params.id && [1,6].indexOf(this.formData.product_type) == -1){
		    this.$set(item, "inventory", 0);
		    this.$set(item, "pm", 1);
		  }
        });
      }
      if (this.$route.query.copy) {
        this.type = 1;
        this.clearSkuStockFields();
      }
	  if(this.$route.params.id && [1,6].indexOf(this.formData.product_type) == -1) {
	    this.$set(this.formData.attr, "inventory", 0);
	    this.$set(this.formData.attr, "pm", 1);
	  }
      this.manyFormValidate = [...this.oneFormBatch, ...data.attrs];
      if (data.product_type == 5) {
        let related = data.related.map((item) => {
          return {
            ...item.productInfo.attrInfo,
            store_name: item.productInfo.store_name,
            write_times: item.write_times,
            writeoff_amount: Number(item.writeoff_amount || 0),
          };
        });
        this.cardData = related;
      }
      if (data.product_type == 6) {
	  if(!data.is_advance){
		 data.advance_time = 1
	  }
	  if(!data.is_cancel_reservation){
		 data.cancel_reservation_time = 1
	  }
	  // 回显自动划分时段（判断出已选择和未选择时段）
	  this.reservationTime = this.$splitTimeRange(data.reservation_times,data.reservation_time_interval);
	  let timeData = data.spec_type?data.attrs[0].reservation_time_data:data.attr.reservation_time_data
	  timeData.forEach(item=>{
		  this.timeCheckAllGroup.push(item.start)
	  })
	  //自动划分全选默认值；
	  this.reservationTime.forEach(item=>{
		  this.timeCheckAllClone.push(item.start)
	  })
	  if(this.reservationTime.length==this.timeCheckAllGroup.length){
		  this.timeCheckAll = true
	  }else{
		  this.timeCheckAll = false
	  }
	  // 时段划分默认显示第一个空数组
	  if(data.customize_time_period.length == 0){
		  data.customize_time_period.push([])
	  }
	  let specCloneData = data.attrs.length ? JSON.parse(JSON.stringify(data.attrs[0].reservation_time_data)) : []
	  specCloneData.forEach(item=>{
		  item.stock = 0
	  })
	  if(data.spec_type){
		  if(data.reservation_time_type == 1){
			  this.timeDataClone = specCloneData;
		  }else{
			  this.customizeTimeData = specCloneData;
		  }
	  }else{
		  if(data.reservation_time_type == 1){
			  this.timeDataClone = data.attr.reservation_time_data;
		  }else{
			  this.customizeTimeData = data.attr.reservation_time_data;
		  }
	  }
    }
    },
    generateHeader(data) {
      let specificationsColumns = data.map((item) => ({
        title: item.value,
        key: item.value,
        minWidth: 140,
        fixed: 'left',
      }));
      if (this.formData.product_type == 0) {
        let headerList = [...specificationsColumns, ...GoodsTableHead];
        // 找到售价的索引
        const priceIndex = headerList.findIndex(
          (item) => item.title === '售价'
        );
		if(this.merchantType != 1){
			headerList.splice(priceIndex + 1, 0, {
			  title: '调价-最低价',
			  slot: 'price_range_min',
			  align: 'center',
			  minWidth: '150px',
			});
			headerList.splice(priceIndex + 2, 0, {
			  title: '调价-最高价',
			  slot: 'price_range_max',
			  align: 'center',
			  minWidth: '150px',
			});
		}
        // 在售价后面插入结算价对象
        if (priceIndex !== -1 && (this.merchantType == 2 || this.goodsSource == 2)) {
          headerList.splice(priceIndex + 1, 0, {
            title: '结算价',
            slot: 'settle_price',
            align: 'center',
            minWidth: '120px',
          });
        }
        this.formData.header = headerList;
      } else if (this.formData.product_type == 3) {
        this.formData.header = [...specificationsColumns, ...VirtualTableHead];
      } else if (this.formData.product_type == 1) {
        this.formData.header = [...specificationsColumns, ...VirtualTableHead2];
      }else if (this.formData.product_type == 6){
		let headerList = [...specificationsColumns, ...ReservationTableHead];
		// 找到售价的索引
		const priceIndex = headerList.findIndex(
		  (item) => item.title === '售价'
		);
		if(this.merchantType != 1){
			headerList.splice(priceIndex + 1, 0, {
			  title: '调价-最低价',
			  tips: '（最小值）',
			  slot: 'price_range_min',
			  align: 'center',
			  minWidth: '150px',
			});
			headerList.splice(priceIndex + 2, 0, {
			  title: '调价-最高价',
			  tips: '（最大值）',
			  slot: 'price_range_max',
			  align: 'center',
			  minWidth: '150px',
			});
		}
		this.formData.header = headerList;
	  }
      // 产品库存仅由库存模块调整，编辑页不再插入「调整库存」列
      this.columnsInstalM = this.formData.header;
    },
	// 单规格以及多规格验证区域价格
	rangePriceVerify(min,max,price){
		if((min==null || min==0) && max>0){
			if(price>max){
				this.$Message.warning('最大值应大于等于售价');
				return false
			}
			return true
		}
		if((max==null || max==0) && min>0){
			if(price<min){
				this.$Message.warning('最小值应小于等于售价');
				return false
			}
			return true
		}
		if(min>0 && max>0){
			if(price<min || price>max){
				this.$Message.warning('售价需在调价区间内');
				return false
			}
			return true
		}
		return true
	},
	// 单规格验证区域价格
	rangePrice(){
		let min = this.formData.attr.price_range_min;
		let max = this.formData.attr.price_range_max;
		let price = this.formData.attr.price;
		return this.rangePriceVerify(min,max,price);
	},
	// 多规格表单验证图片、售价、区域价格；
	specVerify(formData) {
		for (let i = 0; i < formData.attrs.length; i++) {
		  if (!formData.attrs[i].pic) {
		     this.$Message.warning('请上传商品属性的规格图');
			 return false
		  }
		  if (formData.attrs[i].price == null || formData.attrs[i].price == 0) {
		     this.$Message.warning('请填写商品属性的售价');
			 return false
		  }
		  let min = formData.attrs[i].price_range_min;
		  let max = formData.attrs[i].price_range_max;
		  let price = formData.attrs[i].price;
		  if(!this.rangePriceVerify(min,max,price)){
			  return
		  }
		  return true
		}
		return false
	},
    // 上一页；
    upTab() {
      const index = this.filterHeadTab.findIndex((item) => {
        return item.name == this.currentTab;
      });
      this.currentTab = this.filterHeadTab[index - 1].name;
    },
    // 下一页；
    downTab(name) {
      this.$refs[name].validate((valid) => {
        if (valid) {
          if (this.formData.is_show == 2 && !this.formData.auto_on_time) {
            return this.$Message.warning('请填写定时上架时间');
          }
          if (this.off_show == 1 && !this.formData.auto_off_time) {
            return this.$Message.warning('请填写定时下架时间');
          }
		  if (this.merchantType != 1 && [0, 4, 5, 6].includes(this.formData.product_type) && this.currentTab == 2 && this.formData.spec_type == 0) {
			  if(!this.rangePrice()){
				  return
			  }
		  }
		  if(this.merchantType != 1 && [0, 4, 5, 6].includes(this.formData.product_type) && this.currentTab == 2 && this.formData.spec_type == 1){
			  let formData = this.summarizeData();
			  if(!this.specVerify(formData)){
				  return
			  }
		  }
          if (this.currentTab == 4 && !this.formData.delivery_type.length) {
            return this.$Message.warning('请选择配送方式');
          }
          if (this.currentTab == 10) {
            if (!this.$refs.vipPriceBrokerageSet.validateForm()) {
              return;
            }
          }
          const index = this.filterHeadTab.findIndex((item) => {
            return item.name == this.currentTab;
          });
          this.currentTab = this.filterHeadTab[index + 1].name;
        } else {
          this.$Message.warning('请完善数据');
        }
      });
    },
    attrPicTap() {
      this.modalPicTap('dan', 'attr');
    },
    setAllPic() {
      this.modalPicTap('dan', 'oneFormBatch');
    },
    setAttrPic(index) {
      this.modalPicTap('dan', 'manyFormValidate', index);
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
    // 获取运费模板；
    productGetTemplate() {
      productGetTemplateApi({ id: this.$route.params.id }).then((res) => {
        this.templateList = res.data;
      });
    },
    // 添加运费模板
    addTemp() {
      this.$refs.templates.isTemplate = true;
    },
    //查看、编辑运费模板
    editTemp() {
      this.$refs.templates.isTemplate = true;
      this.$refs.templates.editFrom(this.formData.temp_id);
    },
    changeTemplate(msg) {
      this.template = msg;
    },
    // 验证售价大于自定义会员价
    validatePrice(attrs) {
      return new Promise((resolve, reject) => {
        const attrLevelVipPriceMap = new Map();
        this.attrValue.forEach(({ suk, level_price, vip_price }) => {
          attrLevelVipPriceMap.set(suk, {
            levelPrice: level_price.map(({ price }) => Number(price)),
            vipPrice: Number(vip_price),
          });
        });

        const isMaxPrice = attrs.some((attr) => {
          const suk = attr.attr_arr.join();
          const hasSuk = attrLevelVipPriceMap.has(suk);
          if (!hasSuk) {
            return false;
          }
          const { levelPrice, vipPrice } = attrLevelVipPriceMap.get(suk);
          return (this.isVip && attr.price < vipPrice) || (this.levelType == 2 && levelPrice.some((lPrice) => attr.price < lPrice));
        });
        if (!isMaxPrice) {
          resolve();
          return;
        }
        this.$Modal.confirm({
          title: '提示',
          content: `当前设置的商品售价低于${this.formData.spec_type ? '部分' : ''}会员价，是否继续保存？`,
          okText: '保存',
          onOk: () => resolve(),
          onCancel: () => reject(new Error('取消')),
        });
      });
    },
    isWholeYuanMoney(value) {
      if (typeof value === 'number') {
        return Number.isInteger(value) && value >= 0;
      }
      return /^(?:0|[1-9]\d*)(?:\.0+)?$/.test(String(value == null ? '' : value).trim());
    },
    normalizeWholeYuanMoney(value) {
      return Number(String(value).trim());
    },
    validateWholeYuanMoney(formData) {
      if (![0, 4, 5, 6].includes(Number(formData.product_type))) return true;
      const labels = {
        price: '售价',
        ot_price: '划线价',
        settle_price: '结算价',
        cost: '成本价',
      };
      if (Number(formData.is_vip) === 1) labels.vip_price = '会员价';
      if (Number(formData.is_brokerage) === 1 && Number(formData.is_sub) === 1) {
        labels.brokerage = '一级返佣';
        labels.brokerage_two = '二级返佣';
      }
      const attrs = Number(formData.spec_type) === 1 ? formData.attrs : [formData.attr];
      for (const attr of attrs) {
        if (Number(formData.is_vip) !== 1) attr.vip_price = 0;
        if (Number(formData.is_brokerage) !== 1 || Number(formData.is_sub) !== 1) {
          attr.brokerage = 0;
          attr.brokerage_two = 0;
        }
        for (const [field, label] of Object.entries(labels)) {
          if (!Object.prototype.hasOwnProperty.call(attr, field)) continue;
          if (this.isWholeYuanMoney(attr[field])) {
            attr[field] = this.normalizeWholeYuanMoney(attr[field]);
            continue;
          }
          const sku = String(attr.suk || (attr.attr_arr || []).join() || '默认');
          this.currentTab = ['vip_price', 'brokerage', 'brokerage_two'].includes(field) ? '10' : '2';
          this.$Message.error(`规格【${sku}】的${label}必须填写整数金额`);
          return false;
        }
        if (Number(formData.level_type) === 2 && Array.isArray(attr.level_price)) {
          for (const levelPrice of attr.level_price) {
            const price = levelPrice.price != null ? levelPrice.price : levelPrice.inputPrice;
            if (!this.isWholeYuanMoney(price)) {
              const sku = String(attr.suk || (attr.attr_arr || []).join() || '默认');
              this.currentTab = '10';
              this.$Message.error(`规格【${sku}】的等级会员价必须填写整数金额`);
              return false;
            }
            levelPrice.price = this.normalizeWholeYuanMoney(price);
            if (Object.prototype.hasOwnProperty.call(levelPrice, 'inputPrice')) {
              levelPrice.inputPrice = levelPrice.price;
            }
          }
        }
      }
      return true;
    },
    async handleSubmit() {
      let formData = this.summarizeData();
      if (Number(formData.product_type) === 5) formData.unit_name = '张';
      if (Number(formData.product_type) === 6) formData.unit_name = '次';
      if (formData.store_name == '') {
        this.currentTab = '1';
        this.$Message.error('请添加商品名称');
        return;
      }
      if (!formData.cate_id.length) {
        this.currentTab = '1';
        this.$Message.error('请选择商品分类');
        return;
      }
      if (![5, 6].includes(Number(formData.product_type)) && formData.unit_name == '') {
        this.currentTab = '1';
        this.$Message.error('请添加商品单位');
        return;
      }
      if (!this.validateCardRuleSettings(formData)) {
        this.currentTab = '2';
        return;
      }
	  if (this.merchantType != 1 && [0, 4, 5, 6].includes(this.formData.product_type) && this.formData.spec_type == 0) {
		  if(!this.rangePrice()){
			  return
		  }
	  }
      let storeId = [];
      this.storesList.forEach((item) => {
        storeId.push(item.id);
      });
      if (formData.applicable_type == 2 && !storeId.length) {
        return this.$Message.warning('适用门店-请选择适用门店');
      }
      formData.applicable_store_id = storeId;
      formData.type = this.type;
	  let weekId = [];
	  this.$refs.reservationSet.weekList.forEach(item=>{
		  if(item.selected){
			  weekId.push(item.id);
		  }
	  })
	  formData.sale_time_week = weekId;
    // 商品规格的验证
    for (let i = 0; i < formData.items.length; i++) {
      if (formData.items[i].add_pic) {
        for (let j = 0; j < formData.items[i].detail.length; j++) {
          if (!formData.items[i].detail[j].pic) {
            return this.$Message.warning('请上传规格图');
          }
        }
      }
    }

    // 商品属性的验证
    // for (let i = 0; i < formData.attrs.length; i++) {
    //   if (!formData.attrs[i].pic) {
    //     return this.$Message.warning('请上传商品属性的规格图');
    //   }
    //   if (formData.attrs[i].price == null || formData.attrs[i].price == 0) {
    //     return this.$Message.warning('请填写商品属性的售价');
    //   }
    // }
	if(this.merchantType != 1 && [0, 4, 5, 6].includes(this.formData.product_type) && this.formData.spec_type == 1 && !this.specVerify(formData)){
		return
	}
    if (!this.$refs.vipPriceBrokerageSet.validateForm()) {
      return
    }
    const vipPriceBrokerageData = this.$refs.vipPriceBrokerageSet.getFormData();
    formData.is_brokerage=vipPriceBrokerageData.is_brokerage;
    formData.is_sub=vipPriceBrokerageData.is_sub;
    formData.is_vip=vipPriceBrokerageData.is_vip;
    formData.level_type=vipPriceBrokerageData.level_type;
    // 多规格
    if (formData.spec_type) {
      formData.attrs.forEach((item) => {
        const result = vipPriceBrokerageData.attrData.find((value) => {
          return item.attr_arr.join() == value.attr_arr.join();
        });
        if (result) {
          item.brokerage = result.brokerage;
          item.brokerage_two = result.brokerage_two;
          item.vip_price = result.vip_price;
          item.level_price = result.level_price;
        }
      })
    } else {
      formData.attr.brokerage=vipPriceBrokerageData.attrData[0].brokerage;
      formData.attr.brokerage_two=vipPriceBrokerageData.attrData[0].brokerage_two;
      formData.attr.vip_price=vipPriceBrokerageData.attrData[0].vip_price;
      formData.attr.level_price=vipPriceBrokerageData.attrData[0].level_price;
    }
    if (!this.validateWholeYuanMoney(formData)) return;
    if (!formData.product_type && formData.delivery_type.includes('3') && !formData.store_delivery_type.length) {
      return this.$Message.warning('请选择配送类型');
    }
      productAddApi(formData)
        .then(async (res) => {
          this.openSubimit = true;
          this.$Message.success(res.msg);
          const createdProductId = Number(res && res.data && res.data.product_id);
          if (Number(formData.product_type) === 6 && !Number(this.$route.params.id || 0) && createdProductId > 0) {
            try {
              await savePerformanceRuleApi(createdProductId, {
                labor_mode: 'project_configured_amount',
                labor_configured_unit_amount: this.performanceRule.labor_configured_unit_amount,
                consumption_mode: this.performanceRule.consumption_mode,
                consumption_configured_unit_amount: this.performanceRule.consumption_configured_unit_amount,
              });
            } catch (ruleError) {
              this.openSubimit = false;
              this.$Message.error((ruleError && ruleError.msg) || '商品已创建，但手工费保存失败，请重试');
              return;
            }
          }
          if (this.$route.params.id === '0') {
            cacheDelete().catch((err) => {
              this.$Message.error(err.msg);
            });
          }
          setTimeout(() => {
            this.$router.push({
              path: `${this.roterPre}/product/product_list`,
            });
          }, 500);
        })
        .catch((res) => {
          this.openSubimit = false;
          this.$Message.error(res.msg);
        });
    },
    summarizeData() {
      let baseSetData = this.$refs.productBaseSet.formValidate;
      let marketingSetData = this.$refs.marketingSet.formValidate;
      let otherSetData = this.$refs.otherSet.formValidate;
      let cardFaceSet = this.$refs.cardFaceSet.getFormData();
      let formData = { ...baseSetData, ...marketingSetData, ...otherSetData, ...cardFaceSet };
      if (this.$route.query.copy) {
        this.$set(formData, 'id', 0);
        this.type = 1;
        this.clearSkuStockFields();
      }
      this.$set(formData, "slider_image", this.formData.slider_image);
      this.$set(formData, "type", this.type);
      this.$set(formData, 'create_request_key', this.create_request_key || this.genCreateRequestKey());
      this.$set(formData, "product_type", this.formData.product_type);
      this.$set(formData, 'spec_type', this.formData.spec_type);
      this.$set(formData, 'single_spec_name', this.formData.single_spec_name);
      this.$set(formData, 'items', this.attrs);
      this.$set(formData, 'attr', this.formData.attr);
      this.$set(formData, 'attrs', this.manyFormValidate.slice(1));
      this.$set(formData, 'description', this.formData.description);
      this.$set(formData, 'delivery_type', this.formData.delivery_type);
      this.$set(formData, 'store_delivery_type', this.formData.store_delivery_type);
      this.$set(formData, 'freight', this.formData.freight);
      this.$set(formData, 'postage', this.formData.postage);
      this.$set(formData, 'temp_id', this.formData.temp_id);
      this.$set(formData, 'applicable_type', this.formData.applicable_type);
	  this.$set(formData, 'is_inventory', this.formData.product_type == 0 ? (this.formData.is_inventory != null ? this.formData.is_inventory : 1) : 0);
	  this.$set(formData, 'allow_negative_stock', this.formData.product_type == 0 ? (this.formData.allow_negative_stock != null ? this.formData.allow_negative_stock : 1) : 1);
	  this.$set(formData, 'salon_stock_enabled', (this.formData.product_type == 0 && (this.formData.is_inventory != null ? this.formData.is_inventory : 1) == 1) ? (this.formData.salon_stock_enabled != null ? this.formData.salon_stock_enabled : 0) : 0);
	  this.$set(formData, 'reservation_time_type', this.formData.reservation_time_type);
	  this.$set(formData, 'reservation_times', this.formData.reservation_times);
	  this.$set(formData, 'reservation_time_interval', this.formData.reservation_time_interval);
	  this.$set(formData, 'customize_time_period', this.formData.customize_time_period);
	  this.$set(formData, 'reservation_type', this.formData.reservation_type);
	  this.$set(formData, 'reservation_timing_type', this.formData.reservation_timing_type);
	  this.$set(formData, 'is_show_stock', this.formData.is_show_stock);
	  this.$set(formData, 'sale_time_type', this.formData.sale_time_type);
	  this.$set(formData, 'sale_time_week', this.formData.sale_time_week);
	  this.$set(formData, 'sale_time_data', this.formData.sale_time_data);
	  this.$set(formData, 'show_reservation_days_type', this.formData.show_reservation_days_type);
	  this.$set(formData, 'show_reservation_days', this.formData.show_reservation_days);
	  this.$set(formData, 'is_advance', this.formData.is_advance);
	  this.$set(formData, 'advance_time', this.formData.advance_time);
	  this.$set(formData, 'is_cancel_reservation', this.formData.is_cancel_reservation);
	  this.$set(formData, 'cancel_reservation_time', this.formData.cancel_reservation_time);
	  this.$set(formData, 'project_service_duration', this.formData.project_service_duration || 0);
	  this.$set(formData, 'addon_service_duration', this.formData.addon_service_duration || 0);
	  this.$set(formData, 'related', this.cardData);
      this.$set(
        formData,
        'label_id',
        marketingSetData.label_id.map((item) => item.id)
      );
      this.$set(
        formData,
        'store_label_id',
        baseSetData.store_label_id.map((item) => item.id)
      );
      this.$set(
        formData,
        'recommend_list',
        marketingSetData.recommend_list.map((item) => item.product_id)
      );
      this.$set(formData, 'is_sync_show', this.formData.is_sync_show);
      this.$set(formData, 'is_sync_stock', this.formData.is_sync_stock);
      this.$set(formData, 'card_num', this.formData.card_num);
      this.$set(formData, 'card_num_type', this.formData.card_num_type);
      this.$set(formData, 'card_rule_type', this.formData.card_rule_type);
      this.$set(formData, 'card_rule_version', this.formData.card_rule_version || 0);
      this.$set(formData, 'card_choice_limit', this.formData.card_choice_limit || 0);
      this.$set(formData, 'card_shared_times', this.formData.card_shared_times || 0);
      return formData;
    },
    validateCardRuleSettings(formData) {
      if (Number(formData.product_type) !== 5) return true;
      const ruleType = formData.card_rule_type;
      if (!['normal', 'choice_kind', 'choice_count', 'time'].includes(ruleType)) {
        this.$Message.warning('请选择卡项规则');
        return false;
      }
      if (!Array.isArray(formData.related) || !formData.related.length) {
        this.$Message.warning('请添加卡内项目');
        return false;
      }
      const validity = Number((formData.attr || {}).write_valid || 0);
      if (![1, 2, 3].includes(validity)) {
        this.$Message.warning('请选择核销时效类型');
        return false;
      }
      if (ruleType === 'time' && validity === 1) {
        this.$Message.warning('时间卡不允许永久有效');
        return false;
      }
      if (validity === 2 && Number((formData.attr || {}).days || 0) <= 0) {
        this.$Message.warning('请填写有效天数');
        return false;
      }
      if (validity === 3 && (!Array.isArray((formData.attr || {}).section_time) || formData.attr.section_time.length !== 2)) {
        this.$Message.warning('请选择固定有效期');
        return false;
      }
      if (ruleType === 'choice_kind') {
        const choiceLimit = Number(formData.card_choice_limit || 0);
        if (!Number.isInteger(choiceLimit) || choiceLimit <= 0 || choiceLimit > formData.related.length) {
          this.$Message.warning('最多可选项目种数必须大于0且不能超过卡内项目总数');
          return false;
        }
      }
      if (ruleType === 'choice_count') {
        const sharedTimes = Number(formData.card_shared_times || 0);
        if (!Number.isInteger(sharedTimes) || sharedTimes <= 0) {
          this.$Message.warning('共享总次数必须是大于0的整数');
          return false;
        }
      }
      if (['normal', 'choice_kind'].includes(ruleType)) {
        const invalidTimes = formData.related.some((item) => !Number.isInteger(Number(item.write_times)) || Number(item.write_times) <= 0);
        if (invalidTimes) {
          this.$Message.warning('每个卡内项目的可使用次数必须是大于0的整数');
          return false;
        }
      }
      if (ruleType === 'time') {
        const invalidAmount = formData.related.some((item) => item.writeoff_amount === '' || item.writeoff_amount === null || !Number.isInteger(Number(item.writeoff_amount)) || Number(item.writeoff_amount) < 0);
        if (invalidAmount) {
          this.$Message.warning('每个时间卡项目都必须填写不小于0的整数核销金额');
          return false;
        }
      }
      return true;
    },
    handleSaveAsTemplate() {
      let that = this;
      that.$Modal.confirm({
        title: '另存为模板',
        render(h) {
          return h('div', [
            h('Input', {
              props: {
                placeholder: '请输入模板名称',
                value: '',
              },
              style: {
                marginTop: '20px',
              },
              on: {
                input: (val) => {
                  that.templateName = val;
                },
              },
            }),
          ]);
        },
        onOk: () => {
          let spec = this.attrs.map((item) => {
            return {
              value: item.value,
              detail: item.detail.map((e) => e.value),
            };
          });
          let formDynamic = {
            rule_name: that.templateName,
            spec: spec,
          };
          ruleAddApi(formDynamic, 0)
            .then((res) => {
              that.$Message.success(res.msg);
              that.productGetRule();
            })
            .catch((res) => {
              this.$message.error(res.msg);
            });
        },
      });
    },
    // 添加门店
    addStore() {
      this.storeModals = true;
    },
    getStoreId(data) {
      this.storeModals = false;
      let list = this.storesList.concat(data);
      let uni = this.unique(list);
      this.storesList = uni;
    },
    // 对象数组去重
    unique(arr) {
      const res = new Map();
      return arr.filter((arr) => !res.has(arr.id) && res.set(arr.id, 1));
    },
    // 删除门店
    delte(index) {
      this.storesList.splice(index, 1);
    },
    modalPicTap(tit, picTit, index) {
      this.modalPic = true;
      this.isChoice = tit === 'dan' ? '单选' : '多选';
      this.picTit = picTit;
      this.tableIndex = index;
    },
    getPic(pc) {
      switch (this.picTit) {
        case 'danFrom':
          this.formValidate.image = pc.att_dir;
          if (!this.$route.params.id) {
            if (this.formValidate.spec_type === 0) {
              this.oneFormValidate[0].pic = pc.att_dir;
            } else {
              this.manyFormValidate.map((item) => {
                item.pic = pc.att_dir;
              });
              this.oneFormBatch[0].pic = pc.att_dir;
            }
          }
          break;
        case 'danTable':
          this.oneFormValidate[this.tableIndex].pic = pc.att_dir;
          break;
        case 'duopi':
          this.oneFormBatch[this.tableIndex].pic = pc.att_dir;
          break;
        // 商品推荐图
        case 'recommend_image':
          this.formData.recommend_image = pc.att_dir;
          break;
        case 'video':
          this.formData.video_link = pc.att_dir;
          break;
        // 某个商品属性图片
        case 'manyFormValidate':
          this.manyFormValidate[this.tableIndex].pic = pc.att_dir;
          break;
        // 批量商品属性图片
        case 'oneFormBatch':
          this.oneFormBatch[0].pic = pc.att_dir;
          break;
        // 单规格
        case 'attr':
          this.formData.attr.pic = pc.att_dir;
          break;
        // 多规格
        case 'attrs':
          this.attrs[this.tableIndex[0]].detail[this.tableIndex[1]].pic =
            pc.att_dir;
          this.changeSpecImg(
            [this.attrs[this.tableIndex[0]].detail[this.tableIndex[1]].value],
            pc.att_dir
          );
          break;
        case 'card_cover_image':
          this.formData.card_cover_image = pc.att_dir;
          break;
        default:
          this.manyFormValidate[this.tableIndex].pic = pc.att_dir;
      }
      this.modalPic = false;
    },
    getPicD(pc) {
      let slider_image = pc.map((item) => {
        return item.att_dir;
      });
	  let imgArry = [...this.formData.slider_image, ...slider_image];
      this.formData.slider_image = imgArry.slice(0, 10);
      this.modalPic = false;
    },
    cancel() {
      this.$router.push({ path: `${this.roterPre}/product/product_list` });
    },
    // 关闭淘宝弹窗并生成数据
    onClose(data) {
      this.modals = false;
      this.infoData(data);
      this.success = true;
    },
    // 添加卡项选择的商品
    getAtterId(selectCardData){
      this.goodsModal = false;
      const uniqueSet = new Set(this.cardData.map((item) => item.unique));
      const newCardData = selectCardData.filter((item) => !uniqueSet.has(item.unique));
      newCardData.forEach((item) => {
        item.write_times = 1;
        item.writeoff_amount = 0;
      });
      this.cardData = [...this.cardData, ...newCardData];
    },
    formatWholeYuan(value) {
      const amount = Number(value);
      return Number.isFinite(amount) ? String(Math.trunc(amount)) : '0';
    },
    requestCardRuleChange(nextType) {
      if (nextType === this.formData.card_rule_type) return;
      const applyChange = () => {
        this.cardData = [];
        this.cardDataSelection = [];
        this.$set(this.formData, 'card_choice_limit', 0);
        this.$set(this.formData, 'card_shared_times', 0);
        this.$set(this.formData, 'card_num', 0);
        this.$set(this.formData, 'card_num_type', 0);
        this.$set(this.formData.attr, 'write_valid', 0);
        this.$set(this.formData.attr, 'days', 0);
        this.$set(this.formData.attr, 'section_time', []);
        this.section_time = [];
        this.$set(this.formData, 'card_rule_type', nextType);
      };
      if (!this.cardData.length) {
        applyChange();
        return;
      }
      this.$Modal.confirm({
        title: '确认切换卡项规则？',
        content: '切换后将清空卡内项目、次数规则、单次核销金额和有效期。商品基础信息及价格保留。',
        okText: '清空并切换',
        cancelText: '取消',
        onOk: applyChange,
      });
    },
    // 批量设置卡项可核销次数
    handleBatch() {
      this.cardData.forEach((item) => {
        item.write_times = this.batchWriteTimes;
      })
    },
    // 删除卡项选择的商品
    cardDataRowDelete(index) {
      this.cardData.splice(index, 1);
    },
    handleRemoveCardCoverImage() {
      this.formData.card_cover_image = '';
    },
    onchangeTime(e){
      this.formData.attr.section_time = e;
    },
    // 卡项选中
    cardDataChange(selection) {
      this.cardDataSelection = selection;
    },
    // 卡项删除
    cardDataDelete() {
      if (!this.cardDataSelection.length) {
        return;
      }
      const deleteIds = this.cardDataSelection.map((item) => {
        return item.id;
      });
      this.cardData = this.cardData.filter((item) => {
        return !deleteIds.includes(item.id);
      });
      this.cardDataSelection = [];
    },
    sourceChange(value) {
      this.goodsSource = value;
      this.generateHeader(this.attrs);
    },
    // 获取自定义会员价数据
    getProductBrokerage() {
      productBrokerage((this.$route.params.id || this.$route.query.copy || 0), 0).then((res) => {
        const { storeInfo, attrValue, level_list, store_brokerage_ratio, store_brokerage_two } = res.data;
        const { is_vip, level_type, is_brokerage, is_sub } = storeInfo;
        this.isVip = is_vip;
        this.levelType = level_type;
        this.isBrokerage = is_brokerage;
        this.isSub = is_sub;
        this.storeBrokerageRatio = Number(store_brokerage_ratio);
        this.storeBrokerageTwo = Number(store_brokerage_two);
        this.levelList = level_list.map((item) => {
          return {
            ...item,
            discount: item.discount * 100,
          };
        });
        this.attrValue = Object.values(attrValue);
        if (this.$route.params.id || this.$route.query.copy) {
          this.changeSpec();
          this.getInfo();
        }
      }).catch((err) => {
        this.$Message.error(err.msg);
        if (this.$route.params.id || this.$route.query.copy) {
          this.changeSpec();
          this.getInfo();
        }
      });
    },
	changeInventory(value, index){
	  this.manyFormValidate[index].inventory = value.replace(/[^\d]/g, '');
	},
	changeOneInventory(value){
	  this.oneFormBatch[0].inventory = value.replace(/[^\d]/g, '');
	},
	changeOnePm(val){
	  this.manyFormValidate.map(item=>{
	    this.$set(item, 'pm', val);
	  })
	},
	// 调整库存
	handleChange(event,index,num){
		let value = event.target.value;
		value = Number(value);
		let that = this
		if(value < 0){
			clearTimeout(that.timeoutId)
			that.timeoutId = setTimeout(function(){
				that.formData.attr.inventory = 0;
				that.oneFormBatch[0].inventory = 0;
				if(num){
					that.manyFormValidate[index].inventory = 0;
				}
			},1200)
		}
	},
  // 配送方式改变
  onDeliveryTypeChange(value) {
    if (!value.includes('3')) {
      this.formData.store_delivery_type = [];
    }
  },
  },
};
</script>
<style scoped lang="less">
/deep/.ivu-select-item{
	font-size: 12px !important;
}
/deep/.ivu-select-single .ivu-select-selection .ivu-select-selected-value{
	font-size: 12px !important;
}
/deep/.ivu-input-group .ivu-input{
	font-size: 14px !important;
}
/deep/.el-table .cell{
	overflow: unset;
}
.stock-input-box{
  width: 50%;
  height: 32px;
  border-radius: 4px;
  background-color: #f5f7fa;
  padding: 0 7px;
  line-height: 32px;
  font-size: 14px;
  border:1px solid #dcdee2;
  &.on{
	  width: 100%;
	  font-size: 12px;
  }
}
.iconbianji1 {
  font-size: 12px;
  padding-left: 4px;
  cursor: pointer;
}
/deep/.el-table th>.cell{
	display: flex;
	align-items: center;
}
.customize-time:hover{
	.hidden{
		display: block;
	}
}
.timeTable /deep/.ivu-table-header thead tr th{
	padding: 2px 35px;
}
.timeTable /deep/.ivu-table td{
	height: 65px;
}
/deep/.ivu-checkbox-small{
	font-size: 12px !important;
}
.new_tab {
  /deep/.ivu-tabs-nav .ivu-tabs-tab {
    padding: 4px 16px 20px !important;
    font-weight: 500;
  }
}
.fixed-card {
  position: fixed;
  right: 0;
  bottom: 0;
  left: 200px;
  z-index: 20;
  box-shadow: 0 -1px 2px rgb(240, 240, 240);

  /deep/ .ivu-card-body {
    padding: 15px 16px 14px;
  }

  .ivu-form-item {
    margin-bottom: 0;
  }

  /deep/ .ivu-form-item-content {
    margin-right: 124px;
    text-align: center;
  }

  .ivu-btn {
    height: 36px;
    padding: 0 20px;
  }
}
.text-blue {
  color: #2d8cf0;
}
.submission {
  margin-left: 10px;
}
.ifam {
  width: 344px;
  height: 644px;
  background: url('../../../assets/images/phonebg.png') no-repeat center top;
  background-size: 344px 644px;
  padding: 40px 20px;
  padding-top: 50px;
  margin: 0 auto 0 20px;

  .content {
    height: 560px;
    overflow: hidden;
    scrollbar-width: none; /* firefox */
    -ms-overflow-style: none; /* IE 10+ */
    overflow-x: hidden;
    overflow-y: auto;
  }

  .content::-webkit-scrollbar {
    display: none; /* Chrome Safari */
  }
}
.move-icon {
  width: 30px;
  cursor: move;
  margin-right: 10px;
}

.move-icon .icondrag2 {
  font-size: 26px;
  color: #bbb;
}
.drag {
  cursor: move;
  margin: 5px 0;
}
.spec {
  display: block;
  margin: 5px 0;
  position: relative;
  .img-popover {
    cursor: pointer;
    width: 76px;
    height: 76px;
    padding: 6px;
    margin-top: 12px;
    background-color: #fff;
    position: relative;
    border: 1px solid #dcdfe6;
    border-radius: 4px;
    display: flex;
    align-items: center;
    justify-content: center;
    &:hover .img-del {
      display: block;
    }
    .img-del {
      display: none;
      position: absolute;
      right: 3px;
      top: 3px;
      font-size: 16px;
      color: #2d8cf0;
      cursor: pointer;
      z-index: 9;
    }
    .popper {
      width: 100%;
      height: 100%;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      border-radius: 4px;
    }
    .popper-arrow,
    .popper-arrow:after {
      position: absolute;
      display: block;
      width: 0;
      height: 0;
      border-color: transparent;
      border-style: solid;
    }
    .popper-arrow {
      top: -13px;
      border-top-width: 0;
      border-bottom-color: #dcdfe6;
      border-width: 6px;
      filter: drop-shadow(0 2px 12px rgba(0, 0, 0, 0.03));
      &::after {
        top: -5px;
        margin-left: -6px;
        border-top-width: 0;
        border-bottom-color: #fff;
        content: ' ';
        border-width: 6px;
      }
    }
  }
  .del {
    position: absolute;
    display: none;
    right: -3px;
    top: -3px;
    z-index: 9;
  }
}
.spec:hover {
  .del {
    display: block;
    z-index: 999;
    cursor: pointer;
  }
}
/deep/.ivu-input-prefix,
.ivu-input-suffix {
  transform: translateY(7px);
}
.del2 {
  position: absolute;
  right: -4px;
  top: -4px;
  font-size: 14px;
  display: none;
}
.spec:hover {
  .del2 {
    display: block;
    z-index: 999;
    cursor: pointer;
  }
}
.rulesBox {
  display: flex;
  flex-wrap: wrap;
  align-items: center;

  .item {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
  }
  .addfont {
    margin-top: 5px;
  }
  /deep/ .el-popover {
    border: none;
    box-shadow: none;
    padding: 0;
    line-height: 1.5;
  }
}
// 多规格设置
.specifications {
  .specifications-item:hover {
    background-color: #e5eeff;
  }
  .specifications-item:hover .del {
    display: block;
  }
  .specifications-item {
    position: relative;
    display: flex;
    align-items: center;
    padding: 20px 15px;
    transition: all 0.1s;
    background-color: #fafafa;
    margin-bottom: 10px;
    border-radius: 4px;

    .del {
      display: none;
      position: absolute;
      right: 15px;
      top: 15px;
      z-index: 1;
      font-size: 22px;
      color: #2d8cf0;
      cursor: pointer;
    }
    .specifications-item-box {
      position: relative;
      .lineBox {
        position: absolute;
        left: 13px;
        top: 24px;
        width: 30px;
        height: 45px;
        border-radius: 6px;
        border-left: 1px solid #dcdfe6;
        border-bottom: 1px solid #dcdfe6;
      }
      .specifications-item-name {
        .ivu-icon {
          color: #2d8cf0;
          font-size: 16px;
        }
      }
      .mb18 {
        margin-bottom: 18px !important;
      }
      .specifications-item-name-input {
        width: 200px;
		/deep/.ivu-input{
			padding-right: 45px;
		}
      }
	  .specifications-item-val-input{
		  width: 187px;
		  /deep/.ivu-input{
			padding-right: 45px;
		  }
	  }
    }
  }
}
.priceBox {
  width: 100%;
}
.custom-header-class tr {
  background: #f3f8fe !important;
}
.tips {
  display: inline-bolck;
  font-size: 12px;
  color: #999999;
}
.card-rule-summary,
.card-rule-preview {
  max-width: 760px;
  margin-top: 10px;
  padding: 10px 12px;
  line-height: 1.6;
  color: #515a6e;
  background: #f3f8fe;
  border-left: 3px solid #2d8cf0;
}
.card-rule-preview {
  margin-top: 16px;
}
.seeCatMy {
  color: #2d8cf0;
  cursor: pointer;
}
.store-image-column img {
  width: 36px;
  height: 36px;
}
.small .pictrue {
  width: 40px;
  height: 40px;
  margin-right: 0;
  margin-bottom: 0;
  img {
    width: 100%;
    height: 100%;
  }
}
.pictrue {
  width: 60px;
  height: 60px;
  border: 1px dotted rgba(0, 0, 0, 0.1);
  margin-right: 15px;
  margin-bottom: 10px;
  display: inline-block;
  position: relative;
  cursor: pointer;
  img {
    width: 100%;
    height: 100%;
  }
}
.upLoad {
  width: 58px;
  height: 58px;
  line-height: 58px;
  border: 1px dotted rgba(0, 0, 0, 0.1);
  border-radius: 4px;
  background: rgba(0, 0, 0, 0.02);
  cursor: pointer;
}
/deep/.el-table th {
  background: #f3f8fe !important;
  color: #515A6E !important;
}
.save-btn {
  border-color: transparent;

  &:focus {
    box-shadow: none;
  }
}
/deep/ .el-table .cell.column-required:before {
    content: '*';
    display: inline-block;
    margin-right: 4px;
    line-height: 1;
    font-family: SimSun;
    font-size: 14px;
    color: #ed4014;
}
</style>
