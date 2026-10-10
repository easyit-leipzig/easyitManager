// TOKEN nie in öffentlich ausgeliefertem JavaScript speichern.
// Dieses Beispiel ist für geschützte/interne Seiten bzw. Tests gedacht.
(async () => {
  const calendar = new EasyITCalendarAPI({
    baseUrl: '/easyITCalendar/api-v1.php',
    token: 'cal_HIER_TOKEN_EINTRAGEN'
  });
  
  const result = await calendar.updatePlace(3, {
    "name": "Raum 102",
    "description": "Besprechungsraum",
    "street": "Musterstraße 1",
    "postal_code": "04109",
    "city": "Leipzig",
    "country": "DE",
    "room": "102",
    "activ": 1
  });
  console.log(result);
})().catch(error => console.error(error));
