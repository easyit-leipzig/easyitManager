(function (global) {
  'use strict';

  const nj = global.nj;
  if (!nj) throw new Error('nojquery.core.js muss vor calendar.js geladen werden.');

  const CONFIG = global.TinyCalendarInit;
  if (!CONFIG) throw new Error('init.json wurde nicht vor calendar.js geladen.');

  const STORAGE_KEY = 'tinycalendar.lessons.v2';
  const LEGACY_STORAGE_KEY = 'tinycalendar.lessons.v1';
  const COMPLETION_STORAGE_KEY = 'tinycalendar.completionEvents.v2';
  const SLOT_MINUTES = Number(CONFIG.calendar.slot_minutes) || 30;
  const HOUR_START = Number(CONFIG.calendar.hour_start) || 7;
  const HOUR_END = Number(CONFIG.calendar.hour_end) || 22;
  const DAY_COUNT = 7;
  const SNAP_RADIUS_PX = Number(CONFIG.calendar.snap_radius_px) || 5;

  const state = {
    view: CONFIG.views.default,
    weekStart: getMonday(new Date()),
    dayDate: new Date(),
    monthDate: new Date(new Date().getFullYear(), new Date().getMonth(), 1),
    lessons: [],
    filterObject: null,
    loadingEvents: false,
    completionEvents: loadCompletionEvents(),
    blockedRanges: [],
    blockCategories: [],
    blockPersons: [],
    blockMode: false,
    pendingBlock: null,
    suppressSlotClickUntil: 0,
    draggedLessonId: null,
    dragGrabOffsetX: 0,
    activeDropSlot: null,
    suppressLessonClickUntil: 0,
    blockDrag: null,
    blockPointerId: null
  };

  const els = {};

  nj.ready(function () {
    Object.assign(els, {
      grid: nj('#calendarGrid'), weekLabel: nj('#weekLabel'),
      status: nj('#calendarStatus'), helpDialog: nj('#helpDialog').get(),
      helpFrame: nj('#helpFrame').get(), helpTitle: nj('#helpDialogTitle'), helpSubtitle: nj('#helpDialogSubtitle'),
      blockDialog: nj('#blockDialog').get(), blockRangeLabel: nj('#blockRangeLabel'), blockCategory: nj('#blockCategory').get(), blockPerson: nj('#blockPerson').get(), blockReason: nj('#blockReason').get(), blockId: nj('#blockId').get(), blockError: nj('#blockError'), deleteBlock: nj('#deleteBlock').get()
    });
    applyInitConfiguration();
    bindControls();
    render(null);
    loadVisibleEvents();
    document.dispatchEvent(new CustomEvent('tinycalendar:initialized', { detail: { config: CONFIG } }));
  });



  function hasRight(name) { return CONFIG.rights[name] === true; }
  function hasFeature(name) { return CONFIG.features[name] === true; }
  function isViewEnabled(name) { return CONFIG.views[name] === true; }
  function deny(message) { setStatus(message || 'Für diese Aktion fehlt die Berechtigung.', 'error'); return false; }

  function applyInitConfiguration() {
    ['day', 'week', 'month'].forEach(function (view) {
      const button = document.getElementById(`${view}View`);
      if (button) button.hidden = !isViewEnabled(view);
    });
    const helpButton = document.getElementById('helpButton');
    if (helpButton) helpButton.hidden = !(hasFeature('help') && hasRight('help_read'));
    const blockButton = document.getElementById('blockMode');
    if (blockButton) blockButton.hidden = !(hasFeature('blocked_slots') && hasFeature('block_range_drag') && (hasRight('block_create') || hasRight('block_delete')));
    if (!isViewEnabled(state.view)) state.view = ['week', 'day', 'month'].find(isViewEnabled) || 'month';
    document.documentElement.dataset.calendarRole = String((CONFIG.user && CONFIG.user.role_id) || 0);
  }

  function bindControls() {
    nj('#prevWeek').on('click', function () { changePeriod(-1); });
    nj('#nextWeek').on('click', function () { changePeriod(1); });
    nj('#today').on('click', function () {
      const now = new Date();
      state.weekStart = getMonday(now);
      state.dayDate = new Date(now.getFullYear(), now.getMonth(), now.getDate());
      state.monthDate = new Date(now.getFullYear(), now.getMonth(), 1);
      loadVisibleEvents();
    });
    nj('#dayView').on('click', function () { setView('day'); });
    nj('#weekView').on('click', function () { setView('week'); });
    nj('#monthView').on('click', function () { setView('month'); });
    nj('#helpButton').on('click', openHelpDialog);
    nj('#closeHelp').on('click', function () { els.helpDialog.close(); });
    nj('#blockMode').on('click', toggleBlockMode);
    nj('#closeBlockDialog').on('click', closeBlockDialog);
    nj('#cancelBlock').on('click', closeBlockDialog);
    nj('#deleteBlock').on('click', deletePendingBlock);
    nj('#blockForm').on('submit', savePendingBlock);
    // HF154: Bei mehreren personenbezogenen Blockierungen im selben Zeitraum
    // kann jede Markierung gezielt angesprochen werden. Die Markierung stoppt
    // den Slot-Klick, damit nicht versehentlich ein Termin angelegt wird.
    els.grid.on('click', '.block-marker', function (event) {
      event.stopPropagation();
      const id = Number(this.dataset.blockId || 0);
      const block = state.blockedRanges.find(function (item) { return Number(item.id) === id; });
      if (!block) return;
      if (state.blockMode) { openBlockDialog(block); return; }
      setStatus(`Blockierung: ${block.person_name || 'Alle Personen'} · ${block.category_name}${block.reason ? ' – ' + block.reason : ''}.`, 'info');
    });
    els.grid.on('click', '.slot', function () {
      if (Date.now() < state.suppressSlotClickUntil) return;
      const block = blockAtSlot(this.dataset.date, this.dataset.time);
      if (state.blockMode) {
        if (block) openBlockDialog(block);
        else openBlockDialog(selectionToPending(this.dataset.date, this.dataset.time, this.dataset.time));
        return;
      }
      if (block && Number(block.person_id || 0) === 0) { setStatus(`Dieser Zeitraum ist für alle Personen blockiert: ${block.category_name}${block.reason ? ' – ' + block.reason : ''}.`, 'info'); return; }
      openCreateDialog(this.dataset.date, this.dataset.time);
    });
    els.grid.on('contextmenu', '.slot', function (event) {
      if (!event.ctrlKey) return;
      event.preventDefault();
    });
    // HF131: Blockierungs-Drag nativ im Capture-Mode binden. Dadurch wird der
    // Start auch erkannt, wenn ein absolut positioniertes Calendar-Element den
    // Slot optisch überlagert. Die eigentliche Slot-Ermittlung erfolgt anhand
    // der Zeigerkoordinaten und nicht ausschließlich über event.target.
    const gridNode = els.grid.get();
    gridNode.addEventListener('pointerdown', handleBlockGridPointerDown, { capture: true, passive: false });
    document.addEventListener('pointermove', handleBlockPointerMove, { capture: true, passive: false });
    document.addEventListener('pointerup', handleBlockPointerUp, { capture: true, passive: false });
    document.addEventListener('pointercancel', cancelBlockDrag, { capture: true });
    global.addEventListener('blur', cancelBlockDrag);
    els.grid.on('keydown', '.slot', function (event) {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        const block = blockAtSlot(this.dataset.date, this.dataset.time);
        if (state.blockMode) { openBlockDialog(block || selectionToPending(this.dataset.date, this.dataset.time, this.dataset.time)); return; }
        if (block) { setStatus(`Dieser Zeitraum ist blockiert: ${block.category_name}${block.reason ? ' – ' + block.reason : ''}.`, 'info'); return; }
        openCreateDialog(this.dataset.date, this.dataset.time);
      }
    });
    els.grid.on('click', '.lesson', function (event) {
      event.stopPropagation();
      if (Date.now() < state.suppressLessonClickUntil) return;
      openEditDialog(Number(this.dataset.id));
    });
    els.grid.on('dragstart', '.lesson', handleDragStart);
    els.grid.on('dragend', '.lesson', handleDragEnd);
    els.grid.on('dragover', handleDragOver);
    els.grid.on('dragleave', handleDragLeave);
    els.grid.on('drop', handleDrop);
  }


  function openHelpDialog() {
    if (!(hasFeature('help') && hasRight('help_read'))) return deny('Die Hilfe ist für diese Rolle nicht freigegeben.');
    const helpPages = {
      day: { file: 'help/day.html', title: 'Hilfe zur Tagesansicht', subtitle: 'Terminplanung und Blockierungen für einen Tag' },
      week: { file: 'help/week.html', title: 'Hilfe zur Wochenansicht', subtitle: 'Terminplanung und Blockierungen für sieben Tage' },
      month: { file: 'help/month.html', title: 'Hilfe zur Monatsansicht', subtitle: 'Lesende Übersicht aller Termine eines Monats' }
    };
    const page = helpPages[state.view] || helpPages.week;
    els.helpFrame.src = page.file;
    els.helpTitle.text(page.title);
    els.helpSubtitle.text(page.subtitle);
    if (typeof els.helpDialog.showModal === 'function') els.helpDialog.showModal();
    else global.open(page.file, '_blank', 'noopener');
  }

  function toggleBlockMode() {
    if (!hasFeature('blocked_slots') || !hasFeature('block_range_drag')) return deny('Blockierungen sind deaktiviert.');
    if (!hasRight('block_create') && !hasRight('block_delete')) return deny('Für Blockierungen fehlt die Berechtigung.');
    state.blockMode = !state.blockMode;
    const button = document.getElementById('blockMode');
    if (button) { button.classList.toggle('active', state.blockMode); button.setAttribute('aria-pressed', String(state.blockMode)); }
    document.documentElement.classList.toggle('calendar-block-mode', state.blockMode);
    setStatus(state.blockMode ? 'Blockierungsmodus aktiv: freien Zeitraum mit linker Maustaste markieren und ziehen. Vorhandene Blockierung anklicken zum Bearbeiten/Löschen.' : 'Blockierungsmodus beendet.', 'info');
  }

  function slotFromPoint(clientX, clientY) {
    const grid = els.grid && els.grid.get ? els.grid.get() : null;
    if (!grid) return null;

    // Zuerst den normalen DOM-Hit-Test verwenden. elementsFromPoint ist wichtig,
    // weil Termine und andere Layer oberhalb der eigentlichen Slots liegen können.
    const stack = typeof document.elementsFromPoint === 'function'
      ? document.elementsFromPoint(clientX, clientY)
      : [document.elementFromPoint(clientX, clientY)].filter(Boolean);
    for (const hit of stack) {
      const slot = hit && hit.closest ? hit.closest('.slot') : null;
      if (slot && grid.contains(slot)) return slot;
    }

    // Robuster Fallback: geometrische Trefferprüfung. Damit bleibt Click+Move
    // funktionsfähig, selbst wenn Browser/Overlay beim Hit-Test keinen Slot liefern.
    let best = null;
    let bestDistance = Infinity;
    grid.querySelectorAll('.slot').forEach(function (slot) {
      const rect = slot.getBoundingClientRect();
      if (clientX < rect.left || clientX > rect.right || clientY < rect.top || clientY > rect.bottom) return;
      const distance = Math.abs(clientY - (rect.top + rect.height / 2));
      if (distance < bestDistance) { best = slot; bestDistance = distance; }
    });
    return best;
  }

  function handleBlockGridPointerDown(event) {
    if (!hasFeature('blocked_slots') || !hasFeature('block_range_drag')) return;
    if (!(hasRight('block_create') || hasRight('block_delete'))) return;
    if (state.view !== 'week' && state.view !== 'day') return;
    if (!state.blockMode) return;
    if (event.pointerType === 'mouse' && event.button !== 0) return;

    const slot = slotFromPoint(event.clientX, event.clientY);
    if (!slot) return;
    handleBlockPointerDown.call(slot, event);
  }

  function handleBlockPointerDown(event) {
    if (!hasFeature('blocked_slots') || !hasFeature('block_range_drag')) return;
    if (!(hasRight('block_create') || hasRight('block_delete'))) return;
    if (state.view !== 'week' && state.view !== 'day') return;
    if (!state.blockMode) return;
    if (event.pointerType === 'mouse' && event.button !== 0) return;
    if (state.blockDrag) return;

    event.preventDefault();
    event.stopPropagation();
    const date = this.dataset.date;
    const time = this.dataset.time;
    if (!date || !time) return;
    const existing = blockAtSlot(date, time);
    state.blockPointerId = event.pointerId;
    state.blockDrag = {
      date, startTime: time, currentTime: time, existing: existing || null, moved: false,
      lastClientX: event.clientX, lastClientY: event.clientY
    };

    if (!existing && !hasRight('block_create')) {
      state.blockDrag = null; state.blockPointerId = null;
      return deny('Sie dürfen keine Zeitbereiche blockieren.');
    }

    updateBlockDragPreview();
    setStatus(existing
      ? 'Vorhandene Blockierung ausgewählt. Loslassen öffnet die Bearbeitung.'
      : 'Blockierung markieren: bis zum gewünschten Endzeitpunkt ziehen und loslassen.', 'info');
  }

  function handleBlockPointerMove(event) {
    if (!state.blockDrag) return;
    if (state.blockPointerId !== null && event.pointerId !== state.blockPointerId) return;
    event.preventDefault();
    state.blockDrag.lastClientX = event.clientX;
    state.blockDrag.lastClientY = event.clientY;
    const target = slotFromPoint(event.clientX, event.clientY);
    if (!target || target.dataset.date !== state.blockDrag.date) return;
    if (target.dataset.time !== state.blockDrag.currentTime) {
      state.blockDrag.currentTime = target.dataset.time;
      state.blockDrag.moved = true;
      updateBlockDragPreview();
    }
  }

  function handleBlockPointerUp(event) {
    if (!state.blockDrag) return;
    if (state.blockPointerId !== null && event.pointerId !== state.blockPointerId) return;
    event.preventDefault();
    const x = Number.isFinite(event.clientX) ? event.clientX : state.blockDrag.lastClientX;
    const y = Number.isFinite(event.clientY) ? event.clientY : state.blockDrag.lastClientY;
    const target = slotFromPoint(x, y);
    if (target && target.dataset.date === state.blockDrag.date) state.blockDrag.currentTime = target.dataset.time;
    const drag = state.blockDrag;
    clearBlockDragPreview(); state.blockDrag = null; state.blockPointerId = null; state.suppressSlotClickUntil = Date.now() + 350;
    if (drag.existing) { openBlockDialog(drag.existing); return; }
    openBlockDialog(selectionToPending(drag.date, drag.startTime, drag.currentTime));
  }

  function cancelBlockDrag() {
    if (!state.blockDrag) return;
    clearBlockDragPreview(); state.blockDrag = null; state.blockPointerId = null;
    setStatus('Bereichsauswahl abgebrochen.', 'info');
  }

  function getTimeRange(startTime, endTime) {
    const start = timeToMinutes(startTime), end = timeToMinutes(endTime), lower = Math.min(start, end), upper = Math.max(start, end), times = [];
    for (let value = lower; value <= upper; value += SLOT_MINUTES) times.push(minutesToTime(value));
    return times;
  }

  function selectionToPending(date, startTime, endTime) {
    const times = getTimeRange(startTime, endTime); const first = times[0]; const last = times[times.length - 1];
    return { id: 0, start: `${date}T${first}:00`, end: `${date}T${minutesToTime(timeToMinutes(last) + SLOT_MINUTES)}:00`, category_id: Number(state.blockCategories[0]?.id || 0), category_name: String(state.blockCategories[0]?.name || ''), person_id: 0, person_name: 'Alle Personen', reason: '' };
  }

  function updateBlockDragPreview() {
    clearBlockDragPreview(); if (!state.blockDrag) return;
    getTimeRange(state.blockDrag.startTime, state.blockDrag.currentTime).forEach(function (time) {
      const slot = els.grid.get().querySelector(`.slot[data-date="${state.blockDrag.date}"][data-time="${time}"]`);
      if (slot) slot.classList.add('block-range-preview', state.blockDrag.existing ? 'block-preview-existing' : 'block-preview-add');
    });
  }

  function clearBlockDragPreview() {
    if (!els.grid || !els.grid.get()) return;
    els.grid.get().querySelectorAll('.block-range-preview').forEach(function (slot) { slot.classList.remove('block-range-preview', 'block-preview-add', 'block-preview-existing', 'block-preview-remove'); });
  }

  function populateBlockCategories(selected) {
    if (!els.blockCategory) return;
    els.blockCategory.innerHTML = '';
    state.blockCategories.forEach(function (cat) { const option = document.createElement('option'); option.value = String(cat.id); option.textContent = cat.name; option.selected = Number(cat.id) === Number(selected); els.blockCategory.appendChild(option); });
  }

  function populateBlockPersons(selected) {
    if (!els.blockPerson) return;
    els.blockPerson.innerHTML = '';
    const all = document.createElement('option'); all.value = '0'; all.textContent = 'Alle Personen'; all.selected = Number(selected || 0) === 0; els.blockPerson.appendChild(all);
    state.blockPersons.forEach(function (person) { const option = document.createElement('option'); option.value = String(person.id); option.textContent = person.name; option.selected = Number(person.id) === Number(selected); els.blockPerson.appendChild(option); });
  }

  function openBlockDialog(block) {
    if (!block) return;
    if (!state.blockCategories.length) { setStatus('Es ist keine aktive Kategorie vorhanden. Bitte zuerst unter Setup/Datenpflege eine Kategorie anlegen.', 'error'); return; }
    state.pendingBlock = Object.assign({}, block);
    els.blockId.value = String(block.id || ''); els.blockReason.value = String(block.reason || ''); populateBlockCategories(block.category_id || state.blockCategories[0].id); populateBlockPersons(block.person_id || 0);
    els.blockRangeLabel.text(`${formatISODate(datetimeDate(block.start))}, ${datetimeTime(block.start)}–${datetimeTime(block.end)}`);
    els.blockError.text(''); els.deleteBlock.hidden = !(Number(block.id) > 0 && hasRight('block_delete'));
    document.getElementById('saveBlock').hidden = !hasRight('block_create');
    if (typeof els.blockDialog.showModal === 'function') els.blockDialog.showModal(); else els.blockDialog.setAttribute('open','open');
  }

  function closeBlockDialog() {
    if (!els.blockDialog) return;
    if (typeof els.blockDialog.close === 'function' && els.blockDialog.open) els.blockDialog.close(); else els.blockDialog.removeAttribute('open');
    state.pendingBlock = null;
  }

  async function savePendingBlock(event) {
    if (event) event.preventDefault();
    if (!state.pendingBlock || !hasRight('block_create')) return deny('Sie dürfen keine Zeitbereiche blockieren.');
    const categoryId = Number(els.blockCategory.value || 0); if (categoryId < 1) { els.blockError.text('Bitte eine Kategorie wählen.'); return; }
    const payload = { action: Number(state.pendingBlock.id) > 0 ? 'block_update' : 'block_create', id: Number(state.pendingBlock.id || 0), start: state.pendingBlock.start, end: state.pendingBlock.end, category_id: categoryId, person_id: Number(els.blockPerson ? els.blockPerson.value : 0) || 0, reason: String(els.blockReason.value || '').trim() };
    try { await nj.post(global.easyITCalendarHandler, payload, { headers: { 'X-CSRF-Token': global.easyITCalendarCsrf || '' } }); closeBlockDialog(); await loadVisibleEvents(); setStatus('Blockierung wurde gespeichert.', 'success'); }
    catch (error) { els.blockError.text(error.message || 'Blockierung konnte nicht gespeichert werden.'); }
  }

  async function deletePendingBlock() {
    if (!state.pendingBlock || Number(state.pendingBlock.id) < 1) return;
    if (!hasRight('block_delete')) return deny('Sie dürfen Blockierungen nicht löschen.');
    try { await nj.post(global.easyITCalendarHandler, { action: 'block_delete', id: Number(state.pendingBlock.id) }, { headers: { 'X-CSRF-Token': global.easyITCalendarCsrf || '' } }); closeBlockDialog(); await loadVisibleEvents(); setStatus('Blockierung wurde aufgehoben.', 'success'); }
    catch (error) { els.blockError.text(error.message || 'Blockierung konnte nicht gelöscht werden.'); }
  }

  // HF155: zentrale Render-Schnittstelle. null bedeutet ausdrücklich: kein Filter.
  // Ein gesetztes Filterobjekt wird feldgenerisch gegen alle vom Calendar gelieferten
  // Terminwerte einschließlich der rohen DataForm-Felder ausgewertet.
  function render(filterObject) {
    if (arguments.length > 0) state.filterObject = normalizeFilterObject(filterObject);
    els.grid.empty();
    els.grid.get().classList.toggle('month-grid', state.view === 'month');
    els.grid.get().classList.toggle('week-grid', state.view === 'week');
    els.grid.get().classList.toggle('day-grid', state.view === 'day');
    if (state.view === 'month') renderMonth();
    else { renderHeaders(); renderSlots(); renderLessons(); }
    renderPeriodLabel();
    updateViewControls();
  }

  function normalizeFilterObject(filterObject) {
    if (filterObject === null || filterObject === undefined) return null;
    if (typeof filterObject !== 'object' || Array.isArray(filterObject)) throw new TypeError('filterObjekt muss ein Objekt oder null sein.');
    return Object.keys(filterObject).length ? Object.assign({}, filterObject) : null;
  }

  function getPathValue(source, path) {
    if (!source || typeof source !== 'object') return undefined;
    if (Object.prototype.hasOwnProperty.call(source, path)) return source[path];
    return String(path).split('.').reduce(function (value, key) {
      return value !== null && value !== undefined && typeof value === 'object' ? value[key] : undefined;
    }, source);
  }

  function comparable(value) {
    if (value === null || value === undefined) return value;
    if (typeof value === 'string') return value.toLocaleLowerCase('de-DE');
    return value;
  }

  function valueMatchesFilter(actual, expected) {
    if (Array.isArray(expected)) return expected.some(function (candidate) { return valueMatchesFilter(actual, candidate); });
    if (expected && typeof expected === 'object') {
      if (Object.prototype.hasOwnProperty.call(expected, 'eq') && !valueMatchesFilter(actual, expected.eq)) return false;
      if (Object.prototype.hasOwnProperty.call(expected, 'neq') && valueMatchesFilter(actual, expected.neq)) return false;
      if (Array.isArray(expected.in) && !expected.in.some(function (candidate) { return valueMatchesFilter(actual, candidate); })) return false;
      if (Object.prototype.hasOwnProperty.call(expected, 'contains')) {
        const needle = String(expected.contains ?? '').toLocaleLowerCase('de-DE');
        const haystack = Array.isArray(actual) ? actual.join(' ') : String(actual ?? '');
        if (!haystack.toLocaleLowerCase('de-DE').includes(needle)) return false;
      }
      if (Object.prototype.hasOwnProperty.call(expected, 'startsWith') && !String(actual ?? '').toLocaleLowerCase('de-DE').startsWith(String(expected.startsWith ?? '').toLocaleLowerCase('de-DE'))) return false;
      if (Object.prototype.hasOwnProperty.call(expected, 'endsWith') && !String(actual ?? '').toLocaleLowerCase('de-DE').endsWith(String(expected.endsWith ?? '').toLocaleLowerCase('de-DE'))) return false;
      if (Object.prototype.hasOwnProperty.call(expected, 'gt') && !(actual > expected.gt)) return false;
      if (Object.prototype.hasOwnProperty.call(expected, 'gte') && !(actual >= expected.gte)) return false;
      if (Object.prototype.hasOwnProperty.call(expected, 'lt') && !(actual < expected.lt)) return false;
      if (Object.prototype.hasOwnProperty.call(expected, 'lte') && !(actual <= expected.lte)) return false;
      if (Array.isArray(expected.between) && expected.between.length >= 2 && !(actual >= expected.between[0] && actual <= expected.between[1])) return false;
      if (Object.prototype.hasOwnProperty.call(expected, 'empty')) {
        const empty = actual === null || actual === undefined || actual === '' || (Array.isArray(actual) && actual.length === 0);
        if (Boolean(expected.empty) !== empty) return false;
      }
      return true;
    }
    if (Array.isArray(actual)) return actual.some(function (value) { return valueMatchesFilter(value, expected); });
    const a = comparable(actual); const e = comparable(expected);
    if (a === e) return true;
    if (a === null || a === undefined || e === null || e === undefined) return false;
    return String(a) === String(e);
  }

  function lessonMatchesFilter(lesson) {
    if (state.filterObject === null) return true;
    const source = Object.assign({}, lesson.filter_data || {}, lesson);
    return Object.keys(state.filterObject).every(function (field) {
      return valueMatchesFilter(getPathValue(source, field), state.filterObject[field]);
    });
  }

  function filteredLessons() { return state.filterObject === null ? state.lessons : state.lessons.filter(lessonMatchesFilter); }

  function setView(view) {
    if (!isViewEnabled(view)) return deny('Diese Kalenderansicht ist nicht freigegeben.');
    if (view !== 'day' && view !== 'week' && view !== 'month') return;
    const previousView = state.view;
    if (view === 'month') {
      const source = previousView === 'day' ? state.dayDate : state.weekStart;
      state.monthDate = new Date(source.getFullYear(), source.getMonth(), 1);
    } else if (view === 'day') {
      if (previousView === 'month') state.dayDate = new Date(state.monthDate.getFullYear(), state.monthDate.getMonth(), 1);
      else if (previousView === 'week') state.dayDate = new Date(state.weekStart);
    } else if (view === 'week') {
      const source = previousView === 'day' ? state.dayDate : state.monthDate;
      state.weekStart = getMonday(source);
    }
    state.view = view;
    render();
    loadVisibleEvents();
    const messages = {
      day: 'Tagesansicht: alle Funktionen der Wochenansicht stehen für den gewählten Tag zur Verfügung.',
      week: 'Wochenansicht: Termine können verschoben und Zeitbereiche blockiert werden.',
      month: 'Monatsansicht: reine Darstellung aller Termine ohne Drag-and-drop.'
    };
    setStatus(messages[view], 'info');
  }

  function changePeriod(direction) {
    if (state.view === 'month') state.monthDate = new Date(state.monthDate.getFullYear(), state.monthDate.getMonth() + direction, 1);
    else if (state.view === 'day') state.dayDate = addDays(state.dayDate, direction);
    else state.weekStart = addDays(state.weekStart, direction * 7);
    render();
    loadVisibleEvents();
  }

  function updateViewControls() {
    const dayButton = document.getElementById('dayView');
    const weekButton = document.getElementById('weekView');
    const monthButton = document.getElementById('monthView');
    dayButton.classList.toggle('active', state.view === 'day');
    weekButton.classList.toggle('active', state.view === 'week');
    monthButton.classList.toggle('active', state.view === 'month');
    dayButton.setAttribute('aria-pressed', String(state.view === 'day'));
    weekButton.setAttribute('aria-pressed', String(state.view === 'week'));
    monthButton.setAttribute('aria-pressed', String(state.view === 'month'));
  }

  function renderPeriodLabel() {
    if (state.view === 'month') {
      els.weekLabel.text(new Intl.DateTimeFormat('de-DE', { month: 'long', year: 'numeric' }).format(state.monthDate));
      return;
    }
    if (state.view === 'day') {
      els.weekLabel.text(new Intl.DateTimeFormat('de-DE', { weekday: 'long', day: '2-digit', month: '2-digit', year: 'numeric' }).format(state.dayDate));
      return;
    }
    els.weekLabel.text(`${formatDate(state.weekStart)} – ${formatDate(addDays(state.weekStart, 6))}`);
  }

  function visibleDayCount() { return state.view === 'day' ? 1 : DAY_COUNT; }
  function visibleStartDate() { return state.view === 'day' ? state.dayDate : state.weekStart; }

  function renderMonth() {
    const grid = els.grid.get();
    ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'].forEach(function (label) {
      grid.appendChild(nj.create('div', { class: 'month-weekday', text: label }));
    });
    const first = new Date(state.monthDate.getFullYear(), state.monthDate.getMonth(), 1);
    const last = new Date(state.monthDate.getFullYear(), state.monthDate.getMonth() + 1, 0);
    const offset = (first.getDay() + 6) % 7;
    const cellStart = addDays(first, -offset);
    const totalCells = Math.ceil((offset + last.getDate()) / 7) * 7;
    const today = toISODate(new Date());
    for (let index = 0; index < totalCells; index += 1) {
      const date = addDays(cellStart, index);
      const iso = toISODate(date);
      const inMonth = date.getMonth() === state.monthDate.getMonth();
      const dayLessons = filteredLessons()
        .filter(function (lesson) { return datetimeDate(lesson.start) === iso; })
        .sort(function (a, b) { return a.start.localeCompare(b.start) || a.id - b.id; });
      const cell = nj.create('section', {
        class: `month-day${inMonth ? '' : ' outside'}${iso === today ? ' today' : ''}`,
        'aria-label': `${formatDate(date)}, ${dayLessons.length} Termin${dayLessons.length === 1 ? '' : 'e'}`
      });
      cell.appendChild(nj.create('header', { class: 'month-day-number', text: String(date.getDate()) }));
      const list = nj.create('div', { class: 'month-lessons' });
      dayLessons.forEach(function (lesson) {
        const text = [lesson.thema, lesson.description].filter(Boolean).join(' – ');
        list.appendChild(nj.create('article', {
          class: `month-lesson${lesson.activ ? '' : ' inactive'}`,
          title: `${datetimeTime(lesson.start)}–${datetimeTime(lesson.end)}\n${lesson.thema || ''}${lesson.description ? '\n' + lesson.description : ''}`,
          html: `<time>${datetimeTime(lesson.start)}–${datetimeTime(lesson.end)}</time><strong>${escapeHTML(lesson.thema || 'Termin')}</strong>${lesson.description ? `<span>${escapeHTML(lesson.description)}</span>` : ''}`
        }));
      });
      cell.appendChild(list);
      grid.appendChild(cell);
    }
    grid.style.minHeight = '';
  }

  function renderHeaders() {
    els.grid.get().appendChild(nj.create('div', { class: 'corner' }));
    const today = toISODate(new Date());
    for (let day = 0; day < visibleDayCount(); day += 1) {
      const date = addDays(visibleStartDate(), day); const iso = toISODate(date);
      els.grid.get().appendChild(nj.create('div', {
        class: `day-head${iso === today ? ' today' : ''}`,
        html: `<strong>${weekday(date)}</strong><span>${formatDate(date)}</span>`
      }));
    }
  }

  function renderSlots() {
    const totalSlots = (HOUR_END - HOUR_START) * 2;
    for (let row = 0; row < totalSlots; row += 1) {
      const minutes = HOUR_START * 60 + row * SLOT_MINUTES;
      const time = minutesToTime(minutes); const half = minutes % 60 !== 0;
      els.grid.get().appendChild(nj.create('div', { class: `time-label${half ? ' half' : ''}`, text: half ? '·' : time }));
      for (let day = 0; day < visibleDayCount(); day += 1) {
        const date = toISODate(addDays(visibleStartDate(), day));
        const blocks = blocksAtSlot(date, time);
        const block = blocks[0] || null;
        const globalBlock = blocks.some(function (item) { return Number(item.person_id || 0) === 0; });
        const color = block ? blockColor(block.category_id, block.category_color) : '';
        const blockSummary = blocks.map(function (item) { return `${item.person_name || 'Alle Personen'}: ${item.category_name}`; }).join('; ');
        const slot = nj.create('div', {
          class: `slot${half ? '' : ' hour'}${blocks.length ? (globalBlock ? ' blocked' : ' person-blocked') : ''}${blocks.length > 1 ? ' multi-blocked' : ''}`, tabindex: 0, role: 'button',
          'aria-label': `${date}, ${time} bis ${minutesToTime(minutes + SLOT_MINUTES)}${blocks.length ? ', Blockierungen: ' + blockSummary : ''}`,
          title: blocks.length ? blocks.map(function (item) { return `${item.category_name} · ${item.person_name || 'Alle Personen'}${item.reason ? ' – ' + item.reason : ''}\n${datetimeTime(item.start)}–${datetimeTime(item.end)}`; }).join('\n\n') : (state.blockMode ? 'Ziehen, um einen Zeitraum zu blockieren' : 'Klicken, um einen Termin anzulegen'),
          style: block ? `--block-color:${color}` : '',
          dataset: { date, time, blockId: block ? block.id : '', blockCount: blocks.length }
        });
        if (blocks.length) {
          const markerBox = nj.create('span', { class: 'block-markers', 'aria-hidden': 'false' });
          blocks.forEach(function (item, index) {
            const personName = item.person_name || 'Alle Personen';
            const markerText = Number(item.person_id || 0) === 0 ? 'A' : blockPersonInitials(personName);
            markerBox.appendChild(nj.create('span', {
              class: `block-marker${Number(item.person_id || 0) === 0 ? ' global' : ''}`,
              text: markerText,
              title: `${personName} · ${item.category_name}${item.reason ? ' – ' + item.reason : ''}`,
              'aria-label': `Blockierung ${index + 1} von ${blocks.length}: ${personName}, ${item.category_name}`,
              tabindex: 0,
              style: `--marker-color:${blockColor(item.category_id, item.category_color)}`,
              dataset: { blockId: item.id }
            }));
          });
          slot.appendChild(markerBox);
        }
        els.grid.get().appendChild(slot);
      }
    }
  }

  function renderLessons() {
    const grid = els.grid.get(); const slotHeight = 34; const headerHeight = 64;
    const rows = (HOUR_END - HOUR_START) * 2; const dayCount = visibleDayCount(); const columnWidth = `(100% - 72px) / ${dayCount}`;
    calculateLessonLayouts().forEach(function (layout) {
      const lesson = layout.lesson;
      const top = headerHeight + ((layout.visibleStart - HOUR_START * 60) / SLOT_MINUTES) * slotHeight;
      const height = Math.max(30, ((layout.visibleEnd - layout.visibleStart) / SLOT_MINUTES) * slotHeight - 4);
      const laneGap = 3; const dayInset = 4; const laneCount = Math.max(1, layout.laneCount);
      const laneWidth = `((${columnWidth}) - ${dayInset * 2}px - ${(laneCount - 1) * laneGap}px) / ${laneCount}`;
      const left = `calc(72px + (${layout.dayIndex} * ${columnWidth}) + ${dayInset}px + (${layout.lane} * (${laneWidth} + ${laneGap}px)))`;
      const startTime = datetimeTime(lesson.start); const endTime = datetimeTime(lesson.end);
      const details = [lesson.thema, lesson.description].filter(Boolean).join(' · ');
      const studentLabel = `Student ${lesson.to_student}`;
      grid.appendChild(nj.create('button', {
        type: 'button', class: `lesson${layout.laneCount > 1 ? ' overlapping' : ''}${lesson.activ ? '' : ' inactive'}`,
        draggable: true, 'aria-grabbed': 'false',
        'aria-label': `${studentLabel}, ${startTime} bis ${endTime}${details ? ', ' + details : ''}`,
        title: `${studentLabel}\n${startTime}–${endTime}${details ? '\n' + details : ''}\nID: ${lesson.id}\nPer Drag-and-drop verschieben`,
        dataset: { id: lesson.id }, style: `top:${top}px;height:${height}px;left:${left};width:calc(${laneWidth});--student-color:${lesson.student_color || 'transparent'};--category-color:${lesson.category_color || 'var(--easyit-blue-dark)'}`,
        html: `<strong>${escapeHTML(studentLabel)}</strong><small class="lesson-time">${startTime}–${endTime}</small>${lesson.thema ? `<small>${escapeHTML(lesson.thema)}</small>` : ''}${lesson.description ? `<small class="lesson-description">${escapeHTML(lesson.description)}</small>` : ''}`
      }));
    });
    grid.style.minHeight = `${headerHeight + rows * slotHeight}px`;
  }

  function calculateLessonLayouts() {
    const dayCount = visibleDayCount();
    const startDate = visibleStartDate();
    const visibleByDay = Array.from({ length: dayCount }, function () { return []; });
    filteredLessons().forEach(function (lesson) {
      const lessonDate = parseLocalDate(datetimeDate(lesson.start));
      const dayIndex = dayDifference(startDate, lessonDate);
      if (dayIndex < 0 || dayIndex >= dayCount) return;
      const startMinutes = timeToMinutes(datetimeTime(lesson.start));
      const endDate = datetimeDate(lesson.end);
      const endMinutes = endDate === datetimeDate(lesson.start) ? timeToMinutes(datetimeTime(lesson.end)) : 24 * 60;
      const visibleStart = Math.max(startMinutes, HOUR_START * 60); const visibleEnd = Math.min(endMinutes, HOUR_END * 60);
      if (visibleEnd <= visibleStart) return;
      visibleByDay[dayIndex].push({ lesson, dayIndex, startMinutes, endMinutes, visibleStart, visibleEnd, lane: 0, laneCount: 1 });
    });
    const result = [];
    visibleByDay.forEach(function (items) {
      items.sort(function (a, b) { return a.startMinutes - b.startMinutes || b.endMinutes - a.endMinutes; });
      let group = []; let groupEnd = -1;
      function finishGroup() { if (!group.length) return; assignOverlapLanes(group); result.push.apply(result, group); group = []; groupEnd = -1; }
      items.forEach(function (item) { if (group.length && item.startMinutes >= groupEnd) finishGroup(); group.push(item); groupEnd = Math.max(groupEnd, item.endMinutes); });
      finishGroup();
    });
    return result;
  }

  function assignOverlapLanes(group) {
    const laneEnds = [];
    group.forEach(function (item) {
      let lane = laneEnds.findIndex(function (endMinutes) { return endMinutes <= item.startMinutes; });
      if (lane < 0) lane = laneEnds.length; laneEnds[lane] = item.endMinutes; item.lane = lane;
    });
    group.forEach(function (item) { item.laneCount = Math.max(1, laneEnds.length); });
  }

  function handleDragStart(event) {
    if (!(hasFeature('drag_drop') && hasRight('lesson_move'))) { event.preventDefault(); return deny('Termine dürfen nicht verschoben werden.'); }
    if (state.view !== 'week' && state.view !== 'day') { event.preventDefault(); return; }
    const id = Number(this.dataset.id); const lesson = state.lessons.find(function (item) { return item.id === id; });
    if (!lesson) { event.preventDefault(); return; }
    state.draggedLessonId = id; state.dragGrabOffsetX = Math.max(0, event.clientX - this.getBoundingClientRect().left); state.activeDropSlot = null;
    this.classList.add('dragging'); this.setAttribute('aria-grabbed', 'true');
    if (event.dataTransfer) { event.dataTransfer.effectAllowed = 'move'; event.dataTransfer.setData('text/plain', String(id)); }
    clearDropTargets(); setStatus(`Termin ${lesson.id} wird verschoben. Zielslot auswählen.`, 'info');
  }

  function handleDragOver(event) {
    if (state.draggedLessonId === null) return; event.preventDefault();
    if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';
    const targetSlot = findSnapSlot(event.clientX, event.clientY);
    if (targetSlot === state.activeDropSlot) return;
    clearDropTargets(); state.activeDropSlot = targetSlot; if (targetSlot) targetSlot.classList.add('drop-target');
  }

  function handleDragLeave(event) {
    const next = event.relatedTarget;
    if (!next || !els.grid.get().contains(next)) { clearDropTargets(); state.activeDropSlot = null; }
  }

  function handleDrop(event) {
    if (state.draggedLessonId === null) return; event.preventDefault(); event.stopPropagation();
    const rawId = state.draggedLessonId !== null ? state.draggedLessonId : Number(event.dataTransfer && event.dataTransfer.getData('text/plain'));
    const targetSlot = findSnapSlot(event.clientX, event.clientY) || state.activeDropSlot;
    clearDropTargets(); state.activeDropSlot = null;
    if (!Number.isInteger(rawId) || !targetSlot) { setStatus('Kein gültiger Zielslot innerhalb des 5-px-Snap-Radius erkannt.', 'error'); return; }
    moveLessonToSlot(rawId, targetSlot.dataset.date, targetSlot.dataset.time);
  }

  function findSnapSlot(clientX, clientY) {
    const lessonLeftX = clientX - state.dragGrabOffsetX; let bestSlot = null; let bestDistance = Infinity;
    els.grid.get().querySelectorAll('.slot').forEach(function (slot) {
      if (slot.classList.contains('blocked')) return;
      const rect = slot.getBoundingClientRect();
      if (clientY < rect.top - SNAP_RADIUS_PX || clientY > rect.bottom + SNAP_RADIUS_PX || lessonLeftX < rect.left - SNAP_RADIUS_PX || lessonLeftX > rect.right + SNAP_RADIUS_PX) return;
      const horizontalDistance = lessonLeftX < rect.left ? rect.left - lessonLeftX : lessonLeftX > rect.right ? lessonLeftX - rect.right : 0;
      const score = horizontalDistance * 1000 + Math.abs(clientY - (rect.top + rect.height / 2));
      if (score < bestDistance) { bestDistance = score; bestSlot = slot; }
    });
    return bestSlot;
  }

  function handleDragEnd() { this.classList.remove('dragging'); this.setAttribute('aria-grabbed', 'false'); clearDropTargets(); state.draggedLessonId = null; state.dragGrabOffsetX = 0; state.activeDropSlot = null; }
  function clearDropTargets() { els.grid.get().querySelectorAll('.slot.drop-target').forEach(function (slot) { slot.classList.remove('drop-target'); }); }

  async function moveLessonToSlot(id, targetDate, targetStart) {
    if (!(hasFeature('drag_drop') && hasRight('lesson_move'))) return deny('Termine dürfen nicht verschoben werden.');
    const movingLesson = state.lessons.find(function (item) { return item.id === id; });
    if (isSlotBlockedForLesson(targetDate, targetStart, movingLesson)) { setStatus('Der Zielzeitraum ist blockiert und kann nicht belegt werden.', 'error'); state.draggedLessonId = null; return; }
    const index = state.lessons.findIndex(function (item) { return item.id === id; }); if (index < 0) return;
    const oldValue = cloneLesson(state.lessons[index]); const duration = datetimeToMinutes(oldValue.end) - datetimeToMinutes(oldValue.start);
    const newStart = `${targetDate}T${targetStart}:00`; const newEnd = addMinutesToDatetime(newStart, duration);
    if (datetimeDate(newEnd) !== targetDate) { setStatus('Der Termin kann nicht über Mitternacht hinaus verschoben werden.', 'error'); state.draggedLessonId = null; return; }
    const updated = Object.assign({}, oldValue, { start: newStart, end: newEnd });
    const error = validateLesson(updated); if (error) { setStatus(error, 'error'); state.draggedLessonId = null; return; }
    try {
      setStatus('Termin wird verschoben …', 'info');
      await apiRequest('PUT', updated);
      state.lessons[index] = updated;
      const completion = createMoveCompletionEvent(oldValue, updated);
      state.suppressLessonClickUntil = Date.now() + 350; state.draggedLessonId = null; render();
      setStatus(`Termin ${updated.id} verschoben: ${formatISODate(targetDate)}, ${datetimeTime(updated.start)}–${datetimeTime(updated.end)}.`, 'success');
      dispatchMoveCompleted(completion);
    } catch (error) {
      state.draggedLessonId = null;
      setStatus(error.message, 'error');
      render();
    }
  }

  function createMoveCompletionEvent(oldLesson, newLesson) {
    const completion = {
      id: nextCompletionId(), type: 'lesson-move-completed', lesson_id: newLesson.id,
      old_start: oldLesson.start, old_end: oldLesson.end, new_start: newLesson.start, new_end: newLesson.end,
      completed_at: new Date().toISOString()
    };
    state.completionEvents.push(completion); persistCompletionEvents(); return completion;
  }

  function dispatchMoveCompleted(completion) {
    document.dispatchEvent(new CustomEvent('tinycalendar:lesson-move-completed', { detail: Object.assign({}, completion) }));
    if (global.TinyCalendar && typeof global.TinyCalendar.onMoveCompleted === 'function') global.TinyCalendar.onMoveCompleted(Object.assign({}, completion));
  }

  function setStatus(message, type) { if (!els.status || !els.status.get()) return; els.status.text(message || ''); els.status.get().className = `calendar-status ${type || 'info'}`; }
  function formatISODate(value) { return formatDate(parseLocalDate(value)); }

  
  function addMinutesToTime(value,mins){const a=String(value||'00:00').split(':').map(Number);const d=new Date(2000,0,1,a[0]||0,a[1]||0);d.setMinutes(d.getMinutes()+mins);return String(d.getHours()).padStart(2,'0')+':'+String(d.getMinutes()).padStart(2,'0');}
  function useDataFormTermDialog() {
    return window.easyITCalendarUseDataFormDialog === true && Number(window.easyITCalendarTermDataFormId || 0) > 0;
  }
  function openDataFormTermDialog(recordId, defaults) {
    const dlg=document.getElementById('dataFormLessonDialog');
    const frame=document.getElementById('dataFormLessonFrame');
    if(!dlg||!frame) return false;
    const p=new URLSearchParams({
      project:String(window.easyITCalendarProjectId||0),
      dataform:String(window.easyITCalendarTermDataFormId||0),
      mode:recordId?'edit':'create',
      runtime_view:'form',
      embed:'1',
      dialog_embed:'1'
    });
    if(recordId){p.set('record',String(recordId));}
    if(defaults){
      Object.keys(defaults).forEach(k=>{if(defaults[k]!==undefined&&defaults[k]!==null&&defaults[k]!=='')p.set('default_'+k,String(defaults[k]));});
    }
    frame.src=String(window.easyITCalendarDataFormRecordUrl||'../dataform/records.php')+'?'+p.toString();
    dlg.showModal();
    return true;
  }
  window.addEventListener('message',function(ev){
    if(ev.origin!==window.location.origin) return;
    const d=ev.data||{};
    if(d.type==='easyit-dataform-dialog-saved'||d.type==='easyit-dataform-dialog-deleted'){
      const dlg=document.getElementById('dataFormLessonDialog');
      if(dlg&&dlg.open&&window.easyITCalendarCloseDataFormAfterSave===true)dlg.close();
      loadVisibleEvents();
    }
  });
  function closeDataFormTermDialog() {
    const dlg=document.getElementById('dataFormLessonDialog');
    const frame=document.getElementById('dataFormLessonFrame');
    if(frame) frame.src='about:blank';
    if(dlg&&dlg.open) dlg.close();
  }
  function bindDataFormTermDialogClose() {
    const dlg=document.getElementById('dataFormLessonDialog');
    const b=document.getElementById('closeDataFormLesson');
    if(b && b.dataset.easyitCloseBound!=='1'){
      b.dataset.easyitCloseBound='1';
      b.addEventListener('click',function(ev){ev.preventDefault();ev.stopPropagation();closeDataFormTermDialog();});
    }
    if(dlg && dlg.dataset.easyitCancelBound!=='1'){
      dlg.dataset.easyitCancelBound='1';
      dlg.addEventListener('cancel',function(){const frame=document.getElementById('dataFormLessonFrame');if(frame)frame.src='about:blank';});
    }
  }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',bindDataFormTermDialogClose,{once:true});
  else bindDataFormTermDialogClose();
  document.addEventListener('click',function(ev){
    const target=ev.target&&ev.target.closest?ev.target.closest('#closeDataFormLesson'):null;
    if(!target)return;
    ev.preventDefault();ev.stopPropagation();closeDataFormTermDialog();
  });

