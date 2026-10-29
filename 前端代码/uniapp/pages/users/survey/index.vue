<template>
  <view class="survey-page">
    <view v-if="task" class="survey-card">
      <view class="title">{{ task.title }}</view><view v-if="task.description" class="description">{{ task.description }}</view>
      <view v-if="giftLabel" class="gift-note">完成问卷奖励：{{ giftLabel }}</view>
      <view v-if="completed" class="completed"><view>问卷已提交，感谢参与。</view><view>{{ reward.message }}</view><view v-if="error" role="status" aria-live="polite" class="required">{{ error }}</view><button v-if="reward.status === 'pending'" :disabled="submitting" @click="retryReward">重试发放奖励</button></view>
      <template v-else>
        <required-field-guide v-for="(question, index) in task.questions" :key="question.id" ref="guides" :anchor="'survey-question-' + index"><template v-slot:default="{ focused }">
          <view class="question"><view class="question-title">{{ index + 1 }}. {{ question.title }} <text v-if="question.required" class="required">*</text></view>
            <view v-if="question.type !== 'text'">
              <button v-for="option in question.options" :key="option.id" class="option" :aria-pressed="selected(question, option.id)" @click="choose(question, option.id)"><view class="choice-mark" :class="{ selected: selected(question, option.id), radio: question.type === 'single' }"><text v-if="selected(question, option.id)">✓</text></view>{{ option.label }}</button>
            </view>
            <textarea v-else :focus="focused" class="answer-text" :value="answers[question.id] || ''" maxlength="1000" placeholder="请输入您的想法" @input="setAnswer(question.id, $event.detail.value)" />
          </view>
        </template></required-field-guide>
        <required-field-guide v-if="requirements.store_required && !requirements.phone_required" ref="storeGuide" anchor="survey-member-store">
          <view class="question-title">请选择您的所属门店</view>
          <picker :range="requirements.stores || []" range-key="name" @change="chooseStore"><button>{{ selectedStoreName || '选择所属门店' }}</button></picker>
          <view class="description">首次选择将保存为您的所属门店。</view>
        </required-field-guide>
        <view v-else-if="requirements.store_id" class="description">所属门店：{{ requirements.store_name }}</view>
        <view v-if="error" role="status" aria-live="polite" class="required">{{ error }}</view>
        <button class="submit" :disabled="submitting" @click="submit">{{ submitting ? '提交中…' : '提交问卷' }}</button>
      </template>
    </view>
    <view v-else class="empty">{{ loading ? '正在加载问卷…' : error || '问卷暂不可用' }}</view>
  </view>
