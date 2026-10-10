// Beispiele für die Kalenderansichts-API.
// Mehrere Felder werden UND-verknüpft.
window.TinyCalendar.render({
  to_student: 17,
  category_id: 3,
  event_status: ['planned', 'confirmed']
});

// Operatorfilter:
window.TinyCalendar.render({
  thema: { contains: 'Mathematik' },
  start: { gte: '2026-10-10T08:00:00', lt: '2026-10-10T12:00:00' }
});
