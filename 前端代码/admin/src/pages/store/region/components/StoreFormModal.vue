<template>
  <div>
    <Modal
      :value="value"
      :title="editId > 0 ? '编辑门店' : '添加门店'"
      width="1274"
      :mask-closable="false"
      :styles="{ top: '40px' }"
      @on-cancel="handleClose"
    >
      <Form
        v-if="value"
        ref="formItem"
        :model="formItem"
        :rules="ruleValidate"
        :label-width="labelWidth"
        :label-position="labelPosition"
        @submit.native.prevent
      >
        <Spin size="large" fix v-if="spinShow"></Spin>
        <Row :gutter="24">
          <Col span="12">
            <FormItem label="门店照片：" prop="image">
              <div class="picBox" @click="modalPicTap('image')">
                <div class="pictrue" v-if="formItem.image"><img v-lazy="formItem.image" /></div>
                <div class="upLoad" v-else><div class="iconfont">+</div></div>
              </div>
              <div class="tips">建议尺寸：70 * 70px</div>
            </FormItem>
          </Col>
          <Col span="12">
            <FormItem label="门头照片：" prop="background_image">
              <div class="picBox" @click="modalPicTap('background_image')">
                <div class="pictrue" v-if="formItem.background_image"><img v-lazy="formItem.background_image" /></div>
                <div class="upLoad" v-else><div class="iconfont">+</div></div>
              </div>
              <div class="tips">建议尺寸：375 * 192px</div>
            </FormItem>
          </Col>
          <Col span="12">
            <FormItem label="门店名称：" prop="name">
              <Input v-model="formItem.name" maxlength="20" show-word-limit placeholder="请输入门店名称" />
            </FormItem>
          </Col>
          <Col span="12">
            <FormItem label="床位数：" prop="space_num">
              <Input v-model="formItem.space_num" placeholder="请输入床位数" />
            </FormItem>
          </Col>
          <Col span="24">
            <FormItem label="门店简介：">
              <Input
                v-model="formItem.introduction"
                type="textarea"
                :rows="3"
                maxlength="100"
                show-word-limit
                placeholder="请输入门店简介"
              />
            </FormItem>
          </Col>
          <Col span="12">
            <FormItem label="所属组织：" prop="manage_region_path">
              <Cascader
                :data="manageRegionTree"
                v-model="formItem.manage_region_path"
                change-on-select
                filterable
                transfer
                placeholder="请选择所属组织"
                style="width: 100%"
              />
            </FormItem>
          </Col>
          <Col span="12">
            <FormItem label="营业状态：" prop="is_show">
              <i-switch v-model="formItem.is_show" :true-value="1" :false-value="0" size="large">
                <span slot="open">开启</span>
                <span slot="close">关闭</span>
              </i-switch>
            </FormItem>
          </Col>
          <Col span="12">
            <FormItem label="营业时间：" prop="day_time">
              <TimePicker
                type="timerange"
                v-model="formItem.day_time"
                format="HH:mm:ss"
                placement="bottom-end"
                placeholder="请选择营业时间"
                style="width: 100%"
                @on-change="onchangeTime"
              />
            </FormItem>
          </Col>
          <Col span="12">
            <FormItem label="门店类型：" prop="type">
              <RadioGroup v-model="formItem.type">
                <Radio :label="1">自营</Radio>
                <Radio :label="2">加盟</Radio>
              </RadioGroup>
            </FormItem>
          </Col>
          <Col span="12" v-if="!editId">
            <FormItem label="管理员账号：" prop="store_account">
              <Input v-model="formItem.store_account" placeholder="请输入管理员账号" />
            </FormItem>
          </Col>
          <Col span="12" v-if="!editId">
            <FormItem label="管理员密码：" prop="store_password">
              <Input type="password" password v-model="formItem.store_password" placeholder="请输入管理员密码" />
            </FormItem>
          </Col>
          <Col span="12">
            <FormItem label="门店手机号：" prop="phone">
              <Input v-model="formItem.phone" placeholder="请输入门店手机号" />
            </FormItem>
          </Col>
          <Col span="12">
            <FormItem label="门店地址：" prop="address">
              <Cascader
                :data="addresData"
                :load-data="loadData"
                v-model="formItem.addressSelect"
                transfer
                style="width: 100%"
                @on-change="addchack"
              />
            </FormItem>
          </Col>
          <Col span="24">
            <FormItem label="详细地址：" prop="detailed_address">
              <div class="acea-row row-middle">
                <Input v-if="storeAddress" disabled v-model="storeAddress" class="w-240" />
                <Input
                  search
                  enter-button="查找位置"
                  v-model="formItem.detailed_address"
                  placeholder="输入详细地址"
                  class="w-300 ml-6"
                  @on-search="onSearch"
                />
              </div>
            </FormItem>
          </Col>
          <Col span="24" v-if="isApi && mapKey">
            <Maps
              ref="mapChild"
              class="map-sty"
              :mapKey="mapKey"
              :lat="Number(formItem.latitude || 34.34127)"
              :lon="Number(formItem.longitude || 108.93984)"
              :address="storeAddress + formItem.detailed_address"
              @getCoordinates="getCoordinates"
            />
          </Col>
        </Row>
      </Form>
      <div slot="footer">
        <Button @click="handleClose">取消</Button>
        <Button type="primary" :loading="saving" @click="handleSubmit">保存</Button>
      </div>
    </Modal>
    <Modal
      v-model="modalPic"
      width="960"
      scrollable
      footer-hide
      closable
      title="上传图片"
      :mask-closable="false"
      :z-index="1100"
    >
      <uploadPictures
        v-if="modalPic"
        isChoice="单选"
        :gridBtn="gridBtn"
        :gridPic="gridPic"
        @getPic="getPic"
      />
    </Modal>
  </div>
