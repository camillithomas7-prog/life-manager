// ============= STATE =============
const state = {
  view: 'today',
  user: null,
  token: localStorage.getItem('lm_token'),
  projects: [],
  currentTaskFilter: 'open',
  currentProjectFilter: 'all',
  calendarMonth: ymNow(),
  finMonth: ymNow(),
  todayDate: new Date().toISOString().slice(0,10),
  scheduleDay: (new Date().getDay() + 6) % 7,
};

const TYPE_COLORS = {
  sonno: '#94a3b8', sport: '#f59e0b', pasto: '#10b981',
  lavoro: '#4f46e5', personale: '#8b5cf6', libero: '#06b6d4',
};
const TYPE_LABELS = { sonno:'Sonno',sport:'Sport',pasto:'Pasto',lavoro:'Lavoro',personale:'Personale',libero:'Libero' };
const MESI = ['Gennaio','Febbraio','Marzo','Aprile','Maggio','Giugno','Luglio','Agosto','Settembre','Ottobre','Novembre','Dicembre'];
const MESI_SHORT = ['Gen','Feb','Mar','Apr','Mag','Giu','Lug','Ago','Set','Ott','Nov','Dic'];
const GIORNI = ['Lunedì','Martedì','Mercoledì','Giovedì','Venerdì','Sabato','Domenica'];
const GIORNI_SHORT = ['Lun','Mar','Mer','Gio','Ven','Sab','Dom'];

function ymNow() { const d = new Date(); return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0'); }

// ============= API =============
async function api(action, data = null, method = 'GET') {
  let url = `api.php?action=${action}`;
  if (method === 'GET' && data) url += '&' + new URLSearchParams(data).toString();
  const opts = {
    method: method === 'GET' && !data ? 'GET' : (method === 'GET' ? 'GET' : 'POST'),
    headers: {}
  };
  if (state.token) opts.headers['Authorization'] = 'Bearer ' + state.token;
  if (data && opts.method !== 'GET') {
    opts.headers['Content-Type'] = 'application/json';
    opts.body = JSON.stringify(data);
  }
  try {
    const res = await fetch(url, opts);
    const json = await res.json();
    if (res.status === 401) {
      localStorage.removeItem('lm_token');
      state.token = null;
      state.user = null;
      showAuthScreen();
      return { ok: false, error: 'unauth' };
    }
    return json;
  } catch (e) {
    return { ok: false, error: e.message };
  }
}

// ============= UTILS =============
function toast(msg) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.classList.add('show');
  setTimeout(() => t.classList.remove('show'), 2200);
}

