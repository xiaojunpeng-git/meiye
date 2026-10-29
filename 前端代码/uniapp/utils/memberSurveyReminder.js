import { surveyPending, surveyClaim } from '@/api/memberSurvey';
let checking = false;
let foreground = 0;
let checkedKey = '';
export function beginSurveyForeground() { foreground++; }
function routeName() { const pages = getCurrentPages(); return pages.length ? pages[pages.length - 1].route : ''; }
// 提醒只读后台指定名单；同次进入前台与同一登录身份只检查一次，二维码不会被其他任务抢走。
export async function remindMemberSurvey(store) {
  const route = routeName();
  if (checking || !store.getters.isLogin || !route || /survey|login|binding_phone/.test(route)) return;
  const serial = foreground;
  const identity = store.state.app.token;
  const key = serial + ':' + identity;
  if (checkedKey === key) return;
  checkedKey = key; checking = true;
  try {
    const { data: task } = await surveyPending();
    if (!task || !task.id || serial !== foreground || identity !== store.state.app.token || /survey|login|binding_phone/.test(routeName())) return;
    const { data: claimed } = await surveyClaim(task.id);
    if (!claimed || !claimed.id || serial !== foreground || identity !== store.state.app.token || /survey|login|binding_phone/.test(routeName())) return;
    uni.showModal({ title: task.title, content: '您有一份待填写的调查问卷', confirmText: '去填写', cancelText: '稍后', success: res => { if (res.confirm) uni.navigateTo({ url: '/pages/users/survey/index?id=' + task.id }); } });
  } catch (error) { /* 提醒故障不阻断会员端其他已授权业务。 */ }
  finally { checking = false; if (serial !== foreground) setTimeout(() => remindMemberSurvey(store), 0); }
}
