<template>
  <div class="page-account">
    <div class="m0ee4" v-if="loginType === 'wx' || loginType === 'work'">
      <div class="title">
        {{ loginType === "wx" ? "微信登录" : "企业微信" }}
      </div>
      <div class="qrcode" v-if="loginType === 'wx'">
        <img :src="wxData.qrcode" alt="" />
      </div>
      <div class="qrcode" id="qr_code" v-if="loginType === 'work'"></div>
      <div class="trip" v-if="loginType == 'wx'">
        请使用微信扫描二维码登录
        <img
          class="upload"
          src="@/assets/images/upload.png"
          @click="getWechatScan"
          alt=""
        />
      </div>
      <div class="change-login-type" @click="changeLoginType">切换登录方式</div>
    </div>
    <div class="m0ee4" v-if="loginType == 'paw'">
      <div class="title">收银台-登录</div>
      <Form ref="formInline" :model="formInline" :rules="m854f8">
        <FormItem prop="username">
          <Input
            type="text"
            v-model="formInline.username"
            prefix="ios-contact-outline"
            placeholder="请输入用户名"
            size="default"
          />
        </FormItem>
        <FormItem prop="password">
          <Input
            type="password"
            v-model="formInline.password"
            prefix="ios-lock-outline"
            placeholder="请输入密码"
            size="default"
          />
        </FormItem>
        <FormItem>
          <Button
            type="primary"
            long
            size="default"
            @click="handleSubmit('formInline')"
            class="btn"
            >{{ $t("page.login.submit") }}
          </Button>
        </FormItem>
      </Form>
      <div class="login-type">
        <div class="line"></div>
        <div class="more">更多登录方式</div>
        <div class="line"></div>
      </div>
      <div class="login-btn">
        <img src="@/assets/images/wx-login.png" alt="" @click="getWechatScan" />
        <img
          src="@/assets/images/work-chat-login.png"
          alt=""
          @click="getWorkChatConfig"
        />
      </div>
      <div style="margin-top: 15px;text-align: center;margin-bottom: 15px;cursor: pointer;" v-if="app">
        <span @click="toUrl">更换域名</span>
      </div>
    </div>
    <div class="footer">
      <div class="pull-right" v-if="copyright">{{copyright}}</div>
      <div class="pull-right" v-else>Copyright ©2014-2024 <a class="infoUrl" href="https://www.mohe.com" target="_blank">{{svsVersion}}</a></div>
    </div>
    <Verify
        @success="closeModel"
        captchaType="clickWord"
        :imgSize="{ width: '330px', height: '155px' }"
        ref="verify"
    ></Verify>
  </div>
</template>
<script>
import {
  AccountLogin,
  loginInfoApi,
  getWechatScan,
  checkScanLogin,
  getWorkChatConfig,
  workScanLogin,
  copyrightInfoApi,
  isCaptcha
} from "@/api/account";
import mixins from "../mixins";
import Setting from "@/setting";
import SettingEnv from "@/setting.env";
import util from "@/libs/util";
import { loadJs } from "@/utils";
import Cookies from "js-cookie";
import Verify from "@/components/verifition/Verify";
import "@/assets/js/core.js";

