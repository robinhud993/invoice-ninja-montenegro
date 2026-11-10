<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Montenegro EFI fiscalization test console</title>
<style>
  :root {
    --bg: #0f1720;
    --panel: #17222e;
    --panel-2: #1e2b39;
    --line: #2b3b4c;
    --text: #e6edf3;
    --muted: #9db0c3;
    --accent: #2f9e6b;
    --accent-2: #3aa0ff;
    --danger: #d9534f;
    --warn: #d99a2b;
    --ok: #2f9e6b;
    --mono: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
  }
  * { box-sizing: border-box; }
  body { margin: 0; background: var(--bg); color: var(--text); font: 14px/1.5 system-ui, -apple-system, Segoe UI, Roboto, sans-serif; }
  header { padding: 18px 22px; border-bottom: 1px solid var(--line); display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
  header h1 { font-size: 17px; margin: 0; font-weight: 600; }
  .badge { display: inline-block; padding: 2px 9px; border-radius: 999px; font-size: 12px; font-weight: 600; border: 1px solid var(--line); }
  .badge.test { background: #12303f; color: #6fd0ff; border-color: #1f5f7a; }
  .badge.prod { background: #3a1414; color: #ff9b9b; border-color: #7a2a2a; }
  .badge.ok { background: #123024; color: #6fe0a8; border-color: #1f6a4a; }
  .badge.bad { background: #3a1414; color: #ff9b9b; border-color: #7a2a2a; }
  main { padding: 18px 22px 60px; display: grid; gap: 16px; max-width: 1180px; }
  section.card { background: var(--panel); border: 1px solid var(--line); border-radius: 10px; overflow: hidden; }
  section.card > h2 { font-size: 14px; margin: 0; padding: 12px 16px; background: var(--panel-2); border-bottom: 1px solid var(--line); font-weight: 600; letter-spacing: .02em; }
  .body { padding: 16px; }
  .grid { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
  label { display: block; font-size: 12px; color: var(--muted); margin-bottom: 4px; }
  input, select, textarea { width: 100%; padding: 8px 10px; background: #0d151d; color: var(--text); border: 1px solid var(--line); border-radius: 7px; font-size: 13px; }
  input[type=checkbox] { width: auto; }
  input:focus, select:focus { outline: none; border-color: var(--accent-2); }
  .row { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-top: 12px; }
  button { padding: 8px 14px; border-radius: 7px; border: 1px solid var(--line); background: #22303f; color: var(--text); font-size: 13px; cursor: pointer; }
  button:hover { border-color: var(--accent-2); }
  button.primary { background: var(--accent); border-color: var(--accent); color: #04140c; font-weight: 600; }
  button.warn { background: #3a2c11; border-color: var(--warn); color: #ffd591; }
  button.danger { background: #3a1414; border-color: #7a2a2a; color: #ffb3b3; }
  button:disabled { opacity: .5; cursor: not-allowed; }
  .check { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--text); }
  .check input { width: auto; }
  .muted { color: var(--muted); }
  .small { font-size: 12px; }
  .mono { font-family: var(--mono); }
  pre { background: #0b131b; border: 1px solid var(--line); border-radius: 8px; padding: 12px; overflow: auto; max-height: 320px; font-family: var(--mono); font-size: 12px; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  th, td { text-align: left; padding: 7px 9px; border-bottom: 1px solid var(--line); vertical-align: top; }
  th { color: var(--muted); font-weight: 600; font-size: 12px; }
  code { font-family: var(--mono); background: #0b131b; padding: 1px 5px; border-radius: 4px; }
  .kv { display: grid; grid-template-columns: 220px 1fr; gap: 6px 14px; font-size: 13px; }
  .kv div:nth-child(odd) { color: var(--muted); }
  .statusline { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
  .pill { padding: 3px 9px; border-radius: 7px; background: #0b131b; border: 1px solid var(--line); font-size: 12px; }
  .warnbox { border: 1px solid var(--warn); background: #2a2110; color: #ffd591; padding: 10px 12px; border-radius: 8px; margin-bottom: 12px; }
  .errbox { border: 1px solid #7a2a2a; background: #2a1010; color: #ffb3b3; padding: 8px 10px; border-radius: 8px; margin-top: 10px; white-space: pre-wrap; }
  .okbox { border: 1px solid #1f6a4a; background: #0f2a1f; color: #a8f0cd; padding: 8px 10px; border-radius: 8px; margin-top: 10px; white-space: pre-wrap; }
  a { color: #7ec8ff; }
  .log li { font-family: var(--mono); font-size: 12px; padding: 6px 0; border-bottom: 1px solid var(--line); list-style: none; }
  .log { margin: 0; padding: 0; max-height: 260px; overflow: auto; }
  .ok { color: #8ee6b4; }
  .bad { color: #ff9b9b; }
</style>
</head>
<body>
<header>
  <h1>Montenegro EFI fiscalization test console</h1>
  <span id="envBadge" class="badge test">environment unknown</span>
  <span id="connBadge" class="badge">not connected</span>
  <span class="muted small">Diagnostic page. Remove or restrict it before production use.</span>
</header>

<main>

  <div id="prodWarn" class="warnbox" style="display:none;">
    This company is in <strong>PRODUCTION</strong> mode. Registration, fiscalization and cash deposits will hit the live CIS
    (<span class="mono">efi.tax.gov.me</span>) and create real fiscal documents. Switch to test mode before experimenting.
  </div>

  <section class="card">
    <h2>1. Connection</h2>
    <div class="body">
      <div class="grid">
        <div>
          <label for="baseUrl">API base URL</label>
          <input id="baseUrl" placeholder="http://localhost:9080" autocomplete="off">
        </div>
        <div>
          <label for="apiToken">X-API-TOKEN (company token)</label>
          <input id="apiToken" placeholder="paste the company API token" autocomplete="off">
        </div>
        <div>
          <label for="apiSecret">X-API-SECRET (only if API_SECRET is configured)</label>
          <input id="apiSecret" placeholder="leave empty when API_SECRET is not set" autocomplete="off">
        </div>
      </div>
      <div class="row">
        <button class="primary" onclick="testConnection()">Save and test connection</button>
        <button onclick="clearConnection()">Clear stored values</button>
        <span class="muted small">The token is stored in this browser only (localStorage). Find it in the admin under Settings, API Tokens.</span>
      </div>
      <div id="statusResult"></div>
    </div>
  </section>

  <section class="card">
    <h2>2. Certificate and company settings</h2>
    <div class="body">
      <p class="muted small">
        The certificate is validated locally before it is stored. PKCS#12 (.p12, .pfx) and PEM bundles are accepted, up to 5 MB.
        Enabling the standard sets the company e-invoice type to MONTENEGRO.
      </p>
      <div class="grid">
        <div>
          <label for="certFile">Certificate file</label>
          <input id="certFile" type="file" accept=".p12,.pfx,.pem,.cer,.crt,.der,.bin,.txt">
        </div>
        <div>
          <label for="certPass">Certificate passphrase</label>
          <input id="certPass" type="password" placeholder="passphrase" autocomplete="off">
        </div>
        <div>
          <label for="tin">TIN (PIB 8 digits or JMB 13 digits)</label>
          <input id="tin" placeholder="12345678" autocomplete="off">
        </div>
        <div>
          <label for="tcrCode">TCR / ENU code</label>
          <input id="tcrCode" placeholder="ab123cd456" autocomplete="off">
        </div>
        <div>
          <label for="businessUnitCode">Business unit code</label>
          <input id="businessUnitCode" placeholder="ab123cd456" autocomplete="off">
        </div>
        <div>
          <label for="operatorCode">Operator code</label>
          <input id="operatorCode" placeholder="ab123cd456" autocomplete="off">
        </div>
        <div>
          <label for="softwareCode">Software code</label>
          <input id="softwareCode" placeholder="ab123cd456" autocomplete="off">
        </div>
        <div>
          <label for="maintainerCode">Maintainer code (optional)</label>
          <input id="maintainerCode" placeholder="" autocomplete="off">
        </div>
        <div>
          <label for="invoiceType">Default invoice type</label>
          <select id="invoiceType">
            <option value="NONCASH">NONCASH</option>
            <option value="CASH">CASH</option>
          </select>
        </div>
        <div>
          <label for="paymentMethod">Default payment method</label>
          <select id="paymentMethod">
            <option>BANKNOTE</option><option>CARD</option><option>CHECK</option><option>SVOUCHER</option>
            <option>COMPANY</option><option>ORDER</option><option>ADVANCE</option><option selected>ACCOUNT</option>
            <option>FACTORING</option><option>COMPENSATION</option><option>TRANSFER</option><option>WAIVER</option>
            <option>KIND</option><option>OTHER</option>
          </select>
        </div>
        <div>
          <label for="advanceMarker">Advance marker (custom_value1)</label>
          <input id="advanceMarker" value="ADVANCE" autocomplete="off">
        </div>
      </div>
      <div class="grid" style="margin-top:12px;">
        <label class="check"><input id="testMode" type="checkbox" checked> Test mode (efitest)</label>
        <label class="check"><input id="isInVat" type="checkbox"> In the VAT system</label>
        <label class="check"><input id="autoFiscalize" type="checkbox" checked> Auto fiscalize on send</label>
        <label class="check"><input id="requireBuyerTin" type="checkbox" checked> Require buyer TIN for non-cash</label>
        <label class="check"><input id="simplifiedInvoice" type="checkbox"> Simplified invoice</label>
        <label class="check"><input id="reverseCharge" type="checkbox"> Reverse charge</label>
        <label class="check"><input id="enableStandard" type="checkbox" checked> Enable the Montenegro standard</label>
      </div>
      <div class="row">
        <button class="primary" onclick="saveSettings(false)">Save settings</button>
        <button class="primary" onclick="saveSettings(true)">Upload certificate and save</button>
        <button class="warn" onclick="removeCertificate()">Remove certificate</button>
        <button class="danger" onclick="disconnectStandard()">Disconnect standard</button>
        <button onclick="refreshStatus()">Refresh status</button>
      </div>
      <div id="settingsResult"></div>
    </div>
  </section>

  <section class="card">
    <h2>3. ENU / TCR registration (register with the Tax Administration)</h2>
    <div class="body">
      <p class="muted small">
        The CIS exposes <code>registerTCR</code> only. It does not provide a query or list operation, so an ENU code is obtained
        either by registering the device here, or by entering the code printed by the PU portal. Registered devices are listed
        below from the local registry.
      </p>
      <div class="grid">
        <div>
          <label for="tcrInternalId">TCR internal id (required)</label>
          <input id="tcrInternalId" value="1" autocomplete="off">
        </div>
        <div>
          <label for="regBusinessUnit">Business unit code</label>
          <input id="regBusinessUnit" placeholder="defaults to the configured business unit" autocomplete="off">
        </div>
        <div>
          <label for="regSoftware">Software code</label>
          <input id="regSoftware" placeholder="defaults to the configured software code" autocomplete="off">
        </div>
        <div>
          <label for="regMaintainer">Maintainer code (optional)</label>
          <input id="regMaintainer" autocomplete="off">
        </div>
        <div>
          <label for="regValidFrom">Valid from</label>
          <input id="regValidFrom" type="date">
        </div>
        <div>
          <label for="regValidTo">Valid to (optional)</label>
          <input id="regValidTo" type="date">
        </div>
        <div>
          <label for="regType">Device type</label>
          <select id="regType">
            <option value="REGULAR">REGULAR</option>
            <option value="VENDING">VENDING</option>
          </select>
        </div>
      </div>
      <div class="row">
        <button class="primary" onclick="registerTcr()">Register device and get the ENU code</button>
        <button onclick="loadEnus()">Load registered ENUs</button>
      </div>
      <div id="tcrResult"></div>
      <div id="enuTable" style="margin-top:14px;"></div>
    </div>
  </section>

  <section class="card">
    <h2>4. Cash deposit / withdrawal</h2>
    <div class="body">
      <div class="grid">
        <div>
          <label for="depositOperation">Operation</label>
          <select id="depositOperation">
            <option value="INITIAL">INITIAL</option>
            <option value="WITHDRAW">WITHDRAW</option>
          </select>
        </div>
        <div>
          <label for="depositAmount">Cash amount</label>
          <input id="depositAmount" type="number" step="0.01" value="0">
        </div>
        <div>
          <label for="depositTcr">TCR code (optional)</label>
          <input id="depositTcr" placeholder="defaults to the configured TCR" autocomplete="off">
        </div>
        <div>
          <label for="depositDate">Change datetime (optional)</label>
          <input id="depositDate" type="datetime-local">
        </div>
      </div>
      <div class="row">
        <button class="primary" onclick="cashDeposit()">Send cash deposit</button>
      </div>
      <div id="depositResult"></div>
    </div>
  </section>

  <section class="card">
    <h2>5. Fiscalize an invoice</h2>
    <div class="body">
      <div class="grid">
        <div>
          <label for="invoiceSelect">Issued invoices</label>
          <select id="invoiceSelect"><option value="">load invoices first</option></select>
        </div>
        <div>
          <label for="invoiceId">Or paste an invoice hashed id</label>
          <input id="invoiceId" placeholder="hashed invoice id" autocomplete="off">
        </div>
        <div>
          <label for="fiscalKind">Document kind</label>
          <select id="fiscalKind">
            <option value="create">create</option>
            <option value="advance">advance</option>
            <option value="corrective">corrective</option>
            <option value="credit">credit</option>
            <option value="debit">debit</option>
            <option value="baddebt">baddebt</option>
          </select>
        </div>
      </div>
      <div class="row">
        <button onclick="loadInvoices()">Load issued invoices</button>
        <button class="primary" onclick="fiscalizeInvoice()">Fiscalize</button>
        <button onclick="loadLogs()">Load fiscal journal</button>
        <button onclick="pollLatest()">Poll latest log</button>
      </div>
      <div id="fiscalResult"></div>

      <div class="grid" style="margin-top:16px;">
        <div>
          <label for="enuInvoiceId">Assign an ENU to an invoice (hashed id)</label>
          <input id="enuInvoiceId" autocomplete="off">
        </div>
        <div>
          <label for="enuId">ENU hashed id</label>
          <input id="enuId" autocomplete="off">
        </div>
        <div>
          <label for="advanceFinalId">Link advance: final invoice hashed id</label>
          <input id="advanceFinalId" autocomplete="off">
        </div>
        <div>
          <label for="advanceId">Link advance: advance invoice hashed id</label>
          <input id="advanceId" autocomplete="off">
        </div>
      </div>
      <div class="row">
        <button onclick="setEnu()">Assign ENU</button>
        <button onclick="linkAdvance()">Link advance</button>
      </div>
      <div id="actionResult"></div>
    </div>
  </section>

  <section class="card">
    <h2>6. Fiscal journal</h2>
    <div class="body">
      <div id="logTable" class="muted small">Load the fiscal journal to see IIC, JIKR, status and the verification link.</div>
    </div>
  </section>

  <section class="card">
    <h2>7. Report</h2>
    <div class="body">
      <div class="grid">
        <div>
          <label for="reportFrom">Date from</label>
          <input id="reportFrom" type="date">
        </div>
        <div>
          <label for="reportTo">Date to</label>
          <input id="reportTo" type="date">
        </div>
        <div>
          <label for="reportGroup">Group by</label>
          <select id="reportGroup">
            <option value="">none</option>
            <option value="day">day</option>
            <option value="month">month</option>
          </select>
        </div>
      </div>
      <div class="row">
        <button class="primary" onclick="loadReport()">Load report</button>
      </div>
      <div id="reportResult"></div>
    </div>
  </section>

  <section class="card">
    <h2>Activity log</h2>
    <div class="body">
      <ul id="activityLog" class="log"></ul>
      <div class="row"><button onclick="document.getElementById('activityLog').innerHTML='';">Clear log</button></div>
    </div>
  </section>

</main>

<script>
(function () {
  'use strict';

  var VERIFY_TEST = 'https://efitest.tax.gov.me/ic/#/verify';
  var VERIFY_PROD = 'https://efi.tax.gov.me/ic/#/verify';

  var state = {
    base: localStorage.getItem('me_base') || window.location.origin,
    token: localStorage.getItem('me_token') || '',
    secret: localStorage.getItem('me_secret') || '',
    testMode: true,
    logs: [],
    enus: []
  };

  function $(id) { return document.getElementById(id); }
  function esc(value) {
    if (value === null || value === undefined) return '';
    return String(value).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function addLog(title, payload, ok) {
    var cls = ok === undefined ? '' : (ok ? 'ok' : 'bad');
    var line = document.createElement('li');
    var stamp = new Date().toLocaleTimeString();
    line.innerHTML = '<span class="muted">' + esc(stamp) + '</span> <span class="' + cls + '">' + esc(title) + '</span>' +
      (payload !== null && payload !== undefined ? '<pre>' + esc(typeof payload === 'string' ? payload : JSON.stringify(payload, null, 2)) + '</pre>' : '');
    var ul = $('activityLog');
    ul.insertBefore(line, ul.firstChild);
  }

  function authHeaders() {
    var h = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    if (state.token) { h['X-API-TOKEN'] = state.token; }
    if (state.secret) { h['X-API-SECRET'] = state.secret; }
    return h;
  }

  async function api(method, path, opts) {
    opts = opts || {};
    var headers = authHeaders();
    var body;
    if (opts.form) {
      body = opts.form;
    } else if (opts.json !== undefined) {
      headers['Content-Type'] = 'application/json';
      body = JSON.stringify(opts.json);
    }
    var url = state.base.replace(/\/$/, '') + path;
    addLog(method + ' ' + path, opts.json !== undefined ? opts.json : (opts.form ? '(multipart form)' : null));
    try {
      var res = await fetch(url, { method: method, headers: headers, body: body });
      var text = await res.text();
      var json;
      try { json = text ? JSON.parse(text) : {}; } catch (e) { json = { raw: text }; }
      addLog(method + ' ' + path + ' -> ' + res.status, json, res.ok);
      return { ok: res.ok, status: res.status, json: json };
    } catch (err) {
      addLog(method + ' ' + path + ' -> network error', String(err), false);
      return { ok: false, status: 0, json: { message: String(err) } };
    }
  }

  function show(target, ok, message, data) {
    var el = $(target);
    var cls = ok ? 'okbox' : 'errbox';
    el.innerHTML = '<div class="' + cls + '">' + esc(message) + '</div>' +
      (data !== undefined ? '<pre>' + esc(JSON.stringify(data, null, 2)) + '</pre>' : '');
  }

  function confirmProduction(action) {
    if (state.testMode) return true;
    return window.confirm('PRODUCTION MODE: ' + action + ' will create real fiscal documents at efi.tax.gov.me. Continue?');
  }

  function fillSettingsForm(s) {
    if (!s) return;
    $('tin').value = s.tin || '';
    $('tcrCode').value = s.tcr_code || '';
    $('businessUnitCode').value = s.business_unit_code || '';
    $('operatorCode').value = s.operator_code || '';
    $('softwareCode').value = s.software_code || '';
    $('maintainerCode').value = s.maintainer_code || '';
    $('invoiceType').value = s.invoice_type || 'NONCASH';
    $('paymentMethod').value = s.payment_method || 'ACCOUNT';
    $('advanceMarker').value = s.advance_marker || 'ADVANCE';
    $('isInVat').checked = !!s.is_in_vat;
    $('testMode').checked = !!s.test_mode;
    $('autoFiscalize').checked = s.auto_fiscalize !== false;
    $('requireBuyerTin').checked = s.require_buyer_tin !== false;
    $('simplifiedInvoice').checked = !!s.simplified_invoice;
    state.testMode = !!s.test_mode;
    applyEnvironment();
  }

  function applyEnvironment() {
    var env = $('envBadge');
    env.textContent = state.testMode ? 'TEST (efitest)' : 'PRODUCTION (efi.tax.gov.me)';
    env.className = 'badge ' + (state.testMode ? 'test' : 'prod');
    $('prodWarn').style.display = state.testMode ? 'none' : 'block';
  }

  function renderStatus(json) {
    if (!json) return;
    fillSettingsForm(json.settings);
    var ok = json.configured;
    $('connBadge').textContent = json.connected ? (ok ? 'connected and configured' : 'connected, incomplete') : 'not connected';
    $('connBadge').className = 'badge ' + (json.connected && ok ? 'ok' : 'bad');

    var cert = json.certificate || {};
    var html = '<div class="kv">' +
      row('Connected', json.connected) +
      row('Configured', json.configured) +
      row('Missing', (json.missing || []).length ? (json.missing || []).join(', ') : 'none') +
      row('Endpoint', json.endpoint) +
      row('Certificate present', json.has_certificate) +
      row('Certificate valid', json.certificate_valid) +
      row('Certificate subject', cert.subject) +
      row('Certificate issuer', cert.issuer) +
      row('Certificate expires', cert.expires_at) +
      row('Days remaining', json.certificate_days_remaining) +
      row('Expired', json.certificate_expired) +
      row('Expiring soon', json.certificate_expiring_soon) +
      row('Warning window (days)', json.certificate_warning_days) +
      '</div>';
    $('statusResult').innerHTML = html;
    applyEnvironment();
  }

  function row(label, value) {
    return '<div>' + esc(label) + '</div><div class="mono">' + esc(value === null || value === undefined || value === '' ? '-' : value) + '</div>';
  }

  async function testConnection() {
    state.base = $('baseUrl').value.trim() || window.location.origin;
    state.token = $('apiToken').value.trim();
    state.secret = $('apiSecret').value.trim();
    localStorage.setItem('me_base', state.base);
    localStorage.setItem('me_token', state.token);
    localStorage.setItem('me_secret', state.secret);
    var res = await api('GET', '/api/v1/einvoice/montenegro/status');
    if (!res.ok) { show('statusResult', false, 'Status failed: ' + (res.json.message || res.status), res.json); return; }
    renderStatus(res.json);
    var note = document.createElement('div');
    note.className = res.json.configured ? 'okbox' : 'errbox';
    note.textContent = res.json.configured ? 'Configuration looks complete.' : 'Configuration is incomplete. Review the missing settings.';
    $('statusResult').appendChild(note);
  }

  function clearConnection() {
    localStorage.removeItem('me_base');
    localStorage.removeItem('me_token');
    localStorage.removeItem('me_secret');
    state.token = '';
    state.secret = '';
    $('apiToken').value = '';
    $('apiSecret').value = '';
    $('connBadge').textContent = 'not connected';
    $('connBadge').className = 'badge';
    $('statusResult').innerHTML = '';
  }

  function settingsPayload() {
    var p = {
      enable: $('enableStandard').checked,
      certificate_passphrase: $('certPass').value || undefined,
      tin: $('tin').value.trim(),
      tcr_code: $('tcrCode').value.trim(),
      business_unit_code: $('businessUnitCode').value.trim(),
      operator_code: $('operatorCode').value.trim(),
      software_code: $('softwareCode').value.trim(),
      maintainer_code: $('maintainerCode').value.trim(),
      invoice_type: $('invoiceType').value,
      payment_method: $('paymentMethod').value,
      advance_marker: $('advanceMarker').value.trim(),
      is_in_vat: $('isInVat').checked,
      test_mode: $('testMode').checked,
      auto_fiscalize: $('autoFiscalize').checked,
      require_buyer_tin: $('requireBuyerTin').checked,
      simplified_invoice: $('simplifiedInvoice').checked,
      reverse_charge: $('reverseCharge').checked
    };
    return p;
  }

  async function saveSettings(withCertificate) {
    $('certPass').value = $('certPass').value || '';
    if (withCertificate) {
      var file = $('certFile').files[0];
      if (!file) { show('settingsResult', false, 'Choose a certificate file first.'); return; }
      var form = new FormData();
      form.append('certificate', file);
      var payload = settingsPayload();
      Object.keys(payload).forEach(function (k) {
        if (payload[k] !== undefined) { form.append(k, payload[k] === true ? '1' : (payload[k] === false ? '0' : payload[k])); }
      });
      var res = await api('POST', '/api/v1/einvoice/montenegro/setup', { form: form });
      handleSetupResult(res);
      return;
    }
    var res2 = await api('POST', '/api/v1/einvoice/montenegro/setup', { json: settingsPayload() });
    handleSetupResult(res2);
  }

  function handleSetupResult(res) {
    if (!res.ok) { show('settingsResult', false, res.json.message || ('Request failed with status ' + res.status), res.json); return; }
    renderStatus(res.json);
    var note = document.createElement('div');
    note.className = 'okbox';
    note.textContent = 'Saved.';
    $('settingsResult').innerHTML = '';
    $('settingsResult').appendChild(note);
  }

  async function removeCertificate() {
    if (!window.confirm('Remove the stored certificate and passphrase?')) return;
    var res = await api('POST', '/api/v1/einvoice/montenegro/setup', { json: { remove_certificate: true } });
    handleSetupResult(res);
  }

  async function disconnectStandard() {
    if (!window.confirm('Disconnect the Montenegro standard for this company?')) return;
    var res = await api('POST', '/api/v1/einvoice/montenegro/disconnect', { json: { remove_certificate: false } });
    if (!res.ok) { show('settingsResult', false, res.json.message || ('Failed with status ' + res.status), res.json); return; }
    renderStatus(res.json);
  }

  async function refreshStatus() {
    var res = await api('GET', '/api/v1/einvoice/montenegro/status');
    if (res.ok) { renderStatus(res.json); }
  }

  async function registerTcr() {
    if (!confirmProduction('Registering a fiscal device')) return;
    var payload = {
      tcr_internal_id: $('tcrInternalId').value.trim(),
      business_unit_code: $('regBusinessUnit').value.trim() || undefined,
      software_code: $('regSoftware').value.trim() || undefined,
      maintainer_code: $('regMaintainer').value.trim() || undefined,
      valid_from: $('regValidFrom').value || undefined,
      valid_to: $('regValidTo').value || undefined,
      type: $('regType').value
    };
    var res = await api('POST', '/api/v1/einvoice/montenegro/register_tcr', { json: payload });
    if (!res.ok) { show('tcrResult', false, res.json.message || ('Failed with status ' + res.status), res.json); return; }
    show('tcrResult', true, 'ENU / TCR code assigned: ' + (res.json.code || '(none)'), res.json);
    await loadEnus();
    await refreshStatus();
  }

  async function loadEnus() {
    var res = await api('GET', '/api/v1/einvoice/montenegro/enus');
    if (!res.ok) { show('enuTable', false, res.json.message || ('Failed with status ' + res.status)); return; }
    var list = res.json.data || [];
    state.enus = list;
    if (!list.length) { $('enuTable').innerHTML = '<div class="muted small">No ENUs registered yet.</div>'; return; }
    var html = '<table><thead><tr><th>Name</th><th>TCR code</th><th>Business unit</th><th>Operator</th><th>Software</th><th>Default</th><th>Active</th><th>Actions</th></tr></thead><tbody>';
    list.forEach(function (e) {
      html += '<tr>' +
        '<td>' + esc(e.name || '-') + '</td>' +
        '<td class="mono">' + esc(e.tcr_code) + '</td>' +
        '<td class="mono">' + esc(e.business_unit_code) + '</td>' +
        '<td class="mono">' + esc(e.operator_code) + '</td>' +
        '<td class="mono">' + esc(e.software_code) + '</td>' +
        '<td>' + (e.is_default ? 'yes' : 'no') + '</td>' +
        '<td>' + (e.is_active === false ? 'no' : 'yes') + '</td>' +
        '<td><button onclick="saveEnuDefault(\'' + esc(e.hashed_id) + '\',' + (e.is_default ? 'false' : 'true') + ')">' + (e.is_default ? 'Unset default' : 'Make default') + '</button> ' +
        '<button class="danger" onclick="deleteEnu(\'' + esc(e.hashed_id) + '\')">Delete</button></td>' +
        '</tr>';
    });
    html += '</tbody></table>';
    $('enuTable').innerHTML = html;
  }

  async function saveEnuDefault(id, isDefault) {
    // The upsert endpoint fills every field from the request, so send the whole
    // ENU (never just id and is_default) to avoid wiping the registration codes.
    var enu = (state.enus || []).filter(function (e) { return e.hashed_id === id; })[0];
    if (!enu) { show('enuTable', false, 'Reload the ENU list and try again.'); return; }
    var payload = {
      id: id,
      name: enu.name,
      tcr_code: enu.tcr_code,
      business_unit_code: enu.business_unit_code,
      operator_code: enu.operator_code,
      software_code: enu.software_code,
      maintainer_code: enu.maintainer_code,
      is_active: enu.is_active !== false,
      is_default: isDefault
    };
    var res = await api('POST', '/api/v1/einvoice/montenegro/enus', { json: payload });
    if (!res.ok) { show('enuTable', false, res.json.message || 'Failed to update the ENU'); return; }
    await loadEnus();
  }

  async function deleteEnu(id) {
    if (!window.confirm('Delete this ENU?')) return;
    var res = await api('POST', '/api/v1/einvoice/montenegro/enus/delete', { json: { id: id } });
    if (!res.ok) { show('enuTable', false, res.json.message || 'Failed to delete the ENU'); return; }
    await loadEnus();
  }

  async function cashDeposit() {
    if (!confirmProduction('Sending a cash deposit')) return;
    var payload = {
      operation: $('depositOperation').value,
      cash_amount: parseFloat($('depositAmount').value || '0'),
      tcr_code: $('depositTcr').value.trim() || undefined,
      change_datetime: $('depositDate').value || undefined
    };
    var res = await api('POST', '/api/v1/einvoice/montenegro/cash_deposit', { json: payload });
    if (!res.ok) { show('depositResult', false, res.json.message || ('Failed with status ' + res.status), res.json); return; }
    show('depositResult', true, 'Cash deposit accepted. FCDC: ' + (res.json.code || '(none)'), res.json);
  }

  async function loadInvoices() {
    var res = await api('GET', '/api/v1/invoices?per_page=100');
    if (!res.ok) { show('fiscalResult', false, res.json.message || ('Failed with status ' + res.status)); return; }
    var list = (res.json.data || []).filter(function (i) { return [2, 3, 4].indexOf(i.status_id) !== -1; });
    var select = $('invoiceSelect');
    select.innerHTML = '<option value="">select an invoice</option>';
    list.forEach(function (i) {
      var o = document.createElement('option');
      o.value = i.hashed_id;
      o.textContent = (i.number || '(no number)') + ' - ' + (i.amount || '') + ' - status ' + i.status_id;
      select.appendChild(o);
    });
    show('fiscalResult', true, 'Loaded ' + list.length + ' issued invoice(s).', undefined);
  }

  function targetInvoiceId() {
    return $('invoiceId').value.trim() || $('invoiceSelect').value;
  }

  async function fiscalizeInvoice() {
    var id = targetInvoiceId();
    if (!id) { show('fiscalResult', false, 'Choose or paste an invoice first.'); return; }
    if (!confirmProduction('Fiscalizing an invoice')) return;
    var kind = $('fiscalKind').value;
    var res = await api('GET', '/api/v1/invoices/' + encodeURIComponent(id) + '/fiscalize?kind=' + encodeURIComponent(kind));
    if (!res.ok) { show('fiscalResult', false, res.json.message || ('Failed with status ' + res.status), res.json); return; }
    show('fiscalResult', true, 'Fiscalization queued. Poll the fiscal journal for the JIKR.', res.json);
    window.setTimeout(pollLatest, 2500);
  }

  async function setEnu() {
    var id = $('enuInvoiceId').value.trim();
    var enu = $('enuId').value.trim();
    if (!id || !enu) { show('actionResult', false, 'Provide the invoice hashed id and the ENU hashed id.'); return; }
    var res = await api('GET', '/api/v1/invoices/' + encodeURIComponent(id) + '/set_enu?enu_id=' + encodeURIComponent(enu));
    if (!res.ok) { show('actionResult', false, res.json.message || ('Failed with status ' + res.status), res.json); return; }
    show('actionResult', true, 'ENU assigned to the invoice.', res.json);
  }

  async function linkAdvance() {
    var finalId = $('advanceFinalId').value.trim();
    var advanceId = $('advanceId').value.trim();
    if (!finalId || !advanceId) { show('actionResult', false, 'Provide both the final invoice and the advance invoice hashed ids.'); return; }
    var res = await api('GET', '/api/v1/invoices/' + encodeURIComponent(finalId) + '/link_advance?advance_invoice_id=' + encodeURIComponent(advanceId));
    if (!res.ok) { show('actionResult', false, res.json.message || ('Failed with status ' + res.status), res.json); return; }
    show('actionResult', true, 'Advance linked to the final invoice.', res.json);
  }

  function verifyUrl(log) {
    var base = state.testMode ? VERIFY_TEST : VERIFY_PROD;
    var params = new URLSearchParams({
      iic: log.iic || '',
      tin: log.tin || '',
      crtd: log.issue_date_time || '',
      ord: String(log.ordinal || ''),
      bu: log.business_unit_code || '',
      cr: log.tcr_code || '',
      sw: log.software_code || '',
      prc: String(log.total || '')
    });
    return base + '?' + params.toString();
  }

  function renderLogs(list) {
    if (!list.length) { $('logTable').innerHTML = '<div class="muted small">No fiscal records yet.</div>'; return; }
    var html = '<table><thead><tr><th>Invoice</th><th>Kind</th><th>Status</th><th>IIC</th><th>JIKR</th><th>Total</th><th>Issued</th><th>Verification</th></tr></thead><tbody>';
    list.forEach(function (l) {
      var url = verifyUrl(l);
      var link = l.iic ? '<a href="' + esc(url) + '" target="_blank" rel="noopener">open verification</a>' : '-';
      html += '<tr>' +
        '<td class="mono">' + esc(l.invoice_number || l.entity_id) + '</td>' +
        '<td>' + esc(l.kind) + '</td>' +
        '<td>' + esc(l.status) + (l.error_message ? '<br><span class="bad small">' + esc(l.error_message) + '</span>' : '') + '</td>' +
        '<td class="mono small">' + esc(l.iic) + '</td>' +
        '<td class="mono small">' + esc(l.jikr) + '</td>' +
        '<td>' + esc(l.total) + '</td>' +
        '<td class="small">' + esc(l.issue_date_time) + '</td>' +
        '<td>' + link + '</td>' +
        '</tr>';
    });
    html += '</tbody></table>';
    $('logTable').innerHTML = html;
  }

  async function loadLogs() {
    var res = await api('GET', '/api/v1/einvoice/montenegro/fiscal_logs?per_page=50');
    if (!res.ok) { $('logTable').innerHTML = '<div class="errbox">' + esc(res.json.message || ('Failed with status ' + res.status)) + '</div>'; return; }
    state.logs = res.json.data || [];
    renderLogs(state.logs);
  }

  async function pollLatest() {
    await loadLogs();
    var pending = state.logs.some(function (l) { return !l.jikr; });
    if (pending) {
      window.setTimeout(pollLatest, 4000);
    }
  }

  async function loadReport() {
    var q = [];
    if ($('reportFrom').value) { q.push('date_from=' + encodeURIComponent($('reportFrom').value)); }
    if ($('reportTo').value) { q.push('date_to=' + encodeURIComponent($('reportTo').value)); }
    if ($('reportGroup').value) { q.push('group_by=' + encodeURIComponent($('reportGroup').value)); }
    var res = await api('GET', '/api/v1/einvoice/montenegro/report' + (q.length ? '?' + q.join('&') : ''));
    if (!res.ok) { show('reportResult', false, res.json.message || ('Failed with status ' + res.status), res.json); return; }
    var t = res.json.totals || {};
    var html = '<div class="kv">' +
      row('Records', t.records) +
      row('Fiscalized', t.fiscalized) +
      row('Unfiscalized', t.unfiscalized) +
      row('Success rate', t.success_rate) +
      row('Amount total', t.amount_total) +
      row('Amount fiscalized', t.amount_fiscalized) +
      row('Amount unfiscalized', t.amount_unfiscalized) +
      row('Backlog open', (res.json.backlog || {}).open) +
      row('Backlog within window', (res.json.backlog || {}).within_window) +
      row('Backlog outside window', (res.json.backlog || {}).outside_window) +
      '</div>';
    var byKind = res.json.by_kind || [];
    if (byKind.length) {
      html += '<h3 class="small muted" style="margin-top:14px;">By kind</h3><table><thead><tr><th>Kind</th><th>Records</th><th>Fiscalized</th><th>Amount</th></tr></thead><tbody>';
      byKind.forEach(function (k) {
        html += '<tr><td>' + esc(k.kind) + '</td><td>' + esc(k.records) + '</td><td>' + esc(k.fiscalized) + '</td><td>' + esc(k.amount_total) + '</td></tr>';
      });
      html += '</tbody></table>';
    }
    var timeline = res.json.timeline || [];
    if (timeline.length) {
      html += '<h3 class="small muted" style="margin-top:14px;">Timeline</h3><table><thead><tr><th>Period</th><th>Records</th><th>Fiscalized</th><th>Amount</th></tr></thead><tbody>';
      timeline.forEach(function (p) {
        html += '<tr><td>' + esc(p.period) + '</td><td>' + esc(p.records) + '</td><td>' + esc(p.fiscalized) + '</td><td>' + esc(p.amount_total) + '</td></tr>';
      });
      html += '</tbody></table>';
    }
    $('reportResult').innerHTML = html;
  }

  // expose handlers used by inline onclick attributes
  window.testConnection = testConnection;
  window.clearConnection = clearConnection;
  window.saveSettings = saveSettings;
  window.removeCertificate = removeCertificate;
  window.disconnectStandard = disconnectStandard;
  window.refreshStatus = refreshStatus;
  window.registerTcr = registerTcr;
  window.loadEnus = loadEnus;
  window.saveEnuDefault = saveEnuDefault;
  window.deleteEnu = deleteEnu;
  window.cashDeposit = cashDeposit;
  window.loadInvoices = loadInvoices;
  window.fiscalizeInvoice = fiscalizeInvoice;
  window.setEnu = setEnu;
  window.linkAdvance = linkAdvance;
  window.loadLogs = loadLogs;
  window.pollLatest = pollLatest;
  window.loadReport = loadReport;

  // initialise the connection fields
  $('baseUrl').value = state.base;
  $('apiToken').value = state.token;
  $('apiSecret').value = state.secret;
  applyEnvironment();
  if (state.token) { testConnection(); }
})();
</script>
</body>
</html>
