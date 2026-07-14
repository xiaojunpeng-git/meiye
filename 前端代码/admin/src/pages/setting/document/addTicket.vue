<template>
  <Modal
    v-model="modals"
    closable
    :title="id ? '编辑打印机' : '添加打印机'"
    :mask-closable="false"
    :z-index="11"
    width="650"
  >
    <div>
      <Form size="small" ref="form" :model="form" :label-width="120">
        <FormItem label="平台选择：">
          <RadioGroup v-model="form.plat_type" :true-value="1" :false-value="2">
            <Radio :label="1">易联云</Radio>
            <Radio :label="2">飞鹅云</Radio>
          </RadioGroup>
          <p class="desc on">打印平台选择</p>
        </FormItem>
        <FormItem label="打印机名称：" prop="name">
          <Input
            class="w-450"
            v-model="form.name"
            placeholder="请填写打印机名称"
          ></Input>
        </FormItem>
        <div v-if="form.plat_type == 1">
          <FormItem label="开发者ID：" prop="yly_user_id">
            <Input
              class="w-450"
              v-model="form.yly_user_id"
              placeholder="请填写用户id"
            ></Input>
            <p class="desc">易联云开发者ID</p>
          </FormItem>
          <FormItem label="应用密钥：" prop="yly_app_secret">
            <Input
              class="w-450"
              v-model="form.yly_app_secret"
              placeholder="请填写应用密钥"
            ></Input>
            <p class="desc">易联云应用密钥</p>
          </FormItem>
          <FormItem label="应用ID：" prop="yly_app_id">
            <Input
              class="w-450"
              v-model="form.yly_app_id"
              placeholder="请填写应用ID"
            ></Input>
            <p class="desc">易联云应用ID</p>
          </FormItem>
          <FormItem label="终端号：" prop="yly_sn">
            <Input
              class="w-450"
              v-model="form.yly_sn"
              placeholder="请填写终端号"
            ></Input>
            <p class="desc">
              易联云打印机终端号，打印机型号：易联云打印机K4无线版
              <Poptip
                placement="bottom"
                trigger="hover"
                width="256"
                transfer
                padding="8px"
              >
                <a>查看示例</a>
                <div class="exampleImg" slot="content">
                  <img
                    :src="`${baseURL}/statics/system/kuadi100Dump.png`"
                    alt=""
                  />
                </div>
              </Poptip>
            </p>
          </FormItem>
        </div>
        <div v-else>
          <FormItem label="飞鹅云USER：" prop="fey_user">
            <Input
              class="w-450"
              v-model="form.fey_user"
              placeholder="请填写飞鹅云USER"
            ></Input>
            <p class="desc">飞鹅云后台注册账号</p>
          </FormItem>
          <FormItem label="飞鹅云UKEY：" prop="fey_ukey">
            <Input
              class="w-450"
              v-model="form.fey_ukey"
              placeholder="请填写飞鹅云UKEY"
            ></Input>
            <p class="desc">
              飞鹅云后台注册账号后生成的UKEY(注：这不是填打印机的KEY)
            </p>
          </FormItem>
          <FormItem label="飞鹅云SN：" prop="fey_sn">
            <Input
              class="w-450"
              v-model="form.fey_sn"
              placeholder="请填写飞鹅云SN"
            ></Input>
            <p class="desc">
              打印机标签上的编号，必须要在管理后台里添加打印机或调用API接口添加之后才能调用API
            </p>
          </FormItem>
        </div>
        <FormItem label="打印联数：" prop="print_num">
          <div class="acea-row row-middle">
            <InputNumber
              class="w-450 num"
              :min="1"
              v-model="form.print_num"
              placeholder="请填写打印联数"
            />联
          </div>
          <p class="desc">打印机单次打印张数</p>
        </FormItem>
        <FormItem label="打印时机：">
          <CheckboxGroup v-model="form.print_event" size="small">
            <Checkbox label="2">支付后打印</Checkbox>
            <Checkbox label="1">下单后打印</Checkbox>
          </CheckboxGroup>
        </FormItem>
        <FormItem label="小票打印开关：">
          <i-switch
            v-model="form.status"
            :true-value="1"
            :false-value="0"
            size="large"
          >
            <span slot="open">开启</span>
            <span slot="close">关闭</span>
          </i-switch>
        </FormItem>
      </Form>
    </div>
    <div slot="footer">
      <Button @click="cancel">取消</Button>
      <Button type="primary" @click="addPrinterConfirm">确定</Button>
    </div>
  </Modal>
