// TOKEN nie in öffentlich ausgeliefertem JavaScript speichern.
// Dieses Beispiel ist für geschützte/interne Seiten bzw. Tests gedacht.
(async () => {
  const calendar = new EasyITCalendarAPI({
    baseUrl: '/easyITCalendar/api-v1.php',
    token: 'cal_HIER_TOKEN_EINTRAGEN'
  });
  
  const result = await calendar.listBlockCategories();
  console.log(result);
})().catch(error => console.error(error));
