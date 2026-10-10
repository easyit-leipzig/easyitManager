<?php
declare(strict_types=1);

require_once __DIR__.'/DataFormEventDomains.php';

/**
 * Rendert den eigenständigen DS-/RecordSet-Eventhandler-Editor.
 * Der Editor ist absichtlich nicht Bestandteil des DataForm-Eventbereichs.
 */
final class DataFormRecordSetEventEditor
{
    public static function render(
        int $dataformId,
        string $recordsetKey,
        array $handlers
    ): string {
        $handlers = DataFormEventDomains::sanitizeRecordSetHandlers($handlers);

        $labels = [
            'before_current_change'=>'Vor Datensatzwechsel',
            'after_current_change'=>'Nach Datensatzwechsel',
            'before_field_change'=>'Vor Feldänderung',
            'after_field_change'=>'Nach Feldänderung',
            'before_new'=>'Vor Neu',
            'after_new'=>'Nach Neu',
            'before_validate'=>'Vor Validierung',
            'after_validate'=>'Nach Validierung',
            'before_save'=>'Vor Speichern',
            'after_save'=>'Nach Speichern',
            'before_insert'=>'Vor INSERT',
            'after_insert'=>'Nach INSERT',
            'before_update'=>'Vor UPDATE',
            'after_update'=>'Nach UPDATE',
            'before_delete'=>'Vor Löschen',
            'after_delete'=>'Nach Löschen',
        ];

        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');

        ob_start(); ?>
<section class="df-recordset-event-editor" id="ds-eventhandler"
         data-dataform-id="<?= $dataformId ?>"
         data-recordset-key="<?= $e($recordsetKey) ?>">
  <h3>DS-Ereignishandler</h3>
  <p class="muted">
    Dieser Bereich gehört zum RecordSet/Datensatz und nicht zum DataForm-Lifecycle.
    Übergabe-/Übernahmeobjekt aller Handler: <code>recordSet</code>.
  </p>

  <input type="hidden" name="recordset_key" value="<?= $e($recordsetKey) ?>">

  <div class="df-form-grid">
    <?php foreach ($labels as $event => $label): ?>
      <label class="full">
        <strong><?= $e($label) ?></strong>
        <textarea name="record_event_<?= $e($event) ?>" rows="3" spellcheck="false"><?= $e((string)($handlers[$event] ?? '')) ?></textarea>
        <?php if (str_starts_with($event, 'before_')): ?>
          <small>
            <code>return false;</code> oder <code>recordSet.event.cancel=true;</code> bricht die Aktion ab.
            Änderungen an <code>recordSet.current.values</code> werden vor der Persistierung übernommen.
          </small>
        <?php else: ?>
          <small>Übergabeobjekt: <code>recordSet</code>.</small>
        <?php endif; ?>
      </label>
    <?php endforeach; ?>
  </div>

  <details class="full df-action-context-help">
    <summary>RecordSet 1.0 – Übergabe-/Übernahmeobjekt</summary>
    <pre>{ schema, action, project, dataform, current: { id, values, original_values, changes, dirty_fields }, fields, state, navigation, selection, parent, relation, validation, ui, event, result }</pre>
  </details>

  <button type="submit" name="save_recordset_events" value="1">
    DS-Ereignishandler speichern
  </button>
</section>
<?php
        return (string)ob_get_clean();
    }
}
