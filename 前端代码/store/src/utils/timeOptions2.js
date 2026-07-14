import moment from "moment"
export default {
    shortcuts: [
      {
        text: "今天",
        value() {
          const end = new Date();
          const start = new Date();
          start.setTime(
            new Date(
              new Date().getFullYear(),
              new Date().getMonth(),
              new Date().getDate()
            )
          );
          return [start, end];
        },
      },
      {
        text: "昨天",
        value() {
          const end = new Date();
          const start = new Date();
          start.setTime(
            start.setTime(
              new Date(
                new Date().getFullYear(),
                new Date().getMonth(),
                new Date().getDate() - 1
              )
            )
          );
          end.setTime(
            end.setTime(
              new Date(
                new Date().getFullYear(),
                new Date().getMonth(),
                new Date().getDate() - 1
              )
            )
          );
          return [start, end];

        },
      },
      {
        text: "最近7天",
        value() {
          const end = new Date();
          const start = new Date();
          start.setTime(
            start.setTime(
              new Date(
                new Date().getFullYear(),
                new Date().getMonth(),
                new Date().getDate() - 6
              )
            )
          );
          return [start, end];
        },
      },
      {
        text: "最近30天",
        value() {
          const end = new Date();
          const start = new Date();
          start.setTime(
            start.setTime(
              new Date(
                new Date().getFullYear(),
                new Date().getMonth(),
                new Date().getDate() - 29
              )
            )
          );
          return [start, end];
        },
      },
	  {
	    text: "上月",
	    value() {
	      const end = new Date();
	      const start = new Date();
	  	const day = new Date(start.getFullYear(), start.getMonth(), 0).getDate();
	      start.setTime(
	        start.setTime(
	          new Date(new Date().getFullYear(), new Date().getMonth()-1, 1)
	        )
	      );
	  	end.setTime(
	  	  end.setTime(
	  	    new Date(new Date().getFullYear(), new Date().getMonth()-1, day)
	  	  )
	  	);
	      return [start, end];
	    },
	  },
      {
        text: "本月",
        value() {
          const end = new Date();
          const start = new Date();
          start.setTime(
            start.setTime(
              new Date(new Date().getFullYear(), new Date().getMonth(), 1)
            )
          );
          return [start, end];
        },
      },
      {
        text: "本季度",
        value() {
          const start = moment(moment().quarter(moment().quarter()).startOf('quarter').valueOf()).format('YYYY-MM-DD');
          const end = moment(moment().quarter(moment().quarter()).endOf('quarter').valueOf()).format('YYYY-MM-DD');
          return [start, end];
        },
      },
      {
        text: "本年",
        value() {
          const end = new Date();
          const start = new Date();
          start.setTime(start.setTime(new Date(new Date().getFullYear(), 0, 1)));
          return [start, end];
        },
      },
    ],
  };
//   var now = new Date(); //当前日期 
//   var nowMonth = now.getMonth(); //当前月 
//   function getQuarterStartMonth(){ 
//     var quarterStartMonth = 0; 
//     if(nowMonth<3){ 
//     quarterStartMonth = 0; 
//     } 
//     if(2<nowMonth && nowMonth<6){ 
//     quarterStartMonth = 3; 
//     } 
//     if(5<nowMonth && nowMonth<9){ 
//     quarterStartMonth = 6; 
//     } 
//     if(nowMonth>8){ 
//     quarterStartMonth = 9; 
//     } 
//     return quarterStartMonth; 
//     } 
//     function getQuarterStartDate(){ 
//         var quarterStartDate = new Date(nowYear, getQuarterStartMonth(), 1); 
//         return formatDate(quarterStartDate); 
//         } 
//         //或的本季度的停止日期 
// function getQuarterEndDate(){ 
//     var quarterEndMonth = getQuarterStartMonth() + 2; 
//     var quarterStartDate = new Date(nowYear, quarterEndMonth, getMonthDays(quarterEndMonth)); 
//     return formatDate(quarterStartDate); 
//     }
