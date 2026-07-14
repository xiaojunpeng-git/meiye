<template>
  <div>
    <Card  :bordered="false" dis-hover class="ivu-mt" >
      <iframe
          src="https://api.mohe.com?token=AF37D4579721672220B08CA872586943"
          style="width: 100%; height: calc(100vh - 200px)"
          frameborder="0"
      ></iframe>
    </Card>
  </div>
</template>
<script>
import request from '@/plugins/request';
export default {
  name: 'smsConfig',
  created() {
    window.addEventListener('message', this.handleConfig);
  },
  beforeDestroy() {
    window.removeEventListener('message', this.handleConfig);
  },
  methods:{
    handleConfig(data) {
      let IsSave = false;
      if (data.data.accessKey && data.data.secretKey && IsSave === false) {
        IsSave = true;
        request({
          url: 'notify/sms/config',
          method: 'POST',
          data: {
            sms_account: data.data.accessKey,
            sms_token: data.data.secretKey,
          },
        }).then((res) => {});
      }
    },
  }
}
</script>
