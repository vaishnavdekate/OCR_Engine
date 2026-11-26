'use strict';
const $ = id => document.getElementById(id);
const documents = [];
let active = -1;
let pageIndex = 0;
let busy = false;
let ticker;

const el = (tag, text, className) => {
  const node = document.createElement(tag);
  if (text !== undefined) node.textContent = text;
  if (className) node.className = className;
  return node;
};

function message(text, error = false) {
  $('message').textContent = text;
  $('message').className = error ? 'message error' : 'message';
  $('message').hidden = !text;
}

async function checkHealth() {
  try {
    const response = await fetch('/api/health');
    if (!response.ok) throw new Error('unavailable');
    const health = await response.json();
    $('engine-status').textContent = 'Local engine ready';
    $('token-panel').hidden = !health.authentication_required;
  } catch {
    $('engine-status').textContent = 'Engine unavailable';
    message('Cannot connect to the local engine. Start the server and reload this page.', true);
  }
}

function setBusy(value) {
  busy = value;
  $('choose-file').disabled = value;
  $('new-receipt').disabled = value;
  $('file-input').disabled = value;
  $('currency-hint').disabled = value;
  $('engine-status').textContent = value ? 'Reading receipt…' : 'Local engine ready';
}

async function upload(file) {
  if (!file || busy) return;
  if (file.size > 20 * 1024 * 1024) {
    message('This file exceeds 20 MiB. Choose a smaller image or PDF.', true);
    return;
  }
  setBusy(true);
  const start = Date.now();
  const update = () => message(`Reading ${file.name} · ${Math.floor((Date.now() - start) / 1000)}s. Checking orientations and image passes…`);
  update();
  ticker = setInterval(update, 1000);
  try {
    const form = new FormData();
    form.append('file', file);
    if ($('currency-hint').value) form.append('currency', $('currency-hint').value);
    const headers = {};
    if ($('api-token').value) headers.Authorization = `Bearer ${$('api-token').value}`;
    const response = await fetch('/api/receipts', {method: 'POST', body: form, headers});
    const result = await response.json();
    if (!response.ok) throw new Error(typeof result.detail === 'string' ? result.detail : `Upload failed (${response.status}).`);
    documents.push({result, reviews: {}});
    active = documents.length - 1;
    pageIndex = 0;
    message('');
    render();
  } catch (error) {
    message(error.message || 'The receipt could not be read. Please try again.', true);
  } finally {
    clearInterval(ticker);
    setBusy(false);
    $('file-input').value = '';
  }
}

function money(value) {
  return value ? `${value.currency || 'Unknown currency'} ${value.amount}` : 'Not found';
}

function showEvidence(candidate) {
  const page = documents[active].result.pages[pageIndex];
  const [x0, y0, x1, y1] = candidate.box;
  const box = $('evidence-box');
  box.style.left = `${100 * x0 / page.width}%`;
  box.style.top = `${100 * y0 / page.height}%`;
  box.style.width = `${100 * (x1 - x0) / page.width}%`;
  box.style.height = `${100 * (y1 - y0) / page.height}%`;
  box.hidden = false;
  box.scrollIntoView({block: 'center', behavior: 'auto'});
}

