// Aetheris front-end behaviour. Plain JavaScript, no libraries.
// Sections: helpers · menus & themes · sidebar · pop-up dialogs · confirm buttons · countdowns ·
// upload box · rubric totals · consultation step tabs · landing preview · class chat · exam · exam builder

/* ---------- helpers ---------- */
const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];
const csrf = $('meta[name="csrf-token"]')?.content ?? "";

/** Send an action to the server and get JSON back (the server knows by the X-Requested-With header). */
async function post(action, data = {}) {
  try {
    const res = await fetch("index.php", {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf, "X-Requested-With": "fetch" },
      body: JSON.stringify({ action, ...data }),
    });
    const json = await res.json().catch(() => ({}));
    return { status: res.status, ok: res.ok && json.ok !== false, ...json };
  } catch {
    return { ok: false, status: 0, error: "No connection. Check your internet and try again." };
  }
}

const pad = (n) => String(n).padStart(2, "0");
function formatLeft(ms) {
  const s = Math.max(0, Math.floor(ms / 1000));
  const d = Math.floor(s / 86400);
  const hms = `${pad(Math.floor((s % 86400) / 3600))}:${pad(Math.floor((s % 3600) / 60))}:${pad(s % 60)}`;
  return d > 0 ? `${d}d ${hms}` : hms;
}

/* ---------- menus & themes ---------- */
// A menu button opens or closes its own menu; a click anywhere else closes open menus.
document.addEventListener("click", (e) => {
  const toggle = e.target.closest("[data-menu]");
  $$(".menu").forEach((menu) => {
    if (toggle?.dataset.menu === menu.id) menu.hidden = !menu.hidden;
    else if (!menu.contains(e.target) || e.target.closest("[data-theme-choice]")) menu.hidden = true;
    $(`[data-menu="${menu.id}"]`)?.setAttribute("aria-expanded", String(!menu.hidden));
  });
});
document.addEventListener("keydown", (e) => {
  if (e.key === "Escape") $$(".menu").forEach((m) => (m.hidden = true));
});

function markTheme(theme) {
  $$("[data-theme-choice]").forEach((b) => b.setAttribute("aria-checked", String(b.dataset.themeChoice === theme)));
}
markTheme(document.documentElement.dataset.theme);
$$("[data-theme-choice]").forEach((button) =>
  button.addEventListener("click", () => {
    const theme = button.dataset.themeChoice;
    document.documentElement.dataset.theme = theme; // switch instantly, then save
    markTheme(theme);
    post("set_theme", { theme });
  })
);

/* ---------- sidebar ---------- */
try {
  if (localStorage.getItem("aetheris.sidebar") === "collapsed") document.body.classList.add("sidebar-collapsed");
} catch {}
$("[data-collapse-sidebar]")?.addEventListener("click", () => {
  const collapsed = document.body.classList.toggle("sidebar-collapsed");
  try { localStorage.setItem("aetheris.sidebar", collapsed ? "collapsed" : "open"); } catch {}
});
$("[data-open-sidebar]")?.addEventListener("click", () => document.body.classList.add("menu-open"));
$("[data-close-sidebar]")?.addEventListener("click", () => document.body.classList.remove("menu-open"));

/* ---------- pop-up dialogs (<dialog>) ---------- */
$$("[data-open-dialog]").forEach((b) => b.addEventListener("click", () => document.getElementById(b.dataset.openDialog)?.showModal()));
$$("[data-close-dialog]").forEach((b) => b.addEventListener("click", () => b.closest("dialog")?.close()));
$$("dialog").forEach((d) => d.addEventListener("click", (e) => e.target === d && d.close())); // click outside closes

/* ---------- confirm buttons: the first click asks, the second click does it ---------- */
document.addEventListener("click", (e) => {
  const button = e.target.closest("[data-confirm]");
  if (!button) return;
  if (button.dataset.armed) return; // second click: let the form submit
  e.preventDefault();
  const original = button.innerHTML;
  button.dataset.armed = "1";
  button.textContent = button.dataset.confirm;
  setTimeout(() => {
    delete button.dataset.armed;
    button.innerHTML = original;
  }, 4000);
});

