// TOKEN nie in öffentlich ausgeliefertem JavaScript speichern.
// Dieses Beispiel ist für geschützte/interne Seiten bzw. Tests gedacht.
(async () => {
  const calendar = new EasyITCalendarAPI({
    baseUrl: '/easyITCalendar/api-v1.php',
    token: 'cal_HIER_TOKEN_EINTRAGEN'
  });
  
  const result = await calendar.createBlock({
    "start": "2026-10-06T08:00:00",
    "end": "2026-10-06T10:00:00",
    "category_id": 2,
    "reason": "Nicht verfügbar"
  });
  console.log(result);
})().catch(error => console.error(error));
