<template>
  <Modal :value="visible" title="调查完成奖励" width="800" :mask-closable="false" :footer-hide="true" @on-cancel="$emit('close')">
    <div ref="formArea">
      <p>按010赠送规则发放；实物到账后到门店领取，项目和优惠券进入会员权益。</p>
      <Form :label-width="110">
        <FormItem label="奖励发放门店" data-required-key="store"><Select v-model="form.store_id" :disabled="readonly" filterable @on-change="changeStore"><Option v-for="store in stores" :key="store.id" :value="Number(store.id)">{{ store.name }}</Option></Select></FormItem>
        <FormItem label="每轮都奖励"><Checkbox v-model="form.reward_each_round" :disabled="readonly">重发任务后，新一轮完成可再次领奖；关闭则整个任务每人仅奖励一次</Checkbox></FormItem>
      </Form>
      <div v-if="!readonly && form.store_id" class="survey-reward-search"><Input v-model="keyword" placeholder="搜索项目、实物或优惠券" @on-enter="loadOptions" /><Button @click="loadOptions">搜索</Button></div>
      <p v-if="!readonly">目录每次最多显示100项，可搜索缩小范围。</p>
      <div v-if="!readonly" class="survey-reward-search"><Select v-model="selectedProduct" filterable placeholder="添加项目或实物" @on-change="addProduct"><Option v-for="item in products" :key="item.id" :value="Number(item.id)">{{ item.store_name }} · {{ item.suk || '默认规格' }}</Option></Select><Select v-model="selectedCoupon" filterable placeholder="添加优惠券" @on-change="addCoupon"><Option v-for="item in coupons" :key="item.id" :value="Number(item.id)">{{ item.title }}</Option></Select></div>
      <table class="survey-reward-table"><thead><tr><th>奖励内容</th><th>数量</th><th>领取后有效天数</th><th>操作</th></tr></thead><tbody><tr v-for="item in rows" :key="item.kind + item.id"><td>{{ item.store_name }}</td><td><InputNumber :data-required-key="'num-' + item.kind + item.id" v-model="item.num" :min="1" :max="99" :precision="0" :disabled="readonly" /></td><td><InputNumber :data-required-key="'days-' + item.kind + item.id" v-model="item.write_days" :min="1" :max="3650" :precision="0" :disabled="readonly" /></td><td><Button v-if="!readonly" type="text" @click="remove(item)">移除</Button></td></tr></tbody></table>
      <p v-if="!rows.length">未配置奖励，会员仍可填写问卷。</p>
      <p v-if="error" role="status" aria-live="polite" class="survey-reward-error">{{ error }}</p>
      <div class="survey-reward-actions"><Button @click="$emit('close')">关闭</Button><Button v-if="!readonly" type="primary" :loading="saving" :disabled="loadingConfig" @click="save">保存奖励</Button></div>
    </div>
  </Modal>