/* ---------- countdowns ---------- */
function tickCountdowns() {
  $$(".countdown[data-deadline]").forEach((el) => {
    const left = Date.parse(el.dataset.deadline) - Date.now();
    if (left <= 0) {
      el.textContent = el.dataset.closed || "Closed";
      el.className = "countdown closed";
      return;
    }
    el.textContent = formatLeft(left);
    el.classList.toggle("urgent", left < 3600e3);
    el.classList.toggle("soon", left >= 3600e3 && left < 86400e3);
  });
}
tickCountdowns();
setInterval(tickCountdowns, 1000);

/* ---------- upload box ---------- */
$$("[data-dropzone]").forEach((zone) => {
  const input = $("input[type=file]", zone);
  const name = $("[data-file-name]", zone);
  input.addEventListener("change", () => input.files[0] && (name.textContent = input.files[0].name));
  zone.addEventListener("dragover", (e) => { e.preventDefault(); zone.classList.add("drag"); });
  zone.addEventListener("dragleave", () => zone.classList.remove("drag"));
  zone.addEventListener("drop", (e) => {
    e.preventDefault();
    zone.classList.remove("drag");
    if (e.dataTransfer.files.length) {
      input.files = e.dataTransfer.files;
      input.dispatchEvent(new Event("change"));
    }
  });
});

/* ---------- rubric totals ---------- */
$$("[data-rubric]").forEach((group) => {
  const total = $("[data-rubric-total]", group.closest("form"));
  group.addEventListener("input", () => {
    total.textContent = $$("input", group).reduce((sum, i) => sum + (Number(i.value) || 0), 0);
  });
});

/* ---------- copy button ---------- */
$$("[data-copy]").forEach((b) =>
  b.addEventListener("click", () => {
    navigator.clipboard?.writeText($(b.dataset.copy).textContent.trim());
    b.textContent = "Copied";
  })
);

/* ---------- consultation step tabs ---------- */
$$("[data-step-tab]").forEach((tab) =>
  tab.addEventListener("click", () => {
    $$("[data-step-tab]").forEach((t) => {
      const on = t === tab;
      t.setAttribute("aria-selected", String(on));
      on ? t.setAttribute("aria-current", "page") : t.removeAttribute("aria-current");
    });
    $$("[data-step-panel]").forEach((p) => (p.hidden = p.dataset.stepPanel !== tab.dataset.stepTab));
  })
);

/* ---------- landing page preview ---------- */
const preview = $("[data-preview]");
if (preview) {
  let step = 2;
  $("[data-preview-next]", preview).addEventListener("click", () => {
    const steps = $$("[data-step]", preview);
    step = (step + 1) % steps.length;
    steps.forEach((s, i) => (s.hidden = i !== step));
  });
}

/* ---------- class chat ---------- */
const chat = $("#chat");
if (chat) {
  const log = $(".chat-log", chat);
  const input = $("#chat-input", chat);
  const me = Number(chat.dataset.me);
  let last = Number(chat.dataset.last);
  const scrollDown = () => (log.scrollTop = log.scrollHeight);

  function bubble(text, meta, mine, sender = "") {
    $("[data-empty]", log)?.remove();
    const wrap = document.createElement("div");
    wrap.className = "msg" + (mine ? " mine" : "");
    const inner = document.createElement("div");
    if (sender) {
      const who = document.createElement("p");
      who.className = "msg-meta strong";
      who.textContent = sender;
      inner.append(who);
    }
    const body = document.createElement("div");
    body.className = "msg-bubble";
    body.textContent = text; // textContent, never innerHTML: messages can't inject HTML
    const time = document.createElement("p");
    time.className = "msg-meta mono";
    time.textContent = meta;
    inner.append(body, time);
    wrap.append(inner);
    log.append(wrap);
    scrollDown();
    return { wrap, time };
  }

  async function poll() {
    if (document.hidden) return;
    const res = await fetch(`index.php?action=chat_feed&course_id=${chat.dataset.course}&after=${last}`, { headers: { "X-Requested-With": "fetch" } })
      .then((r) => r.json()).catch(() => null);
    for (const m of res?.messages ?? []) {
      last = Math.max(last, m.id);
      if (m.author_id !== me) bubble(m.body, m.time, false, m.author + (m.lecturer ? " · Lecturer" : ""));
    }
  }

  $("[data-chat-form]", chat).addEventListener("submit", async (e) => {
    e.preventDefault();
    const text = input.value.trim();
    if (!text) return;
    input.value = "";
    const { wrap, time } = bubble(text, "Sending...", true); // shows straight away, confirmed below
    wrap.classList.add("sending");
    const res = await post("send_chat", { course_id: chat.dataset.course, body: text });
    wrap.classList.remove("sending");
    if (res.ok) {
      last = Math.max(last, res.message.id);
      time.textContent = res.message.time;
    } else {
      time.textContent = "Not sent: " + (res.error || "try again");
      time.style.color = "var(--danger)";
    }
  });
  scrollDown();
  setInterval(poll, 3000);
}

