function strFormat(str) {
  return str < 10 ? `0${str}` : str;
}

export function currentTime() {
  const myDate = new Date();
  const y = myDate.getFullYear();
  const m = myDate.getMonth() + 1;
  const d = myDate.getDate();
  const date = `${y}-${strFormat(m)}-${strFormat(d)}`;
  const hour = myDate.getHours();
  const min = myDate.getMinutes();
  const secon = myDate.getSeconds();
  const time = `${strFormat(hour)}:${strFormat(min)}:${strFormat(secon)}`;
  return { date, time };
}

export function timeStamp(time, isQuantum) {
  const dates = new Date(time);
  const year = dates.getFullYear();
  const month = dates.getMonth() + 1;
  const date = dates.getDate();
  const day = dates.getDay();
  const hour = dates.getHours();
  const min = dates.getMinutes();
  const days = ['日', '一', '二', '三', '四', '五', '六'];
  return {
    allDate: `${year}/${strFormat(month)}/${strFormat(date)}`,
    date: `${strFormat(year)}-${strFormat(month)}-${strFormat(date)}`,
    title_date: `${strFormat(month)}-${strFormat(date)}`,
    day: `星期${days[day]}`,
    hour: `${strFormat(hour)}:${strFormat(min)}${isQuantum ? '' : ':00'}`,
  };
}

export function initData() {
  const time = [];
  const date = new Date();
  const now = date.getTime();
  const timeStr = 3600 * 24 * 1000;
  const obj = { 0: '今天', 1: '明天' };
  for (let i = 0; i < 15; i += 1) {
    time.push({
      date: timeStamp(now + timeStr * i).date,
      timeStamp: now + timeStr * i,
      week: timeStamp(now + timeStr * i).day,
      title: obj[i] != null ? obj[i] : timeStamp(now + timeStr * i).title_date,
    });
  }
  return time;
}

export function initTime(startTime = '10:00:00', endTime = '21:00:00', timeInterval = 1, isQuantum = false) {
  const time = [];
  const date = timeStamp(Date.now()).allDate;
  const startDate = `${date} ${startTime}`;
  const endDate = `${date} ${endTime}`;
  const startTimeStamp = new Date(startDate).getTime();
  const endTimeStamp = new Date(endDate).getTime();
  const timeStr = 3600 * 1000 * timeInterval;
  const sum = (endTimeStamp - startTimeStamp) / timeStr;
  const count = sum % 2 === 0 ? sum : sum - 1;
  let num = 0;
  for (let i = startTimeStamp; i <= endTimeStamp; i += timeStr) {
    if (isQuantum) {
      num += 1;
      time.push({
        begin: timeStamp(i, isQuantum).hour,
        end: timeStamp(i + timeStr, isQuantum).hour,
        disable: false,
        is_hide: false,
      });
    } else {
      time.push({
        time: timeStamp(i).hour,
        disable: false,
        is_hide: false,
      });
    }
    if (isQuantum && num >= count) return time;
  }
  return time;
}
