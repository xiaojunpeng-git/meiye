<template>
  <Modal :value="visible" title="选择服务老师" width="500" class-name="recharge-modal" @on-cancel="close">
    <Input
      v-model="keyword"
      size="large"
      placeholder="输入关键词搜索员工"
      @input="loadStaff"
    />
    <div class="kuai_out">
      <div
        class="kuai"
        :class="{ choose_kuai: selectedId === 0 }"
        @click="chooseStaff({ id: 0, staff_name: '' })"
      >暂不选择</div>
      <div
        v-for="item in staffList"
        :key="item.id"
        :class="selectedId === item.id ? 'kuai choose_kuai' : 'kuai'"
        @click="chooseStaff(item)"
      >{{ item.staff_name }}</div>
    </div>
    <div v-if="!staffList.length && !loading" class="empty-tip">暂无可选服务老师</div>
    <div slot="footer">
      <div class="gendan-modal-footer">
        <Button class="gendan-footer-btn gendan-footer-btn-outline" @click="clearStaff">清除</Button>
        <Button class="gendan-footer-btn gendan-footer-btn-outline" @click="close">取消</Button>
        <Button class="gendan-footer-btn gendan-footer-btn-primary" type="primary" @click="confirm">确认</Button>
      </div>
    </div>
  </Modal>
</template>

<script>
import { getReservationStaffList } from '@/api/reservation';

export default {
  name: 'reservationStaff',
  props: {
    visible: { type: Boolean, default: false },
    value: { type: Number, default: 0 },
    staffName: { type: String, default: '' },
    query: {
      type: Object,
      default: () => ({}),
    },
  },
  data() {
    return {
      keyword: '',
      staffList: [],
      selectedId: 0,
      selectedName: '',
      loading: false,
    };
  },
  watch: {
    visible(val) {
      if (val) {
        this.selectedId = Number(this.value) || 0;
        this.selectedName = this.staffName || '';
        this.keyword = '';
        this.loadStaff();
      }
    },
  },
  methods: {
    loadStaff() {
      this.loading = true;
      const params = {
        keyword: this.keyword,
        service_date: this.query.service_date || '',
        service_time: this.query.service_time || '',
        service_duration: Number(this.query.service_duration) || 0,
      };
      getReservationStaffList(params)
        .then((res) => {
          this.staffList = (res.data && res.data.list) || [];
          this.loading = false;
        })
        .catch(() => {
          this.staffList = [];
          this.loading = false;
        });
    },
    chooseStaff(item) {
      this.selectedId = Number(item.id) || 0;
      this.selectedName = item.staff_name || '';
    },
    clearStaff() {
      this.selectedId = 0;
      this.selectedName = '';
    },
    confirm() {
      this.$emit('confirm', {
        service_staff_id: this.selectedId,
        service_staff_name: this.selectedName,
      });
      this.close();
    },
    close() {
      this.$emit('close');
    },
  },
};
</script>

<style scoped lang="stylus">
.kuai_out
  display flex
  flex-wrap wrap
  margin-top 16px
  max-height 320px
  overflow-y auto
.kuai
  min-width 88px
  padding 8px 12px
  margin 0 8px 8px 0
  border 1px solid #dcdee0
  border-radius 4px
  text-align center
  cursor pointer
.choose_kuai
  border-color #8558fa
  color #8558fa
  background #f5f0ff
.empty-tip
  padding 24px 0
  text-align center
  color #909399
  font-size 13px
.gendan-modal-footer
  display flex
  flex-wrap nowrap
  justify-content center
  align-items center
  width 100%
  padding-top 8px
  box-sizing border-box
.gendan-footer-btn
  flex 1
  max-width 140px
  min-width 0
  height 46px
  margin-left 12px
  border-radius 30px
  font-size 16px
  white-space nowrap
  &:first-child
    margin-left 0
.gendan-footer-btn-outline
  background-color transparent !important
  border 1px solid #DCDEE0 !important
  color #303133 !important
.gendan-footer-btn-primary
  flex 1
  max-width 140px
</style>
