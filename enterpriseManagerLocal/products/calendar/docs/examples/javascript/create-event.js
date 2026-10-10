// TOKEN nie in öffentlich ausgeliefertem JavaScript speichern.
// Dieses Beispiel ist für geschützte/interne Seiten bzw. Tests gedacht.
(async () => {
  const calendar = new EasyITCalendarAPI({
    baseUrl: '/easyITCalendar/api-v1.php',
    token: 'cal_HIER_TOKEN_EINTRAGEN'
  });
  
  const result = await calendar.createEvent({
    "start": "2026-10-05T14:30:00",
    "end": "2026-10-05T15:30:00",
    "title": "Besprechung",
    "description": "Projektstatus",
    "location_id": 3
  });
  console.log(result);
})().catch(error => console.error(error));
