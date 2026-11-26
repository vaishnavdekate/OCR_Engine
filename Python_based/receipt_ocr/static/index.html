<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Read receipts locally. Extract totals, check the evidence, and export exact amounts.">
  <title>Receipt Lens · Receipt workspace</title>
  <link rel="stylesheet" href="/static/styles.css">
  <script src="/static/app.js" defer></script>
</head>
<body>
  <header class="topbar">
    <a class="brand" href="/" aria-label="Receipt Lens home"><span class="brand-mark">▤</span>Receipt<span class="brand-light">Lens</span></a>
    <span class="workspace-label">RECEIPT WORKSPACE</span>
    <span id="engine-status" class="engine-status">Connecting to engine…</span>
  </header>
  <main>
    <aside class="sidebar">
      <div class="sidebar-heading"><h2>Your receipts</h2><span id="count" class="count">0</span></div>
      <p class="sidebar-description">A clear view of every amount.</p>
      <button id="new-receipt" class="new-button" type="button"><span>＋</span> Upload receipt</button>
      <div id="receipt-list" class="receipt-list"></div>
      <div id="empty-list" class="empty-list">Your session starts here.<br>Upload a receipt to begin.</div>
      <div class="privacy-note"><span class="privacy-icon">⌑</span><div><strong>Stays on this computer</strong><p>Files are processed locally. Session results clear when you reload.</p></div></div>
    </aside>

    <section class="workspace">
      <div class="workspace-title"><div><div class="eyebrow">LESS TYPING. MORE CERTAINTY.</div><h1>Make sense of your receipts.</h1><p>Upload, extract, and check the details in one place.</p></div><a class="api-link" href="/docs" target="_blank" rel="noreferrer">API docs <span>↗</span></a></div>
      <section id="upload-panel" class="upload-panel" aria-labelledby="upload-title">
        <div id="dropzone" class="dropzone">
          <div class="upload-illustration" aria-hidden="true"><div class="paper"><i></i><i></i><i></i><div><i></i><b></b></div></div><span class="upload-plus">＋</span></div>
          <h2 id="upload-title">A receipt in. The details out.</h2>
          <p>Drop your file here, or choose one from your computer.</p>
          <button id="choose-file" type="button" class="primary">Choose a receipt</button>
          <input id="file-input" type="file" accept="image/jpeg,image/png,image/webp,image/tiff,image/avif,application/pdf" hidden>
          <div class="file-types">JPG, PNG, WebP, TIFF, AVIF, PDF <span>·</span> Up to 20 MiB / 5 pages</div>
        </div>
        <div class="upload-options"><label for="currency-hint">Receipt currency <span>(optional)</span></label><select id="currency-hint"><option value="">Detect automatically</option><option>USD</option><option>CHF</option><option>EUR</option><option>INR</option><option>GBP</option><option>CAD</option><option>AUD</option><option>NZD</option><option>JPY</option><option>CNY</option><option>AED</option></select><span class="option-help">Useful when the receipt only shows a symbol.</span></div>
        <div id="token-panel" class="token-panel" hidden><label for="api-token">API token</label><input id="api-token" type="password" autocomplete="off" placeholder="Enter your server's API token"></div>
      </section>
      <div id="message" class="message" role="status" aria-live="polite" hidden></div>

      <section id="results" hidden>
        <div class="results-toolbar"><div><h2 id="document-name">Receipt</h2><div id="document-meta" class="muted"></div></div><div class="export-buttons"><button id="export-csv" class="secondary" type="button">Export CSV</button><button id="export-json" class="secondary" type="button">Export JSON</button></div></div>
        <div id="page-tabs" class="page-tabs" role="tablist" aria-label="Receipt pages"></div>
        <div class="result-grid">
          <section class="viewer-card"><div class="card-heading"><h3>Original evidence</h3><span id="rotation-note" class="muted"></span></div><div class="viewer"><div class="image-wrap"><img id="receipt-image" alt="Uploaded receipt, oriented for reading"><div id="evidence-box" class="evidence-box" hidden></div></div></div><div class="viewer-footer">Select an amount to locate its source row.</div></section>
          <section class="extraction-card">
            <div class="card-heading"><h3>Extracted details</h3><span id="result-status" class="status-badge"></span></div>
            <div class="total-panel"><div class="total-label">RECEIPT TOTAL</div><div class="total-number"><span id="total-currency"></span><strong id="total-amount">—</strong></div><div id="confidence" class="confidence"></div></div>
            <div id="warnings" class="warnings" hidden></div>
            <dl id="details" class="details"></dl>
            <div id="checks" class="checks"></div>
            <form id="review-form" class="review-form"><h4>Check & confirm</h4><p>Confirm the amount against the receipt before using it.</p><div class="review-fields"><label>Amount<input id="review-amount" inputmode="decimal" pattern="-?[0-9]+(\.[0-9]{1,2})?" required placeholder="0.00"></label><label>Currency<input id="review-currency" maxlength="3" pattern="[A-Za-z]{3}" required placeholder="USD" autocomplete="off"></label></div><button type="submit" class="confirm-button">Confirm amount</button><p id="review-feedback" class="review-feedback" role="status"></p></form>
          </section>
        </div>
        <section class="evidence-card"><div class="card-heading"><h3>Amount evidence</h3><span class="muted">Exact values · source text included</span></div><div class="table-scroll"><table><thead><tr><th scope="col">Amount</th><th scope="col">Type</th><th scope="col">Source row</th><th scope="col">OCR passes</th></tr></thead><tbody id="candidate-rows"></tbody></table></div></section>
        <details id="line-items-panel" class="text-panel"><summary>Line items</summary><div id="line-items"></div></details>
        <details class="text-panel"><summary>Full recognized text</summary><pre id="raw-text"></pre></details>
      </section>

      <section id="intro-cards" class="intro-cards"><div><span class="feature-icon">01</span><h3>Read the whole receipt</h3><p>Multiple orientations and image passes help recover small or uneven print.</p></div><div><span class="feature-icon">02</span><h3>Find the right amount</h3><p>Totals, tax, payment, and currency equivalents are shown separately.</p></div><div><span class="feature-icon">03</span><h3>Keep the evidence</h3><p>Inspect the source, confirm the amount, and take your data with you.</p></div></section>
      <footer>Receipt Lens <span>·</span> Local processing. Clear evidence. <span class="footer-right">Powered by open-source OCR</span></footer>
    </section>
  </main>
</body>
</html>
