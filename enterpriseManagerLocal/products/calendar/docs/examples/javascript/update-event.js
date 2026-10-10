// TOKEN nie in öffentlich ausgeliefertem JavaScript speichern.
// Dieses Beispiel ist für geschützte/interne Seiten bzw. Tests gedacht.
(async () => {
  const calendar = new EasyITCalendarAPI({
    baseUrl: '/easyITCalendar/api-v1.php',
    token: 'cal_HIER_TOKEN_EINTRAGEN'
  });
  
  const result = await calendar.updateEvent(17, {
    "start": "2026-10-05T15:00:00",
    "end": "2026-10-05T16:00:00",
    "title": "Besprechung verschoben",
    "description": "Projektstatus",
    "location_id": 3
  });
  console.log(result);
})().catch(error => console.error(error));
