(() => {
  'use strict';
  const storageKey = 'easyit-enterprise-rc102-setup-progress';
  const steps = Array.from(document.querySelectorAll('.step[data-step]'));
  const progressText = document.getElementById('progressText');
  const progressBar = document.getElementById('progressBar');
  const progressTrack = document.querySelector('.progress-track');
  const resetButton = document.getElementById('resetProgress');

  let completed = [];
  try {
    const parsed = JSON.parse(localStorage.getItem(storageKey) || '[]');
    completed = Array.isArray(parsed) ? parsed.map(String) : [];
  } catch (_) {
    completed = [];
  }

  const save = () => localStorage.setItem(storageKey, JSON.stringify(completed));
  const render = () => {
    steps.forEach((step) => {
      const id = String(step.dataset.step);
      const done = completed.includes(id);
      const button = step.querySelector('.step-toggle');
      step.classList.toggle('completed', done);
      if (button) {
        button.setAttribute('aria-pressed', done ? 'true' : 'false');
        button.textContent = done ? '✓ Erledigt' : 'Als erledigt markieren';
      }
    });
    const count = completed.length;
    const total = steps.length;
    const percent = total ? (count / total) * 100 : 0;
    progressText.textContent = `${count} von ${total} Schritten erledigt`;
    progressBar.style.width = `${percent}%`;
    progressTrack.setAttribute('aria-valuenow', String(count));
  };

  steps.forEach((step) => {
    const button = step.querySelector('.step-toggle');
    if (!button) return;
    button.addEventListener('click', () => {
      const id = String(step.dataset.step);
      completed = completed.includes(id) ? completed.filter((item) => item !== id) : [...completed, id];
      save();
      render();
    });
  });

  resetButton?.addEventListener('click', () => {
    if (!window.confirm('Möchten Sie den gespeicherten Setup-Fortschritt wirklich zurücksetzen?')) return;
    completed = [];
    save();
    render();
  });

  render();
})();
