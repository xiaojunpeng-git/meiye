<template>
  <div class="article-manager video-icon form-submit" id="shopp-manager">
    <div class="i-layout-page-header">
      <PageHeader class="product_tabs" hidden-breadcrumb>
        <div slot="title" class="acea-row row-middle">
          <router-link :to="{ path: `${routePre}/product/index` }">
            <div class="font-sm after-line">
              <span class="iconfont iconfanhui"></span>
              <span class="pl10">返回</span>
            </div>
          </router-link>
          <span
            v-text="$route.params.id ? '编辑商品' : '添加商品'"
            class="mr20 ml16 fs-18"
          ></span>
        </div>
      </PageHeader>
    </div>
    <Card :bordered="false" dis-hover class="ivu-mt">
      <div class="new_tab">
        <Tabs v-model="currentTab" @on-click="onhangeTab">
          <TabPane
            v-for="(item, index) in filterHeadTab"
            :key="index"
            :label="item.title"
            :name="item.name"
          ></TabPane>
        </Tabs>
      </div>
      <Form
        class="formValidate mt20"
        ref="formValidate"
        :rules="ruleValidate"
        :model="formValidate"
        :label-width="labelWidth"
        :label-position="labelPosition"
        @submit.native.prevent
      >
        <Row :gutter="24" type="flex" v-show="currentTab === '1'">
          <Col span="24">
            <FormItem label="商品类型：">
              <Select
                :value="formValidate.product_type"
                disabled
                v-width="'50%'"
              >
                <Option :value="0">普通商品</Option>
                <Option :value="4">次卡商品</Option>
                <Option :value="5">卡项商品</Option>
                <Option :value="6">预约商品</Option>
              </Select>
            </FormItem>
          </Col>
          <Col span="24">
            <FormItem label="商品名称：" prop="store_name">
              <Input
                v-model="formValidate.store_name"
                placeholder="请输入商品名称"
                v-width="'50%'"
              />
            </FormItem>
          </Col>
          <Col span="24">
            <FormItem label="平台商品分类：" prop="cate_id">
              <el-cascader
                placeholder="请选择平台商品分类"
                v-width="'50%'"
                size="mini"
                v-model="formValidate.cate_id"
                :options="treeSelect"
                :props="props"
                filterable
                clearable
              >
              </el-cascader>
            </FormItem>
          </Col>
          <Col span="24" v-if="product_category_status == 1">
            <FormItem label="门店商品分类：" prop="cate_id">
              <el-cascader
                placeholder="请选择门店商品分类"
                v-width="'50%'"
                size="mini"
                v-model="formValidate.store_cate_id"
                :options="storeTreeSelect"
                :props="props"
                filterable
                clearable
              >
              </el-cascader>
            </FormItem>
          </Col>
          <Col span="24" class="brandName">
            <FormItem label="商品品牌：" prop="">
              <Cascader
                :data="brandData"
                placeholder="请选择商品品牌"
                change-on-select
                v-model="formValidate.brand_id"
                filterable
                v-width="'50%'"
              ></Cascader>
            </FormItem>
          </Col>
          <Col span="24">
            <FormItem label="单位：" prop="unit_name">
              <Select
                v-model="formValidate.unit_name"
                clearable
                filterable
                v-width="'50%'"
                placeholder="请输入单位"
              >
                <Option
                  v-for="(item, index) in unitNameList"
                  :value="item.name"
                  :key="item.id"
                  >{{ item.name }}</Option
                >
              </Select>
            </FormItem>
          </Col>
          <Col span="24">
            <FormItem
              label="商品标签："
              prop="store_label_id"
              class="labelClass"
            >
              <div class="acea-row row-middle">
                <div
                  class="labelInput acea-row row-between-wrapper"
                  @click="openStoreLabel"
                >
                  <div style="width: 90%">
                    <div v-if="storeDataLabel.length">
                      <Tag
                        closable
                        v-for="(item, index) in storeDataLabel"
                        :key="item.id"
                        @on-close="closeStoreLabel(item)"
                        >{{ item.label_name }}</Tag
                      >
                    </div>
                    <span class="span" v-else>选择商品标签</span>
                  </div>
                  <div class="iconfont iconxiayi"></div>
                </div>
                <span class="addClass" @click="addStoreLabel">新增标签</span>
              </div>
            </FormItem>
          </Col>
          <Col span="24" v-if="formValidate.product_type !=6">
            <FormItem label="商品编码：" prop="">
              <Input
                v-model="formValidate.code"
                placeholder="请输入商品编码"
                v-width="'50%'"
              />
            </FormItem>
          </Col>
          <Col span="24">
            <FormItem label="商品轮播图：" prop="slider_image">
              <div class="acea-row">
                <div
                  class="pictrue"
                  v-for="(item, index) in formValidate.slider_image"
                  :key="index"
                  draggable="true"
                  @dragstart="handleDragStart($event, item)"
                  @dragover.prevent="handleDragOver($event, item)"
                  @dragenter="handleDragEnter($event, item)"
                  @dragend="handleDragEnd($event, item)"
                >
                  <img v-lazy="item" />
                  <Button
                    shape="circle"
                    icon="md-close"
                    @click.native="handleRemove(index)"
                    class="btndel"
                  ></Button>
                </div>
                <div
                  v-if="formValidate.slider_image.length < 10"
                  class="upLoad acea-row row-center-wrapper"
                  @click="modalPicTap('duo')"
                >
                  <Icon type="ios-camera-outline" size="26" />
                </div>
                <Input
                  v-model="formValidate.slider_image[0]"
                  class="input-display"
                ></Input>
              </div>
              <div class="tips">
                建议尺寸：800 *
                800px，可拖拽改变图片顺序，默认首张图为主图，最多上传10张
              </div>
            </FormItem>
          </Col>
          <Col span="24">
            <FormItem label="添加视频：">
              <i-switch v-model="formValidate.video_open" size="large">
                <span slot="open">开启</span>
                <span slot="close">关闭</span>
              </i-switch>
            </FormItem>
          </Col>
          <Col span="24" v-if="formValidate.video_open">
            <FormItem label="视频类型：">
              <RadioGroup v-model="seletVideo" @on-change="changeVideo">
                <Radio :label="0" class="radio">本地视频</Radio>
                <Radio :label="1">视频链接</Radio>
              </RadioGroup>
            </FormItem>
          </Col>
          <Col span="24" v-if="formValidate.video_open">
            <FormItem label="" prop="video_link">
              <Input
                v-if="seletVideo == 1 && !formValidate.video_link"
                v-width="'50%'"
                v-model="videoLink"
                placeholder="请输入视频链接"
              />
              <input
                type="file"
                ref="refid"
                @change="zh_uploadFile_change"
                class="input-display"
              />
              <div
                v-if="
                  seletVideo == 0 &&
                  (upload_type !== '1' || videoLink) &&
                  !formValidate.video_link
                "
                class="ml10 videbox"
                @click="zh_uploadFile"
              >
                +
              </div>
              <Button
                v-if="
                  seletVideo == 1 &&
                  (upload_type !== '1' || videoLink) &&
                  !formValidate.video_link
                "
                type="primary"
                icon="ios-cloud-upload-outline"
                class="uploadVideo"
                @click="zh_uploadFile"
                >确认添加</Button
              >
              <Upload
                v-if="upload_type === '1' && !videoLink"
                :show-upload-list="false"
                :action="fileUrl2"
                :before-upload="videoSaveToUrl"
                :data="uploadData"
                :headers="header"
                :multiple="true"
                style="display: inline-block"
              >
                <div
                  v-if="seletVideo === 0 && !formValidate.video_link"
                  class="videbox"
                >
                  +
                </div>
              </Upload>
              <div class="iview-video-style" v-if="formValidate.video_link">
                <video
                  class="video-style"
                  :src="formValidate.video_link"
                  controls="controls"
                >
                  您的浏览器不支持 video 标签。
                </video>
                <div class="mark"></div>
                <Icon
                  type="ios-trash-outline"
                  class="iconv"
                  @click="delVideo"
                />
              </div>
              <Progress
                class="progress"
                :percent="progress"
                :stroke-width="5"
                v-if="upload.videoIng || videoIng"
              />
              <div class="tips">建议时长：9～30秒，视频宽高比16:9</div>
            </FormItem>
          </Col>
          <Col span="24" class="goodsShow">
            <FormItem label="上架时间：">
              <RadioGroup v-model="formValidate.is_show" @on-change="goodsOn">
                <Radio :label="1">
                  <Icon type="social-apple"></Icon>
                  <span>立即上架</span>
                </Radio>
                <Radio :label="2">
                  <Icon type="social-android"></Icon>
                  <span>定时上架</span>
                </Radio>
                <Radio :label="0">
                  <Icon type="social-windows"></Icon>
                  <span>放入仓库</span>
                </Radio>
              </RadioGroup>
            </FormItem>
          </Col>
          <Col span="24" v-if="formValidate.is_show == 2">
            <FormItem label="">
              <DatePicker
                type="datetime"
                @on-change="onchangeShow"
                :options="startPickOptions"
                :value="formValidate.auto_on_time"
                v-model="formValidate.auto_on_time"
                placeholder="请选择上架时间"
                format="yyyy-MM-dd HH:mm"
                style="width: 260px"
              ></DatePicker>
            </FormItem>
          </Col>
          <Col span="24" class="goodsShow">
            <FormItem label="定时下架：">
              <Switch
                v-model="off_show"
                :true-value="1"
                :false-value="0"
                size="large"
                @on-change="goodsOff"
              >
                <span slot="open">开启</span>
                <span slot="close">关闭</span>
              </Switch>
            </FormItem>
          </Col>
          <Col span="24" v-if="off_show == 1">
            <FormItem label="">
              <DatePicker
                type="datetime"
                @on-change="onchangeOff"
                :options="endPickOptions"
                :value="formValidate.auto_off_time"
                v-model="formValidate.auto_off_time"
                placeholder="请选择下架时间"
                format="yyyy-MM-dd HH:mm"
                style="width: 260px"
              ></DatePicker>
              <div class="tips">
                开启定时下架后，系统会在设置时间下架该商品。下架时间需晚于开售时间，商品才能定时开售。
              </div>
            </FormItem>
          </Col>
        </Row>
        <Row :gutter="24" type="flex" v-show="currentTab === '2'">
          <Col span="24">
            <Alert class="notice" type="warning" show-icon
              >商品分销佣金比例：一级（{{
                rateData.store_brokerage_ratio
              }}%）、二级（{{
                rateData.store_brokerage_two
              }}%）；手续费比例：核销（{{
                rateData.store_writeoff_order_rate
              }}%），分配（{{ rateData.store_self_order_rate }}%），收银（{{
                rateData.store_cashier_order_rate
              }}%）</Alert
            >
          </Col>
          <Col span="24" v-if="formValidate.product_type != 4 && formValidate.product_type != 5">
            <FormItem label="商品规格：" props="spec_type">
              <div class="flex-y-center">
                <RadioGroup
                  v-model="formValidate.spec_type"
                  @on-change="changeSpec"
                >
                  <Radio :label="0" class="radio">单规格</Radio>
                  <Radio :label="1">多规格</Radio>
                </RadioGroup>
                <Dropdown v-if="formValidate.spec_type == 1 && ruleList.length" @on-click="confirm">
                  <span class="pl-14 text-blue pointer cup">
                    选择规格模板
                    <Icon type="ios-arrow-down"></Icon>
                  </span>
                  <template #list>
                    <DropdownMenu>
                      <DropdownItem
                        v-for="item in ruleList"
                        :key="item.id"
                        :name="item.id"
                        >{{ item.rule_name }}</DropdownItem
                      >
                    </DropdownMenu>
                  </template>
                </Dropdown>
              </div>
            </FormItem>
          </Col>
          <!-- 多规格-->
          <Col span="24" v-if="formValidate.spec_type === 1" class="noForm">
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
                          handle=".drag"
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
                            >
                              <template slot="prefix">
                                <span class="iconfont icondrag2"></span>
                              </template>
                            </Input>
                            <div class="img-popover" v-if="item.add_pic">
                              <div class="popper-arrow"></div>
                              <div
                                class="popper"
                                @click="handleSelImg(index, indexn)"
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
              <Button v-if="attrs.length < (formValidate.product_type == 6?1:4)" @click="handleAddRole()"
                >添加新规格</Button
              >
              <Button
                v-if="attrs.length"
                class="save-btn"
                @click="handleSaveAsTemplate()"
                >另存为模板</Button
              >
            </FormItem>
			<FormItem label="时段划分：" required prop="reservation_time_type" v-if="false">
			  <RadioGroup v-model="formValidate.reservation_time_type" @on-change='timeDivide'>
			    <Radio :label="1">
			      <Icon type="social-apple"></Icon>
			      <span>自动划分</span>
			    </Radio>
			    <Radio :label="2">
			      <Icon type="social-android"></Icon>
			      <span>自定义划分</span>
			    </Radio>
			  </RadioGroup>
			  <div class="fs-12 text--w111-999" v-if="formValidate.reservation_time_type==2">
			    请依照时间的先后顺序添加时段，并且时段的开始时间不得早于上一个时段的结束时间。
			  </div>
			  <div class="w-full pt-24 pb-24 pl-20 pr-20 bg-w111-F9F9F9 mt-10 rd-4px" v-if="formValidate.reservation_time_type == 1">
				<span>起止时间：</span>
				<TimePicker v-model="formValidate.reservation_times" format="HH:mm" type="timerange" placement="bottom-end" placeholder="请选择时间" class="w-160"/>
				<span class="ml20">时间跨度：</span>
				<Input v-model="formValidate.reservation_time_interval" type='number' class="w-160" @on-change="handleInputChange" @on-keypress="handleKeyPress">
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
				<div class="customize-time relative w-160 mr-20 mb-20" v-for="(item,index) in formValidate.customize_time_period" :key="index">
					<TimePicker v-model="formValidate.customize_time_period[index]" @on-change='customizeTime' :clearable='false' format="HH:mm" type="timerange" placement="bottom-end" placeholder="请选择时间" class="w-160"/>
					<div @click.stop="closeTime(index)" v-if="formValidate.customize_time_period.length>1" class="hidden w-14 lh-14px bg--w111-ccc rd-7px absolute text-center t-f5 r-f5 z-1">
						<span class="iconfont iconguanbi fs-12 text--w111-fff"></span>
					</div>
				</div>
			    <span class="ml-10px fs-12 text-wlll-2d8cf0 cup mb-20" @click="addTime" v-if="formValidate.customize_time_period.length<25">添加时段（{{formValidate.customize_time_period.length}}/24）</span>
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
                  v-for="(item, index) in formData.header"
                  :key="index"
                  :label="item.title"
                  :min-width="item.minWidth || '100'"
                  :fixed="item.fixed"
                >
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
                          class="priceBox"
                          clearable
                        ></InputNumber>
                      </template>
                      <template v-else-if="item.slot === 'cost'">
                        <InputNumber
                          :controls="false"
                          v-model="oneFormBatch[0].cost"
                          :min="0"
                          :max="9999999999"
                          class="priceBox"
                        ></InputNumber>
                      </template>
                      <template v-else-if="item.slot === 'ot_price'">
                        <InputNumber
                          :controls="false"
                          v-model="oneFormBatch[0].ot_price"
                          :min="0"
                          :max="9999999999"
                          class="priceBox"
                        ></InputNumber>
                      </template>
                      <template v-else-if="item.slot === 'stock'">
						<div v-if="formValidate.product_type == 6 || $route.params.id">--</div>
                        <InputNumber
						  v-else
                          :controls="false"
                          v-model="oneFormBatch[0].stock"
                          :disabled="formValidate.virtual_type == 1"
                          :min="0"
                          :max="9999999999"
                          class="priceBox"
                          clearable
                        ></InputNumber>
                      </template>
					  <template v-else-if="item.slot === 'inventory'">
						  <Input v-model="oneFormBatch[0].inventory" type="number" @on-change="handleChange($event)" @on-keypress="handleKeyPress">
						  	<Select v-model="oneFormBatch[0].pm" slot="prepend" style="width: 70px" transfer>
						  		<Option :value="1" style="font-size: 12px !important;">入库</Option>
						  		<Option :value="0" style="font-size: 12px !important;">出库</Option>
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
                        <Input v-model="oneFormBatch[0].bar_code"></Input>
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
                          :min="0"
                          :max="9999999999"
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
                          class="priceBox"
                        ></InputNumber>
                      </template>
                      <template v-else-if="item.slot === 'cost'">
                        <InputNumber
                          :controls="false"
                          v-model="manyFormValidate[scope.$index].cost"
                          :min="0"
                          :max="9999999999"
                          class="priceBox"
                        ></InputNumber>
                      </template>
                      <template v-else-if="item.slot === 'ot_price'">
                        <InputNumber
                          :controls="false"
                          v-model="manyFormValidate[scope.$index].ot_price"
                          :min="0"
                          :max="9999999999"
                          class="priceBox"
                        ></InputNumber>
                      </template>
                      <template v-else-if="item.slot === 'stock'">
						<div v-if="formValidate.product_type == 6" @click="setTimeStock(scope.$index)" class="text-wlll-2d8cf0 cup">设置预约数量</div>
						<div v-else>
							<div class="stock-input-box on" v-if="$route.params.id">{{ manyFormValidate[scope.$index].stock }}
							  <span v-show="manyFormValidate[scope.$index].pm == 1">(调整后{{ manyFormValidate[scope.$index].stock + Number(manyFormValidate[scope.$index].inventory) }})</span>
							  <span v-show="manyFormValidate[scope.$index].pm == 0">(调整后{{ manyFormValidate[scope.$index].stock - Number(manyFormValidate[scope.$index].inventory) }})</span>
							</div>
							<InputNumber
							  v-else
							  :controls="false"
							  v-model="manyFormValidate[scope.$index].stock"
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
						  		<Option :value="1" style="font-size: 12px !important;">入库</Option>
						  		<Option :value="0" style="font-size: 12px !important;">出库</Option>
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
                          v-model="manyFormValidate[scope.$index].bar_code"
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
                          :min="0"
                          :max="9999999999"
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
                      <!-- <template v-else-if="item.slot === 'fictitious'">
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
                      </template> -->

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
          </Col>
          <!-- 单规格-->
          <div v-if="formValidate.spec_type === 0" style="width: 100%">
            <Col span="24" v-if="formValidate.product_type!=4">
              <FormItem label="图片：" prop="image">
                <div
                  class="pictrueBox"
                  @click="modalPicTap('dan', 'danTable', 0)"
                >
                  <div class="pictrue" v-if="formValidate.attr.pic">
                    <img v-lazy="formValidate.attr.pic" />
                  </div>
                  <div class="upLoad acea-row row-center-wrapper" v-else>
                    <Input
                      v-model="formValidate.attr.pic"
                      class="input-display"
                    ></Input>
                    <Icon type="ios-camera-outline" size="26" />
                  </div>
                </div>
              </FormItem>
            </Col>
            <Col span="24" v-if="formValidate.product_type==4">
              <FormItem label="核销次数：" required prop="attr.write_times">
                <InputNumber :min="1" :max="99999999" v-width="260" v-model="formValidate.attr.write_times"></InputNumber>
              </FormItem>
            </Col>
            <Col span="24" v-if="formValidate.product_type==4 || formValidate.product_type==5">
              <FormItem label="核销时效：" required prop="attr.write_valid">
                <RadioGroup v-model="formValidate.attr.write_valid">
                  <Radio :label="1">永久有效</Radio>
                  <Radio :label="2">购买后几天有效</Radio>
                  <Radio :label="3">固定有效期</Radio>
                </RadioGroup>
                <div class="mt-20" v-if="formValidate.attr.write_valid == 2">
                  <InputNumber :min="1" :max="99999999" :precision="0" v-width="260" v-model="formValidate.attr.days"></InputNumber>天
                </div>
                <div class="tips" v-if="formValidate.attr.write_valid == 3">超过有效期后，商品会自动下架放入仓库</div>
                <DatePicker
                  v-if="formValidate.attr.write_valid == 3"
                  :editable="false"
                  type="datetimerange"
                  format="yyyy-MM-dd HH:mm:ss"
                  placeholder="请选择固定有效期"
                  v-width="260"
                  v-model="section_time"
                  @on-change="onchangeTime"
                ></DatePicker>
              </FormItem>
            </Col>
            <Col span="24">
              <FormItem label="售价：" required>
                <InputNumber
                  v-model="formValidate.attr.price"
                  :min="0"
                  :max="99999999"
                  v-width="260"
                ></InputNumber>
              </FormItem>
            </Col>
            <Col span="24" required>
              <FormItem label="成本价：">
                <InputNumber
                  v-model="formValidate.attr.cost"
                  :min="0"
                  :max="99999999"
                  v-width="260"
                ></InputNumber>
              </FormItem>
            </Col>
            <Col span="24">
              <FormItem label="划线价：">
                <InputNumber
                  v-model="formValidate.attr.ot_price"
                  :min="0"
                  :max="99999999"
                  v-width="260"
                ></InputNumber>
              </FormItem>
            </Col>
			<!-- 不同之处：普通(单规格) -->
            <Col span="24" v-if="formValidate.product_type==0 || formValidate.product_type==4 || formValidate.product_type==5">
              <FormItem label="初始库存：" v-if="$route.params.id" prop="stock">
              	<div class="stock-input-box">
              		{{formValidate.attr.stock}}
              		<span v-show="formValidate.attr.pm == 1">(调整后{{ formValidate.attr.stock + Number(formValidate.attr.inventory) }})</span>
              		<span v-show="formValidate.attr.pm == 0">(调整后{{ formValidate.attr.stock - Number(formValidate.attr.inventory) }})</span>
              	</div>
              </FormItem>
			  <FormItem label="库存：" v-else required prop="attr.stock">
                <InputNumber
                  v-model="formValidate.attr.stock"
                  :min="0"
                  :max="99999999"
                  :disabled="openErp"
                  :precision="0"
                  v-width="260"
                ></InputNumber>
              </FormItem>
			  <FormItem label="调整库存：" v-if="$route.params.id">
			  	<Input v-model="formValidate.attr.inventory" type="number" v-width="260" @on-change="handleChange($event)" @on-keypress="handleKeyPress">
			  		<Select v-model="formValidate.attr.pm" slot="prepend" style="width: 70px">
			  			<Option :value="1">入库</Option>
			  			<Option :value="0">出库</Option>
			  		</Select>
			  	</Input>
			  </FormItem>
			  <FormItem label="商品条形码：" v-if="formValidate.product_type==0">
			    <Input
			      v-model.trim="formValidate.attr.bar_code"
			      v-width="260"
			      placeholder="请输入商品条形码"
			    ></Input>
			  </FormItem>
			  <FormItem label="商品编号：" v-if="formValidate.product_type==0">
			    <Input
			      v-model.trim="formValidate.attr.code"
			      v-width="260"
			      placeholder="请输入商品编码"
			    ></Input>
			  </FormItem>
			  <FormItem label="重量（KG）：" v-if="formValidate.product_type==0">
			    <InputNumber
			      v-model="formValidate.attr.weight"
			      :min="0"
			      :max="99999999"
			      v-width="260"
			    ></InputNumber>
          <div class="tips">该信息将影响同城配送中配送费的计算，务必准确填写</div>
			  </FormItem>
			  <FormItem label="体积(m³)：" v-if="formValidate.product_type==0">
			    <InputNumber
			      v-model="formValidate.attr.volume"
			      :min="0"
			      :max="99999999"
			      v-width="260"
			    ></InputNumber>
			  </FormItem>
			  <FormItem label="库存基本单位：" v-if="formValidate.product_type==0">
			    <Input
			      v-model.trim="formValidate.attr.stock_unit"
			      v-width="260"
			      placeholder="如 片/ml/g，库存与院装配方按此单位记账"
			    ></Input>
			  </FormItem>
			  <FormItem label="销售/包装单位：" v-if="formValidate.product_type==0">
			    <Input
			      v-model.trim="formValidate.attr.sale_unit"
			      v-width="260"
			      placeholder="如 盒/瓶，可留空"
			    ></Input>
			  </FormItem>
			  <FormItem label="销售单位换算数：" v-if="formValidate.product_type==0">
			    <InputNumber
			      v-model="formValidate.attr.unit_convert"
			      :min="0"
			      :max="99999999"
			      v-width="260"
			    ></InputNumber>
			    <div class="tips">1 销售单位 = 换算数 × 基本单位（如 1 盒=10 片则填 10）；不用包装单位填 1</div>
			  </FormItem>
			  <FormItem label="允许小数位：" v-if="formValidate.product_type==0">
			    <InputNumber
			      v-model="formValidate.attr.decimal_scale"
			      :min="0"
			      :max="4"
			      :precision="0"
			      v-width="260"
			    ></InputNumber>
			    <div class="tips">库存数量允许的小数位（0~4）。按件/片等整数单位填 0；ml/g 等可填 1~4</div>
			  </FormItem>
        <FormItem label="卡项商品：" required v-if="formValidate.product_type==5">
          <Button type="primary" @click="goodsModal = true">添加商品</Button>
          <Button type="primary" class="ml-10" :disabled="!cardDataSelection.length" @click="cardDataDelete">批量删除</Button>
          <Table class="mt-20" :columns="cardColumns" :data="formValidate.related" max-height="500" border @on-selection-change="cardDataChange">
            <template slot-scope="{ row }" slot="product">
              <div class="acea-row row-middle flex-nowrap">
                <div v-viewer>
                  <img :src="row.image" class="block w-36 h-36">
                </div>
                <div class="line1 ml-10">{{ row.store_name }}</div>
              </div>
            </template>
          </Table>
			  </FormItem>
        <FormItem label="支持退款：" v-if="formValidate.product_type==5 || formValidate.product_type==4">
          <i-switch v-model="formValidate.is_support_refund" :true-value="1" :false-value="0" size="large">
            <span slot="open">开启</span>
            <span slot="close">关闭</span>
          </i-switch>
			  </FormItem>
            </Col>
			<!-- 不同之处：预约(单规格) -->
			<Col span="24" v-if="formValidate.product_type==6">
			  <FormItem label="时段划分：" required prop="reservation_time_type" v-if="false">
			    <RadioGroup v-model="formValidate.reservation_time_type" @on-change='timeDivide'>
			      <Radio :label="1">
			        <Icon type="social-apple"></Icon>
			        <span>自动划分</span>
			      </Radio>
			      <Radio :label="2">
			        <Icon type="social-android"></Icon>
			        <span>自定义划分</span>
			      </Radio>
			    </RadioGroup>
				<div class="fs-12 text--w111-999" v-if="formValidate.reservation_time_type==2">
				  请依照时间的先后顺序添加时段，并且时段的开始时间不得早于上一个时段的结束时间。
				</div>
			    <div class="w-full pt-24 pb-24 pl-20 pr-20 bg-w111-F9F9F9 mt-10 rd-4px" v-if="formValidate.reservation_time_type == 1">
			  	<span>起止时间：</span>
			  	<TimePicker v-model="formValidate.reservation_times" format="HH:mm" type="timerange" placement="bottom-end" placeholder="请选择时间" class="w-160"/>
			  	<span class="ml20">时间跨度：</span>
			  	<Input v-model="formValidate.reservation_time_interval" type='number' class="w-160" @on-change="handleInputChange" @on-keypress="handleKeyPress">
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
			  	<div class="customize-time relative w-160 mr-20 mb-20" v-for="(item,index) in formValidate.customize_time_period" :key="index">
			  		<TimePicker v-model="formValidate.customize_time_period[index]" @on-change='customizeTime' :clearable='false' format="HH:mm" type="timerange" placement="bottom-end" placeholder="请选择时间" class="w-160"/>
			  		<div @click.stop="closeTime(index)" v-if="formValidate.customize_time_period.length>1" class="hidden w-14 lh-14px bg--w111-ccc rd-7px absolute text-center t-f5 r-f5 z-1">
			  			<span class="iconfont iconguanbi fs-12 text--w111-fff"></span>
			  		</div>
			  	</div>
			      <span class="ml-10px fs-12 text-wlll-2d8cf0 cup mb-20" @click="addTime">添加时段（{{formValidate.customize_time_period.length}}/24）</span>
			  	<Button class="ml-20px mb-20" @click="setCustomizeTime">设置</Button>
			    </div>
			  </FormItem>
			  <FormItem label="库存：" required v-if="false">
			  	<Table :columns="timeColumns" :data="formValidate.attr.reservation_time_data" class="timeTable" border no-data-text="暂无数据"
			  	       highlight-row no-filtered-data-text="暂无筛选结果" max-height="710" width='343'>
			  		<template slot-scope="{ row, index }" slot="time">
			  			<div class="ml-19">{{row.start}}-{{row.end}}</div>
			  		</template>
			  		<template slot-scope="{ row, index }" slot="stock">
			  			<InputNumber
			  			  v-model="formValidate.attr.reservation_time_data[index].stock"
			  			  :min="0"
			  			  :max="99999999"
			  			  :precision="0"
			  			  v-width="129"
						  class="ml-30"
			  			></InputNumber>
			  		</template>
					<template slot-scope="{ row, index }" slot="service_price">
						<InputNumber
						  v-model="formValidate.attr.reservation_time_data[index].service_price"
						  :min="0"
						  :max="99999999"
						  v-width="129"
						></InputNumber>
					</template>
			  	</Table>
			  </FormItem> 
			</Col>
          </div>
        </Row>
		<!-- 预约设置模块 -->
		<div v-show="currentTab === '5'">
		   <reservationSet
		     ref="reservationSet"
		     :baseInfo="formValidate"
		     @weekData="weekData"
		   ></reservationSet>
		</div>
		<!-- 库存设置（仅普通商品 product_type==0） -->
		<div v-show="currentTab === '11'">
		   <inventorySet :baseInfo="formValidate"></inventorySet>
		</div>
		<!-- 商品详情 -->
        <Row v-show="currentTab === '3'" class="mb10">
          <Col span="16">
            <wangeditor
              style="width: 100%"
              :content="contents"
              @editorContent="getEditorContent"
            ></wangeditor>
          </Col>
          <Col span="6" style="width: 33%">
            <div class="ifam">
              <div class="content" v-html="content"></div>
            </div>
          </Col>
        </Row>
        <!-- 其他设置-->
        <Row v-show="currentTab === '4'">
          <Col span="24" v-if="formValidate.product_type != 6 && formValidate.product_type != 5 && formValidate.product_type != 4">
            <FormItem label="配送方式：" prop="" required>
              <CheckboxGroup v-model="formValidate.delivery_type">
                <Checkbox label="1" :disabled="!deliveryType.includes('1')">快递发货</Checkbox>
                <Checkbox label="3" :disabled="!deliveryType.includes('3')" v-if="cityDeliveryStatus">同城配送</Checkbox>
                <Checkbox label="2" :disabled="!deliveryType.includes('2')">到店自提</Checkbox>
              </CheckboxGroup>
              <div class="tips">
                勾选快递发货时，需设置运费；勾选同城配送时，需于设置 -> 同城配送 -> 配送模板中设置配送费模板
              </div>
            </FormItem>
			<FormItem label="运费设置：">
			  <RadioGroup v-model="formValidate.freight">
			    <Radio :label="1">包邮</Radio>
			    <Radio :label="2">固定邮费</Radio>
			    <Radio :label="3">运费模板</Radio>
			  </RadioGroup>
        <div class="tips">
          配送方式勾选快递发货时需设置；运费模板于商品 -> 运费模板中设置
              </div>
			</FormItem>
          </Col>
          <Col span="24" v-if="formValidate.freight == 2">
            <FormItem label="" prop="freight">
              <div class="acea-row row-middle">
                <InputNumber
                  :min="0"
                  v-model="formValidate.postage"
                  placeholder="请输入金额"
                  class="perW20 maxW"
                />
                <span class="ml10">元</span>
              </div>
            </FormItem>
          </Col>
          <Col span="24" v-if="formValidate.freight == 3">
            <FormItem label="" prop="">
              <div class="acea-row">
                <Select
                  v-model="formValidate.temp_id"
                  clearable
                  class="perW20 maxW"
                >
                  <Option
                    v-for="(item, index) in templateList"
                    :value="item.id"
                    :key="index"
                    >{{ item.name }}</Option
                  >
                </Select>
                <Button
                  @click="editTemp"
                  class="ml15"
                  v-if="formValidate.temp_id"
                  >查看运费模板</Button
                >
                <Button @click="addTemp" class="ml15" v-else
                  >添加运费模板</Button
                >
              </div>
            </FormItem>
          </Col>
          <Col span="24">
            <FormItem label="商品展示：">
              <RadioGroup v-model="formValidate.show_type">
                <Radio :label="0">全部</Radio>
                <Radio :label="1">移动端</Radio>
                <Radio :label="2">收银台</Radio>
              </RadioGroup>
            </FormItem>
          </Col>
          <Col span="24">
            <FormItem label="已售数量：">
              <InputNumber
                v-width="'50%'"
                :min="0"
                :max="999999"
                v-model="formValidate.ficti"
                placeholder="请输入已售数量"
              />
            </FormItem>
          </Col>
          <Col span="24">
            <FormItem label="是否限购：">
              <i-switch
                v-model="formValidate.is_limit"
                :true-value="1"
                :false-value="0"
                size="large"
                @on-change="limitTap"
              >
                <span slot="open">开启</span>
                <span slot="close">关闭</span>
              </i-switch>
            </FormItem>
          </Col>
          <Col span="24" v-if="formValidate.is_limit">
            <FormItem label="限购类型：">
              <RadioGroup v-model="formValidate.limit_type">
                <Radio :label="1">单次限购</Radio>
                <Radio :label="2">长期限购</Radio>
              </RadioGroup>
              <div class="tips">
                单次限购是限制每次下单最多购买的数量，长期限购是限制一个用户总共可以购买的数量
              </div>
            </FormItem>
          </Col>
          <Col span="24" v-if="formValidate.is_limit">
            <FormItem label="限购数量：">
              <InputNumber
                :min="1"
                v-model="formValidate.limit_num"
                placeholder="请输入限购数量"
                class="perW20 maxW"
              />
            </FormItem>
          </Col>
          <Col span="24">
            <FormItem label="排序：">
              <InputNumber
                :min="0"
                :max="999999"
                v-width="'50%'"
                v-model="formValidate.sort"
                placeholder="请输入排序"
              />
            </FormItem>
          </Col>
          <Col span="24">
            <FormItem label="商品关键字：" prop="">
              <Input
                v-model="formValidate.keyword"
                placeholder="请输入商品关键字"
                v-width="'50%'"
              />
            </FormItem>
          </Col>
          <Col span="24">
            <FormItem label="商品简介：" prop="">
              <Input
                v-model="formValidate.store_info"
                type="textarea"
                :rows="3"
                placeholder="请输入商品简介"
                v-width="'50%'"
              />
            </FormItem>
          </Col>
          <Col span="24">
            <FormItem label="商品口令：">
              <Input
                v-model="formValidate.command_word"
                type="textarea"
                :rows="3"
                placeholder="请输入商品口令"
                v-width="'50%'"
              />
            </FormItem>
          </Col>
          <Col span="24">
            <FormItem label="商品推荐图：">
              <div
                class="pictrueBox"
              >
                <div class="pictrue" v-if="formValidate.recommend_image">
                  <img v-lazy="formValidate.recommend_image" />
                  <Input
                    v-model="formValidate.recommend_image"
                    class="input-display"
                  ></Input>
                  <Button shape="circle" icon="md-close" class="btndel" @click.native="deleteRecommendImage"></Button>
                </div>
                <div class="upLoad acea-row row-center-wrapper" @click="modalPicTap('dan', 'recommend_image')" v-else>
                  <Input
                    v-model="formValidate.recommend_image"
                    class="input-display"
                  ></Input>
                  <Icon type="ios-camera-outline" size="26" />
                </div>
              </div>
              <div class="tips">(建议图片比例5:2)</div>
            </FormItem>
          </Col>
          <Col span="24">
            <FormItem label="服务保障：">
              <CheckboxGroup v-model="formValidate.ensure_id" class="checkAlls">
                <Checkbox
                  :label="item.id"
                  v-for="(item, index) in ensureData"
                  :key="item.id"
                  >{{ item.name }}</Checkbox
                >
              </CheckboxGroup>
            </FormItem>
          </Col>
          <Col span="24">
            <FormItem label="商品参数：" prop="">
              <Select
                v-model="formValidate.specs_id"
                clearable
                filterable
                v-width="'50%'"
                placeholder="请输入商品参数"
                @on-change="specsInfo"
              >
                <Option
                  v-for="(item, index) in specsData"
                  :value="item.id"
                  :key="index"
                  >{{ item.name }}</Option
                >
              </Select>
            </FormItem>
          </Col>
          <Col span="24" v-if="formValidate.specs_id">
            <FormItem label="" props="">
              <Table
                border
                :columns="specsColumns"
                :data="specsList"
                ref="table"
                class="specsList"
                width="700"
              >
                <template slot-scope="{ row, index }" slot="action">
                  <a @click="delSpecs(index)" v-if="index > 0">删除</a>
                </template>
              </Table>
              <Button class="mt20" @click="addSpecs">添加参数</Button>
            </FormItem>
          </Col>
          <Col span="24">
            <FormItem label="支持退款：" v-if="formValidate.product_type==6">
              <i-switch v-model="formValidate.is_support_refund" :true-value="1" :false-value="0" size="large">
                <span slot="open">开启</span>
                <span slot="close">关闭</span>
              </i-switch>
            </FormItem>
          </Col>
          <Col span="24">
            <FormItem label="自定义留言：">
              <i-switch
                v-model="customBtn"
                @on-change="customMessBtn"
                size="large"
              >
                <span slot="open">开启</span>
                <span slot="close">关闭</span>
              </i-switch>
              <div class="mt10" v-if="customBtn">
                <Select
                  v-model="formValidate.system_form_id"
                  filterable
                  v-width="'50%'"
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
            </FormItem>
          </Col>
		  <Col span="24" v-if="customBtn">
			<FormItem label="表单信息：" v-if="customBtn">
			  <RadioGroup v-model="formValidate.system_form_type">
			    <Radio :label="1">按照商品填写</Radio>
			    <Radio :label="2">按照订单填写</Radio>
			  </RadioGroup>
			  <div class="tips">按照商品：一个订单买2个商品，需填写2份表单；按照订单：一个订单买两个商品，需填写1份表单</div>
			</FormItem>
		  </Col>
          <Col span="24" v-if="customBtn && formValidate.system_form_id">
            <FormItem label="" props="">
              <Table
                border
                :columns="formColumns"
                :data="formTypeList"
                ref="table"
                class="specsList on"
              >
                <template slot-scope="{ row }" slot="require">
                  <span>{{ row.require ? '必填' : '不必填' }}</span>
                </template>
              </Table>
            </FormItem>
          </Col>
        </Row>
        <Row v-if="currentTab === '9'">
          <Col span="24">
            <cardstyleSet :baseInfo="formValidate" @coverChange="coverChange" @modalPicTap="modalPicTap" @deleteImage="deleteCardCoverImage" @colorChange="colorChange"></cardstyleSet>
          </Col>
        </Row>
        <Spin size="large" fix v-if="spinShow"></Spin>
      </Form>
      <Modal
        v-model="modalPic"
        width="960px"
        scrollable
        footer-hide
        closable
        title="上传商品图"
        :mask-closable="false"
        :z-index="1"
      >
        <uploadPictures
          :isChoice="isChoice"
          @getPic="getPic"
          @getPicD="getPicD"
          :gridBtn="gridBtn"
          :gridPic="gridPic"
          v-if="modalPic"
        ></uploadPictures>
      </Modal>
    </Card>
    <Card
      :bordered="false"
      dis-hover
      class="fixed-card"
      :style="{ left: `${!menuCollapse ? '200px' : isMobile ? '0' : '80px'}` }"
    >
      <Form>
        <FormItem>
          <Button v-if="currentTab !== '1'" @click="upTab">上一步</Button>
          <Button
            type="primary"
            class="submission"
            v-if="currentTab !== '4'"
            @click="downTab('formValidate')"
            >下一步</Button
          >
          <Button
            type="primary"
            :disabled="openSubimit"
            class="submission"
            @click="handleSubmit('formValidate')"
            v-if="$route.params.id || currentTab === '4'"
            >保存</Button
          >
        </FormItem>
      </Form>
    </Card>
    <freightTemplate
      :template="template"
      v-on:changeTemplate="changeTemplate"
      ref="templates"
    ></freightTemplate>
    <Modal
      v-model="storeLabelShow"
      scrollable
      title="选择商品标签"
      :closable="true"
      width="540"
      :footer-hide="true"
      :mask-closable="false"
    >
      <labelList
        ref="storeLabel"
        @activeData="activeStoreData"
        @close="storeLabelClose"
      ></labelList>
    </Modal>
    <Modal
      v-model="attrShow"
      scrollable
      title="请选择商品规格"
      :closable="false"
      width="320"
      :footer-hide="true"
      :mask-closable="false"
    >
      <attr-list
        :attrs="attrsList"
        @activeData="activeAttr"
        @close="labelAttr"
        @subAttrs="subAttrs"
        v-if="attrShow"
      ></attr-list>
    </Modal>
	<stockSet ref="stockSet" :timeData='specTimeData' @modalStockSet='modalStockSet'></stockSet>
  <Modal v-model="goodsModal" title="商品列表" footerHide scrollable width="900">
    <goods-attr :goodsType="1" isCard ref="goodSattr" v-if="goodsModal" @getProductId="getAtterId"></goods-attr>
  </Modal>
  </div>