function escapeHtml(str) {
  return String(str || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function fmtDate(d) {
  if (!d) return '';
  const date = new Date(d);
  return date.getDate() + ' ' + MESI_SHORT[date.getMonth()];
}

function fmtMoney(n) {
  return new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' }).format(n || 0);
}

function relativeDay(d) {
  if (!d) return '';
  const today = new Date(); today.setHours(0,0,0,0);
  const date = new Date(d); date.setHours(0,0,0,0);
  const diff = Math.round((date - today) / 86400000);
  if (diff === 0) return 'Oggi';
  if (diff === 1) return 'Domani';
  if (diff === -1) return 'Ieri';
  if (diff > 0 && diff < 7) return 'Tra ' + diff + ' giorni';
  if (diff < 0) return Math.abs(diff) + ' giorni fa';
  return fmtDate(d);
}

function todayLabel() {
  return new Date().toLocaleDateString('it-IT', { weekday: 'long', day: 'numeric', month: 'long' });
}

function timeToMin(t) {
  if (!t) return 0;
  const [h, m] = t.split(':').map(Number);
  return h * 60 + m;
}

function minToHHMM(m) {
  return String(Math.floor(m/60) % 24).padStart(2,'0') + ':' + String(m%60).padStart(2,'0');
}

function fmtDuration(min) {
  if (!min) return '0 min';
  if (min < 60) return min + ' min';
  const h = Math.floor(min/60), m = min%60;
  return m ? `${h}h ${m}min` : `${h}h`;
}

// ============= AUTH =============
function showAuthScreen() {
  document.getElementById('authScreen').classList.remove('hidden');
  document.getElementById('app').classList.add('hidden');
}

function showApp() {
  document.getElementById('authScreen').classList.add('hidden');
  document.getElementById('app').classList.remove('hidden');
  // popola user
  const u = state.user;
  document.getElementById('userName').textContent = u.name || u.email.split('@')[0];
  document.getElementById('userEmail').textContent = u.email;
  document.getElementById('userAvatar').textContent = (u.name || u.email)[0].toUpperCase();
  document.getElementById('todayLabel').textContent = todayLabel();
  document.getElementById('dashHello').textContent = 'Ciao' + (u.name ? ', ' + u.name.split(' ')[0] : '');
  if (u.is_admin) {
    document.querySelectorAll('.nav-admin').forEach(el => el.classList.remove('hidden'));
  }
  switchView(state.view);
}

function showAuthTab(tab) {
  document.querySelectorAll('.auth-tab').forEach(t => t.classList.toggle('active', t.dataset.authTab === tab));
  document.getElementById('loginForm').classList.toggle('hidden', tab !== 'login');
  document.getElementById('registerForm').classList.toggle('hidden', tab !== 'register');
  document.getElementById('verifyPending').classList.add('hidden');
}

async function attemptLogin(email, password) {
  const r = await api('login', { email, password }, 'POST');
  if (r.ok) {
    localStorage.setItem('lm_token', r.token);
    state.token = r.token;
    state.user = r.user;
    showApp();
  } else {
    document.getElementById('loginErr').textContent = r.error;
    document.getElementById('loginErr').classList.remove('hidden');
  }
}

async function attemptRegister(name, email, password) {
  const r = await api('register', { name, email, password }, 'POST');
  if (r.ok) {
    if (r.token) {
      // auto-verify abilitato
      localStorage.setItem('lm_token', r.token);
      state.token = r.token;
      state.user = r.user;
      showApp();
      toast('Benvenuto! Account creato');
    } else {
      // mostra schermo verifica email
      document.getElementById('verifyEmail').textContent = email;
      document.getElementById('loginForm').classList.add('hidden');
      document.getElementById('registerForm').classList.add('hidden');
      document.getElementById('verifyPending').classList.remove('hidden');
      // Mostra link locale se presente
      if (r.verification_url && location.hostname === 'localhost') {
        const linkEl = document.getElementById('verifyLocalLink');
        linkEl.innerHTML = '🔗 In locale: <a href="' + escapeHtml(r.verification_url) + '" style="color:var(--primary);text-decoration:underline">apri link di verifica</a>';
      }
    }
  } else {
    document.getElementById('registerErr').textContent = r.error;
    document.getElementById('registerErr').classList.remove('hidden');
  }
}

async function logout() {
  if (!confirm('Vuoi davvero uscire?')) return;
  await api('logout', {}, 'POST');
  localStorage.removeItem('lm_token');
  state.token = null;
  state.user = null;
  location.reload();
}

async function checkVerifyParam() {
  const params = new URLSearchParams(location.search);
  const v = params.get('verify');
  if (v) {
    const r = await api('verify_email', { token: v }, 'POST');
    if (r.ok) {
      localStorage.setItem('lm_token', r.token);
      state.token = r.token;
      state.user = r.user;
      history.replaceState({}, '', location.pathname);
      toast('Email verificata!');
      showApp();
      return true;
    } else {
      toast(r.error || 'Verifica fallita');
    }
  }
  return false;
}

async function bootAuth() {
  // gestisci link verify
  if (await checkVerifyParam()) return;
  // se token esistente, prova /me
  if (state.token) {
    const r = await api('me');
    if (r.ok) {
      state.user = r.user;
      showApp();
      return;
    }
  }
  showAuthScreen();
}

// ============= NAVIGATION =============
function switchView(view) {
  state.view = view;
  document.querySelectorAll('.view').forEach(v => v.classList.toggle('hidden', v.dataset.view !== view));
  document.querySelectorAll('.nav-item').forEach(n => n.classList.toggle('active', n.dataset.view === view));
  document.querySelectorAll('.bn-item').forEach(n => n.classList.toggle('active', n.dataset.view === view));
  // titolo top bar mobile
  const titles = { today:'Oggi', schedule:'Ritmi', tasks:'Attività', projects:'Progetti', dashboard:'Dashboard',
    routines:'Routine', calendar:'Calendario', goals:'Obiettivi', finances:'Bilancio', notes:'Note',
    profile:'Profilo', admin:'Admin' };
  document.getElementById('topbarTitle').textContent = titles[view] || '';
  closeMoreSheet();
  loadView(view);
}

async function loadView(view) {
  const fns = { today: loadToday, schedule: loadSchedule, dashboard: loadDashboard, projects: loadProjects,
    tasks: loadTasks, routines: loadRoutines, calendar: loadCalendar, goals: loadGoals,
    finances: loadFinances, notes: loadNotes, profile: loadProfile, admin: loadAdmin };
  if (fns[view]) fns[view]();
}

function openMoreSheet() { document.getElementById('moreSheet').classList.remove('hidden'); }
function closeMoreSheet() { document.getElementById('moreSheet').classList.add('hidden'); }

document.addEventListener('click', e => {
  const navItem = e.target.closest('[data-view]');
  if (navItem) {
    e.preventDefault();
    switchView(navItem.dataset.view);
  }
});

// ============= TODAY VIEW =============
function changeTodayDate(delta) {
  if (delta === 0) state.todayDate = new Date().toISOString().slice(0,10);
  else { const d = new Date(state.todayDate); d.setDate(d.getDate()+delta); state.todayDate = d.toISOString().slice(0,10); }
  loadToday();
}

async function loadToday() {
  const r = await api('today_plan', { date: state.todayDate });
  if (!r.ok) return;

  const dateObj = new Date(state.todayDate);
  const isToday = state.todayDate === new Date().toISOString().slice(0,10);
  const dayName = GIORNI[r.weekday];
  document.getElementById('todayTitle').textContent = isToday ? 'Programma di oggi' :
    dateObj.toLocaleDateString('it-IT', { weekday: 'long', day: 'numeric', month: 'long' });
  document.getElementById('todaySubtitle').textContent =
    `${dayName} ${dateObj.getDate()} ${MESI[dateObj.getMonth()]} · ${fmtDuration(r.work_minutes)} di lavoro · ${fmtDuration(r.scheduled_minutes)} pianificate`;

  const remainMin = Math.max(0, r.work_minutes - r.scheduled_minutes);
  const fillPct = r.work_minutes ? Math.round(r.scheduled_minutes / r.work_minutes * 100) : 0;
  document.getElementById('todayKpi').innerHTML = `
    <div class="kpi"><div class="kpi-label">Lavoro disponibile</div><div class="kpi-value">${fmtDuration(r.work_minutes)}</div><div class="kpi-sub">${r.blocks.filter(b=>b.type==='lavoro').length} blocchi</div></div>
    <div class="kpi"><div class="kpi-label">Pianificate</div><div class="kpi-value">${fmtDuration(r.scheduled_minutes)}</div><div class="kpi-sub">saturazione ${fillPct}%</div></div>
    <div class="kpi positive"><div class="kpi-label">Tempo libero</div><div class="kpi-value">${fmtDuration(remainMin)}</div></div>
    <div class="kpi"><div class="kpi-label">Task pian. / fatte</div><div class="kpi-value">${r.scheduled_tasks.length} / ${r.completed_tasks.length}</div></div>
  `;

  const nowMin = isToday ? (new Date().getHours()*60 + new Date().getMinutes()) : -1;
  const tlEl = document.getElementById('todayTimeline');
  if (!r.blocks.length) {
    tlEl.innerHTML = `<div class="empty"><div class="empty-icon">⊟</div>Nessun blocco per ${dayName}.<br><a class="link" data-view="schedule">Configura i ritmi →</a></div>`;
  } else {
    let html = '';
    let nowInjected = false;
    r.blocks.forEach(b => {
      const sm = timeToMin(b.start_time), em = timeToMin(b.end_time);
      const isNow = nowMin >= sm && nowMin < em;
      const isPast = nowMin >= em && isToday;
      if (isToday && !nowInjected && nowMin < sm) { html += `<div class="now-line"></div>`; nowInjected = true; }
      const blockTasks = r.scheduled_tasks.concat(r.completed_tasks).filter(t => {
        const ts = timeToMin(t.scheduled_start);
        return ts >= sm && ts < em;
      }).sort((a,b) => timeToMin(a.scheduled_start) - timeToMin(b.scheduled_start));

      html += `
        <div class="timeline-block ${b.type} ${isNow ? 'now' : ''} ${isPast ? 'past' : ''}" style="--block-color: ${b.color}">
          <div class="block-time">${b.start_time}<span class="end-time">${b.end_time}</span></div>
          <div class="timeline-block-head">
            <div class="block-label">${escapeHtml(b.label)}<span class="block-type-tag">${TYPE_LABELS[b.type]||b.type}</span></div>
            <div style="display:flex;align-items:center;gap:6px">
              <span class="block-duration">${fmtDuration(em-sm)}</span>
              <button class="icon-btn" onclick="openBlockModal(${b.id})">✎</button>
            </div>
          </div>
          ${blockTasks.length ? `<div class="block-tasks">${blockTasks.map(t => `
            <div class="block-task ${t.status==='done'?'done':''}">
              <span class="task-check ${t.status==='done'?'done':''}" onclick="toggleTask(${t.id})"></span>
              <span class="block-task-time">${t.scheduled_start}–${t.scheduled_end}</span>
              <span class="block-task-title">${escapeHtml(t.title)}</span>
              ${t.project_name ? `<span class="task-tag" style="font-size:10.5px"><span class="task-dot" style="background:${t.project_color}"></span>${escapeHtml(t.project_name)}</span>` : ''}
              <span class="block-task-dur">${fmtDuration(t.estimated_minutes||30)}</span>
              <button class="icon-btn" onclick="openTaskModal(${t.id})">✎</button>
            </div>
          `).join('')}</div>` : (b.type === 'lavoro' ? '<div class="block-tasks"><div style="font-size:12px;color:var(--text-soft);font-style:italic;padding:4px 10px">Blocco vuoto — clicca "Pianifica"</div></div>' : '')}
        </div>
      `;
    });
    tlEl.innerHTML = html;
  }

  document.getElementById('todayRoutines').innerHTML = r.routines.length ? r.routines.map(rr => `
    <div class="routine-item ${rr.done_today ? 'done' : ''}" onclick="toggleRoutine(${rr.id})">
      <div class="routine-icon">${escapeHtml(rr.icon)}</div>
      <div class="routine-name">${escapeHtml(rr.title)}</div>
      <div class="routine-time">${rr.time || ''}</div>
    </div>
  `).join('') : '<div class="empty">Nessuna routine.</div>';

  const tr = await api('tasks_list', { filter: 'open' });
  const unsched = (tr.data || []).filter(t => !t.scheduled_date);
  document.getElementById('unscheduledCount').textContent = unsched.length;
  document.getElementById('unscheduledList').innerHTML = unsched.length ? unsched.slice(0, 12).map(t => `
    <div class="task-item">
      <div class="task-check" onclick="toggleTask(${t.id})"></div>
      <div class="task-body">
        <div class="task-title">${escapeHtml(t.title)}</div>
        <div class="task-meta">
          ${t.project_name ? `<span class="task-tag"><span class="task-dot" style="background:${t.color}"></span>${escapeHtml(t.project_name)}</span>` : ''}
          <span class="task-tag">${fmtDuration(t.estimated_minutes||30)}</span>
          <span class="priority-badge priority-${t.priority}">P${t.priority}</span>
        </div>
      </div>
      <div class="task-actions"><button class="icon-btn" onclick="openTaskModal(${t.id})">✎</button></div>
    </div>
  `).join('') : '<div class="empty">Tutto pianificato 🎯</div>';
}

async function planDay() {
  if (!confirm('Pianificare automaticamente le attività per questa giornata?')) return;
  const r = await api('plan_day', { date: state.todayDate, force: true }, 'POST');
  if (r.ok) toast(r.message);
  loadToday();
}

async function clearDayPlan() {
  if (!confirm('Svuotare il piano di questa giornata?')) return;
  await api('clear_day_plan', { date: state.todayDate }, 'POST');
  toast('Piano svuotato');
  loadToday();
}

// ============= SCHEDULE =============
async function loadSchedule() {
  const r = await api('blocks_list');
  if (!r.ok) return;
  const days = r.data;

  // Day picker (mobile)
  const todayIdx = (new Date().getDay() + 6) % 7;
  document.getElementById('dayPicker').innerHTML = GIORNI_SHORT.map((g, i) => `
    <button class="day-pick ${i === state.scheduleDay ? 'active' : ''} ${i === todayIdx ? 'today' : ''}" onclick="selectScheduleDay(${i})">${g}</button>
  `).join('');

  // Lista mobile (giorno selezionato)
  const blocks = days[state.scheduleDay] || [];
  document.getElementById('dayList').innerHTML = blocks.length ? blocks.map(b => `
    <div class="block-row" onclick="openBlockModal(${b.id})" style="border-left: 4px solid ${b.color}">
      <div class="block-row-time">${b.start_time}<br>${b.end_time}</div>
      <div class="block-row-body">
        <div class="block-row-label">${escapeHtml(b.label)}</div>
        <div class="block-row-meta"><span class="block-type-tag">${TYPE_LABELS[b.type]||b.type}</span></div>
      </div>
      <div class="block-row-arrow">›</div>
    </div>
  `).join('') : '<div class="empty">Nessun blocco. Tocca + Blocco per aggiungere.</div>';

  // Griglia desktop
  const PX_PER_HOUR = 50;
  const HEADER_H = 32;
  const grid = document.getElementById('weekGrid');
  grid.style.height = (24 * PX_PER_HOUR + HEADER_H) + 'px';
  let html = '';
  html += '<div style="position:relative;background:var(--surface-2);border-right:1px solid var(--border);">';
  html += `<div style="height:${HEADER_H}px;border-bottom:1px solid var(--border);"></div>`;
  for (let h = 0; h < 24; h++) {
    html += `<div style="height:${PX_PER_HOUR}px;border-bottom:1px dashed var(--border);font-size:10.5px;color:var(--text-soft);padding:2px 6px;font-variant-numeric:tabular-nums;">${String(h).padStart(2,'0')}:00</div>`;
  }
  html += '</div>';
  for (let wd = 0; wd < 7; wd++) {
    const dayDate = new Date();
    dayDate.setDate(dayDate.getDate() - todayIdx + wd);
    html += `<div class="week-day-col" data-weekday="${wd}" onclick="onWeekColClick(event, ${wd})">`;
    html += `<div class="week-day-head ${wd === todayIdx ? 'today' : ''}"><div class="day-name">${GIORNI_SHORT[wd]}</div><div class="day-num">${dayDate.getDate()}</div></div>`;
    for (let h = 0; h < 24; h++) {
      html += `<div style="position:absolute;left:0;right:0;top:${HEADER_H + h * PX_PER_HOUR}px;height:${PX_PER_HOUR}px;border-bottom:1px dashed var(--border);"></div>`;
    }
    days[wd].forEach(b => {
      const sm = timeToMin(b.start_time);
      let em = timeToMin(b.end_time); if (em <= sm) em = 1440;
      const top = HEADER_H + (sm / 60) * PX_PER_HOUR;
      const height = Math.max(20, ((em - sm) / 60) * PX_PER_HOUR - 2);
      html += `<div class="week-block" style="top:${top}px;height:${height}px;background:${b.color};" onclick="event.stopPropagation();openBlockModal(${b.id})"><div class="week-block-label">${escapeHtml(b.label)}</div><div class="week-block-time">${b.start_time}–${b.end_time}</div></div>`;
    });
    html += '</div>';
  }
  grid.innerHTML = html;
}

function selectScheduleDay(d) {
  state.scheduleDay = d;
  loadSchedule();
}

function onWeekColClick(e, wd) {
  const col = e.currentTarget;
  const rect = col.getBoundingClientRect();
  const y = e.clientY - rect.top - 32;
  if (y < 0) return;
  const totalMin = Math.max(0, Math.round(y / 50 * 60 / 30) * 30);
  openBlockModal(null, { weekday: wd, start_time: minToHHMM(totalMin), end_time: minToHHMM(Math.min(1440, totalMin + 60)) });
}

// ============= DASHBOARD =============
async function loadDashboard() {
  const r = await api('dashboard');
  if (!r.ok) return;
  const s = r.stats;
  document.getElementById('dashSubtitle').textContent =
    `${s.tasks_open} attività aperte${s.tasks_overdue ? ', ' + s.tasks_overdue + ' in ritardo' : ''} · ${s.projects_active} progetti attivi.`;

  document.getElementById('kpiGrid').innerHTML = `
    <div class="kpi"><div class="kpi-label">Progetti</div><div class="kpi-value">${s.projects_active}</div><div class="kpi-sub">su ${s.projects_total}</div></div>
    <div class="kpi"><div class="kpi-label">Attività aperte</div><div class="kpi-value">${s.tasks_open}</div><div class="kpi-sub">${s.tasks_today} per oggi</div></div>
    <div class="kpi ${s.tasks_overdue>0?'danger':''}"><div class="kpi-label">In ritardo</div><div class="kpi-value">${s.tasks_overdue}</div></div>
    <div class="kpi"><div class="kpi-label">Saldo mese</div><div class="kpi-value" style="color:${s.income_month-s.expense_month>=0?'var(--success)':'var(--danger)'}">${fmtMoney(s.income_month-s.expense_month)}</div></div>
  `;

  document.getElementById('dashTasks').innerHTML = r.top_tasks.length ? r.top_tasks.map(taskItemHtml).join('') :
    '<div class="empty">Nessuna attività aperta. 🎯</div>';
  document.getElementById('dashRoutines').innerHTML = r.routines.filter(rr => rr.frequency === 'daily').map(rr => `
    <div class="routine-item ${rr.done_today ? 'done' : ''}" onclick="toggleRoutine(${rr.id})">
      <div class="routine-icon">${escapeHtml(rr.icon)}</div>
      <div class="routine-name">${escapeHtml(rr.title)}</div>
      <div class="routine-time">${rr.time || ''}</div>
    </div>
  `).join('') || '<div class="empty">Nessuna routine.</div>';
  document.getElementById('dashProjects').innerHTML = r.top_projects.length ? r.top_projects.map(p => `
    <div class="project-mini">
      <span class="project-mini-dot" style="background:${p.color}"></span>
      <span class="project-mini-name">${escapeHtml(p.name)}</span>
      <span class="project-mini-meta">${escapeHtml(p.category||'')}</span>
    </div>
  `).join('') : '<div class="empty">Nessun progetto.</div>';
  document.getElementById('dashEvents').innerHTML = r.events_upcoming.length ? r.events_upcoming.map(eventItemHtml).join('') : '<div class="empty">Nessun evento.</div>';
}

function taskItemHtml(t) {
  const due = t.due_date ? new Date(t.due_date) : null;
  const today = new Date(); today.setHours(0,0,0,0);
  const dueClass = due ? (due < today && t.status !== 'done' ? 'overdue' : (due.getTime() === today.getTime() ? 'today' : '')) : '';
  return `
    <div class="task-item ${t.status==='done'?'done':''}">
      <div class="task-check ${t.status==='done'?'done':''}" onclick="toggleTask(${t.id})"></div>
      <div class="task-body">
        <div class="task-title">${escapeHtml(t.title)}</div>
        <div class="task-meta">
          ${t.project_name ? `<span class="task-tag"><span class="task-dot" style="background:${t.color}"></span>${escapeHtml(t.project_name)}</span>` : ''}
          ${t.due_date ? `<span class="task-due ${dueClass}">📅 ${relativeDay(t.due_date)}</span>` : ''}
          <span class="task-tag">${fmtDuration(t.estimated_minutes||30)}</span>
          <span class="priority-badge priority-${t.priority}">P${t.priority}</span>
        </div>
      </div>
      <div class="task-actions">
        <button class="icon-btn" onclick="openTaskModal(${t.id})">✎</button>
        <button class="icon-btn danger" onclick="deleteTask(${t.id})">🗑</button>
      </div>
    </div>
  `;
}

function eventItemHtml(e) {
  const d = new Date(e.start_date);
  return `
    <div class="event-item">
      <div class="event-date"><div class="event-day">${d.getDate()}</div><div class="event-month">${MESI_SHORT[d.getMonth()]}</div></div>
      <div class="event-body">
        <div class="event-title">${escapeHtml(e.title)}</div>
        <div class="event-meta">${e.start_time?'🕐 '+e.start_time+' · ':''}${e.project_name?'📁 '+escapeHtml(e.project_name):(e.category||'')}</div>
      </div>
      <div class="task-actions">
        <button class="icon-btn" onclick="openEventModal(${e.id})">✎</button>
        <button class="icon-btn danger" onclick="deleteEvent(${e.id})">🗑</button>
      </div>
    </div>
  `;
}

// ============= PROJECTS =============
async function loadProjects() {
  const r = await api('projects_list');
  if (!r.ok) return;
  state.projects = r.data;
  renderProjects();
}

function renderProjects() {
  const filter = state.currentProjectFilter;
  const list = state.projects.filter(p => filter === 'all' || p.status === filter);
  const grid = document.getElementById('projectsGrid');
  grid.innerHTML = list.length ? list.map(p => `
    <div class="project-card">
      <div class="project-bar" style="background:${p.color}"></div>
      <div class="project-card-body">
        <div class="project-card-head">
          <div><div class="project-name">${escapeHtml(p.name)}</div><div class="project-cat">${escapeHtml(p.category||'—')}</div></div>
          <span class="status-badge status-${p.status}">${p.status}</span>
        </div>
        <div class="project-desc">${escapeHtml(p.description||'')}</div>
        ${p.next_action ? `<div class="project-next"><b>Prossima azione</b>${escapeHtml(p.next_action)}</div>` : ''}
        <div class="project-actions">
          ${p.url ? `<a href="${escapeHtml(p.url)}" target="_blank">🔗 Apri</a>` : ''}
          ${p.path ? `<a onclick="navigator.clipboard.writeText('${escapeHtml(p.path)}');toast('Percorso copiato')">📁 Copia path</a>` : ''}
          <button class="icon-btn" onclick="openProjectModal(${p.id})" style="margin-left:auto">✎</button>
          <button class="icon-btn danger" onclick="archiveProject(${p.id})">🗑</button>
        </div>
      </div>
    </div>
  `).join('') : `<div class="empty"><div class="empty-icon">📁</div>Nessun progetto. Tocca + Nuovo per aggiungere.</div>`;
}

async function archiveProject(id) {
  if (!confirm('Archiviare questo progetto?')) return;
  await api('project_archive', { id }, 'POST');
  toast('Progetto archiviato');
  loadProjects();
}

// ============= TASKS =============
async function loadTasks() {
  const r = await api('tasks_list', { filter: state.currentTaskFilter });
  if (!r.ok) return;
  document.getElementById('tasksList').innerHTML = r.data.length ? r.data.map(taskItemHtml).join('') :
    `<div class="empty"><div class="empty-icon">✓</div>Nessuna attività in questa vista.</div>`;
}

async function toggleTask(id) {
  await api('task_toggle', { id }, 'POST');
  loadView(state.view);
}

async function deleteTask(id) {
  if (!confirm('Eliminare questa attività?')) return;
  await api('task_delete', { id }, 'POST');
  toast('Eliminata');
  loadView(state.view);
}

// ============= ROUTINES =============
async function loadRoutines() {
  const r = await api('routines_list');
  if (!r.ok) return;
  const grid = document.getElementById('routinesList');
  const days = [];
  for (let i = 6; i >= 0; i--) { const d = new Date(); d.setDate(d.getDate() - i); days.push(d.toISOString().slice(0, 10)); }
  const todayStr = new Date().toISOString().slice(0,10);

  grid.innerHTML = r.data.length ? r.data.map(rr => {
    const logsByDate = {};
    (rr.week || []).forEach(l => logsByDate[l.date] = l.done);
    return `
      <div class="routine-card">
        <div class="routine-card-head">
          <div class="routine-card-icon">${escapeHtml(rr.icon)}</div>
          <div style="flex:1;min-width:0">
            <div class="routine-card-title">${escapeHtml(rr.title)}</div>
            <div class="routine-card-sub">${rr.frequency==='daily'?'Ogni giorno':(rr.frequency==='weekly'?'Settimanale':'Mensile')}${rr.time?' · '+rr.time:''}${rr.category?' · '+escapeHtml(rr.category):''}</div>
          </div>
          <button class="icon-btn" onclick="openRoutineModal(${rr.id})">✎</button>
          <button class="icon-btn danger" onclick="deleteRoutine(${rr.id})">🗑</button>
        </div>
        <button class="btn ${rr.done_today?'ghost':'primary'} sm" onclick="toggleRoutine(${rr.id})" style="width:100%">
          ${rr.done_today ? '✓ Fatto oggi' : 'Segna come fatto'}
        </button>
        <div class="routine-week">
          ${days.map(d => {
            const dayName = GIORNI_SHORT[(new Date(d).getDay() + 6) % 7];
            const isToday = d === todayStr;
            const done = logsByDate[d];
            return `<div class="routine-day ${done?'done':''} ${isToday?'today':''}" title="${d}">${dayName}<br><b>${new Date(d).getDate()}</b></div>`;
          }).join('')}
        </div>
      </div>
    `;
  }).join('') : `<div class="empty"><div class="empty-icon">↻</div>Nessuna routine.</div>`;
}

async function toggleRoutine(id) { await api('routine_check', { id }, 'POST'); loadView(state.view); }
async function deleteRoutine(id) { if (!confirm('Eliminare questa routine?')) return; await api('routine_delete', { id }, 'POST'); toast('Eliminata'); loadRoutines(); }

// ============= CALENDAR =============
function changeMonth(delta) {
  if (delta === 0) state.calendarMonth = ymNow();
  else { const [y,m] = state.calendarMonth.split('-').map(Number); const d = new Date(y, m-1+delta, 1); state.calendarMonth = d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0'); }
  loadCalendar();
}

async function loadCalendar() {
  const r = await api('events_list', { month: state.calendarMonth });
  if (!r.ok) return;
  const events = r.data;
  const [y, m] = state.calendarMonth.split('-').map(Number);
  document.getElementById('calSubtitle').textContent = `${MESI[m-1]} ${y}`;
  const firstDay = new Date(y, m-1, 1);
  const lastDay = new Date(y, m, 0);
  const startWeekday = (firstDay.getDay() + 6) % 7;
  const daysInMonth = lastDay.getDate();
  const totalCells = Math.ceil((startWeekday + daysInMonth) / 7) * 7;
  const todayStr = new Date().toISOString().slice(0,10);

  let html = GIORNI_SHORT.map(d => `<div class="cal-head-cell">${d}</div>`).join('');
  for (let i = 0; i < totalCells; i++) {
    const dayNum = i - startWeekday + 1;
    const inMonth = dayNum >= 1 && dayNum <= daysInMonth;
    const dateStr = inMonth ? `${y}-${String(m).padStart(2,'0')}-${String(dayNum).padStart(2,'0')}` : '';
    const dayEvents = events.filter(e => e.start_date === dateStr);
    const isToday = dateStr === todayStr;
    html += `
      <div class="cal-cell ${inMonth?'':'other-month'} ${isToday?'today':''}">
        ${inMonth?`<div class="cal-day-num">${dayNum}</div>`:''}
        ${dayEvents.slice(0,3).map(e => `<div class="cal-event" style="background:${(e.color||'#eef2ff')}33;color:${e.color||'#4f46e5'}" onclick="openEventModal(${e.id})">${escapeHtml(e.title)}</div>`).join('')}
        ${dayEvents.length>3?`<div style="font-size:10px;color:var(--text-muted);margin-top:2px">+${dayEvents.length-3}</div>`:''}
      </div>
    `;
  }
  document.getElementById('calendarGrid').innerHTML = html;
  document.getElementById('eventsList').innerHTML = events.length ? events.map(eventItemHtml).join('') : '<div class="empty">Nessun evento.</div>';
}

async function deleteEvent(id) { if (!confirm('Eliminare questo evento?')) return; await api('event_delete', { id }, 'POST'); toast('Eliminato'); loadView(state.view); }

// ============= GOALS =============
async function loadGoals() {
  const r = await api('goals_list');
  if (!r.ok) return;
  document.getElementById('goalsList').innerHTML = r.data.length ? r.data.map(g => {
    const pct = g.target_value > 0 ? Math.min(100, (g.current_value / g.target_value) * 100) : 0;
    return `
      <div class="goal-card">
        <div class="goal-cat">${escapeHtml(g.category||'Generale')}</div>
        <div class="goal-title">${escapeHtml(g.title)}</div>
        ${g.description ? `<div class="goal-desc">${escapeHtml(g.description)}</div>` : ''}
        <div class="goal-progress-bar"><div class="goal-progress-fill" style="width:${pct}%"></div></div>
        <div class="goal-progress-text"><span><b>${g.current_value||0}</b> / ${g.target_value||0} ${escapeHtml(g.unit||'')}</span><span>${pct.toFixed(0)}%</span></div>
        ${g.deadline ? `<div style="font-size:12px;color:var(--text-muted);margin-top:8px">📅 Entro ${relativeDay(g.deadline)}</div>` : ''}
        <div style="display:flex;gap:6px;margin-top:12px">
          <button class="btn ghost sm" onclick="openGoalModal(${g.id})" style="flex:1">Modifica</button>
          <button class="icon-btn danger" onclick="deleteGoal(${g.id})">🗑</button>
        </div>
      </div>
    `;
  }).join('') : `<div class="empty"><div class="empty-icon">◎</div>Nessun obiettivo.</div>`;
}

async function deleteGoal(id) { if (!confirm('Eliminare?')) return; await api('goal_delete', { id }, 'POST'); toast('Eliminato'); loadGoals(); }

// ============= FINANCES =============
function changeFinMonth(delta) {
  if (delta === 0) state.finMonth = ymNow();
  else { const [y,m] = state.finMonth.split('-').map(Number); const d = new Date(y, m-1+delta, 1); state.finMonth = d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0'); }
  loadFinances();
}

async function loadFinances() {
  const r = await api('finances_list', { month: state.finMonth });
  if (!r.ok) return;
  const [y, m] = state.finMonth.split('-').map(Number);
  document.getElementById('finSubtitle').textContent = `${MESI[m-1]} ${y}`;
  document.getElementById('finKpi').innerHTML = `
    <div class="kpi positive"><div class="kpi-label">Entrate</div><div class="kpi-value">${fmtMoney(r.totals.income)}</div></div>
    <div class="kpi danger"><div class="kpi-label">Uscite</div><div class="kpi-value">${fmtMoney(r.totals.expense)}</div></div>
    <div class="kpi"><div class="kpi-label">Saldo</div><div class="kpi-value" style="color:${r.totals.balance>=0?'var(--success)':'var(--danger)'}">${fmtMoney(r.totals.balance)}</div></div>
  `;
  document.getElementById('financesList').innerHTML = r.data.length ? r.data.map(f => `
    <div class="finance-item">
      <div class="finance-icon ${f.type}">${f.type==='entrata'?'+':'−'}</div>
      <div class="finance-body">
        <div class="finance-desc">${escapeHtml(f.description||'(senza descrizione)')}</div>
        <div class="finance-meta">${fmtDate(f.date)}${f.category?' · '+escapeHtml(f.category):''}${f.project_name?' · '+escapeHtml(f.project_name):''}</div>
      </div>
      <div class="finance-amount ${f.type}">${f.type==='entrata'?'+':'−'}${fmtMoney(f.amount)}</div>
      <div class="task-actions"><button class="icon-btn danger" onclick="deleteFinance(${f.id})">🗑</button></div>
    </div>
  `).join('') : '<div class="empty">Nessun movimento.</div>';
}

async function deleteFinance(id) { if (!confirm('Eliminare?')) return; await api('finance_delete', { id }, 'POST'); toast('Eliminato'); loadFinances(); }

// ============= NOTES =============
async function loadNotes() {
  const r = await api('notes_list');
  if (!r.ok) return;
  document.getElementById('notesGrid').innerHTML = r.data.length ? r.data.map(n => `
    <div class="note-card ${n.pinned?'pinned':''}" onclick="openNoteModal(${n.id})">
      ${n.pinned?'<span class="note-pin">📌</span>':''}
      <div class="note-title">${escapeHtml(n.title)}</div>
      <div class="note-content">${escapeHtml(n.content||'')}</div>
      ${n.tag?`<span class="note-tag">${escapeHtml(n.tag)}</span>`:''}
    </div>
  `).join('') : `<div class="empty"><div class="empty-icon">✎</div>Nessuna nota.</div>`;
}

// ============= PROFILE =============
function loadProfile() {
  const u = state.user;
  document.getElementById('profileInfo').innerHTML = `
    <div class="profile-row"><span>Email</span><b>${escapeHtml(u.email)}</b></div>
    <div class="profile-row"><span>Nome</span>
      <input id="profileName" value="${escapeHtml(u.name||'')}" style="text-align:right;border:none;background:transparent;font-weight:600;color:var(--text);max-width:60%">
    </div>
    <div class="profile-row"><span>Ruolo</span><b>${u.is_admin?'Admin':'Utente'}</b></div>
    <button class="btn primary sm" onclick="saveProfile()" style="margin-top:12px">Salva nome</button>
  `;
}

async function saveProfile() {
  const name = document.getElementById('profileName').value.trim();
  const r = await api('update_profile', { name }, 'POST');
  if (r.ok) {
    state.user.name = name;
    document.getElementById('userName').textContent = name || state.user.email.split('@')[0];
    document.getElementById('userAvatar').textContent = (name || state.user.email)[0].toUpperCase();
    toast('Profilo aggiornato');
  }
}

// ============= ADMIN =============
async function loadAdmin() {
  if (!state.user.is_admin) { switchView('today'); return; }
  const r = await api('admin_users_list');
  if (!r.ok) return;
  document.getElementById('adminUsersList').innerHTML = `
    <div class="admin-table">
      <div class="admin-row admin-head">
        <div>Email</div><div>Nome</div><div>Stato</div><div>Verificato</div><div>Creato</div><div>Azioni</div>
      </div>
      ${r.data.map(u => `
        <div class="admin-row">
          <div>${escapeHtml(u.email)} ${u.is_admin?'<span class="badge-admin">admin</span>':''}</div>
          <div>${escapeHtml(u.name||'—')}</div>
          <div>${u.active ? '<span class="dot-green"></span> Attivo' : '<span class="dot-red"></span> Sospeso'}</div>
          <div>${u.email_verified ? '✓' : '✗'}</div>
          <div class="muted">${u.created_at ? u.created_at.slice(0,10) : ''}</div>
          <div>
            ${!u.email_verified ? `<button class="btn ghost sm" onclick="adminVerify(${u.id})">Verifica</button>` : ''}
            ${u.id !== state.user.id ? `<button class="btn ${u.active?'danger':'ghost'} sm" onclick="adminToggle(${u.id})">${u.active?'Sospendi':'Attiva'}</button>` : ''}
          </div>
        </div>
      `).join('')}
    </div>
  `;
}

async function adminVerify(id) { await api('admin_user_verify', { id }, 'POST'); toast('Verificato'); loadAdmin(); }
async function adminToggle(id) { await api('admin_user_toggle_active', { id }, 'POST'); loadAdmin(); }

// ============= MODALS =============
function showModal(title, html) {
  document.getElementById('modalTitle').textContent = title;
  document.getElementById('modalBody').innerHTML = html;
  document.getElementById('modal').classList.remove('hidden');
}
function closeModal() { document.getElementById('modal').classList.add('hidden'); }
document.getElementById('modal').addEventListener('click', e => { if (e.target.id === 'modal') closeModal(); });

async function openTaskModal(id) {
  const projectsR = await api('projects_list');
  const projects = projectsR.data || [];
  let task = { title: '', notes: '', project_id: '', priority: 3, due_date: '', status: 'todo', estimated_minutes: 30 };
  if (id) {
    const r = await api('tasks_list', { filter: 'all' });
    task = (r.data || []).find(t => t.id === id) || task;
  }
  showModal(id ? 'Modifica attività' : 'Nuova attività', `
    <form id="taskForm">
      <div class="field"><label>Titolo</label><input name="title" required value="${escapeHtml(task.title)}" autofocus></div>
      <div class="field"><label>Note</label><textarea name="notes">${escapeHtml(task.notes)}</textarea></div>
      <div class="field-row">
        <div class="field"><label>Progetto</label><select name="project_id"><option value="">Nessuno</option>${projects.map(p => `<option value="${p.id}" ${task.project_id==p.id?'selected':''}>${escapeHtml(p.name)}</option>`).join('')}</select></div>
        <div class="field"><label>Priorità</label><select name="priority"><option value="1" ${task.priority==1?'selected':''}>P1 — Critica</option><option value="2" ${task.priority==2?'selected':''}>P2 — Alta</option><option value="3" ${task.priority==3?'selected':''}>P3 — Media</option><option value="4" ${task.priority==4?'selected':''}>P4 — Bassa</option></select></div>
      </div>
      <div class="field-row">
        <div class="field"><label>Scadenza</label><input type="date" name="due_date" value="${task.due_date||''}"></div>
        <div class="field"><label>Durata stimata</label><select name="estimated_minutes">
          <option value="15" ${task.estimated_minutes==15?'selected':''}>15 min</option>
          <option value="30" ${task.estimated_minutes==30?'selected':''}>30 min</option>
          <option value="45" ${task.estimated_minutes==45?'selected':''}>45 min</option>
          <option value="60" ${task.estimated_minutes==60?'selected':''}>1 ora</option>
          <option value="90" ${task.estimated_minutes==90?'selected':''}>1h 30min</option>
          <option value="120" ${task.estimated_minutes==120?'selected':''}>2 ore</option>
          <option value="180" ${task.estimated_minutes==180?'selected':''}>3 ore</option>
          <option value="240" ${task.estimated_minutes==240?'selected':''}>4 ore</option>
        </select></div>
      </div>
    </form>
    <div class="modal-foot">
      ${id?`<button class="btn danger" style="margin-right:auto" onclick="deleteTask(${id});closeModal()">Elimina</button>`:''}
      <button class="btn ghost" onclick="closeModal()">Annulla</button>
      <button class="btn primary" onclick="saveTask(${id||'null'})">Salva</button>
    </div>
  `);
}

async function saveTask(id) {
  const f = document.getElementById('taskForm');
  const data = Object.fromEntries(new FormData(f));
  if (id) data.id = id;
  if (!data.title) { toast('Titolo richiesto'); return; }
  await api('task_save', data, 'POST');
  closeModal(); toast('Attività salvata'); loadView(state.view);
}

async function openProjectModal(id) {
  let p = { name:'', category:'', status:'attivo', priority:3, path:'', url:'', description:'', next_action:'', color:'#6366f1' };
  if (id) { const r = await api('projects_list'); p = (r.data||[]).find(x => x.id === id) || p; }
  showModal(id ? 'Modifica progetto' : 'Nuovo progetto', `
    <form id="projectForm">
      <div class="field-row">
        <div class="field"><label>Nome</label><input name="name" required value="${escapeHtml(p.name)}" autofocus></div>
        <div class="field" style="flex:0 0 80px"><label>Colore</label><input type="color" name="color" value="${p.color}" style="height:42px;padding:2px"></div>
      </div>
      <div class="field-row">
        <div class="field"><label>Categoria</label><input name="category" value="${escapeHtml(p.category)}"></div>
        <div class="field"><label>Stato</label><select name="status"><option value="attivo" ${p.status==='attivo'?'selected':''}>Attivo</option><option value="pausa" ${p.status==='pausa'?'selected':''}>In pausa</option><option value="completato" ${p.status==='completato'?'selected':''}>Completato</option></select></div>
        <div class="field"><label>Priorità</label><select name="priority"><option value="1" ${p.priority==1?'selected':''}>P1</option><option value="2" ${p.priority==2?'selected':''}>P2</option><option value="3" ${p.priority==3?'selected':''}>P3</option><option value="4" ${p.priority==4?'selected':''}>P4</option></select></div>
      </div>
      <div class="field"><label>Descrizione</label><textarea name="description">${escapeHtml(p.description)}</textarea></div>
      <div class="field"><label>Prossima azione</label><input name="next_action" value="${escapeHtml(p.next_action)}"></div>
      <div class="field-row">
        <div class="field"><label>Cartella</label><input name="path" value="${escapeHtml(p.path)}"></div>
        <div class="field"><label>URL</label><input name="url" value="${escapeHtml(p.url)}"></div>
      </div>
    </form>
    <div class="modal-foot">
      <button class="btn ghost" onclick="closeModal()">Annulla</button>
      <button class="btn primary" onclick="saveProject(${id||'null'})">Salva</button>
    </div>
  `);
}
async function saveProject(id) { const f = document.getElementById('projectForm'); const data = Object.fromEntries(new FormData(f)); if (id) data.id = id; if (!data.name) { toast('Nome richiesto'); return; } await api('project_save', data, 'POST'); closeModal(); toast('Salvato'); loadProjects(); }

async function openRoutineModal(id) {
  let r = { title:'', icon:'✓', frequency:'daily', time:'', category:'', active:1 };
  if (id) { const rr = await api('routines_list'); r = (rr.data||[]).find(x => x.id === id) || r; }
  showModal(id ? 'Modifica routine' : 'Nuova routine', `
    <form id="routineForm">
      <div class="field-row">
        <div class="field" style="flex:0 0 80px"><label>Icona</label><input name="icon" value="${escapeHtml(r.icon)}" maxlength="2" style="text-align:center;font-size:20px"></div>
        <div class="field"><label>Titolo</label><input name="title" required value="${escapeHtml(r.title)}" autofocus></div>
      </div>
      <div class="field-row">
        <div class="field"><label>Frequenza</label><select name="frequency"><option value="daily" ${r.frequency==='daily'?'selected':''}>Giornaliera</option><option value="weekly" ${r.frequency==='weekly'?'selected':''}>Settimanale</option><option value="monthly" ${r.frequency==='monthly'?'selected':''}>Mensile</option></select></div>
        <div class="field"><label>Ora</label><input type="time" name="time" value="${r.time||''}"></div>
      </div>
      <div class="field"><label>Categoria</label><input name="category" value="${escapeHtml(r.category)}"></div>
    </form>
    <div class="modal-foot">
      <button class="btn ghost" onclick="closeModal()">Annulla</button>
      <button class="btn primary" onclick="saveRoutine(${id||'null'})">Salva</button>
    </div>
  `);
}
async function saveRoutine(id) { const f = document.getElementById('routineForm'); const data = Object.fromEntries(new FormData(f)); if (id) data.id = id; if (!data.title) { toast('Titolo richiesto'); return; } await api('routine_save', data, 'POST'); closeModal(); toast('Salvata'); loadRoutines(); }

async function openEventModal(id) {
  const projectsR = await api('projects_list');
  const projects = projectsR.data || [];
  let e = { title:'', description:'', start_date: new Date().toISOString().slice(0,10), start_time:'', category:'', project_id:'' };
  if (id) { const rr = await api('events_list', { month: state.calendarMonth }); e = (rr.data||[]).find(x => x.id === id) || e; }
  showModal(id ? 'Modifica evento' : 'Nuovo evento', `
    <form id="eventForm">
      <div class="field"><label>Titolo</label><input name="title" required value="${escapeHtml(e.title)}" autofocus></div>
      <div class="field-row">
        <div class="field"><label>Data</label><input type="date" name="start_date" required value="${e.start_date}"></div>
        <div class="field"><label>Ora</label><input type="time" name="start_time" value="${e.start_time||''}"></div>
      </div>
      <div class="field-row">
        <div class="field"><label>Progetto</label><select name="project_id"><option value="">Nessuno</option>${projects.map(p => `<option value="${p.id}" ${e.project_id==p.id?'selected':''}>${escapeHtml(p.name)}</option>`).join('')}</select></div>
        <div class="field"><label>Categoria</label><input name="category" value="${escapeHtml(e.category)}"></div>
      </div>
      <div class="field"><label>Descrizione</label><textarea name="description">${escapeHtml(e.description)}</textarea></div>
    </form>
    <div class="modal-foot">
      <button class="btn ghost" onclick="closeModal()">Annulla</button>
      <button class="btn primary" onclick="saveEvent(${id||'null'})">Salva</button>
    </div>
  `);
}
async function saveEvent(id) { const f = document.getElementById('eventForm'); const data = Object.fromEntries(new FormData(f)); if (id) data.id = id; if (!data.title || !data.start_date) { toast('Titolo e data richiesti'); return; } await api('event_save', data, 'POST'); closeModal(); toast('Salvato'); loadView(state.view); }

async function openGoalModal(id) {
  let g = { title:'', description:'', target_value:0, current_value:0, unit:'', deadline:'', category:'', status:'attivo' };
  if (id) { const r = await api('goals_list'); g = (r.data||[]).find(x => x.id === id) || g; }
  showModal(id ? 'Modifica obiettivo' : 'Nuovo obiettivo', `
    <form id="goalForm">
      <div class="field"><label>Titolo</label><input name="title" required value="${escapeHtml(g.title)}" autofocus></div>
      <div class="field"><label>Descrizione</label><textarea name="description">${escapeHtml(g.description)}</textarea></div>
      <div class="field-row">
        <div class="field"><label>Attuale</label><input type="number" step="0.01" name="current_value" value="${g.current_value}"></div>
        <div class="field"><label>Obiettivo</label><input type="number" step="0.01" name="target_value" value="${g.target_value}"></div>
        <div class="field"><label>Unità</label><input name="unit" value="${escapeHtml(g.unit)}"></div>
      </div>
      <div class="field-row">
        <div class="field"><label>Categoria</label><input name="category" value="${escapeHtml(g.category)}"></div>
        <div class="field"><label>Scadenza</label><input type="date" name="deadline" value="${g.deadline||''}"></div>
      </div>
    </form>
    <div class="modal-foot">
      <button class="btn ghost" onclick="closeModal()">Annulla</button>
      <button class="btn primary" onclick="saveGoal(${id||'null'})">Salva</button>
    </div>
  `);
}
async function saveGoal(id) { const f = document.getElementById('goalForm'); const data = Object.fromEntries(new FormData(f)); if (id) data.id = id; if (!data.title) { toast('Titolo richiesto'); return; } await api('goal_save', data, 'POST'); closeModal(); toast('Salvato'); loadGoals(); }

async function openNoteModal(id) {
  let n = { title:'', content:'', tag:'', pinned:0 };
  if (id) { const r = await api('notes_list'); n = (r.data||[]).find(x => x.id === id) || n; }
  showModal(id ? 'Modifica nota' : 'Nuova nota', `
    <form id="noteForm">
      <div class="field"><label>Titolo</label><input name="title" required value="${escapeHtml(n.title)}" autofocus></div>
      <div class="field"><label>Contenuto</label><textarea name="content" style="min-height:160px">${escapeHtml(n.content)}</textarea></div>
      <div class="field-row">
        <div class="field"><label>Tag</label><input name="tag" value="${escapeHtml(n.tag)}"></div>
        <div class="field" style="flex:0 0 120px"><label>Fissata</label><select name="pinned"><option value="0" ${!n.pinned?'selected':''}>No</option><option value="1" ${n.pinned?'selected':''}>📌 Sì</option></select></div>
      </div>
    </form>
    <div class="modal-foot">
      ${id?`<button class="btn danger" style="margin-right:auto" onclick="deleteNote(${id})">Elimina</button>`:''}
      <button class="btn ghost" onclick="closeModal()">Annulla</button>
      <button class="btn primary" onclick="saveNote(${id||'null'})">Salva</button>
    </div>
  `);
}
async function saveNote(id) { const f = document.getElementById('noteForm'); const data = Object.fromEntries(new FormData(f)); if (id) data.id = id; if (!data.title) { toast('Titolo richiesto'); return; } await api('note_save', data, 'POST'); closeModal(); toast('Salvata'); loadNotes(); }
async function deleteNote(id) { if (!confirm('Eliminare?')) return; await api('note_delete', { id }, 'POST'); closeModal(); toast('Eliminata'); loadNotes(); }

async function openFinanceModal() {
  const projectsR = await api('projects_list');
  const projects = projectsR.data || [];
  showModal('Nuovo movimento', `
    <form id="financeForm">
      <div class="field-row">
        <div class="field"><label>Tipo</label><select name="type"><option value="entrata">Entrata</option><option value="uscita">Uscita</option></select></div>
        <div class="field"><label>Importo €</label><input type="number" step="0.01" name="amount" required value="0" autofocus></div>
        <div class="field"><label>Data</label><input type="date" name="date" required value="${new Date().toISOString().slice(0,10)}"></div>
      </div>
      <div class="field"><label>Descrizione</label><input name="description"></div>
      <div class="field-row">
        <div class="field"><label>Progetto</label><select name="project_id"><option value="">Nessuno</option>${projects.map(p => `<option value="${p.id}">${escapeHtml(p.name)}</option>`).join('')}</select></div>
        <div class="field"><label>Categoria</label><input name="category"></div>
      </div>
    </form>
    <div class="modal-foot">
      <button class="btn ghost" onclick="closeModal()">Annulla</button>
      <button class="btn primary" onclick="saveFinance()">Salva</button>
    </div>
  `);
}
async function saveFinance() { const f = document.getElementById('financeForm'); const data = Object.fromEntries(new FormData(f)); if (!data.amount || !data.date) { toast('Importo e data richiesti'); return; } await api('finance_save', data, 'POST'); closeModal(); toast('Salvato'); loadFinances(); }

async function openBlockModal(id, defaults = {}) {
  let b = { weekday: defaults.weekday ?? state.scheduleDay, start_time: defaults.start_time ?? '09:00', end_time: defaults.end_time ?? '10:00', type:'lavoro', label:'', color:'#4f46e5', notes:'' };
  if (id) {
    const r = await api('blocks_list');
    for (const day of r.data) { const found = day.find(x => x.id === id); if (found) { b = found; break; } }
  }
  showModal(id ? 'Modifica blocco' : 'Nuovo blocco', `
    <form id="blockForm">
      <div class="field"><label>Etichetta</label><input name="label" required value="${escapeHtml(b.label)}" placeholder="Es: Palestra, Lavoro mattina, Pranzo..." autofocus></div>
      <div class="field-row">
        <div class="field"><label>Giorno</label><select name="weekday">${GIORNI.map((g,i) => `<option value="${i}" ${b.weekday==i?'selected':''}>${g}</option>`).join('')}</select></div>
        <div class="field"><label>Tipo</label><select name="type" onchange="document.querySelector('[name=color]').value = ({sonno:'#94a3b8',sport:'#f59e0b',pasto:'#10b981',lavoro:'#4f46e5',personale:'#8b5cf6',libero:'#06b6d4'})[this.value]">${Object.entries(TYPE_LABELS).map(([k,v]) => `<option value="${k}" ${b.type===k?'selected':''}>${v}</option>`).join('')}</select></div>
        <div class="field" style="flex:0 0 70px"><label>Colore</label><input type="color" name="color" value="${b.color}" style="height:42px;padding:2px"></div>
      </div>
      <div class="field-row">
        <div class="field"><label>Inizio</label><input type="time" name="start_time" required value="${b.start_time}"></div>
        <div class="field"><label>Fine</label><input type="time" name="end_time" required value="${b.end_time}"></div>
      </div>
      <div class="field"><label>Note</label><textarea name="notes" rows="2">${escapeHtml(b.notes||'')}</textarea></div>
    </form>
    <div class="modal-foot">
      ${id?`<button class="btn danger" style="margin-right:auto" onclick="deleteBlock(${id})">Elimina</button>`:''}
      <button class="btn ghost" onclick="closeModal()">Annulla</button>
      <button class="btn primary" onclick="saveBlock(${id||'null'})">Salva</button>
    </div>
  `);
}
async function saveBlock(id) { const f = document.getElementById('blockForm'); const data = Object.fromEntries(new FormData(f)); if (id) data.id = id; if (!data.label) { toast('Etichetta richiesta'); return; } await api('block_save', data, 'POST'); closeModal(); toast('Salvato'); loadView(state.view); }
async function deleteBlock(id) { if (!confirm('Eliminare?')) return; await api('block_delete', { id }, 'POST'); closeModal(); toast('Eliminato'); loadView(state.view); }

function openCopyDayModal() {
  showModal('Copia giorno', `
    <p style="color:var(--text-muted);margin-bottom:16px;font-size:13px">Sostituisce i blocchi del giorno destinazione con quelli del giorno sorgente.</p>
    <div class="field-row">
      <div class="field"><label>Da</label><select id="copyFrom">${GIORNI.map((g,i)=>`<option value="${i}">${g}</option>`).join('')}</select></div>
      <div class="field"><label>A</label><select id="copyTo">${GIORNI.map((g,i)=>`<option value="${i}">${g}</option>`).join('')}</select></div>
    </div>
    <div class="modal-foot">
      <button class="btn ghost" onclick="closeModal()">Annulla</button>
      <button class="btn primary" onclick="doCopyDay()">Copia</button>
    </div>
  `);
}
async function doCopyDay() { const from = +document.getElementById('copyFrom').value; const to = +document.getElementById('copyTo').value; if (from === to) { toast('Sorgente = destinazione'); return; } await api('blocks_copy_day', { from, to }, 'POST'); closeModal(); toast('Copiato'); loadSchedule(); }

// ============= AUTH FORM HANDLERS =============
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.auth-tab').forEach(t => t.addEventListener('click', () => showAuthTab(t.dataset.authTab)));
  document.getElementById('loginForm').addEventListener('submit', e => {
    e.preventDefault();
    const f = e.target;
    document.getElementById('loginErr').classList.add('hidden');
    attemptLogin(f.email.value.trim(), f.password.value);
  });
  document.getElementById('registerForm').addEventListener('submit', e => {
    e.preventDefault();
    const f = e.target;
    document.getElementById('registerErr').classList.add('hidden');
    attemptRegister(f.name.value.trim(), f.email.value.trim(), f.password.value);
  });
  document.getElementById('changePwdForm').addEventListener('submit', async e => {
    e.preventDefault();
    const f = e.target;
    const r = await api('change_password', { current: f.current.value, new: f.new.value }, 'POST');
    if (r.ok) { f.reset(); toast('Password aggiornata'); }
    else toast(r.error);
  });

  document.querySelectorAll('[data-filter]').forEach(c => c.addEventListener('click', () => {
    document.querySelectorAll('[data-filter]').forEach(b => b.classList.toggle('active', b === c));
    state.currentProjectFilter = c.dataset.filter; renderProjects();
  }));
  document.querySelectorAll('[data-tfilter]').forEach(c => c.addEventListener('click', () => {
    document.querySelectorAll('[data-tfilter]').forEach(b => b.classList.toggle('active', b === c));
    state.currentTaskFilter = c.dataset.tfilter; loadTasks();
  }));

  bootAuth();
});
