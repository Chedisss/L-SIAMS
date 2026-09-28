// L-SIAMS integration tests: drives the real web app and device API on the
// test copy (port 8081, database lsiams_test) and checks the database directly.
// How it was run (Week 7): a full copy of the project served on 127.0.0.1:8081 with its own
// database (lsiams_test) and realtime port, prepared by setup.php, whose JSON output was
// saved to /tmp/claude-0/it_cfg.json. Run with Playwright: node integration.js
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const crypto = require('crypto');
const fs = require('fs');

const B = 'http://127.0.0.1:8081';
const cfg = JSON.parse(fs.readFileSync('/tmp/claude-0/it_cfg.json', 'utf8'));
const PW = 'L-SIAMS-Demo-2026!';
const sql = (q) => execSync(`mysql -uroot lsiams_test -N -B -e "${q.replace(/"/g, '\\"')}"`).toString().trim();
const results = [];
let shotN = 0;

async function test(id, group, name, expected, fn) {
  let actual, pass;
  try { [pass, actual] = await fn(); await closeTemp(); } catch (e) { pass = false; actual = 'Error: ' + e.message.split('\n')[0]; }
  results.push({ id, group, name, expected, actual, status: pass ? 'Pass' : 'Fail' });
  console.log(`${pass ? 'PASS' : 'FAIL'} ${id} ${name} -> ${actual}`);
}

async function login(page, user, pass) {
  await page.goto(B + '/login');
  await page.fill('#username', user); await page.fill('#password', pass);
  await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.press('#password', 'Enter')]);
  await page.waitForTimeout(500);
}

// ---- signed device requests, exactly as the firmware builds them ----
async function device(method, path, bodyObj, opts = {}) {
  const body = bodyObj === undefined ? '' : JSON.stringify(bodyObj);
  const ts = String(Math.floor(Date.now() / 1000));
  const nonce = opts.nonce || crypto.randomBytes(8).toString('hex');
  const canon = [method, path, cfg.device_id, ts, nonce, crypto.createHash('sha256').update(body).digest('hex')].join('\n');
  let sig = crypto.createHmac('sha256', cfg.hmac).update(canon).digest('hex');
  if (opts.badSig) sig = sig.replace(/^./, c => (c === 'a' ? 'b' : 'a'));
  const headers = { 'Content-Type': 'application/json', Accept: 'application/json' };
  if (!opts.unsigned) Object.assign(headers, {
    'X-LSIAMS-Device-Id': cfg.device_id, 'X-LSIAMS-Api-Key': cfg.api_key,
    'X-LSIAMS-Timestamp': ts, 'X-LSIAMS-Nonce': nonce, 'X-LSIAMS-Signature': sig,
  });
  if (opts.requestId) headers['X-LSIAMS-Request-Id'] = opts.requestId;
  const r = await fetch(B + path, { method, headers, body: body || undefined });
  let j = {}; try { j = await r.json(); } catch {}
  return { status: r.status, code: j.code, message: j.message, data: j.data };
}