</template>

<script>
import Setting from '@/setting';
import { printSave } from '@/api/setting';
export default {
  name: 'addTicket',
  props: {
    formData: {
      type: Object,
      default: () => ({}),
    },
  },
  data() {
    return {
      baseURL: Setting.apiBaseURL.replace(/adminapi/, ''),
      id: 0,
      modals: false,
      form: {
        plat_type: 1,
        name: '',
        yly_user_id: '',
        yly_app_secret: '',
        yly_app_id: '',
        yly_sn: '',
        print_num: 1,
        print_event: [],
        status: 1,
        fey_user: '',
        fey_ukey: '',
        fey_sn: '',
      },
    };
  },
  watch: {
    formData(value) {
      Object.keys(this.form).forEach((key) => {
        this.form[key] = value[key];
      });
    },
  },
  methods: {
    react() {
      this.form = {
        plat_type: 1,
        name: '',
        yly_user_id: '',
        yly_app_secret: '',
        yly_app_id: '',
        yly_sn: '',
        print_num: 1,
        print_event: [],
        status: 1,
        fey_user: '',
        fey_ukey: '',
        fey_sn: '',
      };
    },
    cancel() {
      this.modals = false;
    },
    addPrinterConfirm() {
      if (this.form.name.trim() == '') {
        return this.$Message.error('请填写打印机名称');
      }
      if (this.form.plat_type == 1) {
        if (this.form.yly_user_id.trim() == '') {
          return this.$Message.error('请填写用户id');
        }
        if (this.form.yly_app_secret.trim() == '') {
          return this.$Message.error('请填写应用密钥');
        }
        if (this.form.yly_app_id.trim() == '') {
          return this.$Message.error('请填写应用ID');
        }
        if (this.form.yly_sn.trim() == '') {
          return this.$Message.error('请填写终端号');
        }
      } else {
        if (this.form.fey_user.trim() == '') {
          return this.$Message.error('请填写飞鹅云USER');
        }
        if (this.form.fey_ukey.trim() == '') {
          return this.$Message.error('请填写飞鹅云UKEY');
        }
        if (this.form.fey_sn.trim() == '') {
          return this.$Message.error('请填写飞鹅云SN');
        }
      }
      if (!this.form.print_num) {
        return this.$Message.error('请填写打印联数');
      }
      console.log(this.id);
      console.log(this.form);
      printSave(this.id, this.form)
        .then((res) => {
          this.$Message.success(res.msg);
          this.modals = false;
          this.$emit('printerList');
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
  },
};
</script>

<style lang="less" scoped>
.desc {
  font-size: 12px;
  color: #909399;
  line-height: normal;
  margin-top: 16px;
  &.on {
    margin-top: 6px;
  }
}
/deep/.ivu-checkbox-wrapper {
  font-size: 12px;
}
/deep/.ivu-modal-footer {
  border-top: 0;
}
/deep/.ivu-modal-content {
  border-radius: 10px;
}
/deep/.ivu-modal-header {
  border-radius: 10px 10px 0 0;
  background-color: #ffffff;
}
/deep/.ivu-modal-body {
  padding: 12px 31px 0 0;
}
/deep/.ivu-form-item {
  margin-bottom: 20px;
}
.w-450 {
  width: 450px;
}
.num {
  margin-right: 13px;
}
</style>