/* ---------- exam: timer, autosave, tab switches ---------- */
const exam = $("#exam");
if (exam) {
  const form = $("form", exam);
  const deadline = Date.parse(exam.dataset.deadline);
  const timerEl = $("[data-timer]", exam);
  const saveState = $("[data-save-state]", exam);
  const answeredEl = $("[data-answered]", exam);
  const warning = $("[data-switch-warning]", exam);
  let switches = Number(exam.dataset.switches);
  let saveTimer;
  let finishing = false;

  const collect = () => {
    const answers = {};
    $$("[data-question]", exam).forEach((el) => {
      if (el.type === "radio") { if (el.checked) answers[el.dataset.question] = Number(el.value); }
      else if (el.value.trim()) answers[el.dataset.question] = el.value;
    });
    return answers;
  };
  const showCount = () => (answeredEl.textContent = Object.keys(collect()).length);
  const showSwitches = () => {
    if (!switches) return;
    warning.hidden = false;
    $("span", warning).textContent = `You have left this tab ${switches} ${switches === 1 ? "time" : "times"}. Your lecturer can see this.`;
  };

  async function save() {
    saveState.textContent = "Saving...";
    const res = await post("save_exam", { exam_id: exam.dataset.exam, answers: collect() });
    saveState.textContent = res.ok ? "Saved" : res.error || "Not saved, will retry";
    if (res.status === 409) location.reload(); // time is up or already submitted
  }

  async function finish() {
    if (finishing) return;
    finishing = true;
    clearTimeout(saveTimer);
    await save();
    form.submit();
  }

  exam.addEventListener("input", () => {
    showCount();
    clearTimeout(saveTimer);
    saveTimer = setTimeout(save, 700);
  });
  form.addEventListener("submit", (e) => { e.preventDefault(); finish(); });
  document.addEventListener("visibilitychange", () => {
    if (document.hidden && !finishing) {
      switches++;
      showSwitches();
      post("save_exam", { exam_id: exam.dataset.exam, tab_switch: true });
    }
  });
  setInterval(() => {
    const left = deadline - Date.now();
    timerEl.textContent = formatLeft(left);
    timerEl.classList.toggle("urgent", left < 60_000);
    if (left <= 0) finish(); // hand in automatically at 00:00
  }, 500);
  showCount();
  showSwitches();
}

/* ---------- exam builder ---------- */
const questionList = $("[data-questions]");
if (questionList) {
  const template = $("#question-template");
  const renumber = () =>
    $$("[data-question-block]", questionList).forEach((block, i) => {
      const type = $('[data-field="type"]', block).value;
      $("[data-question-label]", block).textContent = `${i + 1} · ${type === "MCQ" ? "Multiple choice" : "Essay"}`;
      $$("[data-field]", block).forEach((f) => (f.name = `questions[${i}][${f.dataset.field}]`));
      $$("[data-option]", block).forEach((f) => (f.name = `questions[${i}][options][]`));
      $$("[data-correct]", block).forEach((f) => (f.name = `questions[${i}][correct]`));
    });
  const add = (type) => {
    const block = template.content.firstElementChild.cloneNode(true);
    $('[data-field="type"]', block).value = type;
    $('[data-field="points"]', block).value = type === "MCQ" ? 10 : 20;
    if (type === "ESSAY") $("[data-options]", block).remove();
    questionList.append(block);
    renumber();
  };
  $$("[data-add-question]").forEach((b) => b.addEventListener("click", () => add(b.dataset.addQuestion)));
  questionList.addEventListener("click", (e) => {
    if (e.target.closest("[data-remove-question]") && $$("[data-question-block]", questionList).length > 1) {
      e.target.closest("[data-question-block]").remove();
      renumber();
    }
  });
  add("MCQ");
}