export default {
  mixins: [mixins],
  components: {
    Verify
  },
  data() {
    return {
      app: SettingEnv.isAPP,
      autoLogin: true,
      loginType: "paw", // paw 密码登录 wx 微信登录 work企业微信
      formInline: {
        username: "",
        password: "",
        code: "",
      },
      wxData: {},
      m854f8: {
        username: [
          { required: true, message: "请输入用户名", trigger: "blur" },
        ],
        password: [{ required: true, message: "请输入密码", trigger: "blur" }],
        code: [{ required: true, message: "请输入验证码", trigger: "blur" }],
      },
      errorNum: 0,
      login_logo: "",
      site_name: "",
      site_url: "",
      setTime: undefined,
      initTime: 0,
      copyright:'',
      svsVersion:''
    };
  },
  computed: {
    logoutType() {
      return this.$store.state.store.user.logoutType;
    }
  },
  created() {
    var _this = this;
    const route = this.$route;
    document.onkeydown = function(e) {
      if (route.name === "login") {
        let key = window.event.keyCode;
        if (key === 13) {
          _this.handleSubmit("formInline");
        }
      }
    };
    if (route.query.code) {
      let data = { code: route.query.code };
      return this.workScanLogin(data);
    }
    if (Cookies.get("cashierData")) {
      return this.loginSuc(JSON.parse(Cookies.get("cashierData")));
    }
    if (this.logoutType == 'handover') {
      this.$Message.success('交班成功');
      this.$store.commit('store/user/setLogoutType', '');
    }
  },
  watch: {
    loginType(nav) {},
  },
  mounted() {
    this.$nextTick(() => {
      this.swiperData();
      this.getCopyright();
    });
  },
  destroyed() {
    clearInterval(this.setTime);
    this.setTime = undefined;
    this.initTime = 0;
  },
  methods: {
    toUrl(){
      this.$router.replace({ name: 'auxScreen'});
    },
    // 企业微信回调登录
    workScanLogin(data) {
      workScanLogin(data)
        .then((res) => {
          this.loginSuc(res);
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    getWorkChatConfig() {
      this.loginType = "work";
      loadJs(
        "https://wwcdn.weixin.qq.com/node/wework/wwopen/js/wwLogin-1.2.5.js"
      ).then(() => {
        getWorkChatConfig().then((res) => {
          new WwLogin({
            id: "qr_code",
            appid: res.data.work_corp_id,
            agentid: res.data.work_agent_id,
            lang: "zh",
            redirect_uri: encodeURIComponent(
              window.location.protocol +
                "//" +
                window.location.host +
                `${Setting.roterPre}/login`
            ),
            href:
              "data:text/css;base64,LmltcG93ZXJCb3ggLnFyY29kZSB7d2lkdGg6IDIwMHB4O30KLmltcG93ZXJCb3ggLnRpdGxlIHtkaXNwbGF5OiBub25lO30KLmltcG93ZXJCb3ggLmluZm8ge3dpZHRoOiAyMDBweDt9Ci5zdGF0dXNfaWNvbiB7ZGlzcGxheTogbm9uZSAgIWltcG9ydGFudH0KLmltcG93ZXJCb3ggLnN0YXR1cyB7dGV4dC1hbGlnbjogY2VudGVyO30g",
          });
        });
      });
    },
    changeLoginType() {
      clearInterval(this.setTime);
      this.setTime = undefined;
      this.loginType = "paw";
      this.initTime = 0;
    },
    getWechatScan() {
      clearInterval(this.setTime);
      this.setTime = undefined;
      this.initTime = 0;
      getWechatScan()
        .then((res) => {
          this.loginType = "wx";
          this.wxData = res.data;
          this.setTime = setInterval(() => {
            this.getLoginStatus();
            this.initTime++;
            if (this.initTime >= 60 && this.loginType == "wx") {
              this.getWechatScan();
            }
          }, 1000);
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    getLoginStatus() {
      let data = {
        key: this.wxData.key,
        type: this.loginType == "wx" ? 1 : 2, //1微信 2企业微信
      };
      checkScanLogin(data).then((res) => {
        clearInterval(this.setTime);
        this.initTime = 0;
        this.setTime = undefined;
        this.loginSuc(res);
      });
    },
    swiperData() {
      loginInfoApi()
        .then((res) => {
          let data = res.data || {};
          this.login_logo = data.login_logo
            ? data.login_logo
            : require("@/assets/images/logo.png");
          this.site_name = data.site_name;
          this.site_url = data.site_url;
        })
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
    getCopyright() {
      copyrightInfoApi().then(res => {
        let data = res.data || {};
        this.copyright = data.copyrightContext;
        this.svsVersion = data.version;
      }).catch(res => {
        this.$Message.error(res.msg)
      })
    },
    // 关闭模态框
    closeModel(params) {
      let msg = this.$Message.loading({
        content: "登录中...",
        duration: 0,
      });
      AccountLogin({
        account: this.formInline.username,
        pwd: this.formInline.password,
        // imgcode: this.formInline.code,
        captchaType: params ? 'clickWord' : '',
        captchaVerification: params ? params.captchaVerification : ''
      })
        .then(async (res) => {
          this.$store.dispatch("store/account/setPageTitle");
          msg();
          this.loginSuc(res);
        })
        .catch((res) => {
          msg();
          let data = res === undefined ? {} : res;
          this.errorNum++;
          // this.captchas();
          this.formInline.code = "";
          this.$Message.error(data.msg || "登录失败");
        });
    },
    async loginSuc(res) {
      let expires = res.data.expires_time;
      // 记录用户登陆信息
	  util.cookies.set("pageTitle", res.data.store_name);
      util.cookies.set("uuid", res.data.user_info.id, {
        expires: expires,
      });
      localStorage.setItem('token', res.data.token);
      util.cookies.set("token", res.data.token, {
        expires: expires,
      });
      util.cookies.set("expires_time", res.data.expires_time, {
        expires: expires,
      });
      const db = await this.$store.dispatch("store/db/database", {
        user: true,
      });
      // 保存菜单信息
      db.set("cashier_unique_auth", res.data.unique_auth)
        .set("cashier_user_info", res.data.user_info)
        .write();
      this.$store.commit("store/menus/getmenusNav", res.data.menus);

      let userInfoCashier = {
        account: res.data.user_info.account,
        avatar: res.data.user_info.avatar,
        logo: res.data.logo,
        logoSmall: res.data.logo_square,
      };
      let storage = window.localStorage;
      storage.setItem("cashier_user_info", JSON.stringify(userInfoCashier));
      storage.setItem("product_category_status", res.data.product_category_status);
      storage.setItem("store_id", res.data.store_id);

      // 记录用户信息
      this.$store.dispatch("store/user/set", {
        name: res.data.user_info.account,
        avatar: res.data.user_info.avatar,
        access: res.data.unique_auth,
        logo: res.data.logo,
        logoSmall: res.data.logo_square,
        version: res.data.version,
        newOrderAudioLink: res.data.newOrderAudioLink,
      });
      Cookies.remove("cashierData");

      try {
        //打开副屏
        window.Jsbridge.invoke('collectLoginSuccess',JSON.stringify({'p1-key':'p1-value'}));
      }catch (e){
      }
      return this.$router.replace({
        path: this.$route.query.redirect || `${Setting.roterPre}/cashier/index`,
      });
    },
    getExpiresTime(expiresTime) {
      let nowTimeNum = Math.round(new Date() / 1000);
      let expiresTimeNum = expiresTime - nowTimeNum;
      return parseFloat(parseFloat(parseFloat(expiresTimeNum / 60) / 60) / 24);
    },
    closefail() {
      this.$Message.error("校验错误");
    },
    handleSubmit(name) {
      this.$refs[name].validate((valid) => {
        if (valid) {
          isCaptcha({
            account: this.formInline.username
          }).then(res => {
            if (res.data.is_captcha) {
              this.$refs.verify.show();
            } else {
              this.closeModel();
            }
          });
        }
      });
    },
  },
};
</script>
<style scoped lang="stylus">
  .pull-right {
    float: right!important;
    font-size 16px;
    .infoUrl{
      margin 0;
      color #515a6e !important;
      &:hover{
        color #1890ff!important;
      }
    }
  }
  .footer{
    position: fixed;
    bottom: 0;
    width: 100%;
    left: 0;
    margin: 0;
    background: rgba(255,255,255,.8);
    border-top: 1px solid #e7eaec;
    overflow: hidden;
    padding: 10px 20px;
    height: 43px;
  }
.page-account {
  height: 100%;
  background-image: url('../../../assets/images/bg.png');
  background-repeat: no-repeat;
  background-position: center;
  background-size: cover;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;

  .container {
    .page-account-top {
      img {
        height: 41px;
      }
    }
  }
}

.page-account .code {
  display: flex;
  align-items: center;
  justify-content: center;
}

.page-account .code .pictrue {
  height: 32px;
}

.swiperPic img {
  width: 100%;
  height: 100%;
}

.m0ee4 {
  width: 400px;
  padding: 0 40px 32px;
  border-radius: 30px;
  background-color: #fff;

  .title {
    font-size: 24px;
    font-weight: 500;
    text-align: center;
    color: rgba(0, 0, 0, 0.85);
    padding-top: 36px;
    margin-bottom: 31px;
  }

  .info {
    color: #CCCCCC;
    font-size: 12px;
    margin-top: 53px;
  }

  .qrcode {
    margin: 0 auto;
    text-align: center;
    width: 100%;

    img {
      width: 200px;
      height: 200px;
    }
  }

  /deep/ iframe {
    height: 270px !important;
  }

  .trip {
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;

    .upload {
      width: 14px;
      height: 14px;
      cursor: pointer;
      margin-left: 5px;
    }
  }

  .change-login-type {
    text-align: center;
    color: #1890FF;
    margin-top: 18px;
    cursor: pointer;
    font-size: 14px;
  }
}

.page-account-container {
}

.btn {
  background: #1890FF !important;
}

.captchaBox {
  width: 310px;
}

input {
  display: block;
  width: 290px;
  line-height: 40px;
  margin: 10px 0;
  padding: 0 10px;
  outline: none;
  border: 1px solid #c8cccf;
  border-radius: 4px;
  color: #6a6f77;
}

#msg {
  width: 100%;
  line-height: 40px;
  font-size: 14px;
  text-align: center;
}

a:link, a:visited, a:hover, a:active {
  margin-left: 100px;
  color: #0366D6;
}

.m0ee4 >>> .ivu-input-large {
  font-size: 14px !important;
}

.login-btn {
  display: flex;
  align-items: center;
  justify-content: center;

  img {
    margin: 25px 44px 0 44px;
    width: 39px;
    height: 31px;
    cursor: pointer;
  }
}

.login-type {
  display: flex;
  align-items: center;
  color: #DDE4EC;
  justify-content: space-between;

  .more {
    font-size: 14px;
  }

  .line {
    width: 100px;
    height: 1.5px;
    background-color: #DDE4EC;
  }
}
</style>