function openCreateDialog(date, start) {
    if (!hasRight('lesson_create')) return deny('Sie dürfen keine Termine anlegen.');
    if(!useDataFormTermDialog()) return deny('Das Termin-DataForm ist nicht konfiguriert. Öffnen Sie Calendar → Setup.');
    const end=addMinutesToTime(start, SLOT_MINUTES);
    const beginValue=`${date}T${normalizeHalfHour(start)}:00`;
    const endValue=`${date}T${normalizeHalfHour(end)}:00`;
    openDataFormTermDialog(0,{start:beginValue,end:endValue,activ:1,event_status:'planned'});
  }

  function openEditDialog(id) {
    if (!hasRight('lesson_edit')) return deny('Sie dürfen Termine nur ansehen, aber nicht bearbeiten.');
    const recordId=Number(id&&typeof id==='object'?(id.id||id.lesson_id||0):id);
    if(!recordId) return;
    if(!useDataFormTermDialog()) return deny('Das Termin-DataForm ist nicht konfiguriert. Öffnen Sie Calendar → Setup.');
    openDataFormTermDialog(recordId,null);
  }

  function validateLesson(lesson) {
    if (!Number.isInteger(lesson.id) || lesson.id < 1) return 'ID muss eine positive Ganzzahl sein.';
    if (!Number.isInteger(lesson.to_role) || lesson.to_role < 0 || !Number.isInteger(lesson.to_tutor) || lesson.to_tutor < 0 || !Number.isInteger(lesson.to_student) || lesson.to_student < 0) return 'Rolle, Tutor und Student müssen Ganzzahlen ab 0 sein.';
    if (!isValidDatetime(lesson.start) || !isValidDatetime(lesson.end)) return 'Start und Ende müssen gültige Datums-/Zeitwerte sein.';
    if (datetimeToMinutes(lesson.end) <= datetimeToMinutes(lesson.start)) return 'Das Ende muss nach dem Beginn liegen.';
    if (typeof lesson.activ !== 'boolean') return 'activ muss ein boolescher Wert sein.';
    if (typeof lesson.thema !== 'string' || typeof lesson.description !== 'string') return 'Thema und Beschreibung müssen Text sein.';
    if (!Array.isArray(lesson.appendizies) || lesson.appendizies.some(function (name) { return typeof name !== 'string' || !name.trim(); })) return 'appendizies muss ein Array gültiger Dateinamen sein.';
    return '';
  }

  function visibleRange() {
    if (state.view === 'month') {
      const first = new Date(state.monthDate.getFullYear(), state.monthDate.getMonth(), 1);
      const next = new Date(state.monthDate.getFullYear(), state.monthDate.getMonth() + 1, 1);
      return { start: toISODate(first), end: toISODate(next) };
    }
    const first = visibleStartDate();
    return { start: toISODate(first), end: toISODate(addDays(first, visibleDayCount())) };
  }

  async function loadVisibleEvents() {
    if (!hasRight('lesson_read')) { state.lessons = []; render(); return; }
    const range = visibleRange();
    state.loadingEvents = true;
    setStatus('Termine werden aus der Datenbank geladen …', 'info');
    try {
      const data = await nj.post(global.easyITCalendarHandler, {
        action: 'list',
        start: range.start,
        end: range.end
      }, {
        timeout: 30000,
        headers: { 'X-CSRF-Token': global.easyITCalendarCsrf || '' }
      });
      state.lessons = (Array.isArray(data.events) ? data.events : []).map(normalizeLesson);
      state.blockedRanges = (Array.isArray(data.blocks) ? data.blocks : []).map(normalizeBlock);
      state.blockCategories = (Array.isArray(data.block_categories) ? data.block_categories : []).map(function (c) { return { id: Number(c.id), name: String(c.name || ('Kategorie #' + c.id)) }; });
      state.blockPersons = (Array.isArray(data.block_persons) ? data.block_persons : []).map(function (p) { return { id: Number(p.id), name: String(p.name || ('Person #' + p.id)) }; });
      render();
      setStatus(`${state.lessons.length} Termin(e), ${state.blockedRanges.length} Blockierung(en) geladen.`, 'success');
    } catch (error) {
      state.lessons = []; state.blockedRanges = [];
      render();
      setStatus(`Termine konnten nicht geladen werden: ${error.message}`, 'error');
    } finally { state.loadingEvents = false; }
  }

  async function apiRequest(method, payload) {
    const actionMap = { POST: 'create', PUT: 'update', DELETE: 'delete' };
    const action = actionMap[String(method || '').toUpperCase()];
    if (!action) throw new Error(`Nicht unterstützte Kalenderaktion: ${method}`);
    return nj.post(global.easyITCalendarHandler, Object.assign({ action }, payload || {}), {
      headers: { 'X-CSRF-Token': global.easyITCalendarCsrf || '' }
    });
  }

  function migrateLesson(item, index) {
    try {
      if (item && item.start && String(item.start).includes('T')) return normalizeLesson(item);
      const date = item.date || toISODate(new Date());
      return normalizeLesson({
        id: Number.isInteger(Number(item.id)) && Number(item.id) > 0 ? Number(item.id) : index + 1,
        to_role: Number(item.to_role || 0), to_tutor: Number(item.to_tutor || 0), to_student: Number(item.to_student || 0),
        start: `${date}T${item.start || '09:00'}:00`, end: `${date}T${item.end || '09:30'}:00`, activ: item.activ !== false,
        thema: item.thema || item.subject || '', description: item.description || item.notes || item.student || '',
        appendizies: Array.isArray(item.appendizies) ? item.appendizies : []
      });
    } catch (error) { console.warn('Termin wurde bei der Migration verworfen.', item, error); return null; }
  }

  function normalizeLesson(item) {
    return {
      id: Number(item.id), to_role: Number(item.to_role), to_tutor: Number(item.to_tutor), to_student: Number(item.to_student),
      start: normalizeDatetime(item.start), end: normalizeDatetime(item.end), activ: Boolean(item.activ),
      thema: String(item.thema || ''), description: String(item.description || ''),
      student_color: /^#[0-9a-f]{6}$/i.test(String(item.student_color || '')) ? String(item.student_color).toLowerCase() : '',
      category_id: Number(item.category_id || 0), category_name: String(item.category_name || ''),
      category_color: /^#[0-9a-f]{6}$/i.test(String(item.category_color || '')) ? String(item.category_color).toLowerCase() : '',
      appendizies: Array.isArray(item.appendizies) ? item.appendizies.map(String).map(function (v) { return v.trim(); }).filter(Boolean) : [],
      version_no: Number(item.version_no || 1),
      filter_data: item.filter_data && typeof item.filter_data === 'object' && !Array.isArray(item.filter_data) ? Object.assign({}, item.filter_data) : {}
    };
  }

  function slotKey(date, time) { return `${date}T${time}`; }
  function normalizeBlock(item) { return { id: Number(item.id), start: normalizeDatetime(item.start), end: normalizeDatetime(item.end), category_id: Number(item.category_id || 0), category_name: String(item.category_name || ('Kategorie #' + item.category_id)), category_color: /^#[0-9a-f]{6}$/i.test(String(item.category_color || '')) ? String(item.category_color).toLowerCase() : '', person_id: Number(item.person_id || 0), person_name: String(item.person_name || (Number(item.person_id || 0) > 0 ? ('Person #' + item.person_id) : 'Alle Personen')), reason: String(item.reason || '') }; }
  function blocksAtSlot(date, time) { const start = `${date}T${time}:00`; const end = addMinutesToDatetime(start, SLOT_MINUTES); return state.blockedRanges.filter(function (b) { return b.start < end && b.end > start; }); }
  function blockAtSlot(date, time) { return blocksAtSlot(date, time)[0] || null; }
  function isSlotBlocked(date, time) { return blocksAtSlot(date, time).some(function (b) { return Number(b.person_id || 0) === 0; }); }
  function isSlotBlockedForLesson(date, time, lesson) { const persons = lesson ? [Number(lesson.to_tutor || 0), Number(lesson.to_student || 0)] : []; return blocksAtSlot(date, time).some(function (b) { const pid=Number(b.person_id || 0); return pid===0 || persons.includes(pid); }); }
  function blockColor(categoryId, configuredColor) { const configured=String(configuredColor || '').trim(); if (/^#[0-9a-f]{6}$/i.test(configured)) return configured.toLowerCase(); const palette = ['#9b3d3d','#7a5b12','#356a3f','#2f648d','#6b4f91','#8a4f73','#3d7272','#6b5d48']; const id=Math.max(1,Number(categoryId)||1); return palette[(id-1)%palette.length]; }
  function blockPersonInitials(name) {
    const parts=String(name||'').trim().split(/\s+/).filter(Boolean);
    if (!parts.length) return '?';
    if (parts.length === 1) return parts[0].slice(0,2).toUpperCase();
    return (parts[0][0] + parts[parts.length-1][0]).toUpperCase();
  }
  function toggleBlockedSlot(date, time) { if (!state.blockMode) toggleBlockMode(); openBlockDialog(blockAtSlot(date,time) || selectionToPending(date,time,time)); }
  function dispatchBlockChanged(action, date, time) { document.dispatchEvent(new CustomEvent('tinycalendar:block-changed', { detail: { action, date, start: time, end: minutesToTime(timeToMinutes(time)+SLOT_MINUTES) } })); }
  function dispatchBlockRangeChanged(action, date, start, end) { document.dispatchEvent(new CustomEvent('tinycalendar:block-range-changed', { detail: { action, date, start, end } })); }
  function loadCompletionEvents() { try { const value = JSON.parse(localStorage.getItem(COMPLETION_STORAGE_KEY) || '[]'); return Array.isArray(value) ? value : []; } catch (error) { return []; } }
  function persist() { /* Termine werden serverseitig gespeichert. */ }
  function persistCompletionEvents() { localStorage.setItem(COMPLETION_STORAGE_KEY, JSON.stringify(state.completionEvents)); }
  function nextLessonId() { return state.lessons.reduce(function (max, item) { return Math.max(max, item.id); }, 0) + 1; }
  function nextCompletionId() { return state.completionEvents.reduce(function (max, item) { return Math.max(max, Number(item.id) || 0); }, 0) + 1; }
  function parseFilenames(value) { return String(value || '').split(/[\n,;]+/).map(function (name) { return name.trim(); }).filter(Boolean); }
  function normalizeHalfHour(value) { return minutesToTime(Math.round(timeToMinutes(value) / SLOT_MINUTES) * SLOT_MINUTES); }
  function normalizeDatetime(value) { const text = String(value || '').trim().replace(' ', 'T'); return text.length === 16 ? `${text}:00` : text.slice(0, 19); }
  function isValidDatetime(value) { return /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/.test(String(value)) && !Number.isNaN(new Date(value).getTime()); }
  function datetimeDate(value) { return String(value).slice(0, 10); }
  function datetimeTime(value) { return String(value).slice(11, 16); }
  function datetimeToMinutes(value) { return Math.floor(new Date(value).getTime() / 60000); }
  function addMinutesToDatetime(value, minutes) { const date = new Date(value); date.setMinutes(date.getMinutes() + minutes); return `${toISODate(date)}T${minutesToTime(date.getHours() * 60 + date.getMinutes())}:00`; }
  function cloneLesson(item) { return Object.assign({}, item, { appendizies: item.appendizies.slice(), filter_data: Object.assign({}, item.filter_data || {}) }); }
  function getMonday(date) { const value = new Date(date.getFullYear(), date.getMonth(), date.getDate()); const day = value.getDay() || 7; value.setDate(value.getDate() - day + 1); return value; }
  function addDays(date, count) { const value = new Date(date.getFullYear(), date.getMonth(), date.getDate()); value.setDate(value.getDate() + count); return value; }
  function dayDifference(a, b) { return Math.round((Date.UTC(b.getFullYear(), b.getMonth(), b.getDate()) - Date.UTC(a.getFullYear(), a.getMonth(), a.getDate())) / 86400000); }
  function parseLocalDate(value) { const parts = String(value).split('-').map(Number); return new Date(parts[0], parts[1] - 1, parts[2]); }
  function toISODate(date) { return [date.getFullYear(), pad(date.getMonth() + 1), pad(date.getDate())].join('-'); }
  function formatDate(date) { return new Intl.DateTimeFormat('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' }).format(date); }
  function weekday(date) { return new Intl.DateTimeFormat('de-DE', { weekday: 'short' }).format(date); }
  function timeToMinutes(value) { const parts = String(value || '00:00').split(':').map(Number); return parts[0] * 60 + parts[1]; }
  function minutesToTime(value) { const normalized = Math.max(0, Math.min(value, 24 * 60)); return `${pad(Math.floor(normalized / 60))}:${pad(normalized % 60)}`; }
  function pad(value) { return String(value).padStart(2, '0'); }
  function escapeHTML(value) { return String(value).replace(/[&<>'"]/g, function (char) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' })[char]; }); }

  global.TinyCalendar = {
    render: function (filterObject) { render(filterObject === undefined ? null : filterObject); return this; },
    getFilter: function () { return state.filterObject === null ? null : JSON.parse(JSON.stringify(state.filterObject)); },
    getConfig: function () { return JSON.parse(JSON.stringify(CONFIG)); },
    hasRight: hasRight,
    getLessons: function () { return hasRight('lesson_read') ? state.lessons.map(cloneLesson) : []; },
    setLessons: function (lessons) {
      const normalized = (Array.isArray(lessons) ? lessons : []).map(normalizeLesson);
      const error = normalized.map(validateLesson).find(Boolean); if (error) throw new TypeError(error);
      const ids = normalized.map(function (item) { return item.id; });
      if (new Set(ids).size !== ids.length) throw new TypeError('Termin-IDs müssen eindeutig sein.');
      state.lessons = normalized; render();
    },
    getCompletionEvents: function () { return state.completionEvents.map(function (item) { return Object.assign({}, item); }); },
    clearCompletionEvents: function () { state.completionEvents = []; persistCompletionEvents(); },
    getBlockedSlots: function () { const keys=[]; state.blockedRanges.forEach(function (b) { let cur=b.start; while(cur<b.end){keys.push(cur.slice(0,16));cur=addMinutesToDatetime(cur,SLOT_MINUTES);} }); return keys; },
    getBlockedRanges: function () { return state.blockedRanges.map(function (b) { return Object.assign({},b); }); },
    getBlockCategories: function () { return state.blockCategories.map(function (c) { return Object.assign({},c); }); },
    toggleBlockedSlot: toggleBlockedSlot,
    moveLesson: function (id, date, start) { moveLessonToSlot(Number(id), date, normalizeHalfHour(start)); },
    onMoveCompleted: null, openSlot: openCreateDialog,
    config: CONFIG,
    constants: { SLOT_MINUTES, HOUR_START, HOUR_END, SNAP_RADIUS_PX },
    schema: { id: 'int', to_role: 'int', to_tutor: 'int', to_student: 'int', start: 'datetime', end: 'datetime', activ: 'boolean', thema: 'text', description: 'text', location: 'int', location_name: 'text', category_id: 'int', category_name: 'text', event_status: 'text', appendizies: 'string[]', filter_data: 'object' }
  };
})(window);
