<template>
  <Modal
    v-model="modals"
    scrollable
    title="修改跟单人员"
    width="500px"
  >
    <div class="heng">
      跟单人员：
      <Select
        v-model="form.gendan_staff_id"
        class="input-add"
        filterable
        clearable
        placeholder="请选择跟单人员"
        @on-change="onStaffChange"
      >
        <Option :value="0">不跟单</Option>
        <Option v-for="item in staffList" :key="item.id" :value="item.id">{{ item.staff_name }}</Option>
      </Select>
    </div>
    <div slot="footer">
      <Button type="primary" style="margin-left: 20px" @click="saveGendan">保存</Button>
      <Button @click="cancel">关闭</Button>
    </div>
  </Modal>
</template>

<script>
import { saveGendan, staffallList } from '@/api/yeji';

export default {
  name: 'changeGendan',
  data() {
    return {
      form: {
        id: 0,
        is_gendan: 0,
        gendan_staff_id: 0,
      },
      staffList: [],
      modals: false,
    };
  },
  methods: {
    cancel() {
      this.modals = false;
    },
    loadStaff(storeId) {
      const params = { keyword: '' };
      if (storeId) {
        params.store_id = storeId;
      }
      staffallList(params).then((res) => {
        this.staffList = res.data || [];
      });
    },
    showGendan(row) {
      this.form = {
        id: row.id,
        is_gendan: Number(row.is_gendan) === 1 ? 1 : 0,
        gendan_staff_id: Number(row.gendan_staff_id) || 0,
      };
      this.loadStaff(row.store_id);
    },
    onStaffChange(val) {
      this.form.is_gendan = val > 0 ? 1 : 0;
    },
    saveGendan() {
      saveGendan(this.form).then(() => {
        this.$Message.success('修改成功！');
        this.$parent.getList();
        this.modals = false;
      });
    },
  },
};
</script>

<style scoped>
.heng {
  margin-bottom: 20px;
}
.input-add {
  width: 280px;
}
</style>
