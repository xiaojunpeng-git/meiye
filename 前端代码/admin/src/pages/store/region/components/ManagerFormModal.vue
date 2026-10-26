<template>
  <div>
    <Modal
      :value="value"
      :title="editId > 0 ? '编辑管理员' : '添加管理员'"
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
        <Row :gutter="24">
          <Col span="12">
            <FormItem label="选择组织：" prop="manageRegion">
              <Cascader
                :data="manageRegionTree"
                v-model="formItem.manage_region_path"
                change-on-select
                filterable
                transfer
                placeholder="请选择组织"
                style="width: 100%"
                @on-change="changeManageRegion"
              />
            </FormItem>
          </Col>
          <Col span="12">
            <FormItem label="管理员名字：" prop="name">
              <Input v-model="formItem.name" placeholder="请输入管理员名字" :maxlength="50" />
            </FormItem>
          </Col>
          <Col span="12">
            <FormItem label="选择用户：" prop="image">
              <div class="picBox" @click="customer">
                <div class="pictrue" v-if="formItem.image || formItem.uid">
                  <img v-if="formItem.image" v-lazy="formItem.image" />
                  <Icon v-else type="ios-person" size="26" />
                </div>
                <div class="upLoad acea-row row-center-wrapper" v-else>
                  <Icon type="ios-camera-outline" size="26" />
                </div>
              </div>
              <div class="tips">选定商城用户后，该管理员可在移动端个人中心查看组织统计入口。</div>
            </FormItem>
          </Col>
          <Col span="12">
            <FormItem label="管理员手机号：" prop="phone">
              <Input v-model="formItem.phone" placeholder="请输入管理员手机号" />
            </FormItem>
          </Col>
          <Col span="12">
            <FormItem label="管理员账号：" prop="account">
              <Input v-model="formItem.account" placeholder="选填，不填则默认用手机号" />
            </FormItem>
          </Col>
          <Col span="12">
            <FormItem label="管理员密码：" prop="pwd">
              <Input type="text" v-model="formItem.pwd" placeholder="请输入密码" />
            </FormItem>
          </Col>
          <Col span="12">
            <FormItem label="确认密码：" prop="conf_pwd">
              <Input type="text" v-model="formItem.conf_pwd" placeholder="请输入确认密码" />
            </FormItem>
          </Col>
          <Col span="12">
            <FormItem label="排序：" prop="sort">
              <InputNumber :min="0" v-model="formItem.sort" style="width: 100%" />
            </FormItem>
          </Col>
          <Col span="12" v-if="formItem.manage_region_id">
            <FormItem label="组织隔离：" prop="is_alone">
              <i-switch v-model="formItem.is_alone" :true-value="1" :false-value="0" size="large">
                <span slot="open">开启</span>
                <span slot="close">关闭</span>
              </i-switch>
              <div class="tips">开启后，用户定位到本组织推荐地区时，仅可在本组织内切换门店。</div>
            </FormItem>
          </Col>
        </Row>
      </Form>
      <div slot="footer">
        <Button @click="handleClose">取消</Button>
        <Button type="primary" :loading="saving" @click="handleSubmit">保存</Button>
      </div>
    </Modal>
    <Modal
      v-model="customerShow"
      scrollable
      title="请选择商城用户"
      width="900"
      :z-index="1100"
    >
      <customerInfo v-if="customerShow" @imageObject="imageObject" />
    </Modal>
  </div>
</template>

<script>
import { mapState } from "vuex";
import customerInfo from "@/components/customerInfo";
import { getRegionInfo, postRegion, getRegionManageCascader } from "@/api/store";

