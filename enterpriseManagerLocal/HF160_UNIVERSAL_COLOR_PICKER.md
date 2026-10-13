# HF160 – Universal Color Picker

Calendar-Datenpflege behandelt Farbfelder einheitlich. Felder, deren technische Namen `color`, `colour` oder `farbe` als Namensbestandteil enthalten, werden automatisch mit HTML-Color-Picker, synchronem `#RRGGBB`-Feld und Leeren-Schaltfläche gerendert.

Dies gilt insbesondere für `categories.category_color` und `persons.calendar_color`, aber ebenso für künftig ergänzte Farbfelder. Leere optionale Farbwerte bleiben möglich und werden nicht automatisch als Schwarz gespeichert.

Die gleiche Bedienung ist im Standalone-/HTML-Calendar-Admin enthalten.
