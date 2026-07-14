<template>
  <div>
    <div class="i-layout-page-header">
      <PageHeader class="product_tabs" hidden-breadcrumb>
        <div slot="title" class="acea-row row-middle">
          <router-link :to="{ path: `${routePre}/staff/index` }">
            <div class="font-sm after-line">
              <span class="iconfont iconfanhui"></span>
              <span class="pl10">返回</span>
            </div>
          </router-link>
          <span
              v-text="$route.params.id > 0 ? '编辑店员' : '添加店员'"
              class="mr20 ml16 fs-18"
          ></span>
        </div>
      </PageHeader>
    </div>
    <Card :bordered="false" dis-hover class="ivu-mt">
      <Form
          ref="formInline"
          :model="formInline"
          :rules="ruleValidate"
          :label-width="labelWidth"
          :label-position="labelPosition"
          @submit.native.prevent
      >
        <FormItem label="店员名称：" label-for="staff_name" prop="staff_name">
          <Input placeholder="请输入店员名称" v-model="formInline.staff_name" v-width="'50%'" />
        </FormItem>
        <FormItem label="店员头像：" required prop="avatar">
          <div class="picBox" @click="modalPicTap('单选', 'avatar')">
            <div class="pictrue" v-if="formInline.avatar"><img v-lazy="formInline.avatar"></div>
            <div class="upLoad acea-row row-center-wrapper" v-else>
              <span class="iconfont iconjiahao1"></span>
            </div>
          </div>
        </FormItem>
        <FormItem label="商城用户：" prop="uid">
          <div v-if="formInline.uid">{{userName}}（ID：{{formInline.uid}}）
            <span @click="customer" class="ml-10 text-wlll-2d8cf0 cup">换绑</span>
            <span @click="delCustomer" class="ml-10 text-wlll-2d8cf0 cup">解绑</span>
          </div>
          <div v-else @click="customer" class="text-wlll-2d8cf0 cup">+选择用户</div>
          <div class="tips">选择用户后，该店员可在移动端个人中心看到门店中心入口。门店中心的细分权限可通过身份控制</div>
        </FormItem>
        <FormItem label="店员账号：" prop="account">
          <Input v-model="formInline.account" placeholder="请输入店员账号" v-width="'50%'"></Input>
        </FormItem>
        <FormItem label="店员密码：" :required='$route.params.id > 0?false:true'>
          <Input type="password" v-model="formInline.pwd" placeholder="请输入店员密码" v-width="'50%'"></Input>
        </FormItem>
        <FormItem label="手机号码：" prop="phone">
          <Input v-model="formInline.phone" placeholder="请输入手机号码" v-width="'50%'"></Input>
        </FormItem>
        <FormItem label="关联企微：" prop="work_member_id">
          <Select
              v-model="formInline.work_member_id"
              clearable
              filterable
              v-width="'50%'"
              placeholder="请选择企微员工"
          >
            <Option
                v-for="(item, index) in workList"
                :value="item.value"
                :key="item.value"
            >{{ item.label }}</Option
            >
          </Select>
          <div class="tips">选择总部同步的企微员工。关联成功后，于移动端门店中心查看企微码。</div>
        </FormItem>
        <FormItem label="店员权限：" prop="roles">
          <Select
              v-model="formInline.roles"
              clearable
              filterable
              multiple
              v-width="'50%'"
              placeholder="请选择店员角色"
          >
            <Option
                v-for="(item, index) in roleList"
                :value="item.value+''"
                :key="item.value"
            >{{ item.label }}</Option
            >
          </Select>
          <div class="tips">选择角色后，此用户将继承此角色的所有权限</div>
        </FormItem>
        <FormItem label="选择职位：" prop="position">
          <Select
              v-model="formInline.position"
              clearable
              filterable
              v-width="'50%'"
              placeholder="请选择职位"
          >
            <Option
                v-for="(item, index) in positionData"
                :value="item.value"
                :key="item.value"
            >{{ item.label }}</Option
            >
          </Select>
          <div class="tips">选择职位</div>
        </FormItem>
        <FormItem label="选择职级：" prop="position_level">
          <Select
              v-model="formInline.position_level"
              clearable
              filterable
              v-width="'50%'"
              placeholder="请选择职级"
          >
            <Option
                v-for="(item, index) in positionLevelData"
                :value="item.value"
                :key="item.value"
            >{{ item.label }}</Option
            >
          </Select>
          <div class="tips">选择职级</div>
        </FormItem>
        <FormItem label="店长开关" prop="is_manager">
          <i-switch
              v-model="formInline.is_manager"
              :value="formInline.is_manager"
              :true-value="1"
              :false-value="0"
              size="large"
          >
            <span slot="open">显示</span>
            <span slot="close">隐藏</span>
          </i-switch>
        </FormItem>
        <FormItem label="客服开关" prop="is_customer">
          <i-switch
              v-model="formInline.is_customer"
              :value="formInline.is_customer"
              :true-value="1"
              :false-value="0"
              size="large"
          >
            <span slot="open">显示</span>
            <span slot="close">隐藏</span>
          </i-switch>
        </FormItem>
        <FormItem label="允许被其他门店选中" prop="is_customer">
          <i-switch
              v-model="formInline.can_choose"
              :value="formInline.can_choose"
              :true-value="1"
              :false-value="0"
              size="large"
          >
            <span slot="open">是</span>
            <span slot="close">否</span>
          </i-switch>
        </FormItem>
        <FormItem label="可被预约" prop="is_reservable">
          <i-switch
              v-model="formInline.is_reservable"
              :true-value="1"
              :false-value="0"
              size="large"
          >
            <span slot="open">是</span>
            <span slot="close">否</span>
          </i-switch>
          <div class="tips">关闭后该员工不会在预约看板及预约选人列表中展示（启用排班管理时以排班为准）</div>
        </FormItem>
        <FormItem label="是否管家" prop="is_butler">
          <i-switch
              v-model="formInline.is_butler"
              :true-value="1"
              :false-value="0"
              size="large"
          >
            <span slot="open">是</span>
            <span slot="close">否</span>
          </i-switch>
          <div class="tips">开启后可在手机端门店中心查看全店待确认预约，并执行接单/拒绝操作</div>
        </FormItem>
        <FormItem label="客服二维码：" required prop="customer_url" v-if="formInline.is_customer">
          <div class="picBox" @click="modalPicTap('单选', 'customer_url')">
            <div class="pictrue" v-if="formInline.customer_url"><img v-lazy="formInline.customer_url"></div>
            <div class="upLoad acea-row row-center-wrapper" v-else>
              <span class="iconfont iconjiahao1"></span>
            </div>
          </div>
        </FormItem>