export default {
  name: "ManagerFormModal",
  components: { customerInfo },
  props: {
    value: { type: Boolean, default: false },
    editId: { type: [Number, String], default: 0 },
    defaultRegionId: { type: [Number, String], default: 0 },
  },
  data() {
    const validateUpload = (rule, value, callback) => {
      if (!this.formItem.uid) callback(new Error("请选择用户"));
      else callback();
    };
    const validateManageRegion = (rule, value, callback) => {
      if (!this.formItem.manage_region_id) callback(new Error("请选择组织"));
      else callback();
    };
    const validateAccount = (rule, value, callback) => {
      if (!value) {
        callback();
        return;
      }
      if (!/^[\dA-Za-z\u4e00-\u9fa5]{3,}$/.test(value)) {
        callback(new Error("管理员账号仅支持数字、字母、汉字组合且最小3位"));
      } else {
        callback();
      }
    };
    const validatePwd = (rule, value, callback) => {
      if (!value) {
        callback();
        return;
      }
      // 编辑时回显的 bcrypt 哈希允许原样保存
      if (/^\$2[ay]\$/.test(value)) {
        callback();
        return;
      }
      if (!/^[\dA-Za-z]{6,}$/.test(value)) {
        callback(new Error("管理员密码仅支持数字、字母组合且最小6位"));
      } else {
        callback();
      }
    };
    const validateConfPwd = (rule, value, callback) => {
      if (!this.formItem.pwd && !value) {
        callback();
        return;
      }
      if (this.formItem.pwd !== value) {
        callback(new Error("确认密码与密码不一致"));
      } else {
        callback();
      }
    };
    const validatePhone = (rule, value, callback) => {
      if (!value) return callback(new Error("请填写手机号"));
      if (!/^400[0-9]{7}|^1[3456789]\d{9}$|^0[0-9]{2,3}-[0-9]{7,8}/.test(value)) {
        callback(new Error("手机号格式不正确!"));
      } else {
        callback();
      }
    };
    return {
      saving: false,
      customerShow: false,
      manageRegionTree: [],
      formItem: this.emptyForm(),
      ruleValidate: {
        manageRegion: [{ required: true, validator: validateManageRegion, trigger: "change" }],
        name: [
          { required: true, message: "请输入管理员名字", trigger: "blur" },
          { type: "string", max: 50, message: "管理员名字最多50个字符", trigger: "blur" },
        ],
        image: [{ required: true, validator: validateUpload, trigger: "change" }],
        account: [{ validator: validateAccount, trigger: "blur" }],
        pwd: [{ validator: validatePwd, trigger: "blur" }],
        conf_pwd: [{ validator: validateConfPwd, trigger: "blur" }],
        phone: [{ required: true, validator: validatePhone, trigger: "blur" }],
      },
    };
  },
  computed: {
    ...mapState("admin/layout", ["isMobile"]),
    labelWidth() {
      return this.isMobile ? undefined : 120;
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
        manage_region_id: 0,
        manage_region_path: [],
        name: "",
        image: "",
        uid: 0,
        account: "",
        pwd: "",
        conf_pwd: "",
        phone: "",
        sort: 0,
        is_alone: 1,
      };
    },
    open() {
      this.formItem = this.emptyForm();
      getRegionManageCascader()
        .then((res) => {
          this.manageRegionTree = this.attachRegionPid(res.data || []);
          if (this.editId) {
            this.loadInfo();
          } else if (this.defaultRegionId) {
            this.formItem.manage_region_path = this.buildManageRegionPath(
              Number(this.defaultRegionId),
              this.manageRegionTree
            );
            this.changeManageRegion(this.formItem.manage_region_path);
          }
        })
        .catch((err) => this.$Message.error(err.msg || "加载组织失败"));
    },
    loadInfo() {
      getRegionInfo(this.editId)
        .then((res) => {
          const data = res.data || {};
          this.formItem.manage_region_id = data.manage_region_id || 0;
          this.formItem.manage_region_path = data.manage_region_path || [];
          this.formItem.phone = data.phone || "";
          this.formItem.account = data.account || "";
          this.formItem.uid = Number(data.uid || (data.userInfo && data.userInfo.uid) || 0);
          this.formItem.image = data.userInfo && data.userInfo.avatar ? data.userInfo.avatar : "";
          this.formItem.sort = data.sort != null ? data.sort : 0;
          this.formItem.is_alone = data.is_alone != null ? data.is_alone : 0;
          this.formItem.name = data.name || data.admin_name || "";
          this.formItem.pwd = data.pwd || "";
          this.formItem.conf_pwd = data.conf_pwd || data.pwd || "";
          if (data.manage_region_id && !this.formItem.manage_region_path.length) {
            this.formItem.manage_region_path = this.buildManageRegionPath(
              data.manage_region_id,
              this.manageRegionTree
            );
          }
          this.changeManageRegion(this.formItem.manage_region_path);
        })
        .catch((err) => this.$Message.error(err.msg || "加载失败"));
    },
    attachRegionPid(tree, pid = 0) {
      return (tree || [])
        .filter((item) => item.value !== 0)
        .map((item) => ({
          ...item,
          pid,
          children:
            item.children && item.children.length
              ? this.attachRegionPid(item.children, item.value)
              : [],
        }));
    },
    buildManageRegionPath(id, tree, prefix = []) {
      for (let i = 0; i < (tree || []).length; i++) {
        const node = tree[i];
        const path = prefix.concat([node.value]);
        if (Number(node.value) === Number(id)) return path;
        if (node.children && node.children.length) {
          const childPath = this.buildManageRegionPath(id, node.children, path);
          if (childPath.length) return childPath;
        }
      }
      return [];
    },
    changeManageRegion(value) {
      const path = value || this.formItem.manage_region_path || [];
      this.formItem.manage_region_path = path;
      this.formItem.manage_region_id = path.length ? Number(path[path.length - 1]) : 0;
      if (this.$refs.formItem) this.$refs.formItem.validateField("manageRegion");
    },
    customer() {
      this.customerShow = true;
    },
    imageObject(e) {
      this.customerShow = false;
      this.formItem.uid = e.uid;
      this.formItem.image = e.image;
      if (this.$refs.formItem) this.$refs.formItem.validateField("image");
    },
    handleClose() {
      this.$emit("input", false);
    },
    handleSubmit() {
      this.formItem.name = (this.formItem.name || "").trim();
      this.formItem.account = (this.formItem.account || "").trim();
      const path = this.formItem.manage_region_path || [];
      if (path.length) {
        this.formItem.manage_region_id = Number(path[path.length - 1]);
      }
      this.$refs.formItem.validate((valid) => {
        if (!valid) {
          this.$Message.error("请完善数据");
          return;
        }
        const payload = {
          ...this.formItem,
          uid: Number(this.formItem.uid || 0),
        };
        if (!this.editId) {
          payload.nickname = (this.formItem.account || this.formItem.name || "").trim();
        }
        this.saving = true;
        postRegion(payload, this.editId || 0)
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
  margin-top 8px
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
</style>
