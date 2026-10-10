// TOKEN nie in öffentlich ausgeliefertem JavaScript speichern.
// Dieses Beispiel ist für geschützte/interne Seiten bzw. Tests gedacht.
(async () => {
  const calendar = new EasyITCalendarAPI({
    baseUrl: '/easyITCalendar/api-v1.php',
    token: 'cal_HIER_TOKEN_EINTRAGEN'
  });
  
  const result = await calendar.updateBlock(8, {
    "start": "2026-10-06T09:00:00",
    "end": "2026-10-06T11:00:00",
    "category_id": 2,
    "reason": "Verschoben"
  });
  console.log(result);
})().catch(error => console.error(error));