</template>

<script>
import { mapState } from "vuex";
import uploadPictures from "@/components/uploadPictures";
import Maps from "@/components/map/map.vue";
import {
  keyApi,
  storeGetInfoApi,
  cityApi,
  storeUpdateApi,
  getRegionManageCascader,
} from "@/api/store";

export default {
  name: "StoreFormModal",
  components: { uploadPictures, Maps },
  props: {
    value: { type: Boolean, default: false },
    editId: { type: [Number, String], default: 0 },
    defaultRegionId: { type: [Number, String], default: 0 },
  },
  data() {
    const validatePhone = (rule, value, callback) => {
      if (!value) return callback(new Error("请填写手机号"));
      if (!/^1[3456789]\d{9}$/.test(value) && !/^0\d{2,3}-?\d{7,8}$/.test(value)) {
        return callback(new Error("手机号格式不正确"));
      }
      callback();
    };
    const validateUpload = (rule, value, callback) => {
      if (!this.formItem.image) callback(new Error("请上传门店照片"));
      else callback();
    };
    return {
      spinShow: false,
      saving: false,
      modalPic: false,
      picTit: "",
      isApi: 0,
      mapKey: "",
      storeAddress: "",
      addresData: [],
      manageRegionTree: [],
      formItem: this.emptyForm(),
      ruleValidate: {
        image: [{ required: true, validator: validateUpload, trigger: "change" }],
        name: [{ required: true, message: "请输入门店名称", trigger: "blur" }],
        phone: [{ required: true, validator: validatePhone, trigger: "blur" }],
        manage_region_path: [
          {
            required: true,
            type: "array",
            min: 1,
            message: "请选择所属组织",
            trigger: "change",
          },
        ],
        store_account: [
          {
            validator: (rule, value, callback) => {
              if (this.editId) return callback();
              if (!value) return callback(new Error("请输入管理员账号"));
              callback();
            },
            trigger: "blur",
          },
        ],
        store_password: [
          {
            validator: (rule, value, callback) => {
              if (this.editId) return callback();
              if (!value) return callback(new Error("请输入管理员密码"));
              callback();
            },
            trigger: "blur",
          },
        ],
      },
      gridPic: { xl: 6, lg: 8, md: 12, sm: 12, xs: 12 },
      gridBtn: { xl: 4, lg: 8, md: 8, sm: 8, xs: 8 },
    };
  },
  computed: {
    ...mapState("admin/layout", ["isMobile"]),
    labelWidth() {
      return this.isMobile ? undefined : 110;
    },
    labelPosition() {
      return this.isMobile ? "top" : "right";
    },
  },
  watch: {
    value(val) {
      if (val) this.open();
    },
  },
  methods: {
    emptyForm() {
      return {
        id: 0,
        image: "",
        background_image: "",
        name: "",
        space_num: "",
        introduction: "",
        manage_region_path: [],
        manage_region_id: 0,
        is_show: 1,
        day_time: [],
        type: 1,
        store_account: "",
        store_password: "",
        phone: "",
        address: "",
        detailed_address: "",
        addressSelect: [],
        latitude: "",
        longitude: "",
        province: 0,
        city: 0,
        area: 0,
        street: 0,
        valid_range: 0,
        product_status: 1,
        product_verify_status: 0,
        delivery_type: ["1", "2"],
        default_delivery: 1,
      };
    },
    open() {
      this.formItem = this.emptyForm();
      this.storeAddress = "";
      this.isApi = 0;
      this.cityInfo({ pid: 0 });
      this.getKey();
      getRegionManageCascader()
        .then((res) => {
          this.manageRegionTree = res.data || [];
          if (this.editId) {
            this.loadInfo(this.editId);
          } else if (this.defaultRegionId) {
            this.formItem.manage_region_path = this.buildPath(
              Number(this.defaultRegionId),
              this.manageRegionTree
            );
          }
        })
        .catch((err) => this.$Message.error(err.msg || "加载组织失败"));
    },
    loadInfo(id) {
      this.spinShow = true;
      storeGetInfoApi(id)
        .then((res) => {
          this.isApi = 1;
          const info = res.data.info || {};
          const addressSelect = [];
          if (info.province) addressSelect.push(info.province);
          if (info.city) addressSelect.push(info.city);
          if (info.area) addressSelect.push(info.area);
          if (info.street) addressSelect.push(info.street);
          this.formItem = {
            ...this.emptyForm(),
            ...info,
            addressSelect,
            day_time: info.timeVal || info.day_time || [],
            manage_region_path: info.manage_region_path || [],
            type: Number(info.type || 1),
            is_show: Number(info.is_show != null ? info.is_show : 1),
          };
          if (!this.formItem.manage_region_path.length && info.manage_region_id) {
            this.formItem.manage_region_path = this.buildPath(
              Number(info.manage_region_id),
              this.manageRegionTree
            );
          }
          this.storeAddress = info.address || "";
          this.$nextTick(() => this.onSearch());
        })
        .catch((err) => this.$Message.error(err.msg || "加载门店失败"))
        .finally(() => {
          this.spinShow = false;
        });
    },
    buildPath(id, tree, prefix = []) {
      for (let i = 0; i < (tree || []).length; i++) {
        const node = tree[i];
        const path = prefix.concat([node.value]);
        if (Number(node.value) === Number(id)) return path;
        if (node.children && node.children.length) {
          const child = this.buildPath(id, node.children, path);
          if (child.length) return child;
        }
      }
      return [];
    },
    handleClose() {
      this.$emit("input", false);
    },
    modalPicTap(picTit) {
      this.picTit = picTit;
      this.modalPic = true;
    },
    getPic(pc) {
      this.formItem[this.picTit] = pc.att_dir;
      this.modalPic = false;
      if (this.$refs.formItem) this.$refs.formItem.validateField(this.picTit);
    },
    onchangeTime(e) {
      this.formItem.day_time = e;
    },
    cityInfo(data) {
      cityApi(data).then((res) => {
        this.addresData = res.data || [];
      });
    },
    loadData(item, callback) {
      item.loading = true;
      cityApi({ pid: item.value }).then((res) => {
        item.children = res.data;
        item.loading = false;
        callback();
      });
    },
    addchack(e, selectedData) {
      (e || []).forEach((i, index) => {
        if (index === 0) this.formItem.province = i;
        else if (index === 1) this.formItem.city = i;
        else if (index === 2) this.formItem.area = i;
        else this.formItem.street = i;
      });
      this.formItem.address = (selectedData || []).map((o) => o.label).join("/");
      this.storeAddress = (selectedData || []).map((o) => o.label).join("");
    },
    getCoordinates(data) {
      this.formItem.latitude = (data.location && data.location.lat) || 34.34127;
      this.formItem.longitude = (data.location && data.location.lng) || 108.93984;
    },
    onSearch() {
      if (this.$refs.mapChild) {
        this.$refs.mapChild.searchKeyword(this.storeAddress + this.formItem.detailed_address);
      }
    },
    getKey() {
      keyApi()
        .then((res) => {
          this.mapKey = res.data.key;
        })
        .catch(() => {});
    },
    handleSubmit() {
      this.$refs.formItem.validate((valid) => {
        if (!valid) return;
        if (!this.formItem.day_time || !this.formItem.day_time[0]) {
          this.formItem.day_time = ["00:00:00", "23:59:59"];
        }
        const path = this.formItem.manage_region_path || [];
        this.formItem.manage_region_id = path.length ? Number(path[path.length - 1]) : 0;
        this.saving = true;
        storeUpdateApi(this.editId || 0, this.formItem)
          .then((res) => {
            this.$Message.success(res.msg || "保存成功");
            this.$emit("input", false);
            this.$emit("success");
          })
          .catch((err) => this.$Message.error(err.msg || "保存失败"))
          .finally(() => {
            this.saving = false;
          });
      });
    },
  },
};
</script>

<style scoped lang="stylus">
.tips
  font-size 12px
  color #999
  line-height 1.5
  margin-top 6px
.picBox
  display inline-block
  cursor pointer
  .upLoad
    width 58px
    height 58px
    line-height 58px
    text-align center
    border 1px dotted rgba(0, 0, 0, 0.1)
    border-radius 4px
    background rgba(0, 0, 0, 0.02)
  .pictrue
    width 60px
    height 60px
    border 1px dotted rgba(0, 0, 0, 0.1)
    img
      width 100%
      height 100%
      object-fit cover
.map-sty
  width 100%
  height 320px
.w-240
  width 240px
.w-300
  width 300px
.ml-6
  margin-left 6px
</style>
