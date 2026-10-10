# HF155 – Calendar render(filterObjekt)

Die Kalenderansicht besitzt ab HF155 eine zentrale Render-Schnittstelle:

```javascript
TinyCalendar.render(filterObjekt);
```

`filterObjekt === null` bedeutet ausdrücklich: kein Filter; alle für den sichtbaren Zeitraum geladenen Termine werden angezeigt.

Beispiele:

```javascript
TinyCalendar.render(null);
TinyCalendar.render({ category_id: 3 });
TinyCalendar.render({ to_student: 17, activ: true });
TinyCalendar.render({ event_status: ['planned', 'confirmed'] });
TinyCalendar.render({ thema: { contains: 'Mathematik' } });
TinyCalendar.render({ start: { gte: '2026-10-10T08:00:00', lt: '2026-10-10T12:00:00' } });
```

Alle Event-/DataForm-Felder werden serverseitig zusätzlich in `filter_data` ausgeliefert. Damit bleiben auch projektspezifische oder später ergänzte Felder filterbar, ohne dass `calendar.js` für jedes Feld erweitert werden muss.

Unterstützte Vergleichsformen: direkter Wert (`eq`), `neq`, `in`, `contains`, `startsWith`, `endsWith`, `gt`, `gte`, `lt`, `lte`, `between`, `empty`. Mehrere Felder im Filterobjekt werden UND-verknüpft. Arraywerte innerhalb eines Feldes werden ODER-verknüpft.
