// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2021 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------

export default {
  data() {
    return {
      disabled: false,
      text: "获取验证码",
	  run: undefined
    };
  },
  methods: {
    sendCode() {
      if (this.disabled) return;
      this.disabled = true;
      let n = 60;
      this.text = "剩余 " + n + "s";
      this.run = setInterval(() => {
        n = n - 1;
        if (n < 0) {
          clearInterval(this.run);
        }
        this.text = "剩余 " + n + "s";
        if (this.text < "剩余 " + 0 + "s") {
          this.disabled = false;
          this.text = "重新获取";
        }
      }, 1000);
    }
  },
  beforeDestroy() {
	  clearInterval(this.run);
  },
};