</template>
<script>
import { surveyGifts, surveyUpdateGifts, questionnaireGifts, questionnaireUpdateGifts, surveyGiftOptions } from '@/api/memberSurvey';
import { guideRequired, clearRequiredGuidance } from '@/utils/requiredGuidance';
const empty = () => ({ store_id: 0, reward_each_round: true, product: [], coupon: [] });
export default {
  name: 'MemberSurveyReward', props: { visible: Boolean, surveyId: Number, questionnaireId: Number, readonly: Boolean, inherited: Boolean },
  data: () => ({ form: empty(), stores: [], products: [], coupons: [], selectedProduct: null, selectedCoupon: null, keyword: '', saving: false, loadingConfig: false, loadEpoch: 0, optionsEpoch: 0, error: '' }),
  computed: { rows() { return this.form.product.concat(this.form.coupon); } },
  watch: { visible(open) { if (open) this.load(); else { this.loadEpoch++; this.optionsEpoch++; clearRequiredGuidance(); } }, surveyId() { if (this.visible) this.load(); }, questionnaireId() { if (this.visible) this.load(); } },
  beforeDestroy() { clearRequiredGuidance(); },
  methods: {
    // 请求身份随弹窗切换失效，迟到的旧任务配置不得覆盖当前任务。
    async load() { const epoch = ++this.loadEpoch; this.loadingConfig = true; this.error = ''; try { const [config, options] = await Promise.all([this.questionnaireId ? questionnaireGifts(this.questionnaireId) : surveyGifts(this.surveyId), surveyGiftOptions(0)]); if (epoch !== this.loadEpoch || !this.visible) return; this.form = { ...empty(), ...config.data }; ['product', 'coupon'].forEach(kind => { this.form[kind] = this.form[kind].map(row => ({ ...row, kind })); }); this.stores = options.data.stores; await this.$nextTick(); await this.loadOptions(); } catch (e) { if (epoch === this.loadEpoch) this.error = e.msg || e.message || '奖励加载失败'; } finally { if (epoch === this.loadEpoch) this.loadingConfig = false; } },
    async loadOptions() { const epoch = ++this.optionsEpoch; const storeId = this.form.store_id; if (!storeId) { this.products = []; this.coupons = []; return; } try { const { data } = await surveyGiftOptions(storeId, this.keyword); if (epoch !== this.optionsEpoch || storeId !== this.form.store_id) return; this.products = data.products; this.coupons = data.coupons; } catch (e) { if (epoch === this.optionsEpoch) this.error = e.msg || e.message; } },
    changeStore() { if (this.loadingConfig) return; this.form.product = []; this.form.coupon = []; this.keyword = ''; this.loadOptions(); },
    addProduct(id) { const item = this.products.find(row => Number(row.id) === id); if (item && !this.form.product.some(row => row.id === id)) this.form.product.push({ id, product_type: Number(item.product_type), store_name: item.store_name, num: 1, write_days: 30, kind: 'product' }); this.$nextTick(() => { this.selectedProduct = null; }); },
    addCoupon(id) { const item = this.coupons.find(row => Number(row.id) === id); if (item && !this.form.coupon.some(row => row.id === id)) this.form.coupon.push({ id, store_name: item.title, num: 1, write_days: 30, kind: 'coupon' }); this.$nextTick(() => { this.selectedCoupon = null; }); },
    remove(item) { this.form[item.kind] = this.form[item.kind].filter(row => row !== item); },
    async save() { if (this.rows.length && !this.form.store_id) { guideRequired(this.$refs.formArea, 'store', '请在这里选择奖励发放门店'); return; } for (const item of this.rows) { if (!Number.isInteger(item.num) || item.num < 1 || item.num > 99) return guideRequired(this.$refs.formArea, 'num-' + item.kind + item.id, '请在这里填写1至99的整数数量'); if (!Number.isInteger(item.write_days) || item.write_days < 1 || item.write_days > 3650) return guideRequired(this.$refs.formArea, 'days-' + item.kind + item.id, '请在这里填写1至3650的有效天数'); } this.saving = true; this.error = ''; try { const payload = { sendAll: this.form }; if (this.questionnaireId) await questionnaireUpdateGifts(this.questionnaireId, payload); else await surveyUpdateGifts(this.surveyId, payload); this.$emit('saved'); this.$emit('close'); } catch (e) { this.error = e.msg || e.message || '保存失败，请重试'; } finally { this.saving = false; } }
  }
};
</script>
<style scoped>
.survey-reward-search{display:flex;gap:12px;margin:12px 0}.survey-reward-table{width:100%;border-collapse:collapse;margin-top:16px}.survey-reward-table th,.survey-reward-table td{padding:10px;border:1px solid #e1e8ef;text-align:left}.survey-reward-actions{display:flex;justify-content:flex-end;gap:12px;margin-top:20px}.survey-reward-error{color:#b92b2b}
</style>