</template>
<script>
import { surveyEntry, surveyDetail, surveySubmit, surveyRetry } from '@/api/memberSurvey';
import { toLogin } from '@/libs/login';
import RequiredFieldGuide from '@/components/requiredFieldGuide';
import { surveyEntryToken } from '@/utils/memberSurveyScene';
export default {
  components: { RequiredFieldGuide },
  data: () => ({ task: null, answers: {}, loading: true, submitting: false, error: '', taskId: 0, entryToken: '', completed: false, reward: {}, pageScrollTop: 0, loaded: false, selectedStoreId: 0 }),
  computed: { giftLabel() { return ((this.task && this.task.gifts) || []).map(g => g.name + ' × ' + g.quantity).join('、'); }, draftKey() { return 'member-survey-draft:' + (this.entryToken || this.taskId); }, requirements() { return (this.task && this.task.requirements) || {phone_required:true,store_required:true,stores:[]}; }, selectedStoreName() { const store=(this.requirements.stores || []).find(s => Number(s.id) === this.selectedStoreId); return store ? store.name : ''; } },
  onLoad(options) { try { this.entryToken = surveyEntryToken(options); this.taskId = Number(options.id || 0); this.load(); } catch(e) { this.error=e.message;this.loading=false; } },
  onShow() { if (this.loaded) this.load(); }, onPageScroll(event) { this.pageScrollTop = event.scrollTop; },
  onUnload() { if (!this.completed) this.persist(); },
  methods: {
    // 草稿只存当前设备并绑定任务轮次，登录跳转可恢复；提交确认后立即清除。
    persist() { if (this.task && !this.completed) uni.setStorageSync(this.draftKey, { round: this.task.send_round, answers: this.answers, store_id:this.selectedStoreId }); },
    chooseStore(event) { const store=(this.requirements.stores || [])[Number(event.detail.value)];this.selectedStoreId=store?Number(store.id):0;this.persist(); },
    async load() { this.loading = true; this.error = ''; try {
      const response = this.entryToken ? await surveyEntry(this.entryToken) : await surveyDetail(this.taskId);
      let task = response.data; this.taskId = Number(task.id);
      if (this.entryToken && this.$store.getters.isLogin) task = (await surveyDetail(this.taskId)).data;
      const draft = uni.getStorageSync(this.draftKey); this.task = task; this.completed = !!task.completed; this.reward = task.reward || {};
      if (draft && Number(draft.round) === Number(task.send_round)) this.answers = draft.answers || {};
      else { this.answers = {}; uni.removeStorageSync(this.draftKey); }
      if (this.completed) uni.removeStorageSync(this.draftKey);
      const chosen = Number(draft && draft.store_id || 0);this.selectedStoreId=(this.requirements.stores || []).some(s=>Number(s.id)===chosen)?chosen:0;
    } catch (e) { this.error = e.msg || '问卷暂不可用'; this.task = null; } finally { this.loading = false; this.loaded = true; } },
    selected(question, id) { const value = this.answers[question.id]; return question.type === 'multiple' ? (value || []).indexOf(id) !== -1 : value === id; },
    setAnswer(id, value) { this.$set(this.answers, id, value); this.persist(); },
    choose(question, id) { if (question.type === 'single') this.setAnswer(question.id, id); else { const values = this.answers[question.id] || []; this.setAnswer(question.id, values.indexOf(id) === -1 ? values.concat(id) : values.filter(v => v !== id)); } },
    async submit() { if (this.submitting || !this.task) return;
      const index = this.task.questions.findIndex(q => q.required && (!this.answers[q.id] || (typeof this.answers[q.id] === 'string' && !this.answers[q.id].trim()) || (Array.isArray(this.answers[q.id]) && !this.answers[q.id].length)));
      if (index !== -1) { this.$refs.guides[index].guide('请在这里填写第' + (index + 1) + '题'); return; }
      this.persist();
      if (!this.$store.getters.isLogin || this.requirements.phone_required) { toLogin(undefined, undefined, '/pages/users/survey/index?' + (this.entryToken ? 'entry=' + encodeURIComponent(this.entryToken) : 'id=' + this.taskId)); return; }
      if (this.requirements.store_required && !this.selectedStoreId) { this.$refs.storeGuide.guide('请在这里选择您的所属门店');return; }
      this.submitting = true; this.error = ''; try { const response = await surveySubmit(this.taskId, this.answers, this.task.send_round, this.selectedStoreId); this.reward = response.data.gift || {}; this.completed = true; uni.removeStorageSync(this.draftKey); } catch (e) { this.error = e.msg || '提交失败，已填内容保留，请稍后重试'; } finally { this.submitting = false; }
    },
    async retryReward() { if (this.submitting) return; this.submitting = true; this.error = ''; try { this.reward = (await surveyRetry(this.taskId)).data; } catch (e) { this.error = e.msg || '奖励暂未发放，请稍后重试'; } finally { this.submitting = false; } }
  }
};
</script>
<style scoped>
.survey-page { min-height:100vh; background:#f5f7fb; padding:24rpx; box-sizing:border-box; }
.survey-card { background:white; border-radius:20rpx; padding:32rpx; }
.title { font-size:38rpx; font-weight:700; color:#202936; line-height:1.4; }
.description { color:#687487; font-size:27rpx; line-height:1.6; margin-top:14rpx; }
.gift-note { margin-top:22rpx; padding:18rpx 20rpx; background:#fff5e6; color:#a66008; border-radius:12rpx; font-size:27rpx; line-height:1.5; }
.question { padding:32rpx 0; border-bottom:1px solid #edf0f4; }
.question-title { font-size:30rpx; font-weight:600; line-height:1.5; margin-bottom:22rpx; color:#202936; }
.required { color:#ef4b4b; }
.option { display:flex; align-items:center; gap:12rpx; min-height:80rpx; font-size:28rpx; color:#344054; }
.choice-mark { width:36rpx; height:36rpx; border:2rpx solid #a8b1be; border-radius:6rpx; box-sizing:border-box; display:flex; align-items:center; justify-content:center; flex:none; color:white; font-size:25rpx; }
.choice-mark.radio { border-radius:50%; }
.choice-mark.selected { background:#2478eb; border-color:#2478eb; }
.answer-text { width:100%; min-height:190rpx; padding:20rpx; box-sizing:border-box; background:#f7f8fa; border-radius:12rpx; font-size:28rpx; }
.submit { background:#2478eb; color:white; margin:36rpx 0 14rpx; border-radius:12rpx; }
.empty { padding:80rpx 20rpx; text-align:center; color:#808695; }
</style>
<style scoped>.option{background:white;text-align:left;padding:12rpx 0;line-height:1.5;border-radius:0}.option::after{border:0}.completed{padding:40rpx 0;line-height:2}</style>