</template>

<script>
import { mapState, mapMutations } from 'vuex';
import Setting from '@/setting';
import util from '@/libs/util';
import vuedraggable from 'vuedraggable';
import uploadPictures from '@/components/uploadPictures';
import freightTemplate from '@/components/freightTemplate';
import wangeditor from '@/components/wangEditor/index.vue';
import labelList from '@/components/labelList';
import goodsAttr from '@/components/goodsAttr';
import attrList from '../components/attrList';
import stockSet from './components/stockSet.vue';
import reservationSet from './components/reservationSet.vue';
import inventorySet from './components/inventorySet.vue';
import cardstyleSet from './components/cardstyleSet.vue';
import {
  productInfoApi,
  cascaderList,
  productAddApi,
  productGetRuleApi,
  productGetTemplateApi,
  productGetTempKeysApi,
  cacheDelete,
  brandList,
  productAllUnit,
  uploadType,
  productAllEnsure,
  productLabelAdd,
  productAllSpecs,
  allSystemForm,
  systemFormInfo,
  ruleAddApi,
} from '@/api/product';
import { erpConfig } from '@/api/erp';
import { deliveryConfigApi, storeGetInfoApi } from '@/api/setting';
import { uploadByPieces } from '@/utils/upload'; //引入uploadByPieces方法
import { arraysEqual } from '@/utils';
export default {
  name: 'product_productAdd',
  components: {
    uploadPictures,
    freightTemplate,
    attrList,
    labelList,
    wangeditor,
    draggable: vuedraggable,
	stockSet,
	reservationSet,
	inventorySet,
	cardstyleSet,
  goodsAttr
  },
  data() {
    return {
      rateData: {}, //商品分销佣金比例
      formTypeList: [],
      formColumns: [
        {
          title: '表单标题',
          key: 'title',
          minWidth: 100,
        },
        {
          title: '表单类型',
          key: 'name',
          minWidth: 100,
        },
        {
          title: '是否必填',
          slot: 'require',
          minWidth: 100,
        },
      ],
      routePre: Setting.routePre,
      specsList: [],
      specsColumns: [
        {
          title: '参数名称',
          key: 'name',
          align: 'center',
          width: 150,
          render: (h, params) => {
            return h('div', [
              h('Input', {
                props: {
                  value: params.row.name,
                  placeholder: '请输入参数名称',
                },
                on: {
                  'on-change': (e) => {
                    params.row.name = e.target.value;
                    this.specsList[params.index].name = e.target.value;
                  },
                },
              }),
            ]);
          },
        },
        {
          title: '参数值',
          key: 'value',
          align: 'center',
          width: 300,
          render: (h, params) => {
            return h('div', [
              h('Input', {
                props: {
                  value: params.row.value,
                  placeholder: '请输入参数值',
                },
                on: {
                  'on-change': (e) => {
                    params.row.value = e.target.value;
                    this.specsList[params.index].value = e.target.value;
                  },
                },
              }),
            ]);
          },
        },
        {
          title: '排序',
          key: 'sort',
          align: 'center',
          width: 100,
          render: (h, params) => {
            return h('div', [
              h('InputNumber', {
                props: {
                  value: parseInt(params.row.sort) || 0,
                  placeholder: '排序',
                  precision: 0,
                },
                on: {
                  'on-change': (e) => {
                    params.row.sort = e;
                    this.specsList[params.index].sort = e;
                  },
                },
              }),
            ]);
          },
        },
        {
          title: '操作',
          slot: 'action',
          align: 'center',
          minWidth: 120,
        },
      ],
      customBtn: false, //自定义留言开关
      attrShow: false,
      content: '',
      contents: '',
      seletVideo: 0,
      fileUrl: Setting.apiBaseURL + '/file/upload',
      fileUrl2: Setting.apiBaseURL + '/file/video_upload',
      upload_type: '', //视频上传类型 1 本地上传 2 3 4 OSS上传
      uploadData: {}, // 上传参数
      header: {},
      storeDataLabel: [],
      storeLabelShow: false,
      props: { emitPath: false, multiple: true, checkStrictly: true },
      type: 0,
      off_show: 0,
      spinShow: false,
      openSubimit: false,
      grid3: {
        xl: 18,
        lg: 18,
        md: 20,
        sm: 24,
        xs: 24,
      },
      // 批量设置表格data
      oneFormBatch: [
        {
          detail: {},
          pic: '',
          price: null,
          cost: null,
          ot_price: null,
          stock: null,
          bar_code: '',
          code: '',
          weight: null,
          volume: null,
          stock_unit: '',
          sale_unit: '',
          unit_convert: null,
          decimal_scale: null,
		  pm: 1,
		  inventory: 0
        },
      ],
      // 规格数据
      formDynamic: {
        attrsName: '',
        attrsVal: '',
      },
      GoodsTableHead: [
        {
          title: '图片',
          slot: 'pic',
          align: 'center',
          minWidth: '80',
        },
        {
          title: '售价',
          slot: 'price',
          align: 'center',
          minWidth: '95',
        },
        {
          title: '成本价',
          slot: 'cost',
          align: 'center',
          minWidth: '95',
        },
        {
          title: '划线价',
          slot: 'ot_price',
          align: 'center',
          minWidth: '95',
        },
        {
          title: '库存',
          slot: 'stock',
          align: 'center',
          minWidth: '150',
        },
        {
          title: '商品编号',
          slot: 'code',
          align: 'center',
          minWidth: '120',
        },
        {
          title: '商品条形码',
          slot: 'bar_code',
          align: 'center',
          minWidth: '120',
        },
        {
          title: '重量（KG）',
          slot: 'weight',
          align: 'center',
          minWidth: '95',
        },
        {
          title: '体积(m³)',
          slot: 'volume',
          align: 'center',
          minWidth: '95',
        },
        {
          title: '库存单位',
          slot: 'stock_unit',
          align: 'center',
          minWidth: '100',
        },
        {
          title: '销售单位',
          slot: 'sale_unit',
          align: 'center',
          minWidth: '100',
        },
        {
          title: '换算数',
          slot: 'unit_convert',
          align: 'center',
          minWidth: '110',
        },
        {
          title: '小数位',
          slot: 'decimal_scale',
          align: 'center',
          minWidth: '90',
        },
        {
          title: '默认选中规格',
          slot: 'selected_spec',
          fixed: 'right',
          align: 'center',
          minWidth: '100',
        },
        {
          title: '操作',
          slot: 'action',
          fixed: 'right',
          align: 'center',
          minWidth: '140',
        },
      ],
	  ReservationTableHead:[
		{
		  title: '图片',
		  slot: 'pic',
		  align: 'center',
		  minWidth: '80px',
		},
		{
		  title: '售价',
		  slot: 'price',
		  align: 'center',
		  minWidth: '120px',
		},
		{
		  title: '成本价',
		  slot: 'cost',
		  align: 'center',
		  minWidth: '120px',
		},
		{
		  title: '划线价',
		  slot: 'ot_price',
		  align: 'center',
		  minWidth: '120px',
		},
		{
		  title: '预约数量',
		  slot: 'stock',
		  align: 'center',
		  minWidth: '120px',
		},
		{
		  title: '默认选中规格',
		  slot: 'selected_spec',
		  fixed: 'right',
		  align: 'center',
		  minWidth: '100px',
		},
		{
		  title: '操作',
		  slot: 'action',
		  fixed: 'right',
		  align: 'center',
		  minWidth: '120px',
		}
	  ],
      gridPic: {
        xl: 6,
        lg: 8,
        md: 12,
        sm: 12,
        xs: 12,
      },
      gridBtn: {
        xl: 4,
        lg: 8,
        md: 8,
        sm: 8,
        xs: 8,
      },
      formValidate: {
        store_name: '', //商品名称
        cate_id: [], //平台商品分类
        store_cate_id: [], //门店商品分类
        brand_id: [], //商品品牌
        unit_name: '', //单位
        store_label_id: [], //商品标签
        code: '', //商品编码
        slider_image: [], //商品轮播图
        video_open: false, //添加视频开关
        video_link: '', //视频链接
        is_show: 1, //上架时间类型
        auto_on_time: '', //上架时间
        auto_off_time: '', //定时下架时间
        spec_type: 0, //商品规格
        description: '', //商品详情
        delivery_type: [], //配送方式
        freight: 1, //运费设置
        postage: 0, //固定邮费金额
        temp_id: '', //运费模板ID
        show_type: 0, //商品展示
        ficti: 0, //已售数量
        is_limit: 0, //是否限购开关
        limit_type: 1, //限购类型 1单次限购，2长期限购
        limit_num: 1, //限购数量
        sort: 0, //排序
        keyword: '', //商品关键字
        store_info: '', //商品简介
        command_word: '', //商品口令
        recommend_image: '', //商品推荐图
        ensure_id: [], //服务保障
        specs_id: 0, //商品参数
		system_form_type: 1,
        system_form_id: 0, //自定义留言ID
        supplier_id: 0, //供应商
        disk_info: '', //卡密简介
        custom_form: [], //自定义留言
        image: '',
        id: 0,
        attr: {
          pic: '', //图片
          price: 0, //售价
          cost: 0, //成本价
          ot_price: 0, //划线价
          stock: 0, //库存
          bar_code: '', //商品条形码
          code: '', //商品编号
          weight: 0, //重量（KG）
          volume: 0, //体积
          stock_unit: '', //库存基本单位
          sale_unit: '', //销售/包装单位
          unit_convert: 1, //销售单位换算数
          decimal_scale: 0, //允许小数位0~4
		  reservation_time_data: [], //预约时间段数组库存
      write_valid: 1, //核销时效
      days: 1, //核销时效-购买后几天有效
      section_time: '', //核销时效-固定有效期
      write_times: 1, //核销次数
        },
        attrs: [],
        items: [],
        header: [],
        specs: [],
        product_type: 0,
		is_inventory: 1, //参与库存管理（仅普通商品）
		allow_negative_stock: 1, //允许负库存
		salon_stock_enabled: 0, //可作为院装耗材（仅普通商品且参与库存）
		reservation_time_type:1 ,//预约时段类型1:自动划分2:自定义
		reservation_times: [], //[预约时间段开始，预约时间短结束]
		reservation_time_interval:30, //预约时段自动类型：时间间隔（分钟）
		customize_time_period: [[]], //自定义时间段
		// 预约设置模块参数
		reservation_type:1, //预约类型1：到店服务+上门服务，2：到店服务，3：上门服务
		reservation_timing_type:1, //预约时机1：购买时预约+先买后约，2：购买时预约，3：先买后约
		is_show_stock:1 ,//是否展示库存
		sale_time_type:1, //销售日期1：每天，2:每周，3：自定义时间
		sale_time_week:[], //销售日期每周设置
		sale_time_data:[], //销售日期自定义
		show_reservation_days_type:1, //显示可预约日期类型1：全部展示，2：自定义展示时间
		show_reservation_days:1, //显示多少天内可预约日期（天）
		is_advance:0, //是否需要提前预约0：无需提前1：可以
		advance_time:1, //提前多少小时预约（小时）
		is_cancel_reservation:0, //是否可以取消预约0：不允许1：可以 
		cancel_reservation_time:1, //服务开始前多少小时允许取消（小时）
    is_support_refund: 0, //支持退款
    card_cover: 1, //卡片封面
    card_cover_image: '', //卡片封面-图片
    card_cover_color: '', //卡片封面-颜色
    related: [], //卡项选择的商品
      },
      formData: {
        header: [],
      },
      ruleList: [],
      templateList: [],
      manyFormValidate: [],
      images: [],
      currentTab: '1',
      isChoice: '',
      modalPic: false,
      template: false,
      treeSelect: [],
      ensureData: [],
      specsData: [],
      picTit: '',
      tableIndex: 0,
      ruleValidate: {
        store_name: [
          { required: true, message: '请输入商品名称', trigger: 'blur' },
        ],
        cate_id: [
          {
            required: true,
            message: '请选择商品分类',
            trigger: 'change',
            type: 'array',
          },
        ],
        keyword: [
          { required: true, message: '请输入商品关键字', trigger: 'blur' },
        ],
        unit_name: [
          {
            required: true,
            message: '请输入单位',
            trigger: 'change',
          },
        ],
        store_info: [
          { required: true, message: '请输入商品简介', trigger: 'blur' },
        ],
        slider_image: [
          {
            required: true,
            message: '请上传商品轮播图',
            type: 'array',
            trigger: 'change',
          },
        ],
        spec_type: [
          { required: true, message: '请选择商品规格', trigger: 'change' },
        ],
        selectRule: [
          { required: true, message: '请选择商品规格属性', trigger: 'change' },
        ],
      },
      upload: {
        videoIng: false, // 是否显示进度条；
      },
      videoIng: false, // 是否显示进度条；
      progress: 0, // 进度条默认0
      videoLink: '',
      attrs: [],
      brandData: [],
      unitNameList: [],
      attrsList: [],
      openErp: false,
      formList: [],
      storeTreeSelect: [],
      product_category_status: 0,
      canSel: true,
      currentIndex: 0,
      templateName: '',
	  timeoutId: null, //定时器
	  reservationTime: [],//时间区域
	  timeCheckAll:true, //自动划分控制全选
	  timeCheckAllGroup:[],//自动划分当前选中的元素
	  timeCheckAllClone:[],//自动划分克隆全部选中的元素
	  timeDataClone:[],//自定义划分时的库存（为了切换时段划分时，可以复原之前选中的数据）
	  timeInputNumberValue:0, //批量设置单规格库存值（库存）
	  timeServiceInputNumberValue:0, //批量设置单规格库存值（服务费）
	  tooltipVisible:false,//控制气泡的显示和隐藏（库存）
	  tooltipServiceVisible:false,//控制气泡的显示和隐藏（服务费）
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
									this.tooltipServiceVisible = false;
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
	  		},
			// {
			// 	title: "服务费",
			// 	slot: "service_price",
			// 	align: "center",
			// 	minWidth: 180,
			// 	renderHeader: (h, row) => {
			// 		return h('div', [
			// 		  h('Poptip',{
			// 			props:{
			// 				transfer:true,
			// 				placement: 'top',
			// 				trigger:'click',
			// 				value: this.tooltipServiceVisible,
			// 			},
			// 			on: {
			// 				'on-popper-hide': () => {
			// 					this.tooltipServiceVisible = false; // 气泡隐藏时设置 visible 为 false
			// 				}
			// 			},
			// 			scopedSlots:{
			// 				default: () => h('span', {
			// 					on: {
			// 					  click: ($event) => {
			// 						$event.stopPropagation();
			// 					    this.tooltipServiceVisible = true; // 点击单元格时显示气泡
			// 						this.tooltipVisible = false;
			// 						this.timeServiceInputNumberValue = 0;
			// 					  },
			// 					}
			// 				},[
			// 					h('span', '服务费'),
			// 					h('span', {
			// 						class: ['iconfont iconbianji11'],
			// 						style: {
			// 						  marginLeft: '6px',
			// 						  color:'#AAAAAA',
			// 						  fontSize:'12px'
			// 						}
			// 					})
			// 				]),
			// 			  content:()=>h('div',[
			// 				h('div',{
			// 					class:['fs-12 text-wlll-515A6E mb-12']
			// 				},'批量修改'),
			// 				h('InputNumber',{
			// 					props:{
			// 					  min:0,
			// 					  max:99999999,
			// 					  value: this.timeServiceInputNumberValue
			// 					},
			// 					class:['w-85'],
			// 					 on: {
			// 						 'on-change': (value) => {
			// 						   this.timeServiceInputNumberValue = value;
			// 						 }
			// 					 }
			// 				}),
			// 				h('Button',{
			// 					props:{
			// 					  size:'small',
			// 					},
			// 					class:['ml-8'],
			// 					on: {
			// 					  click: () => {
			// 						this.tooltipServiceVisible = false;
			// 					  }
			// 					}
			// 				},'取消'),
			// 				h('Button',{
			// 					props:{
			// 					  type:'primary',
			// 					  size:'small'
			// 					},
			// 					class:['ml-8'],
			// 					on: {
			// 					  click: () => {
			// 						this.tooltipServiceVisible = false;
			// 					    this.handleServiceButtonClick();
			// 					  }
			// 					}
			// 				},'确定'),
			// 			  ])
			// 			},
			// 		  })
			// 		]);
			// 	}
			// },
	  ],
	  customizeTimeData:[],//自定义划分时的库存（为了切换时段划分时，可以复原之前选中的数据）
	  specTimeData:[], //多规格对应属性库存数据
	  specIndex:0, //多规格对应索引值
	  weekList:[], //可售日期选择每周时得数据
    cardColumns: [
      {
          type: 'selection',
          width: 60,
          align: 'center'
      },
      {
          title: '商品信息',
          slot: 'product',
          width: 210,
          align: 'center',
      },
      {
          title: '商品规格',
          key: 'suk',
          align: 'center',
      },
      {
          title: '商品类型',
          align: 'center',
          render: (h, params) => {
            return h('div', params.row.product_type ? '预约商品' : '普通商品');
          }
      },
      {
          title: '售价',
          key: 'price',
          align: 'center',
      },
      {
          align: 'center',
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
                    display: this.formValidate.related.length ? 'inline-block' : 'none'
                  }
                }),
              ]),
            ]);
          },
          render: (h, params) => {
            return h('InputNumber', {
              props: {
                value: params.row.write_times,
                min: 1,
                max: params.row.stock,
                readonly: params.row.stock <= 1,
                precision: 0,
              },
              on: {
                'on-change': (value) => {
                  this.formValidate.related[params.index].write_times = value;
                },
              },
            });
          }
      },
      {
          title: '操作',
          align: 'center',
          render: (h, params) => {
            return h('a', {
              props: {
                min: 1,
                max: params.row.stock,
                precision: 0,
              },
              on: {
                click: () => {
                  this.cardDataRowDelete(params.index);
                },
              },
            }, '删除');
          }
      },
    ],
    goodsModal: false,
    poptipVisible: false,
    batchWriteTimes: 1,
	// batchStock: null,
  section_time: [],
  cardDataSelection: [],
  deliveryType: [],
  cityDeliveryStatus: 0, // 商城同城配送开启状态
    };
  },
  computed: {
    ...mapState('store/layout', ['isMobile', 'menuCollapse']),
    labelWidth() {
      return this.isMobile ? undefined : 120;
    },
    labelPosition() {
      return this.isMobile ? 'top' : 'right';
    },
    labelBottom() {
      return this.isMobile ? undefined : 15;
    },
    startPickOptions() {
      const that = this;
      return {
        disabledDate(time) {
          if (that.formValidate.auto_off_time) {
            return (
              time.getTime() >
              new Date(that.formValidate.auto_off_time).getTime() - 86400000
            );
          }
          return '';
        },
      };
    },
    endPickOptions() {
      const that = this;
      return {
        disabledDate(time) {
          if (that.formValidate.is_show == '1') {
            return time.getTime() < Date.now();
          }
          if (that.formValidate.auto_on_time) {
            return (
              time.getTime() <
              new Date(that.formValidate.auto_on_time).getTime() + 86400000
            );
          }
          return '';
        },
      };
    },
	filterHeadTab() {
	   let headTab = [];
	   if(this.formValidate.product_type==6){
		   headTab = [
		     { title: '基础信息', name: '1' },
		     { title: '预约服务', name: '2' },
		     { title: '预约设置', name: '5' },
		     { title: '商品详情', name: '3' },
		     { title: '其他设置', name: '4' },
		   ]
	   }else if(this.formValidate.product_type==5) {
      headTab = [
        { title: '基础信息', name: '1' },
        { title: '卡项信息', name: '2' },
        { title: '卡面设置', name: '9' },
        { title: '商品详情', name: '3' },
        { title: '其他设置', name: '4' },
      ]
     }else {
		   headTab = [
		     { title: '基础信息', name: '1' },
		     { title: '规格库存', name: '2' },
		     { title: '商品详情', name: '3' },
		     { title: '其他设置', name: '4' },
		   ]
		   // 库存设置仅普通商品(product_type==0)可见，紧随「基础信息」之后
		   if (this.formValidate.product_type == 0) {
		     headTab.splice(1, 0, { title: '库存设置', name: '11' });
		   }
	   }
	   return headTab;
	}
  },
  created() {
    this.getToken();
    this.getErpConfig();
    this.productGetRule();
    let product_category_status =
      localStorage.getItem('product_category_status') || 0;
    this.product_category_status = product_category_status;
	this.formValidate.product_type = Number(this.$route.query.productType) || 0;
  },
  mounted() {
    this.setCopyrightShow({ value: false });
    if (
      (this.$route.params.id !== '0' && this.$route.params.id) ||
      this.$route.query.copy
    ) {
      this.getInfo();
    }
    this.getDeliveryConfig();
    this.getStoreInfo();
    this.goodsCategory(0);
    this.goodsCategory(1);
    this.productGetTemplate();
    this.getBrandList();
    this.getAllUnit();
    this.uploadType();
    this.getProductAllEnsure();
    this.getProductAllSpecs();
    this.getAllSystemForm();
  },
  destroyed() {
    this.setCopyrightShow({ value: true });
  },
  methods: {
    ...mapMutations('store/layout', ['setCopyrightShow']),
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
		this.formValidate.attr.reservation_time_data = [];
		if(e==1){
			this.formValidate.attr.reservation_time_data = this.timeDataClone
		}else{
			this.formValidate.attr.reservation_time_data = this.customizeTimeData
		}
		if(this.attrs.length){
		   this.generateAttr(this.attrs);
		}
	},
	closeTime(index){
	  this.formValidate.customize_time_period.splice(index, 1)
	},
	// 判断交集和递增；
	intersection(customizeTime){
		let intersection = this.$hasIntersection(customizeTime); //是否有交集
		let Incremental = this.$isTimeRangesIncreasing(customizeTime); //是否递增
		return (intersection || !Incremental)
	},
	// 自定义添加时段；
	addTime(){
		let customizeTime = this.formValidate.customize_time_period;
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
		let customizeTime = this.formValidate.customize_time_period;
		customizeTime[customizeTime.length-1]=e;
	},
	// 自定义划分设置按钮
	setCustomizeTime(){
		let customizeTime = this.formValidate.customize_time_period;
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
				stock:0,
				service_price:0
			}
			customizeTimeData.push(data);
		})
		this.customizeTimeData = customizeTimeData;
		this.formValidate.attr.reservation_time_data = customizeTimeData;
		if(this.formValidate.spec_type == 1 && !this.attrs.length){
		  return this.$Message.error('请设置商品规格');
		}
		if(this.attrs.length){
		   this.$Message.success('设置成功');
		   this.generateAttr(this.attrs);
		}
	},
	// 单规格批量设置库存;
	handleButtonClick(){
		this.formValidate.attr.reservation_time_data.forEach(item=>{
			item.stock = this.timeInputNumberValue;
		})
	},
	// 单规格批量设置服务费;
	handleServiceButtonClick(){
		this.formValidate.attr.reservation_time_data.forEach(item=>{
			item.service_price = this.timeServiceInputNumberValue;
		})
	},
	// 设置自动划分时间；
	setTime(){
		let timeCheckAllGroup = [],that = this;
		let reservationTimes = this.formValidate.reservation_times;
		let time = this.formValidate.reservation_time_interval;
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
		this.formValidate.attr.reservation_time_data = this.reservationTime;
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
		this.formValidate.attr.reservation_time_data = data;
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
		this.formValidate.attr.reservation_time_data = data;
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
				that.formValidate.reservation_time_interval = 10;
			},1200)
		}else{
			clearTimeout(that.timeoutId)
			that.timeoutId = null;
			if (value > 1440) {
				setTimeout(function(){
					that.formValidate.reservation_time_interval = 1440;
				})
			}
		}
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
    getAllSystemForm() {
      allSystemForm()
        .then((res) => {
          this.formList = res.data;
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    limitTap(e) {
      if (e) {
        this.formValidate.limit_type =
          this.formValidate.is_limit && !this.formValidate.limit_type ? 1 : 0;
        this.formValidate.limit_num =
          this.formValidate.is_limit && this.formValidate.limit_num == 0
            ? 1
            : 0;
      } else {
        this.formValidate.limit_type = 0;
        this.formValidate.limit_num = 0;
      }
    },
    //erp配置
    getErpConfig() {
      erpConfig()
        .then((res) => {
          this.openErp = res.data.open_erp;
          this.rateData = res.data;
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    delSpecs(index) {
      this.specsList.splice(index, 1);
    },
    addSpecs() {
      let obj = { name: '', value: '', sort: 0 };
      this.specsList.push(obj);
    },
    specsInfo(e) {
      this.specsData.forEach((item) => {
        if (item.id == e) {
          this.specsList = item.specs;
        }
      });
    },
    getProductAllSpecs() {
      productAllSpecs()
        .then((res) => {
          this.specsData = res.data;
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    getProductAllEnsure() {
      productAllEnsure()
        .then((res) => {
          this.ensureData = res.data;
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    customMessBtn(e) {
      if (!e) {
        this.formValidate.system_form_id = 0;
      }
    },
    //定时上架
    onchangeShow(e) {
      this.formValidate.auto_on_time = e;
    },
    //定时下架
    onchangeOff(e) {
      this.formValidate.auto_off_time = e;
    },
    //选中属性
    activeAttr(e) {
      this.attrsList = e;
    },
    //关闭属性弹窗
    labelAttr() {
      this.attrShow = false;
    },
    doCombination(arr) {
      var count = arr.length - 1; //数组长度(从0开始)
      var tmp = [];
      var totalArr = []; // 总数组

      return doCombinationCallback(arr, 0); //从第一个开始
      //js 没有静态数据，为了避免和外部数据混淆，需要使用闭包的形式
      function doCombinationCallback(arr, curr_index) {
        for (let val of arr[curr_index]) {
          tmp[curr_index] = val; //以curr_index为索引，加入数组
          //当前循环下标小于数组总长度，则需要继续调用方法
          if (curr_index < count) {
            doCombinationCallback(arr, curr_index + 1); //继续调用
          } else {
            totalArr.push(tmp.join(',')); //(直接给push进去，push进去的不是值，而是值的地址)
          }

          //js  对象都是 地址引用(引用关系)，每次都需要重新初始化，否则 totalArr的数据都会是最后一次的 tmp 数据；
          let oldTmp = tmp;
          tmp = [];
          for (let index of oldTmp) {
            tmp.push(index);
          }
        }
        return totalArr;
      }
    },
    //提交属性值；
    subAttrs(e) {
      let selectData = [];
      this.attrsList.forEach((el, index) => {
        let obj = [];
        el.details.forEach((label) => {
          if (label.select) {
            obj.push(label.name);
          }
        });
        if (obj.length) {
          selectData.push(obj);
        }
      });
      let newData = [];
      if (selectData.length) {
        newData = this.doCombination(selectData);
      }
      this.attrShow = false;
      // this.activeAtter = selectData;
      this.oneFormBatch[0].attr = newData.length ? newData.join(';') : '全部';
      this.manyFormValidate.forEach((j) => {
        j.select = false;
        if (newData.length) {
          newData.forEach((item) => {
            if (j.values.split('').length == item.split('').length) {
              if (j.values == item) {
                j.select = true;
              }
            } else {
              if (j.values.indexOf(item) != -1) {
                j.select = true;
              }
            }
          });
        } else {
          j.select = true;
        }
      });
      this.$set(this, 'manyFormValidate', this.manyFormValidate);
    },
    goodsOn(e) {
      if (e == 0 || e == 1) {
        this.formValidate.auto_on_time = '';
      }
    },
    goodsOff(e) {
      if (!e) {
        this.formValidate.auto_off_time = '';
      }
    },
    getAllUnit() {
      productAllUnit()
        .then((res) => {
          this.unitNameList = res.data;
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    addStoreLabel() {
      this.$modalForm(productLabelAdd()).then(() => {});
    },
    closeStoreLabel(label) {
      let index = this.storeDataLabel.indexOf(
        this.storeDataLabel.filter((d) => d.id == label.id)[0]
      );
      this.storeDataLabel.splice(index, 1);
    },
    activeStoreData(storeDataLabel) {
      this.storeLabelShow = false;
      this.storeDataLabel = storeDataLabel;
    },
    openStoreLabel(row) {
      this.storeLabelShow = true;
      this.$refs.storeLabel.userLabel(
        JSON.parse(JSON.stringify(this.storeDataLabel))
      );
    },
    // 标签弹窗关闭
    storeLabelClose() {
      this.storeLabelShow = false;
    },
    // 品牌列表
    getBrandList() {
      brandList()
        .then((res) => {
          //initBran()函数作用iview中规定value必须是字符串，后台返回成了数字，用于处理这个，给了个递归；
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
    videoSaveToUrl(file) {
      let imgTypeArr = ['video/mp4'];
      let imgType = imgTypeArr.indexOf(file.type) !== -1;
      if (!imgType) {
        return this.$Message.warning({
          content: '文件  ' + file.name + '  格式不正确, 请选择格式正确的视频',
          duration: 5,
        });
      }
      uploadByPieces({
        randoms: '', // 随机数，这里作为给后端处理分片的标识 根据项目看情况 是否要加
        file: file, // 视频实体
        pieceSize: 3, // 分片大小
        success: (data) => {
          this.formValidate.video_link = data.file_path;
          this.progress = 100;
        },
        error: (e) => {
          this.$Message.error(e.msg);
        },
        uploading: (chunk, allChunk) => {
          this.videoIng = true;
          let st = Math.floor((chunk / allChunk) * 100);
          this.progress = st;
        },
      });
      return false;
    },
    // 上传头部token
    getToken() {
      this.header['Authori-zation'] = 'Bearer ' + util.cookies.get('token');
    },
    //获取视频上传类型
    uploadType() {
      uploadType().then((res) => {
        this.upload_type = res.data.upload_type;
      });
    },
    getEditorContent(data) {
      this.content = data;
    },
    infoData(data) {
      //次卡商品和卡项商品将核销日期时间段回显
      if(data.product_type == 4 || data.product_type == 5){
        this.section_time = data.attr.section_time;
      }
      // 卡项商品
      if (data.product_type == 5) {
        this.formValidate.related = data.related.map((item) => {
          return {
            ...item.productInfo.attrInfo,
            store_name: item.productInfo.store_name,
            write_times: item.write_times,
          };
        });
      }
	  this.formValidate.attr.reservation_time_data = [];
      Object.keys(this.formValidate).forEach((key) => {
        if (data.hasOwnProperty(key)) {
          const formItem = data[key];
          if (key === 'delivery_type') {
            // 处理配送方式
            this.formValidate.delivery_type = [];
            const deliveryType = data.delivery_type;
            const storeDeliveryType = data.store_delivery_type;
            if (deliveryType.includes('3') && storeDeliveryType.includes('1')) {
              this.formValidate.delivery_type.push('1');
            }
            if (deliveryType.includes('2')) {
              this.formValidate.delivery_type.push('2');
            }
            if (deliveryType.includes('3') && storeDeliveryType.includes('2')) {
              this.formValidate.delivery_type.push('3');
            }
            this.formValidate.delivery_type = this.formValidate.delivery_type.filter((value) => {
              // 平台的同城配送关闭
              if (!this.cityDeliveryStatus && value == 3) {
                return false;
              }
              // 门店的配送方式控制
              if (this.deliveryType.length && !this.deliveryType.includes(value)) {
                return false;
              }
              return true;
            });
          } else if (key !== 'related') {
            this.formValidate[key] = formItem;
          }
        }
      });
      this.attrs = data.items || [];
      this.off_show = data.auto_off_time ? 1 : 0;
      this.formValidate.brand_id = data.brand_id.map(String);
      this.formValidate.is_limit = this.formValidate.is_limit ? 1 : 0;
      this.formTypeList = data.custom_form_info;
      this.contents = data.description;
      this.storeDataLabel = data.store_label_id;
      this.specsList = data.specs;
      if (this.formValidate.system_form_id) {
        this.customBtn = true;
      }
      // 多规格
      if (data.spec_type) {
        // 生成规格表头
        this.generateHeader(this.attrs);
		data.attrs.map(item=>{
			if(this.$route.params.id && [6].indexOf(this.formValidate.product_type) == -1){
			  this.$set(item, "inventory", 0);
			  this.$set(item, "pm", 1);
			}
		})
        this.manyFormValidate = [...this.oneFormBatch, ...data.attrs];
      }
	  if(this.$route.params.id && [6].indexOf(this.formValidate.product_type) == -1) {
	    this.$set(this.formValidate.attr, "inventory", 0);
	    this.$set(this.formValidate.attr, "pm", 1);
	  }
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
      let specCloneData = JSON.parse(JSON.stringify(data.attrs[0].reservation_time_data))
      specCloneData.forEach(item=>{
      item.stock = 0;
      item.service_price = 0;
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
    },
    // 添加运费模板
    addTemp() {
      this.$refs.templates.isTemplate = true;
    },
    //查看、编辑运费模板
    editTemp() {
      this.$refs.templates.isTemplate = true;
      this.$refs.templates.editFrom(this.formValidate.temp_id);
    },
    // 删除视频；
    delVideo() {
      let that = this;
      that.$set(that.formValidate, 'video_link', '');
      that.$set(that, 'progress', 0);
      that.videoIng = false;
      that.upload.videoIng = false;
    },
    zh_uploadFile() {
      if (this.seletVideo == 1) {
        if (this.videoLink && this.$getFileType(this.videoLink) == 'video') {
          this.formValidate.video_link = this.videoLink;
        } else {
          return this.$Message.error('请输入正确的视频链接');
        }
      } else {
        this.$refs.refid.click();
      }
    },
    zh_uploadFile_change(evfile) {
      let that = this;
      let suffix = evfile.target.files[0].name.substr(
        evfile.target.files[0].name.indexOf('.')
      );
      if (suffix.indexOf('.mp4') === -1) {
        return that.$Message.error('只能上传MP4文件');
      }
      let types = {
        key: evfile.target.files[0].name,
        contentType: evfile.target.files[0].type,
      };
      productGetTempKeysApi(types)
        .then((res) => {
          that.$videoCloud
            .videoUpload({
              type: res.data.type,
              evfile: evfile,
              res: res,
              uploading(status, progress) {
                that.upload.videoIng = status;
                if (res.status == 200) {
                  that.progress = 100;
                }
              },
            })
            .then((res) => {
              that.formValidate.video_link = res.url;
              that.$Message.success('视频上传成功');
              that.upload.videoIng = false;
            })
            .catch((res) => {
              that.$Message.error(res);
            });
        })
        .catch((res) => {
          that.$Message.error(res.msg);
        });
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
          if (
            this.formValidate.is_show == 2 &&
            !this.formValidate.auto_on_time
          ) {
            return this.$Message.warning('请填写定时上架时间');
          }
          if (this.off_show == 1 && !this.formValidate.auto_off_time) {
            return this.$Message.warning('请填写定时下架时间');
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
    setAllPic() {
      this.modalPicTap('dan', 'oneFormBatch');
    },
    setAttrPic(index) {
      this.modalPicTap('dan', 'manyFormValidate', index);
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
    // 选择规格模板
    confirm(id) {
      const { rule_value } = this.ruleList.find((item) => item.id === id) || {};
      const attrs = rule_value.map((item) => ({
        ...item,
        add_pic: 0,
      }));
      this.attrs = this.formValidate.product_type == 6?attrs.slice(0, 1):attrs;
      this.canSel = true;
      this.generateAttr(this.attrs);
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
    // 获取运费模板；
    productGetTemplate() {
      productGetTemplateApi().then((res) => {
        this.templateList = res.data;
      });
    },
    // 删除表格中的属性
    delAttrTable(val) {
      for (let i = 0; i < this.manyFormValidate.length; i++) {
        let item = this.manyFormValidate[i];
        if ( Object.values(item.detail) && Object.values(item.detail).includes(val) ) {
          this.manyFormValidate.splice(i, 1);
          i--;
        }
      }
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
            if (this.oneFormBatch[0].price !== null) {
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
    // 删除规格
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
    // 删除属性
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
    // 添加属性
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
    // 商品分类；
    goodsCategory(type) {
      cascaderList(type)
        .then((res) => {
          if (type) {
            this.storeTreeSelect = res.data;
          } else {
            this.treeSelect = res.data;
          }
        })
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
    //视视上传类型
    changeVideo(e) {
      this.formValidate.video_link = '';
      this.videoLink = '';
    },
    // 改变规格
    changeSpec() {},
    // 详情
    getInfo() {
      this.spinShow = true;
      productInfoApi(this.$route.params.id || this.$route.query.copy)
        .then((res) => {
          this.infoData(res.data.productInfo);
          this.spinShow = false;
        })
        .catch((res) => {
          this.spinShow = false;
          this.$Message.error(res.msg);
        });
    },
    // tab切换
    onhangeTab(name) {
      this.currentTab = name;
    },
    handleRemove(i) {
      this.formValidate.slider_image.splice(i, 1);
    },
    // 点击商品图
    modalPicTap(tit, picTit, index) {
      this.modalPic = true;
      this.isChoice = tit === 'dan' ? '单选' : '多选';
      this.picTit = picTit;
      this.tableIndex = index;
    },
    // 获取单张图片信息
    getPic(pc) {
      switch (this.picTit) {
        case 'danTable':
          this.formValidate.attr.pic = pc.att_dir;
          break;
        case 'recommend_image':
          this.formValidate.recommend_image = pc.att_dir;
          break;
        case 'attrs':
          this.attrs[this.tableIndex[0]].detail[this.tableIndex[1]].pic =
            pc.att_dir;
          this.changeSpecImg(
            [this.attrs[this.tableIndex[0]].detail[this.tableIndex[1]].value],
            pc.att_dir
          );
          break;
        case 'oneFormBatch':
          this.oneFormBatch[0].pic = pc.att_dir;
          break;
        case 'manyFormValidate':
          this.manyFormValidate[this.tableIndex].pic = pc.att_dir;
          break;
        case 'card_cover_image':
          this.formValidate.card_cover_image = pc.att_dir;
          break;
      }
      this.modalPic = false;
    },
    // 获取多张图信息
    getPicD(pc) {
      this.images = pc;
      this.images.map((item) => {
        this.formValidate.slider_image.push(item.att_dir);
        this.formValidate.slider_image = this.formValidate.slider_image.splice(
          0,
          10
        );
      });
      this.modalPic = false;
    },
    // 提交
    handleSubmit(name) {
      this.$refs[name].validate((valid) => {
        if (valid) {
          if (!this.formValidate.store_name.trim()) {
            return this.$Message.warning('基础信息-商品名称不能为空');
          }
          if (
            this.formValidate.is_show == 2 &&
            !this.formValidate.auto_on_time
          ) {
            return this.$Message.warning('基础信息-定时上架时间不能为空');
          }
          if (this.off_show == 1 && !this.formValidate.auto_off_time) {
            return this.$Message.warning('基础信息-定时下架时间不能为空');
          }
          if (
            this.formValidate.freight == 2 &&
            this.formValidate.product_type == 0 &&
            this.formValidate.postage <= 0
          ) {
            return this.$Message.warning('物流设置-固定邮费不能为0');
          }
          if (
            this.formValidate.freight == 3 &&
            this.formValidate.product_type == 0 &&
            !this.formValidate.temp_id
          ) {
            return this.$Message.warning('物流设置-运费模板不能为空');
          }
          if (this.currentTab == 4 && !this.formValidate.delivery_type.length && this.formValidate.product_type == 0) {
            return this.$Message.warning('请选择配送方式');
          }
          if (this.customBtn && this.formValidate.system_form_id == 0) {
            return this.$Message.warning('其他设置-请选择自定义表单模板');
          }
          this.formValidate.type = this.type;
          if (this.formValidate.spec_type === 0) {
            this.formValidate.items = [];
          } else {
            this.formValidate.items = this.attrs;
            this.formValidate.attrs = this.manyFormValidate.slice(1);
          }
          if (
            this.formValidate.spec_type === 1 &&
            this.manyFormValidate.length === 0
          ) {
            return this.$Message.warning('规格库存-请点击生成多规格');
          }
          let item = this.formValidate.attrs;
          for (let i = 0; i < this.specsList.length; i++) {
            let data = this.specsList[i];
            if (!data.name.trim()) {
              return this.$Message.error('请输入参数名称');
            }
            if (!data.value.trim()) {
              return this.$Message.error('请输入参数值');
            }
          }
          this.openSubimit = true;
          this.formValidate.description = this.formatRichText(this.content);
          // 商品标签
          let storeActiveIds = [];
          this.storeDataLabel.forEach((item) => {
            storeActiveIds.push(item.id);
          });
          this.formValidate.store_label_id = storeActiveIds;
          // 商品参数
          this.formValidate.specs = this.specsList;
          if (this.$route.query.copy) {
            this.formValidate.id = 0;
            this.formValidate.soure_link = '';
          }
          let weekId = [];
          this.weekList.forEach(item=>{
          if(item.selected){
            weekId.push(item.id);
          }
          })
          weekId = weekId.length?weekId:[1,2,3,4,5]
          this.formValidate.sale_time_week = weekId;
          if (this.formValidate.card_cover == 1) {
            this.formValidate.card_cover_color = '';
          } else {
            this.formValidate.card_cover_image = '';
          }
          // 库存设置：仅普通商品(product_type==0)可参与库存；非产品强制关闭
          if (this.formValidate.product_type == 0) {
            this.formValidate.is_inventory =
              this.formValidate.is_inventory != null ? this.formValidate.is_inventory : 1;
            this.formValidate.allow_negative_stock =
              this.formValidate.allow_negative_stock != null ? this.formValidate.allow_negative_stock : 1;
            // 院装耗材开关：仅参与库存管理时可开启，否则强制关闭
            this.formValidate.salon_stock_enabled =
              this.formValidate.is_inventory == 1
                ? (this.formValidate.salon_stock_enabled != null ? this.formValidate.salon_stock_enabled : 0)
                : 0;
          } else {
            this.formValidate.is_inventory = 0;
            this.formValidate.allow_negative_stock = 1;
            this.formValidate.salon_stock_enabled = 0;
          }
          productAddApi(this.formValidate)
            .then(async (res) => {
              this.$Message.success(res.msg);
              this.openSubimit = false;
              if (this.$route.params.id === '0') {
                cacheDelete().catch((err) => {
                  this.$Message.error(err.msg);
                });
              }
              setTimeout(() => {
                this.$router.push({
                  path: `${Setting.routePre}/product/index`,
                });
              }, 500);
            })
            .catch((res) => {
              this.$Message.error(res.msg);
              this.openSubimit = false;
            });
        } else {
          if (!this.formValidate.store_name) {
            return this.$Message.warning('基础信息-商品名称不能为空');
          } else if (!this.formValidate.cate_id.length) {
            return this.$Message.warning('基础信息-商品分类不能为空');
          } else if (!this.formValidate.unit_name) {
            return this.$Message.warning('基础信息-商品单位不能为空');
          } else if (!this.formValidate.slider_image.length) {
            return this.$Message.warning('基础信息-商品轮播图不能为空');
          }
        }
      });
    },
    changeTemplate(msg) {
      this.template = msg;
    },
    // 移动
    handleDragStart(e, item) {
      this.dragging = item;
    },
    handleDragEnd(e, item) {
      this.dragging = null;
    },
    handleDragOver(e) {
      e.dataTransfer.dropEffect = 'move';
    },
    handleDragEnter(e, item) {
      e.dataTransfer.effectAllowed = 'move';
      if (item === this.dragging) {
        return;
      }
      const newItems = [...this.formValidate.slider_image];
      const src = newItems.indexOf(this.dragging);
      const dst = newItems.indexOf(item);
      newItems.splice(dst, 0, ...newItems.splice(src, 1));
      this.formValidate.slider_image = newItems;
    },
    formatRichText(html) {
      let newContent = html.replace(/<img[^>]*>/gi, function (match, capture) {
        match = match
          .replace(/style="[^"]+"/gi, '')
          .replace(/style='[^']+'/gi, '');
        match = match
          .replace(/width="[^"]+"/gi, '')
          .replace(/width='[^']+'/gi, '');
        match = match
          .replace(/height="[^"]+"/gi, '')
          .replace(/height='[^']+'/gi, '');
        return match;
      });
      newContent = newContent.replace(
        /style="[^"]+"/gi,
        function (match, capture) {
          match = match
            .replace(/width:[^;]+;/gi, 'max-width:100%;')
            .replace(/width:[^;]+;/gi, 'max-width:100%;');
          return match;
        }
      );
      newContent = newContent.replace(
        /\<img/gi,
        '<img style="max-width:100%;height:auto;display:block;margin-top:0;margin-bottom:0;"'
      );
      return newContent;
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
    handleFocus(val) {
      this.changeAttrValue = val;
    },
    handleBlur() {
      this.changeAttrValue = '';
    },
    handleSelImg(index, indexn) {
      this.modalPicTap('dan', 'attrs', [index, indexn]);
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
          pic: '',
          price: 0,
          cost: 0,
          ot_price: 0,
          stock: 0,
          code: '',
          bar_code: '',
          weight: 0,
          volume: 0,
          stock_unit: '',
          sale_unit: '',
          unit_convert: 1,
          decimal_scale: 0,
          is_default_select: 0,
          is_show: 1,
          unique: '',
          brokerage: 0,
          brokerage_two: 0,
          vip_price: 0,
          vip_proportion: 0,
        };
        if(this.formValidate.product_type == 6){
          this.$set(row, 'reservation_time_data', this.formValidate.attr.reservation_time_data);
        }
        for (let i = 0; i < combination.length; i++) {
          const value = combination[i];
          this.$set(row, data[i].value, value);
          this.$set(row, 'title', data[i].value);
          this.$set(row, 'key', data[i].value);
          this.$set(row.detail, data[i].value, value);
		  if(this.$route.params.id && [6].indexOf(this.formValidate.product_type) == -1){
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
                pic: manyItem.pic,
                price: manyItem.price,
                cost: manyItem.cost,
                ot_price: manyItem.ot_price,
                stock: manyItem.stock,
                code: manyItem.code || '',
                bar_code: manyItem.bar_code || '',
                weight: manyItem.weight || 0,
                volume: manyItem.volume || 0,
                stock_unit: manyItem.stock_unit || '',
                sale_unit: manyItem.sale_unit || '',
                unit_convert: manyItem.unit_convert != null ? manyItem.unit_convert : 1,
                decimal_scale: manyItem.decimal_scale != null ? manyItem.decimal_scale : 0,
                is_default_select: manyItem.is_default_select || 0,
                is_show: manyItem.is_show || 1,
                unique: manyItem.unique || '',
                brokerage: manyItem.brokerage,
                brokerage_two: manyItem.brokerage_two,
                vip_price: manyItem.vip_price,
                vip_proportion: manyItem.vip_proportion,
                // is_virtual: manyItem.is_virtual,
				inventory: 0,
				pm: 1
              });
              if(this.formValidate.product_type == 6){
              row.reservation_time_data = this.formValidate.attr.reservation_time_data;
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
    // 商品属性表头
    generateHeader(data) {
      let specificationsColumns = data.map((item) => ({
        title: item.value,
        key: item.value,
        minWidth: '140',
        fixed: 'left',
      }));
	  if (this.formValidate.product_type == 0) {
		this.formData.header = [...specificationsColumns, ...this.GoodsTableHead];
	  }else if(this.formValidate.product_type == 6){
		this.formData.header = [...specificationsColumns, ...this.ReservationTableHead];  
	  }
	  if(this.$route.params.id && [6].indexOf(this.formValidate.product_type) == -1){
	    const stockIndex = this.formData.header.findIndex(
	      (item) => item.slot === "stock"
	    );
	    this.formData.header.splice(stockIndex + 1, 0, {
	      title: "调整库存",
	      slot: "inventory",
	      align: "center",
	      minWidth: "180px",
	    })
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
    // 添加卡项选择的商品
    getAtterId(selectCardData) {
      this.goodsModal = false;
      const uniqueSet = new Set(this.formValidate.related.map((item) => item.unique));
      const newCardData = selectCardData.filter((item) => !uniqueSet.has(item.unique));
      newCardData.forEach((item) => {
        item.write_times = 1;
      });
      this.formValidate.related = [...this.formValidate.related, ...newCardData];
    },
    // 批量设置卡项商品可核销次数
    handleBatch() {
      this.formValidate.related.forEach((item) => {
        item.write_times = this.batchWriteTimes;
      });
    },
    // 删除卡项选择的商品
    cardDataRowDelete(index) {
      this.formValidate.related.splice(index, 1);
    },
    deleteRecommendImage() {
      this.formValidate.recommend_image = '';
    },
    deleteCardCoverImage() {
      this.formValidate.card_cover_image = '';
    },
    colorChange(value) {
      this.formValidate.card_cover_color = value;
    },
    coverChange(value) {
      this.formValidate.card_cover = value;
    },
    onchangeTime(e){
      this.formValidate.attr.section_time = e;
    },
    // 卡项商品选中
    cardDataChange(selection) {
      this.cardDataSelection = selection;
    },
    // 卡项商品删除
    cardDataDelete() {
      if (!this.cardDataSelection.length) {
        return;
      }
      const deleteIds = this.cardDataSelection.map((item) => {
        return item.id;
      });
      this.formValidate.related = this.formValidate.related.filter((item) => {
        return !deleteIds.includes(item.id);
      });
      this.cardDataSelection = [];
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
				that.formValidate.attr.inventory = 0;
				that.oneFormBatch[0].inventory = 0;
				if(num){
					that.manyFormValidate[index].inventory = 0;
				}
			},1200)
		}
	},
  getDeliveryConfig() {
    deliveryConfigApi().then((res) => {
      this.cityDeliveryStatus = Number(res.data.city_delivery_status);
      // 平台的同城配送关闭
      if (!this.cityDeliveryStatus) {
        this.formValidate.delivery_type = this.formValidate.delivery_type.filter((value) => value != 3);
      }
    });
  },
  getStoreInfo() {
    storeGetInfoApi()
      .then((res) => {
        this.deliveryType = res.data.delivery_type;
        if (this.$route.params.id) {
          // 门店的配送方式控制
          if (this.formValidate.delivery_type.length) {
            this.formValidate.delivery_type = this.formValidate.delivery_type.filter((value) => this.deliveryType.includes(value));
          }
        } else {
          this.formValidate.delivery_type = res.data.delivery_type;
        }
      })
      .catch((err) => {
        this.$Message.error(err.msg);
      });
  },
  },
};
</script>
<style scoped lang="stylus">
/deep/.ivu-select-input,/deep/.ivu-cascader-label{
	font-size: 12px !important;
}
/deep/.ivu-select-item{
	font-size: 12px !important;
}
/deep/.ivu-select-single .ivu-select-selection .ivu-select-selected-value{
	font-size: 12px !important;
}
/deep/.ivu-input-group .ivu-input{
	font-size: 14px !important;
}
.stock-input-box{
  width: 260px;
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
.w-160{
	width: 160px !important;
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
.notice {
  font-size: 12px;
  line-height: 12px;
  border-color: #FFF6E9;
  background-color: #FFF6E9;
  color: #FF9400;
  margin: 0 27px 24px 27px;

  /deep/.ivu-alert-icon {
    font-size: 15px;
    color: #FF9400;
  }
}

.video-style {
  width: 100%;
  height: 100% !important;
  border-radius: 10px;
}

.select-add {
  width: 200px;
  margin-left: 6px;
  margin-right: 10px;
}

.input-display {
  display: none;
}

.width-add {
  width: 200px;
}

.custom-input {
  width: 100px;
  margin-right: 10px;
}

.specsList {
  /deep/.ivu-table-header table {
    border: 0 !important;
  }

  /deep/.ivu-table-header thead tr th {
    padding: 0 !important;
    background-color: #EEEEEE !important;
  }

  /deep/.ivu-table-cell {
    padding: 0 !important;
  }

  /deep/.ivu-table-border th, /deep/.ivu-table-border td {
    border-right: unset;
  }

  /deep/.ivu-table td {
    height: 59px;
  }

  &.on {
    width: 50% !important;

    /deep/.ivu-table {
      width: 100% !important;
    }

    /deep/.ivu-table td {
      height: 40px;
      padding: 0 !important;
    }

    /deep/.ivu-table-cell {
      padding: 0 16px !important;
    }
  }
}

.form-submit {
  /deep/.ivu-card {
    border-radius: 0;
  }

  margin-bottom: 79px;

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
}

.seeCatMy {
  color: #2d8cf0;
  cursor: pointer;
}

.addCustom_content {
  margin-top: 20px;

  .custom_box {
    margin-bottom: 10px;
  }

  .addfont {
    display: inline-block;
    font-size: 13px;
    font-weight: 400;
    color: #1890FF;
    cursor: pointer;
  }
}

.addCustomBox {
  margin-top: 12px;
  font-size: 13px;
  font-weight: 400;
  color: #1890FF;

  .btn {
    cursor: pointer;
    width: max-content;
  }
}

.checkAlls /deep/.ivu-checkbox-inner {
  width: 14px;
  height: 14px;
}

.checkAlls /deep/.ivu-checkbox-wrapper {
  font-size: 12px;
}

.lines {
  border-bottom: 1px dashed #eee;
  margin-bottom: 20px;
}

.iosfont {
  font-size: 20px !important;
}

.selectOn {
  color: #2d8cf0;
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

.offShow {
  position: absolute;
}

.goodsShow /deep/.ivu-radio-group-vertical .ivu-radio-wrapper {
  height: 35px;
  line-height: 35px;
}

.videbox {
  width: 60px;
  height: 60px;
  background: rgba(0, 0, 0, 0.02);
  border-radius: 4px;
  border: 1px dashed #DDDDDD;
  line-height: 50px;
  text-align: center;
  color: #898989;
  font-size: 30px;
  font-weight: 400;
  cursor: pointer;
}

.brandName {
  /deep/.ivu-cascader {
    display: inline-block;
  }
}

.formValidate {
  .addClass {
    color: #1890FF;
    margin-left: 14px;
    padding: 9px 0;
    cursor: pointer;
  }
}

.productType {
  width: 120px;
  height: 60px;
  background: #FFFFFF;
  border-radius: 3px;
  border: 1px solid #E7E7E7;
  float: left;
  text-align: center;
  padding-top: 8px;
  position: relative;
  cursor: pointer;
  line-height: 23px;
  margin-right: 12px;

  &.on {
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

.labelInput {
  border: 1px solid #dcdee2;
  width: 50%;
  padding: 0 5px;
  border-radius: 5px;
  min-height: 30px;
  cursor: pointer;

  .span {
    color: #c5c8ce;
  }

  .iconxiayi {
    font-size: 12px;
  }
}

.labelClass {
  /deep/.ivu-form-item-content {
    line-height: unset;
  }
}

.ivu-checkbox-wrapper {
  margin-right: 19px;
}

.list-group {
  margin-left: -8px;
}

.borderStyle {
  border: 1px solid #ccc;
  padding: 8px;
  border-radius: 4px;
}

.drag {
  cursor: move;
}

.move-icon {
  width: 30px;
  cursor: move;
  margin-right: 10px;
}

.move-icon .icondrag2 {
  font-size: 26px;
  color: #d8d8d8;
}

.maxW /deep/.ivu-select-dropdown {
  max-width: 600px;
}

#shopp-manager .ivu-table-wrapper {
  border-left: 1px solid #dcdee2;
  border-top: 1px solid #dcdee2;
}

.noLeft {
  >>> .ivu-form-item-content {
    margin-left: 0 !important;
  }
}

#shopp-manager .ivu-form-item .tips {
  display: inline-bolck;
  font-size: 12px;
  font-weight: 400;
  color: #999999;
}

.iview-video-style {
  width: 40%;
  height: 180px;
  border-radius: 10px;
  background-color: #707070;
  margin-top: 10px;
  position: relative;
  overflow: hidden;
}

.iview-video-style .iconv {
  color: #fff;
  line-height: 180px;
  width: 50px;
  height: 50px;
  display: inherit;
  font-size: 26px;
  position: absolute;
  top: -74px;
  left: 50%;
  margin-left: -25px;
}

.iview-video-style .mark {
  position: absolute;
  width: 100%;
  height: 30px;
  top: 0;
  background-color: rgba(0, 0, 0, 0.5);
  text-align: center;
}

.uploadVideo {
  margin-left: 10px;
}

.submission {
  margin-left: 10px;
}

.form-submit .fixed-card .ivu-btn {
  height: 32px;
}

.color-list .tip {
  color: #c9c9c9;
}

.color-list .color-item {
  width: 70px;
  height: 28px;
  line-height: 28px;
  color: #fff;
  margin-right: 10px;
  border-radius: 2px;
  text-align: center;

  .num {
    color: #1890FF;
    width: 14px;
    height: 14px;
    text-align: center;
    line-height: 14px;
    border-radius: 50%;
    background-color: #fff;
    margin-right: 6px;
  }
}

.color-list .color-item.blue {
  background-color: #1E9FFF;
}

.color-list .color-item.yellow {
  background-color: rgb(254, 185, 0);
}

.color-list .color-item.green {
  background-color: #009688;
}

.color-list .color-item.red {
  background-color: #ed4014;
}

.color-list .color-item.colorBlue {
  background: linear-gradient(270deg, #5ECFFF 0%, #0084FF 100%);
}

.columnsBox {
  margin-right: 10px;
}

.priceBox {
  width: 100%;
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

.pictrueBox {
  display: inline-block;
}

.pictrueTab {
  width: 40px !important;
  height: 40px !important;
}

.pictrue {
  width: 60px;
  height: 60px;
  border: 1px dotted rgba(0, 0, 0, 0.1);
  margin-right: 15px;
  margin-bottom: 10px;
  display: inline-block;
  position: relative;

  img {
    width: 100%;
    height: 100%;
  }

  .btndel {
    position: absolute;
    z-index: 1;
    width: 20px !important;
    height: 20px !important;
    left: 46px;
    top: -4px;
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

.labeltop {
  >>> .ivu-form-item-label {
    float: none !important;
    display: inline-block !important;
    margin-left: 120px !important;
    width: auto !important;
  }

  .icondrop-down {
    font-size: 12px;
    margin-left: 5px;
  }
}

.video-icon {
  background-image: url('https://cdn.oss.9gt.net/prov1.1/1/icons.png'); // cdn.oss.9gt.net/prov1.1/1/icons.png);
  background-position: -9999px;
  background-repeat: no-repeat;
}

.progress {
  margin-top: 10px;
}

.new_tab {
  >>>.ivu-tabs-nav .ivu-tabs-tab {
    padding: 4px 16px 20px !important;
    font-weight: 500;
  }
}

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
      }
    }
  }
}

.ml30 {
  margin-left: 30px;
}

.mr10 {
  margin-right: 10px !important;
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

    .popper-arrow, .popper-arrow:after {
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

/deep/.ivu-input-prefix, .ivu-input-suffix {
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

.text-blue {
  color: #2d8cf0;
}

.color-picker {
  border: 1px solid #dcdee2;
}

.flex-y-center {
  display: flex;
  align-items: center;
}

/deep/.el-table th {
  background: #f3f8fe !important;
  color: #515A6E !important;
}
.save-btn {
  border-color: transparent;
  color: #2d8cf0;

  &:focus {
    box-shadow: none;
  }
}
</style>