function render() {
  $('count').textContent = String(documents.length);
  $('empty-list').hidden = documents.length > 0;
  $('receipt-list').replaceChildren();
  documents.forEach((doc, i) => {
    const button = el('button', undefined, `receipt-entry${active === i ? ' active' : ''}`);
    button.type = 'button';
    button.setAttribute('aria-pressed', String(active === i));
    button.append(el('strong', doc.result.filename), el('small', `${doc.result.pages.length} page${doc.result.pages.length > 1 ? 's' : ''} · ${money(doc.result.pages[0].total)}`));
    button.addEventListener('click', () => {active = i; pageIndex = 0; render();});
    $('receipt-list').append(button);
  });
  if (active < 0) return;
  $('intro-cards').hidden = true;
  $('results').hidden = false;
  const doc = documents[active];
  const result = doc.result;
  const page = result.pages[pageIndex];
  const review = doc.reviews[pageIndex];
  $('document-name').textContent = result.filename;
  $('document-meta').textContent = `${result.pages.length} page${result.pages.length > 1 ? 's' : ''} · ${(result.processing_ms / 1000).toFixed(1)}s · ${page.passes} OCR passes per page`;
  $('page-tabs').replaceChildren();
  result.pages.forEach((_, i) => {
    const tab = el('button', `Page ${i + 1}`);
    tab.type = 'button';
    tab.setAttribute('role', 'tab');
    tab.setAttribute('aria-selected', String(i === pageIndex));
    tab.addEventListener('click', () => {pageIndex = i; render();});
    $('page-tabs').append(tab);
  });
  $('page-tabs').hidden = result.pages.length === 1;
  $('receipt-image').src = page.preview || '';
  $('receipt-image').hidden = !page.preview;
  $('evidence-box').hidden = true;
  $('rotation-note').textContent = page.rotation ? `Oriented ${page.rotation}° counterclockwise` : 'As uploaded';
  $('result-status').textContent = review ? 'Reviewed' : page.needs_review ? 'Check required' : 'Ready to check';
  $('result-status').className = `status-badge${!review && page.needs_review ? ' review' : ''}`;
  $('total-currency').textContent = page.total?.currency || '';
  $('total-amount').textContent = page.total?.amount || '—';
  $('confidence').textContent = page.total ? `${Math.round(page.confidence * 100)} / 100 evidence score · heuristic, not a probability` : 'No reliable total found. Review the image and candidates.';
  $('warnings').replaceChildren(...page.warnings.map(w => el('p', w)));
  $('warnings').hidden = !page.warnings.length;
  $('details').replaceChildren();
  for (const [label, value] of [['Merchant', page.merchant || 'Not found'], ['Subtotal', money(page.subtotal)], ['Tax', money(page.tax)], ['Tip', money(page.tip)]]) {
    $('details').append(el('dt', label), el('dd', value));
  }
  const checks = [];
  if (page.checks.preprocessing_consensus === 'matched') checks.push('✓ Total agrees across both image passes');
  if (page.checks.line_items_match_total === 'true') checks.push(`✓ Line items add up to ${page.checks.line_item_sum}`);
  if (page.checks.arithmetic === 'matched') checks.push('✓ Subtotal, tax, and tip reconcile');
  $('checks').replaceChildren(...checks.map(c => el('div', c)));
  $('review-amount').value = review?.amount || page.total?.amount || '';
  $('review-currency').value = review?.currency || page.total?.currency || '';
  $('review-feedback').textContent = review ? `Confirmed as ${money(review)}. Included separately in your exports.` : '';
  $('candidate-rows').replaceChildren();
  for (const candidate of page.candidates) {
    const row = el('tr');
    const amountCell = el('td');
    const button = el('button', money(candidate));
    button.type = 'button';
    button.title = candidate.reasons.join(' ');
    button.addEventListener('click', () => showEvidence(candidate));
    amountCell.append(button);
    row.append(amountCell, el('td', candidate.role.replaceAll('_', ' ')), el('td', candidate.source_text), el('td', `${candidate.observations} / 2`));
    $('candidate-rows').append(row);
  }
  if (!page.candidates.length) {
    const row = el('tr'); const cell = el('td', 'No monetary amounts were readable. Try a clearer image.'); cell.colSpan = 4; row.append(cell); $('candidate-rows').append(row);
  }
  $('raw-text').textContent = page.raw_text || 'No text found.';
  $('line-items').replaceChildren(...page.line_items.map(item => el('div', `${item.quantity ? item.quantity + ' × ' : ''}${item.description} · ${item.currency || ''} ${item.amount}`)));
  if (!page.line_items.length) $('line-items').append(el('p', 'No line items were confidently identified.'));
}

function download(content, type, extension) {
  const url = URL.createObjectURL(new Blob([content], {type}));
  const link = el('a'); link.href = url;
  link.download = documents[active].result.filename.replace(/\.[^.]+$/, '') + extension;
  link.click(); setTimeout(() => URL.revokeObjectURL(url), 1000);
}

$('export-json').addEventListener('click', () => {
  const doc = documents[active];
  // Preview images are omitted from portable exports; original OCR and reviews stay distinct.
  const exported = {...doc.result, pages: doc.result.pages.map(({preview, ...page}) => page), reviews: doc.reviews};
  download(JSON.stringify(exported, null, 2), 'application/json', '.json');
});
$('export-csv').addEventListener('click', () => {
  const doc = documents[active];
  const rows = [['filename', 'page', 'ocr_total', 'ocr_currency', 'reviewed_total', 'reviewed_currency', 'reviewed_at', 'needs_review']];
  doc.result.pages.forEach((page, i) => {
    const review = doc.reviews[i];
    rows.push([doc.result.filename, page.page, page.total?.amount || '', page.total?.currency || '', review?.amount || '', review?.currency || '', review?.reviewed_at || '', review ? false : page.needs_review]);
  });
  const csv = rows.map(row => row.map(value => {
    let text = String(value);
    if (/^[=+@\t\r\n]/.test(text) || (/^-/.test(text) && !/^-\d+(\.\d+)?$/.test(text))) text = "'" + text;
    return '"' + text.replaceAll('"', '""') + '"';
  }).join(',')).join('\r\n');
  download(csv, 'text/csv;charset=utf-8', '.csv');
});
$('review-form').addEventListener('submit', event => {
  event.preventDefault();
  const value = $('review-amount').value.trim();
  const currency = $('review-currency').value.trim().toUpperCase();
  if (!/^-?\d+(\.\d{1,2})?$/.test(value) || !/^[A-Z]{3}$/.test(currency)) return;
  // Normalize cents as strings, without a floating-point conversion.
  const [whole, fraction = ''] = value.split('.');
  const amount = whole + '.' + fraction.padEnd(2, '0');
  documents[active].reviews[pageIndex] = {amount, currency, reviewed_at: new Date().toISOString()};
  render();
});
for (const id of ['choose-file', 'new-receipt']) $(id).addEventListener('click', () => $('file-input').click());
$('file-input').addEventListener('change', event => upload(event.target.files[0]));
for (const type of ['dragenter', 'dragover']) $('dropzone').addEventListener(type, event => {event.preventDefault(); $('dropzone').classList.add('dragging');});
for (const type of ['dragleave', 'drop']) $('dropzone').addEventListener(type, event => {event.preventDefault(); $('dropzone').classList.remove('dragging');});
$('dropzone').addEventListener('drop', event => upload(event.dataTransfer.files[0]));
checkHealth();