<!--        <FormItem label="部门：" prop="department">-->
<!--          <Input v-model="formInline.department" placeholder="请输入部门" v-width="'50%'"></Input>-->
<!--        </FormItem>-->
        <FormItem label="工号：" prop="employee_number">
          <Input v-model="formInline.employee_number" placeholder="请输入工号" v-width="'50%'"></Input>
        </FormItem>
        <FormItem label="身份证号：" prop="id_card">
          <Input v-model="formInline.id_card" placeholder="请输入身份证号" v-width="'50%'"></Input>
        </FormItem>
        <FormItem label="年龄：" prop="age">
          <Input v-model="formInline.age" placeholder="请输入年龄" v-width="'50%'"></Input>
        </FormItem>
        <FormItem label="劳动关系所在地：" prop="join_area">
          <Input v-model="formInline.join_area" placeholder="请输入劳动关系所在地" v-width="'50%'"></Input>
        </FormItem>
        <FormItem label="入职日期：">
          <DatePicker
              :editable="false"
              :clearable="true"
              @on-change="setJoin"
              :value="formInline.join_date"
              format="yyyy/MM/dd"
              type="datetime"
              placement="bottom-start"
              placeholder="自定义时间"
              style="width: 250px"
              :options="options"
          >
          </DatePicker>
        </FormItem>
        <FormItem label="生日日期：">
          <DatePicker
              :editable="false"
              :clearable="true"
              @on-change="setBirthday"
              :value="formInline.birthday_date"
              format="yyyy/MM/dd"
              type="datetime"
              placement="bottom-start"
              placeholder="自定义时间"
              style="width: 250px"
              :options="options"
          >
          </DatePicker>
        </FormItem>
        <FormItem label="合同起始日：">
          <DatePicker
              :editable="false"
              :clearable="true"
              @on-change="contractBegin"
              :value="formInline.contract_begin"
              format="yyyy/MM/dd"
              type="datetime"
              placement="bottom-start"
              placeholder="自定义时间"
              style="width: 250px"
              :options="options"
          >
          </DatePicker>
        </FormItem>
        <FormItem label="合同终止日：">
          <DatePicker
              :editable="false"
              :clearable="false"
              @on-change="contractEnd"
              :value="formInline.contract_end"
              format="yyyy/MM/dd"
              type="datetime"
              placement="bottom-start"
              placeholder="自定义时间"
              style="width: 250px"
              :options="options"
          >
          </DatePicker>
        </FormItem>
        <FormItem label="籍贯：" prop="id_card">
          <Input v-model="formInline.birthday_area" placeholder="请输入籍贯" v-width="'50%'"></Input>
        </FormItem>
        <FormItem label="现居地：" prop="now_area">
          <Input v-model="formInline.now_area" placeholder="请输入现居地" v-width="'50%'"></Input>
        </FormItem>
        <FormItem label="生日类型：">
          <Select
              v-model="formInline.birthday_type"
              class="mr15"
              placeholder="请选择生日类型"
              clearable
          >
            <Option :value="1">农历</Option>
            <Option :value="2">新历</Option>
          </Select>
        </FormItem>
        <FormItem label="状态" prop="status">
          <i-switch
              v-model="formInline.status"
              :value="formInline.status"
              :true-value="1"
              :false-value="0"
              size="large"
          >
            <span slot="open">显示</span>
            <span slot="close">隐藏</span>
          </i-switch>
        </FormItem>
        <FormItem label="工资状态" prop="salary_status">
          <i-switch
              v-model="formInline.salary_status"
              :value="formInline.salary_status"
              :true-value="1"
              :false-value="0"
              size="large"
          >
            <span slot="open">显示</span>
            <span slot="close">隐藏</span>
          </i-switch>
        </FormItem>
      </Form>
    </Card>
    <div class="h-68"></div>
    <Card
        :bordered="false"
        dis-hover
        class="fixed-card"
    >
      <Form>
        <FormItem>
          <Button
              type="primary"
              class="submission"
              @click="handleSubmit('formInline')"
          >保存</Button
          >
        </FormItem>
      </Form>
    </Card>
    <Modal v-model="modalPic" width="960px" scrollable footer-hide closable title='上传门店照片' :mask-closable="false" :z-index="99">
      <uploadPictures :isChoice="isChoice" @getPic="getPic" v-if="modalPic"></uploadPictures>
    </Modal>
    <Modal v-model="modalUser" width="960px" scrollable footer-hide closable title='请选择商城用户' :mask-closable="false" :z-index="99">
      <userList @imageObject='imageObject'></userList>
    </Modal>
  </div>
