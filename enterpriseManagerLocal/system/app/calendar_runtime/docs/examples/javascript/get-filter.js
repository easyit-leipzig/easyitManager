// Aktuell gesetzten Filter lesen.
const currentFilter = window.TinyCalendar.getFilter();
console.log(currentFilter);

// Filter vollständig zurücksetzen und alle Datensätze anzeigen.
window.TinyCalendar.render(null);
