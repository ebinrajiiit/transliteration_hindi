/* Transliteration UI.
 *
 * Rules this file exists to enforce (CLAUDE.md section 5, UX rule):
 *  - the Hindi field is always editable;
 *  - alternate candidates are offered, never silently chosen;
 *  - once the user hand-edits the field, we stop auto-overwriting it;
 *  - a failed lookup leaves a usable form, never a blocked one.
 */
(function () {
  'use strict';

  var en      = document.getElementById('name_en');
  var hi      = document.getElementById('name_hi');
  var status  = document.getElementById('translit-status');
  var panel   = document.getElementById('candidates');
  var rows    = document.getElementById('candidate-rows');
  var warn    = document.getElementById('cand-warn');
  var retry   = document.getElementById('retry-btn');
  var form    = document.getElementById('person-form');

  if (!en || !hi) { return; }

  // Two distinct states:
  //   approved  - the field holds a value the user stands behind, so a new
  //               lookup must not overwrite it. Set by typing OR by picking a chip.
  //   handTyped - the user typed into the field themselves. Only this warrants
  //               the "clicking a suggestion will replace your text" warning;
  //               picking a chip is not hand-editing.
  var approved   = hi.value.trim() !== '';  // editing an existing row counts as approved
  var handTyped  = false;
  var tokens     = [];                      // [{en, word, chosen, candidates[]}]
  var picked     = [];                      // chosen candidate index per token
  var timer      = null;
  var seq        = 0;                       // guards against out-of-order responses
  var lastQuery  = '';

  function setStatus(text, kind) {
    status.textContent = text || '';
    status.className = 'status' + (kind ? ' ' + kind : '');
  }

  function joinPicked() {
    return tokens.map(function (t, i) {
      if (!t.word) { return t.en; }
      var c = t.candidates || [];
      if (!c.length) { return ''; }
      return c[Math.min(picked[i] || 0, c.length - 1)];
    }).join('');
  }

  function renderCandidates() {
    rows.innerHTML = '';
    var anyChoice = false;

    tokens.forEach(function (t, i) {
      if (!t.word || !t.candidates || t.candidates.length < 2) { return; }
      anyChoice = true;

      var row = document.createElement('div');
      row.className = 'cand-row';

      var label = document.createElement('span');
      label.className = 'cand-label';
      label.textContent = t.en;
      row.appendChild(label);

      t.candidates.forEach(function (cand, ci) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'chip devanagari' + ((picked[i] || 0) === ci ? ' chosen' : '');
        b.textContent = cand;
        b.addEventListener('click', function () {
          picked[i] = ci;
          hi.value = joinPicked();
          // An explicit click is the user approving this spelling, and it
          // supersedes whatever they had typed before.
          approved  = true;
          handTyped = false;
          setStatus('your choice — will be saved as shown', 'manual');
          renderCandidates();
        });
        row.appendChild(b);
      });

      rows.appendChild(row);
    });

    panel.hidden = !anyChoice;
    warn.hidden  = !(anyChoice && handTyped);
  }

  function lookup(text) {
    var mySeq = ++seq;
    setStatus('looking up…', 'busy');
    retry.hidden = true;

    var url = 'api/translit.php?text=' + encodeURIComponent(text);

    fetch(url, { headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (mySeq !== seq) { return; }   // a newer request already went out

        tokens = data.tokens || [];
        picked = tokens.map(function () { return 0; });

        if (data.offline) {
          setStatus('service unreachable — type it yourself', 'bad');
          retry.hidden = false;
          panel.hidden = true;
          return;
        }

        if (!data.ok) {
          setStatus(data.message || 'no result', 'bad');
          panel.hidden = true;
          return;
        }

        if (!approved) {
          hi.value = data.hindi;
        }

        setStatus(
          approved ? 'suggestions updated (your text kept)' : 'auto-filled — edit if needed',
          data.complete ? 'ok' : 'partial'
        );
        renderCandidates();
      })
      .catch(function () {
        if (mySeq !== seq) { return; }
        setStatus('service unreachable — type it yourself', 'bad');
        retry.hidden = false;
        panel.hidden = true;
      });
  }

  function schedule() {
    var text = en.value.trim();
    clearTimeout(timer);

    if (text === '') {
      setStatus('');
      panel.hidden = true;
      return;
    }
    if (text === lastQuery) { return; }

    timer = setTimeout(function () {
      lastQuery = text;
      lookup(text);
    }, 400);
  }

  en.addEventListener('input', schedule);
  en.addEventListener('blur', function () {
    clearTimeout(timer);
    var text = en.value.trim();
    if (text && text !== lastQuery) { lastQuery = text; lookup(text); }
  });

  hi.addEventListener('input', function () {
    approved  = true;
    handTyped = true;
    setStatus('your text — will be saved as typed', 'manual');
    warn.hidden = panel.hidden;
  });

  retry.addEventListener('click', function () {
    var text = en.value.trim();
    if (text) { lastQuery = text; lookup(text); }
  });

  // A pending lookup must never delay a submit.
  form.addEventListener('submit', function () { clearTimeout(timer); seq++; });
})();