</template>

<script>
import Setting from "@/setting";
import timeOptions from '@/utils/timeOptions';
import { mapState, mapMutations } from 'vuex';
import {systemRoleList,workMemberList,postStaff,getStaffInfo,position,positionLevel} from "@/api/staff.js";
import uploadPictures from '@/components/uploadPictures';
import userList from '@/components/userList';
export default {
  name: 'roleAdd',
  components: { uploadPictures,userList },
  data () {
    let validateUpload = (rule, value, callback) => {
      if (!this.formInline.avatar) {
        callback(new Error('请上传店员头像'))
      } else {
        callback()
      }
    };
    let validateUid = (rule, value, callback) => {
      if (!this.formInline.uid) {
        callback(new Error('请上传商城用户'))
      } else {
        callback()
      }
    };
    let validatePhone = (rule, value, callback) => {
      if (!value) {
        return callback(new Error('请填写手机号'));
      } else if (!/^1[3456789]\d{9}$/.test(value)) {
        callback(new Error('手机号格式不正确!'));
      } else {
        callback();
      }
    };
    let validateUrl = (rule, value, callback) => {
      if (!this.formInline.customer_url) {
        callback(new Error('请上传客服二维码'))
      } else {
        callback()
      }
    };
    return {
      routePre: Setting.routePre,
      id:0, //店员id；
      modalPic: false,
      isChoice: '单选',
      picTit:'',
      userName:'',
      modalUser: false,
      roleList:[],
      positionData:[],
      positionLevelData:[],
      workList:[],
      options: timeOptions,
      formInline: {
        staff_name:'',
        avatar:'',
        uid:0,
        account:'',
        pwd:'',
        phone:'',
        work_member_id:'',
        roles:[],
        position:0,
        position_level:0,
        is_manager:0,
        is_customer:0,
        can_choose:0,
        is_reservable:1,
        is_butler:0,
        customer_url:'',
        status:1,
        salary_status:1,
        department:'',
        employee_number:'',
        join_date:'',
        id_card:'',
        birthday_date:'',
        birthday_type:1,
        age:0,
        join_area:'',
        birthday_area:'',
        now_area:'',
        contract_begin:'',
        contract_end:''
      },
      ruleValidate: {
        staff_name: [
          { required: true, message: '请输入店员名称', trigger: 'blur' }
        ],
        avatar: [
          { required: true, validator: validateUpload, trigger: 'change' }
        ],
        // uid: [
        // 	{ required: true, validator: validateUid, trigger: 'change' }
        // ],
        account: [
          { required: true, message: '请输入店员账号', trigger: 'blur' }
        ],
        phone: [
          { required: true, validator: validatePhone, trigger: 'blur' }
        ],
        roles: [
          { required: true, message: '请选择店员权限', trigger: 'change', type: "array" }
        ],
        customer_url: [
          { required: true, validator: validateUrl, trigger: 'change' }
        ]
      }
    }
  },
  computed: {
    ...mapState('admin/layout', ['isMobile', 'menuCollapse']),
    labelWidth() {
      return this.isMobile ? undefined : 120;
    },
    labelPosition() {
      return this.isMobile ? 'top' : 'right';
    },
  },
  created(){
    if(this.$route.params.id>0){
      this.id = this.$route.params.id
      this.staffInfo();
    }
    this.systemRole();
    this.positionList();
    this.positionLevelList();
    this.workMember();
  },
  methods: {
    setJoin(date) {
      this.formInline.join_date = date;
    },
    setBirthday(date) {
      this.formInline.birthday_date = date;
    },
    contractBegin(date) {
      this.formInline.contract_begin = date;
    },
    contractEnd(date) {
      this.formInline.contract_end = date;
    },
    staffInfo (){
      getStaffInfo(this.id).then(res=>{
        this.formInline = { ...this.formInline, ...(res.data.ps_info || {}) };
        this.userName = res.data.ps_info.nickname;
        this.formInline.pwd = '';
      }).catch(err=>{
        this.$Message.error(err.msg);
      })
    },
    // 选择图片
    modalPicTap (tit, picTit) {
      this.modalPic = true;
      this.picTit = picTit || "";
      this.$refs.formInline.validateField(picTit)
    },
    // 选中图片
    getPic (pc) {
      this.formInline[this.picTit] = pc.att_dir;
      this.modalPic = false;
      this.$refs.formInline.validateField(this.picTit)
    },
    delCustomer(){
      this.formInline.uid=0;
    },
    customer(){
      this.modalUser = true;
      this.$refs.formInline.validateField('uid');
    },
    imageObject(e){
      this.formInline.uid = e.uid;
      this.userName = e.name;
      this.modalUser = false;
      this.$refs.formInline.validateField('uid');
    },
    systemRole(){
      systemRoleList().then(res=>{
        this.roleList = res.data;
      }).catch(err=>{
        this.$Message.error(err.msg);
      })
    },
    positionList(){
      position().then(res=>{
        this.positionData = res.data;
      }).catch(err=>{
        this.$Message.error(err.msg);
      })
    },
    positionLevelList(){
      positionLevel().then(res=>{
        this.positionLevelData = res.data;
      }).catch(err=>{
        this.$Message.error(err.msg);
      })
    },
    workMember(){
      workMemberList().then(res=>{
        this.workList = res.data;
      }).catch(err=>{
        this.$Message.error(err.msg);
      })
    },
    // 提交
    handleSubmit (name) {
      this.$refs[name].validate((valid) => {
        if (valid) {
          if(this.$route.params.id == 0 && !this.formInline.pwd){
            return this.$Message.error('请输入店员密码');
          }
          postStaff(this.formInline,this.id).then(res=>{
            this.$Message.success(res.msg);
            this.$router.push({ path: this.routePre + "/staff/index" });
          }).catch(err=>{
            this.$Message.error(err.msg);
          })
        } else {
          return false
        }
      })
    }
  }
}
</script>

<style scoped lang="stylus">
/deep/.ivu-select-item,/deep/.ivu-select-input{
  font-size: 12px !important;
}
.tips{
  font-size: 12px;
  font-weight: 400;
  color: #999999;
  margin-top: 6px;
}
.fixed-card {
  position: fixed;
  right: 0;
  bottom: 0;
  left: 220px;
  z-index: 99;
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
.picBox{
  display: inline-block;
  cursor: pointer;
.upLoad{
  width: 58px;
  height: 58px;
  line-height: 58px;
  border: 1px dotted rgba(0, 0, 0, 0.1);
  border-radius: 4px;
  background: rgba(0, 0, 0, 0.02);
}
.pictrue{
  width: 60px;
  height: 60px;
  border: 1px dotted rgba(0, 0, 0, 0.1);
  margin-right: 10px;
img {
  width: 100%;
  height: 100%;
}
}
.iconfont{
  color: #898989;
}
}
</style>