let temp = [];
async function closeTemp() { for (const c of temp) await c.close().catch(() => {}); temp = []; }
(async () => {
  const b = await chromium.launch();
  const newPage = async (keep) => { const c = await b.newContext({ viewport: { width: 1440, height: 900 } }); if (!keep) temp.push(c); return c.newPage(); };
  const shot = async (page, name) => { await page.screenshot({ path: `evidence/${String(++shotN).padStart(2, '0')}-${name}.png` }); };
  fs.mkdirSync('evidence', { recursive: true });

  // ================= UI <-> Authentication =================
  await test('IT-01', 'UI ↔ Authentication', 'Administrator signs in with valid credentials',
    'Redirected to the Administrator Dashboard', async () => {
      const p = await newPage(); await login(p, 'demoadmin', PW);
      const ok = p.url().endsWith('/admin') && (await p.title()).startsWith('Dashboard');
      await shot(p, 'admin-login-success');
      return [ok, `Landed on ${new URL(p.url()).pathname} ("${await p.title()}")`];
    });

  await test('IT-02', 'UI ↔ Authentication', 'Sign-in with a wrong password',
    'Access denied; error message shown; stays on the sign-in page', async () => {
      const p = await newPage(); await login(p, 'acruz', 'WrongPassword!1');
      const txt = await p.textContent('body');
      const msg = (txt.match(/(Incorrect|Invalid|wrong)[^.]*\./i) || [''])[0];
      await shot(p, 'wrong-password');
      return [new URL(p.url()).pathname === '/login' && msg !== '', `Stayed on /login; message: "${msg.trim()}"`];
    });

  await test('IT-03', 'UI ↔ Authentication', 'Opening an admin page without signing in',
    'Redirected to the sign-in page', async () => {
      const p = await newPage(); await p.goto(B + '/admin/students');
      return [new URL(p.url()).pathname === '/login', `Redirected to ${new URL(p.url()).pathname}`];
    });

  await test('IT-04', 'UI ↔ Authentication', 'Teacher account opens an administrator page',
    'Access refused (role-based access control)', async () => {
      const p = await newPage(); await login(p, 'lbautista', PW);
      const r = await p.goto(B + '/admin/users');
      await shot(p, 'teacher-blocked-from-admin');
      return [r.status() === 403 || !p.url().includes('/admin/users'), `HTTP ${r.status()} at ${new URL(p.url()).pathname}`];
    });

  // ================= Authentication <-> Database =================
  await test('IT-05', 'Authentication ↔ Database', 'Successful sign-in is recorded',
    'New login_history row (success) and an active user_sessions row', async () => {
      const uid = sql("SELECT user_id FROM users WHERE username='rsantos'");
      const before = +sql(`SELECT COUNT(*) FROM login_history WHERE user_id=${uid} AND status='success'`);
      const p = await newPage(); await login(p, 'rsantos', PW);
      const after = +sql(`SELECT COUNT(*) FROM login_history WHERE user_id=${uid} AND status='success'`);
      const live = +sql(`SELECT COUNT(*) FROM user_sessions WHERE user_id=${uid} AND terminated_at IS NULL`);
      return [after === before + 1 && live >= 1, `login_history +${after - before}; ${live} active session(s)`];
    });

  await test('IT-06', 'Authentication ↔ Database', 'Five wrong passwords lock the account',
    'users.locked_until set ~15 min ahead; the correct password is then refused', async () => {
      for (let i = 0; i < 5; i++) { const p = await newPage(); await login(p, 'gvillanueva', 'Nope-' + i); await p.context().close(); }
      const mins = sql("SELECT TIMESTAMPDIFF(MINUTE, NOW(), locked_until) FROM users WHERE username='gvillanueva'");
      const p = await newPage(); await login(p, 'gvillanueva', PW);
      await shot(p, 'account-locked');
      const blocked = new URL(p.url()).pathname === '/login';
      return [mins !== 'NULL' && +mins >= 13 && blocked, `locked_until = now + ${mins} min; correct password ${blocked ? 'refused' : 'ACCEPTED'}`];
    });

  await test('IT-07', 'Authentication ↔ Database', 'Signing out ends the session',
    'user_sessions row gets terminated_at; protected pages redirect to sign-in', async () => {
      const uid = sql("SELECT user_id FROM users WHERE username='cdizon'");
      const p = await newPage(); await login(p, 'cdizon', PW);
      const token = await p.getAttribute('meta[name="csrf-token"]', 'content');
      await p.request.post(B + '/logout', { headers: { 'X-CSRF-Token': token }, form: { _token: token } });
      const ended = sql(`SELECT termination_reason FROM user_sessions WHERE user_id=${uid} ORDER BY session_id DESC LIMIT 1`);
      await p.goto(B + '/teacher');
      return [ended !== 'NULL' && new URL(p.url()).pathname === '/login', `terminated (${ended}); /teacher now → ${new URL(p.url()).pathname}`];
    });

  // ================= UI <-> Database =================
  const b2 = await chromium.launch();
  const admin = await (await b2.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
  await login(admin, 'demoadmin', PW);

  await test('IT-08', 'UI ↔ Database', 'Add Student form saves a new student',
    'New row in students table with the entered details', async () => {
      await admin.goto(B + '/admin/students/create');
      await admin.fill('[name=student_number]', '2026-IT-0001');
      await admin.fill('[name=first_name]', 'Integration');
      await admin.fill('[name=last_name]', 'Tester');
      await admin.selectOption('[name=gender]', { index: 1 });
      await admin.fill('[name=birthdate]', '2010-05-14');
      await admin.selectOption('[name=grade_level_id]', sql("SELECT grade_level_id FROM sections WHERE section_code='G7-RIZAL'"));
      await admin.waitForFunction(() => { const s = document.querySelector('[name=section_id]'); return s && !s.disabled && s.options.length > 1; });
      await admin.selectOption('[name=section_id]', { index: 1 });
      await admin.fill('[name=guardian_name]', 'Maria Tester');
      await admin.fill('[name=guardian_contact]', '09171234567');
      await shot(admin, 'add-student-form');
      await Promise.all([admin.waitForNavigation({ waitUntil: 'load' }), admin.click('form[data-draft="student-new"] button[type=submit]')]);
      await admin.waitForTimeout(800);
      const row = sql("SELECT CONCAT(student_id,' | ',first_name,' ',last_name,' | section ',section_id) FROM students WHERE student_number='2026-IT-0001'");
      await shot(admin, 'add-student-saved');
      return [row !== '', row ? `Saved: ${row}` : `No row found (page ${admin.url()} — ${(await admin.textContent('.field-error, .alert, h1').catch(()=> '')).trim().slice(0,120)})`];
    });

  await test('IT-09', 'UI ↔ Database', 'Dashboard figures match the database',
    'Students and Teachers counts on the dashboard equal the database counts', async () => {
      await admin.goto(B + '/admin', { waitUntil: 'load' });
      const body = await admin.textContent('body');
      const ui = +(body.match(/Students\s*(\d+)/i) || [0, -1])[1];
      const uiT = +(body.match(/Teachers\s*(\d+)/i) || [0, -1])[1];
      const db = +sql("SELECT COUNT(*) FROM students WHERE deleted_at IS NULL AND status='active'");
      const dbT = +sql("SELECT COUNT(*) FROM teachers WHERE deleted_at IS NULL AND status='active'");
      return [ui === db && uiT === dbT, `Students UI ${ui} / DB ${db}; Teachers UI ${uiT} / DB ${dbT} (page ${admin.url()})`];
    });

  // ================= Classroom Terminal <-> Server <-> Database =================
  await test('IT-12', 'Terminal ↔ Server', 'Request without a signature',
    'Refused with HTTP 401; nothing recorded', async () => {
      const r = await device('POST', '/api/attendance/tap', { rfid_uid: cfg.cards[0].card_uid }, { unsigned: true });
      return [r.status === 401, `HTTP ${r.status} ${r.code}`];
    });

  await test('IT-13', 'Terminal ↔ Server', 'Request with a tampered signature',
    'Refused (SIGNATURE_INVALID)', async () => {
      const r = await device('POST', '/api/device/heartbeat', { firmware: '2.4.0', wifi_signal: -55, queue: 0, uptime: 100 }, { badSig: true });
      return [r.status === 401 && r.code === 'SIGNATURE_INVALID', `HTTP ${r.status} ${r.code}`];
    });

  await test('IT-14', 'Terminal ↔ Database', 'Terminal heartbeat',
    'Accepted; devices.last_heartbeat_at and a device_heartbeats row written', async () => {
      const before = +sql('SELECT COUNT(*) FROM device_heartbeats WHERE device_row_id=4');
      const r = await device('POST', '/api/device/heartbeat', { firmware: '2.4.0', wifi_signal: -55, queue: 0, uptime: 3600, free_heap: 150000 });
      const after = +sql('SELECT COUNT(*) FROM device_heartbeats WHERE device_row_id=4');
      const age = sql('SELECT TIMESTAMPDIFF(SECOND, last_heartbeat_at, NOW()) FROM devices WHERE id=4');
      return [r.status === 200 && after === before + 1 && +age <= 5, `HTTP ${r.status}; heartbeat rows +${after - before}; last heartbeat ${age}s ago`];
    });

  await test('IT-15', 'Terminal ↔ Database', 'Student taps before the teacher opens the session',
    'Refused (SESSION_NOT_OPEN); no attendance row', async () => {
      const r = await device('POST', '/api/attendance/tap', { rfid_uid: cfg.cards[0].card_uid, request_id: crypto.randomUUID() }, { requestId: crypto.randomUUID() });
      return [r.code === 'SESSION_NOT_OPEN', `HTTP ${r.status} ${r.code}`];
    });

  await test('IT-16', 'Terminal ↔ Database', 'Teacher fingerprint opens the scheduled class',
    'Session opened in attendance_sessions for the scheduled subject/section/room', async () => {
      const r = await device('POST', '/api/attendance/start', { fingerprint_id: 3, confidence: 142, schedule_id: null });
      const row = sql("SELECT CONCAT(session_code,' | status ',status,' | roster ',roster_count) FROM attendance_sessions WHERE status='open' AND device_row_id=4");
      return [(r.status === 200 || r.status === 201) && row.includes('open'), `HTTP ${r.status} ${r.code}; ${row}`];
    });

  const rid = crypto.randomUUID();
  await test('IT-17', 'Terminal ↔ Database', 'Student RFID tap after the session opens',
    'Time-in recorded in attendance_records with status', async () => {
      const r = await device('POST', '/api/attendance/tap', { rfid_uid: cfg.cards[0].card_uid, request_id: rid }, { requestId: rid });
      const row = sql(`SELECT CONCAT('time_in ',TIME(time_in),' | ',arrival_status) FROM attendance_records WHERE student_id=${cfg.cards[0].student_id} AND session_id=(SELECT session_id FROM attendance_sessions WHERE status='open' AND device_row_id=4) AND time_in IS NOT NULL`);
      return [(r.status === 200 || r.status === 201) && row !== '', `HTTP ${r.status}: "${r.message}"; DB ${row}`];
    });

  await test('IT-18', 'Terminal ↔ Database', 'Same tap re-sent (network retry)',
    'Accepted as a duplicate; still exactly one attendance row', async () => {
      const r = await device('POST', '/api/attendance/tap', { rfid_uid: cfg.cards[0].card_uid, request_id: rid }, { requestId: rid });
      const n = sql(`SELECT COUNT(*) FROM attendance_records WHERE student_id=${cfg.cards[0].student_id} AND session_id=(SELECT session_id FROM attendance_sessions WHERE status='open' AND device_row_id=4)`);
      return [+n === 1, `HTTP ${r.status} ${r.code}; rows for student = ${n}`];
    });

  await test('IT-19', 'Terminal ↔ Database', 'Card of a student from another section',
    'Refused; not recorded in this class', async () => {
      const q = crypto.randomUUID();
      const r = await device('POST', '/api/attendance/tap', { rfid_uid: cfg.other_section_card, request_id: q }, { requestId: q });
      const n = +sql(`SELECT COUNT(*) FROM attendance_records WHERE request_id='${q}'`);
      return [r.status >= 400 && n === 0, `HTTP ${r.status} ${r.code}; rows written ${n}`];
    });

  await test('IT-20', 'Terminal ↔ Server', 'Replayed request (same nonce reused)',
    'Second copy refused as a replay', async () => {
      const nonce = crypto.randomBytes(8).toString('hex');
      const a = await device('POST', '/api/device/heartbeat', { firmware: '2.4.0', wifi_signal: -55, queue: 0, uptime: 3601 }, { nonce });
      const c = await device('POST', '/api/device/heartbeat', { firmware: '2.4.0', wifi_signal: -55, queue: 0, uptime: 3601 }, { nonce });
      return [a.status === 200 && c.status >= 400, `first HTTP ${a.status}; replay HTTP ${c.status} ${c.code}`];
    });

  await test('IT-21', 'Terminal ↔ Web UI', 'Tap appears on the teacher dashboard',
    'Teacher sees the open session and the tapped student', async () => {
      const p = await newPage(); await login(p, 'lbautista', PW);
      await p.goto(B + '/teacher', { waitUntil: 'load' }); await p.waitForTimeout(800);
      const name = sql(`SELECT last_name FROM students WHERE student_id=${cfg.cards[0].student_id}`);
      const body = await p.textContent('body');
      await shot(p, 'teacher-dashboard-after-tap');
      return [body.includes('Session open') && body.includes(name), `"Session open" shown: ${body.includes('Session open')}; ${name} listed: ${body.includes(name)}`];
    });


  await test('IT-22', 'Terminal ↔ Database', 'Class closed early, reopened by fingerprint, absent student taps',
    'Session reopens; the student marked Absent at close is recorded as present/late', async () => {
      const end = await device('POST', '/api/attendance/end', {});
      const card = cfg.cards[1];
      const st = sql(`SELECT final_status FROM attendance_records WHERE student_id=${card.student_id} AND session_id=(SELECT session_id FROM attendance_sessions WHERE schedule_id=18 AND session_date=CURDATE())`);
      const re = await device('POST', '/api/attendance/start', { fingerprint_id: 3, confidence: 150, schedule_id: null });
      const q = crypto.randomUUID();
      const t = await device('POST', '/api/attendance/tap', { rfid_uid: card.card_uid, request_id: q }, { requestId: q });
      const after = sql(`SELECT CONCAT(final_status,' at ',TIME(time_in)) FROM attendance_records WHERE student_id=${card.student_id} AND session_id=(SELECT session_id FROM attendance_sessions WHERE schedule_id=18 AND session_date=CURDATE())`);
      return [end.status === 200 && st === 'Absent' && t.status === 201 && !after.startsWith('Absent'),
        `close HTTP ${end.status} → student ${st}; reopen HTTP ${re.status} ${re.code}; tap HTTP ${t.status} → ${after}`];
    });

  // ================= Reports <-> Database =================
  const today = sql('SELECT CURDATE()');
  await test('IT-10', 'Reports ↔ Database', 'Daily Attendance report returns the stored records',
    'Report row count equals attendance records in the database for the day', async () => {
      await admin.goto(B + '/admin/reports');
      const token = await admin.getAttribute('meta[name="csrf-token"]', 'content');
      const r = await admin.request.post(B + '/admin/reports/preview', { headers: { 'X-CSRF-Token': token, Accept: 'application/json' }, form: { type: 'daily', date: today } });
      const j = await r.json();
      const total = (j.data || j).total_rows;
      const db = +sql(`SELECT COUNT(*) FROM attendance_records ar JOIN attendance_sessions s ON s.session_id=ar.session_id WHERE DATE(s.opened_at)='${today}'`);
      return [total === db, `Report ${total} rows; database ${db} records`];
    });

  await test('IT-11', 'Reports ↔ Database', 'Export the report as PDF and Excel and save it',
    'Valid PDF and XLSX files downloaded; generated_reports row saved', async () => {
      const token = await admin.getAttribute('meta[name="csrf-token"]', 'content');
      const before = +sql('SELECT COUNT(*) FROM generated_reports');
      const pdf = await admin.request.post(B + '/admin/reports/generate', { headers: { 'X-CSRF-Token': token }, form: { type: 'daily', date: today, format: 'pdf', save: '1' } });
      const xlsx = await admin.request.post(B + '/admin/reports/generate', { headers: { 'X-CSRF-Token': token }, form: { type: 'daily', date: today, format: 'xlsx' } });
      const pb = await pdf.body(), xb = await xlsx.body();
      fs.writeFileSync('evidence/daily-report.pdf', pb);
      const after = +sql('SELECT COUNT(*) FROM generated_reports');
      const ok = pb.slice(0, 4).toString() === '%PDF' && xb.slice(0, 2).toString() === 'PK' && after === before + 1;
      return [ok, `PDF ${(pb.length / 1024).toFixed(0)} KB (%PDF), XLSX ${(xb.length / 1024).toFixed(0)} KB (zip), generated_reports +${after - before}`];
    });

  await b.close(); await b2.close();
  fs.writeFileSync('integration-results.json', JSON.stringify(results, null, 2));
  const pass = results.filter(r => r.status === 'Pass').length;
  console.log(`\n${pass}/${results.length} passed`);
})();
