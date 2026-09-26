import {
  APPROVAL_STATUS_LABEL,
  APPROVAL_TYPE_LABEL,
  DOC_STATUS_LABEL,
  DOC_TYPE_LABEL,
  formatRevision,
  PRIORITY_LABEL,
  PROJECT_TYPE_LABEL,
  STATUS_LABEL,
  type Tone,
  STATUS_TONE,
} from '@/config/labels';
import { computeAnalytics, processStatLabel, weeklyReport, weeklyReportText } from '@/domain/analytics';
import { currentProcess, customerName, userName } from '@/domain/context';
import {
  attentionFlags,
  daysSinceUpdate,
  displayStatus,
  isActiveProject,
  isDueSoon,
  isOverdue,
  missingDocumentProcesses,
  overdueDays,
} from '@/domain/metrics';
import { visibleProjects } from '@/domain/permissions';
import {
  addDays,
  describeDue,
  endOfMonth,
  endOfWeek,
  formatDate,
  formatMonthYear,
  isBetween,
  startOfMonth,
  startOfWeek,
} from '@/lib/date';
import { normalize } from '@/lib/utils';
import type { ApprovalType, DbState, DocType, ISODate, Project, ProjectType, PublicUser } from '@/types';

/**
 * Data-grounded NPD assistant. It never generates free text about data it
 * cannot see: every answer is assembled from the projects the user is
 * permitted to access (PRD §13). The `AssistantProvider` interface in the
 * service layer lets this be swapped for an LLM that calls the same tools.
 */

export interface ProjectRef {
  code: string;
  name: string;
  customer: string;
  meta: string;
  badge?: { label: string; tone: Tone };
}

export type AssistantBlock =
  | { type: 'text'; text: string }
  | { type: 'projects'; items: ProjectRef[] }
  | { type: 'summary'; title: string; code?: string; rows: Array<[string, string]> }
  | { type: 'list'; items: string[] }
  | { type: 'stats'; items: Array<{ label: string; value: string }> }
  | { type: 'report'; title: string; text: string };

export interface AssistantAnswer {
  intent: string;
  blocks: AssistantBlock[];
  basis: string;
}

export const NOT_AVAILABLE = 'Data tersebut belum tersedia di sistem.';

export const SUGGESTED_QUESTIONS = [
  'Project Subcont apa saja yang sedang menunggu Customer?',
  'Project Kymm sekarang sampai mana?',
  'Project mana yang overdue?',
  'Project Subcont yang belum memiliki artwork?',
  'Artwork Facetology terakhir revisi berapa?',
  'Berapa kali Kymm melakukan revisi artwork?',
  'Deadline minggu ini apa saja?',
  'Process mana yang paling lama?',
  'Berapa project New Mold bulan ini?',
  'Berapa project Subcont yang sedang berjalan?',
  'Buatkan weekly NPD report.',
  'Project mana yang membutuhkan follow-up?',
];

const GENERIC_TOKENS = new Set([
  'project', 'projek', 'proyek', 'yang', 'apa', 'saja', 'mana', 'berapa', 'sekarang', 'sampai', 'untuk', 'dengan', 'dan', 'atau', 'dari',
  'new', 'mold', 'subcont', 'tube', 'bottle', 'case', 'cap', 'jar', 'botol', 'ml', 'artwork', 'revisi', 'terakhir', 'status', 'summary',
  'ringkasan', 'buatkan', 'buat', 'tolong', 'posisi', 'proses', 'process', 'customer', 'kali', 'melakukan', 'the', 'di', 'ke', 'ini', 'itu',
  'sudah', 'belum', 'sedang', 'menunggu', 'dimana', 'mana', 'bagaimana', 'progress', 'rev', 'drawing', 'report', 'trial', 'approval',
]);

const DOC_KEYWORDS: Array<[RegExp, DocType[], string]> = [
  [/\bartwork\b/, ['artwork'], 'Artwork'],
  [/\b3d\b/, ['drawing_3d'], '3D Drawing'],
  [/\b2d\b/, ['drawing_2d'], '2D Drawing'],
  [/mold drawing/, ['mold_drawing'], 'Mold Drawing'],
  [/trial report|laporan trial/, ['trial_report'], 'Trial Report'],
  [/\bnpr\b/, ['npr'], 'NPR'],
  [/\bcoa\b/, ['coa'], 'COA'],
  [/validation|validasi/, ['validation_report'], 'Validation Report'],
];

const APPROVAL_KEYWORDS: Array<[RegExp, ApprovalType, string]> = [
  [/\bt0\b/, 't0', 'T0'],
  [/\b3d\b/, '3d', '3D'],
  [/masterbatch/, 'masterbatch', 'masterbatch'],
  [/trial/, 'trial', 'trial'],
  [/artwork/, 'artwork', 'artwork'],
];

interface Ctx {
  db: DbState;
  user: PublicUser;
  today: ISODate;
  visible: Project[];
  q: string;
}

function ref(ctx: Ctx, p: Project, meta?: string): ProjectRef {
  const cur = currentProcess(ctx.db, p);
  const st = displayStatus(p, ctx.today);
  return {
    code: p.code,
    name: p.name,
    customer: customerName(ctx.db, p.customerId),
    meta: meta ?? [cur && isActiveProject(p) ? cur.name : null, `Target ${formatDate(p.targetDate)}`].filter(Boolean).join(' · '),
    badge: { label: st === 'overdue' ? `Overdue ${overdueDays(p, ctx.today)} hari` : STATUS_LABEL[st], tone: STATUS_TONE[st] },
  };
}

function detectType(q: string): ProjectType | undefined {
  if (/\bsubcont\b|\bsub cont\b/.test(q)) return 'subcont';
  if (/new mold|newmold|mold baru/.test(q)) return 'new_mold';
  return undefined;
}

function detectRange(q: string, today: ISODate): { from: ISODate; to: ISODate; label: string } | undefined {
  if (/hari ini|today/.test(q)) return { from: today, to: today, label: 'hari ini' };
  if (/minggu depan|pekan depan/.test(q)) {
    const s = addDays(startOfWeek(today), 7);
    return { from: s, to: addDays(s, 6), label: 'minggu depan' };
  }
  if (/minggu ini|pekan ini|this week/.test(q)) return { from: startOfWeek(today), to: endOfWeek(today), label: 'minggu ini' };
  if (/bulan ini|this month/.test(q)) return { from: startOfMonth(today), to: endOfMonth(today), label: `bulan ini (${formatMonthYear(today)})` };
  return undefined;
}

function wordIn(q: string, token: string) {
  return new RegExp(`(^|\\s)${token.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}(\\s|$)`).test(q);
}

/** Finds projects referenced in the question among `pool`. */
function matchProjects(ctx: Ctx, pool: Project[]): { projects: Project[]; byCustomerOnly: boolean } {
  const q = ctx.q;
  const scored = pool.map((p) => {
    let score = 0;
    let nameHits = 0;
    if (q.includes(normalize(p.code))) score += 10;
    const cust = normalize(customerName(ctx.db, p.customerId));
    const custTokens = cust.split(' ').filter((t) => t.length >= 3);
    const custHit = q.includes(cust) || custTokens.some((t) => !GENERIC_TOKENS.has(t) && wordIn(q, t));
    if (custHit) score += 2;
    for (const t of normalize(p.name).split(' ')) {
      if (t.length < 3 || GENERIC_TOKENS.has(t) || custTokens.includes(t)) continue;
      if (wordIn(q, t)) nameHits += 1;
    }
    score += nameHits * 3;
    return { p, score, nameHits, custHit };
  });
  const max = Math.max(0, ...scored.map((s) => s.score));
  if (max === 0) return { projects: [], byCustomerOnly: false };
  const top = scored.filter((s) => s.score === max);
  return { projects: top.map((s) => s.p), byCustomerOnly: top.every((s) => s.nameHits === 0 && s.custHit) };
}

function basis(ctx: Ctx) {
  return `Berdasarkan ${ctx.visible.length} project yang dapat Anda akses · data per ${formatDate(ctx.today)}`;
}

const text = (t: string): AssistantBlock => ({ type: 'text', text: t });

function projectPosition(ctx: Ctx, p: Project): AssistantBlock[] {
  const cur = currentProcess(ctx.db, p);
  const st = displayStatus(p, ctx.today);
  const lastRev = lastRevisionLabel(ctx, p, ['artwork', 'drawing_3d', 'drawing_2d']);
  const rows: Array<[string, string]> = [
    ['Project', `${p.code} · ${p.name}`],
    ['Customer', customerName(ctx.db, p.customerId)],
    ['Type', PROJECT_TYPE_LABEL[p.type]],
    ['Current Process', cur ? cur.name : '—'],
    ['Status', st === 'overdue' ? `Overdue ${overdueDays(p, ctx.today)} hari (${STATUS_LABEL[p.status]})` : STATUS_LABEL[st]],
    ['PIC Proses', cur ? userName(ctx.db, cur.picId) : '—'],
    ['Waiting For', p.waitingFor ?? '—'],
    ['Last Update', formatDate(p.updatedAt.slice(0, 10))],
    ['Last Revision', lastRev ?? 'Belum ada dokumen revisi'],
    ['Next Action', `${p.nextAction} (due ${formatDate(p.nextActionDue)})`],
    ['Target', formatDate(p.targetDate)],
  ];
  if (p.status === 'hold' && p.statusReason) rows.splice(5, 0, ['Alasan Hold', p.statusReason]);
  return [{ type: 'summary', title: 'PROJECT SUMMARY', code: p.code, rows }];
}

function lastRevisionLabel(ctx: Ctx, p: Project, types: DocType[]): string | undefined {
  const docs = ctx.db.documents.filter((d) => d.projectId === p.id && types.includes(d.type));
  let best: { label: string; at: string } | undefined;
  for (const d of docs) {
    const v = ctx.db.documentVersions.find((x) => x.id === d.latestVersionId);
    if (!v) continue;
    const label = `${DOC_TYPE_LABEL[d.type]} ${formatRevision(v.revision)} (${DOC_STATUS_LABEL[v.status]})`;
    if (!best || v.uploadedAt > best.at) best = { label, at: v.uploadedAt };
  }
  return best?.label;
}

function listAnswer(ctx: Ctx, intent: string, items: Project[], headline: (n: number) => string, empty: string, meta?: (p: Project) => string): AssistantAnswer {
  if (items.length === 0) return { intent, blocks: [text(empty)], basis: basis(ctx) };
  return { intent, blocks: [text(headline(items.length)), { type: 'projects', items: items.map((p) => ref(ctx, p, meta?.(p))) }], basis: basis(ctx) };
}

export function answerQuestion(db: DbState, user: PublicUser, question: string, today: ISODate): AssistantAnswer {
  const q = normalize(question);
  const visible = visibleProjects(user, db.projects);
  const ctx: Ctx = { db, user, today, visible, q };
  const type = detectType(q);
  const range = detectRange(q, today);
  const inType = (p: Project) => !type || p.type === type;
  const typeLabel = type ? ` ${PROJECT_TYPE_LABEL[type]}` : '';
  const active = visible.filter(isActiveProject);

  if (!q) return { intent: 'empty', blocks: [text('Silakan ketik pertanyaan tentang project NPD.')], basis: basis(ctx) };

  // Assistant is read-only: important data is never changed without explicit confirmation.
  if (/^(tolong |mohon )?(ubah|ganti|hapus|update|set|tandai|setujui|approve|tolak|reject|pindahkan|tutup|selesaikan)\b/.test(q)) {
    const m = matchProjects(ctx, visible).projects[0];
    return {
      intent: 'mutation',
      blocks: [
        text('Saya hanya membaca data dan tidak mengubah data project. Perubahan penting harus dilakukan langsung oleh user yang berwenang dengan konfirmasi eksplisit di halaman project.'),
        ...(m ? [{ type: 'projects' as const, items: [ref(ctx, m, 'Buka project untuk melakukan perubahan')] }] : []),
      ],
      basis: basis(ctx),
    };
  }

  if (/^(halo|hai|hi|hello|help|bantuan|apa yang bisa|bisa apa)/.test(q)) {
    return {
      intent: 'help',
      blocks: [
        text(`Halo ${user.name.split(' ')[0]}! Saya menjawab pertanyaan berdasarkan data project NPD yang dapat Anda akses — posisi project, approval, revisi dokumen, deadline, overdue, bottleneck, dan weekly report.`),
        { type: 'list', items: SUGGESTED_QUESTIONS.slice(0, 6) },
      ],
      basis: basis(ctx),
    };
  }

  // Weekly NPD report
  if (/weekly|mingguan|laporan (npd|minggu)|report npd/.test(q)) {
    const start = startOfWeek(today);
    const r = weeklyReport(db, visible, start, today);
    return {
      intent: 'weekly_report',
      blocks: [
        text(`Weekly NPD Report periode ${formatDate(r.periodStart)} – ${formatDate(r.periodEnd)}.`),
        {
          type: 'stats',
          items: [
            { label: 'Total Project', value: String(r.totalProject) },
            { label: 'New Project', value: String(r.newProject) },
            { label: 'Completed', value: String(r.completed) },
            { label: 'On Progress', value: String(r.onProgress) },
            { label: 'Waiting Customer', value: String(r.waitingCustomer) },
            { label: 'Waiting Supplier', value: String(r.waitingSupplier) },
            { label: 'Overdue', value: String(r.overdue) },
            { label: 'Due Soon', value: String(r.dueSoon) },
          ],
        },
        { type: 'report', title: 'Weekly NPD Report', text: weeklyReportText(r, formatDate) },
      ],
      basis: basis(ctx),
    };
  }

  // Detect projects mentioned, and whether they exist but are outside the user's scope.
  const mentioned = matchProjects(ctx, visible);
  const outOfScope = mentioned.projects.length === 0 && matchProjects({ ...ctx, visible: db.projects }, db.projects).projects.length > 0;
  const notAccessible: AssistantAnswer = {
    intent: 'no_access',
    blocks: [text('Saya tidak menemukan project tersebut dalam data yang dapat Anda akses.')],
    basis: basis(ctx),
  };

  // Revision count ("Berapa kali Kymm melakukan revisi artwork?")
  if (/berapa kali/.test(q) && /revisi|reject|tolak|ditolak/.test(q)) {
    if (outOfScope) return notAccessible;
    const ap = APPROVAL_KEYWORDS.find(([re]) => re.test(q)) ?? APPROVAL_KEYWORDS[4];
    const pool = mentioned.projects.length ? mentioned.projects : visible.filter(inType);
    const rows = pool
      .map((p) => ({
        p,
        n: db.approvals.filter((a) => a.projectId === p.id && a.type === ap[1] && (a.status === 'rejected' || a.status === 'revision_required')).length,
      }))
      .filter((r) => mentioned.projects.length > 0 || r.n > 0);
    if (rows.length === 0) return { intent: 'revision_count', blocks: [text(`Tidak ada revisi ${ap[2]} yang tercatat.`)], basis: basis(ctx) };
    const total = rows.reduce((s, r) => s + r.n, 0);
    const subject = mentioned.byCustomerOnly ? customerName(db, rows[0].p.customerId) : rows.length === 1 ? rows[0].p.name : 'project terkait';
    return {
      intent: 'revision_count',
      blocks: [
        text(`${subject} tercatat ${total} kali revisi ${ap[2]} (approval Rejected / Revision Required).`),
        { type: 'projects', items: rows.map((r) => ref(ctx, r.p, `${r.n}× revisi ${ap[2]}`)) },
      ],
      basis: basis(ctx),
    };
  }

  // Latest revision ("Artwork Facetology terakhir revisi berapa?")
  const docKw = DOC_KEYWORDS.find(([re]) => re.test(q));
  if (docKw && /revisi|rev\b|versi|terakhir|terbaru/.test(q) && !/belum/.test(q)) {
    if (outOfScope) return notAccessible;
    if (mentioned.projects.length === 0)
      return { intent: 'last_revision', blocks: [text('Sebutkan nama project atau customer, misalnya "Artwork Facetology terakhir revisi berapa?".')], basis: basis(ctx) };
    const items = mentioned.projects.map((p) => {
      const docs = db.documents.filter((d) => d.projectId === p.id && docKw[1].includes(d.type));
      if (docs.length === 0) return { p, line: `${p.name}: belum ada dokumen ${docKw[2]}.` };
      const parts = docs.map((d) => {
        const v = db.documentVersions.find((x) => x.id === d.latestVersionId)!;
        const count = db.documentVersions.filter((x) => x.documentId === d.id).length;
        return `${d.name} — ${formatRevision(v.revision)} (${DOC_STATUS_LABEL[v.status]}, ${formatDate(v.uploadedAt.slice(0, 10))}, ${count} versi tersimpan)`;
      });
      return { p, line: `${p.name}: ${parts.join('; ')}` };
    });
    return { intent: 'last_revision', blocks: [text(`Revisi ${docKw[2]} terakhir:`), { type: 'list', items: items.map((i) => i.line) }, { type: 'projects', items: items.map((i) => ref(ctx, i.p)) }], basis: basis(ctx) };
  }

  // Subcont projects without artwork
  if (/belum/.test(q) && /artwork/.test(q)) {
    const items = active.filter((p) => p.type === 'subcont' && !db.documents.some((d) => d.projectId === p.id && d.type === 'artwork'));
    return listAnswer(ctx, 'no_artwork', items, (n) => `${n} project Subcont aktif belum memiliki dokumen artwork:`, 'Semua project Subcont aktif sudah memiliki artwork.', (p) => {
      const cur = currentProcess(db, p);
      return `${cur?.name ?? '—'} · Drafter ${userName(db, p.drafterId)}`;
    });
  }

  // Waiting for customer
  if (/menunggu|waiting|tunggu/.test(q) && /customer|pelanggan|approval|persetujuan/.test(q)) {
    const items = active.filter((p) => inType(p) && p.status === 'waiting_approval');
    return listAnswer(ctx, 'waiting_customer', items, (n) => `${n} project${typeLabel} sedang menunggu Customer / approval:`, `Tidak ada project${typeLabel} yang sedang menunggu Customer.`, (p) => {
      const a = db.approvals.find((x) => x.projectId === p.id && x.status === 'pending');
      return a ? `${APPROVAL_TYPE_LABEL[a.type]} · ${a.revision} · menunggu sejak ${formatDate(a.requestedDate)}` : (p.waitingFor ?? '');
    });
  }

  // Waiting external / supplier
  if (/menunggu|waiting|tunggu/.test(q) && /supplier|external|eksternal|vendor|mold maker/.test(q)) {
    const items = active.filter((p) => inType(p) && p.status === 'waiting_external');
    return listAnswer(ctx, 'waiting_external', items, (n) => `${n} project${typeLabel} sedang menunggu pihak eksternal:`, 'Tidak ada project yang menunggu pihak eksternal.', (p) => p.waitingFor ?? '—');
  }

  // Overdue
  if (/overdue|terlambat|telat|lewat deadline|melewati/.test(q)) {
    const items = active.filter((p) => inType(p) && isOverdue(p, today)).sort((a, b) => overdueDays(b, today) - overdueDays(a, today));
    return listAnswer(ctx, 'overdue', items, (n) => `${n} project${typeLabel} overdue:`, `Tidak ada project${typeLabel} yang overdue.`, (p) => {
      const cur = currentProcess(db, p);
      return `Terlambat ${overdueDays(p, today)} hari · ${cur?.name ?? '—'} · Next: ${p.nextAction}`;
    });
  }

  // Due soon
  if (/due soon|mendekati deadline|hampir (deadline|jatuh tempo)/.test(q)) {
    const items = active.filter((p) => inType(p) && isDueSoon(p, today, db.settings.dueSoonDays));
    return listAnswer(ctx, 'due_soon', items, (n) => `${n} project due soon (≤ ${db.settings.dueSoonDays} hari):`, 'Tidak ada project yang due soon.', (p) => `Target ${formatDate(p.targetDate)} (${describeDue(p.targetDate, today)})`);
  }

  // Deadlines in a range
  if (/deadline|jatuh tempo|\bdue\b|tenggat/.test(q)) {
    const r = range ?? { from: startOfWeek(today), to: endOfWeek(today), label: 'minggu ini' };
    const lines: Array<{ date: ISODate; line: string; p: Project }> = [];
    for (const p of active.filter(inType)) {
      if (isBetween(p.targetDate, r.from, r.to)) lines.push({ date: p.targetDate, p, line: `${formatDate(p.targetDate)} — Target project ${p.code} ${p.name}` });
      if (isBetween(p.nextActionDue, r.from, r.to)) lines.push({ date: p.nextActionDue, p, line: `${formatDate(p.nextActionDue)} — Next action ${p.code}: ${p.nextAction}` });
      const cur = currentProcess(db, p);
      if (cur && isBetween(cur.plannedFinish, r.from, r.to) && cur.plannedFinish !== p.nextActionDue)
        lines.push({ date: cur.plannedFinish, p, line: `${formatDate(cur.plannedFinish)} — Planned finish ${cur.name} (${p.code})` });
    }
    lines.sort((a, b) => a.date.localeCompare(b.date));
    const overdueNA = active.filter((p) => inType(p) && p.nextActionDue < r.from);
    const blocks: AssistantBlock[] = [];
    if (lines.length === 0) blocks.push(text(`Tidak ada deadline ${r.label} (${formatDate(r.from)} – ${formatDate(r.to)}).`));
    else blocks.push(text(`Deadline ${r.label} (${formatDate(r.from)} – ${formatDate(r.to)}):`), { type: 'list', items: lines.map((l) => l.line) });
    if (overdueNA.length && r.from >= today)
      blocks.push(text(`Catatan: ${overdueNA.length} next action sudah lewat due sebelum periode ini.`), { type: 'projects', items: overdueNA.map((p) => ref(ctx, p, `Next action ${describeDue(p.nextActionDue, today)}: ${p.nextAction}`)) });
    return { intent: 'deadlines', blocks, basis: basis(ctx) };
  }

  // Longest process / bottleneck
  if ((/paling lama|terlama|bottleneck|lama/.test(q) && /proses|process|tahap/.test(q)) || /bottleneck/.test(q)) {
    const a = computeAnalytics(db, visible.filter(inType), today);
    if (a.processStats.length === 0) return { intent: 'bottleneck', blocks: [text(NOT_AVAILABLE)], basis: basis(ctx) };
    const top = a.processStats.slice(0, 5);
    return {
      intent: 'bottleneck',
      blocks: [
        text(`Process dengan rata-rata durasi terlama adalah ${processStatLabel(top[0])}: ${top[0].avgDuration} hari (plan ${top[0].avgPlanned} hari, ${top[0].samples} sampel).${a.bottleneck ? ` Bottleneck terbesar terhadap plan: ${processStatLabel(a.bottleneck)} (+${Math.max(0, Math.round((a.bottleneck.avgDuration - a.bottleneck.avgPlanned) * 10) / 10)} hari).` : ''}`),
        { type: 'list', items: top.map((s, i) => `${i + 1}. ${processStatLabel(s)} — rata-rata ${s.avgDuration} hari, maks ${s.maxDuration} hari${s.running ? `, ${s.running} sedang berjalan` : ''}`) },
      ],
      basis: basis(ctx),
    };
  }

  // Counts
  if (/berapa|jumlah|total/.test(q) && /project|projek|proyek/.test(q)) {
    let pool = visible.filter((p) => inType(p) && p.status !== 'cancelled');
    let label = `project${typeLabel}`;
    if (/berjalan|aktif|on progress|sedang|jalan/.test(q)) {
      pool = pool.filter(isActiveProject);
      label += ' yang sedang berjalan';
    } else if (/selesai|completed/.test(q)) {
      pool = pool.filter((p) => p.status === 'completed');
      label += ' yang sudah selesai';
    }
    if (range) {
      pool = pool.filter((p) => isBetween(p.createdAt.slice(0, 10), range.from, range.to) || isBetween(p.startDate, range.from, range.to));
      label += ` yang dibuat ${range.label}`;
    }
    const byStatus = new Map<string, number>();
    for (const p of pool) {
      const s = STATUS_LABEL[displayStatus(p, today)];
      byStatus.set(s, (byStatus.get(s) ?? 0) + 1);
    }
    return {
      intent: 'count',
      blocks: [
        text(`Ada ${pool.length} ${label}.`),
        ...(pool.length ? [{ type: 'stats' as const, items: [...byStatus.entries()].map(([k, v]) => ({ label: k, value: String(v) })) }] : []),
        ...(pool.length ? [{ type: 'projects' as const, items: pool.map((p) => ref(ctx, p)) }] : []),
      ],
      basis: basis(ctx),
    };
  }

  // Follow-up / attention
  if (/follow.?up|tindak lanjut|perhatian|perlu di|butuh/.test(q)) {
    const rows = active
      .filter(inType)
      .map((p) => {
        const procs = db.processes.filter((x) => x.projectId === p.id);
        const docs = db.documents.filter((d) => d.projectId === p.id);
        const versions = new Map(db.documentVersions.map((v) => [v.id, v]));
        const missing = missingDocumentProcesses(procs, docs, versions).length;
        const f = attentionFlags(p, today, db.settings, missing);
        const reasons: string[] = [];
        if (f.overdue) reasons.push(`overdue ${overdueDays(p, today)} hari`);
        if (p.nextActionDue < today) reasons.push(`next action ${describeDue(p.nextActionDue, today)}`);
        if (f.dueSoon) reasons.push('due soon');
        if (f.waitingApproval) reasons.push('menunggu approval customer');
        if (f.waitingExternal) reasons.push(`menunggu ${p.waitingFor ?? 'external'}`);
        if (f.noUpdate) reasons.push(`tidak ada update ${daysSinceUpdate(p, today)} hari`);
        if (f.missingDocument) reasons.push('dokumen wajib belum ada');
        if (procs.some((x) => x.status === 'problem')) reasons.push('ada problem di proses');
        const score = (f.overdue ? 5 : 0) + (p.nextActionDue < today ? 3 : 0) + (f.dueSoon ? 2 : 0) + reasons.length;
        return { p, reasons, score };
      })
      .filter((r) => r.reasons.length > 0 && r.p.status !== 'hold')
      .sort((a, b) => b.score - a.score);
    if (rows.length === 0) return { intent: 'follow_up', blocks: [text('Tidak ada project yang membutuhkan follow-up saat ini.')], basis: basis(ctx) };
    return {
      intent: 'follow_up',
      blocks: [text(`${rows.length} project membutuhkan follow-up (diurutkan dari yang paling mendesak):`), { type: 'projects', items: rows.map((r) => ref(ctx, r.p, `${r.reasons.join(' · ')} → ${r.p.nextAction}`)) }],
      basis: basis(ctx),
    };
  }

  // My work
  if (/\b(saya|aku)\b/.test(q) && /tugas|pekerjaan|project|kerjaan|action/.test(q)) {
    const items = active.filter((p) => {
      const cur = currentProcess(db, p);
      return cur?.picId === user.id;
    });
    return listAnswer(ctx, 'my_work', items, (n) => `${n} project sedang menunggu tindakan Anda:`, 'Tidak ada proses aktif yang di-assign ke Anda.', (p) => `${currentProcess(db, p)?.name ?? '—'} · ${p.nextAction} (due ${formatDate(p.nextActionDue)})`);
  }

  // Pending approvals
  if (/approval/.test(q) && /pending|belum diputus|menunggu keputusan/.test(q)) {
    const ids = new Set(visible.map((p) => p.id));
    const pending = db.approvals.filter((a) => ids.has(a.projectId) && a.status === 'pending');
    if (pending.length === 0) return { intent: 'approvals', blocks: [text('Tidak ada approval berstatus Pending.')], basis: basis(ctx) };
    return {
      intent: 'approvals',
      blocks: [
        text(`${pending.length} approval berstatus ${APPROVAL_STATUS_LABEL.pending}:`),
        { type: 'projects', items: pending.map((a) => ref(ctx, db.projects.find((p) => p.id === a.projectId)!, `${a.code} · ${APPROVAL_TYPE_LABEL[a.type]} · ${a.revision} · ${a.approverName}`)) },
      ],
      basis: basis(ctx),
    };
  }

  // Hold
  if (/\bhold\b|ditunda/.test(q)) {
    const items = active.filter((p) => inType(p) && p.status === 'hold');
    return listAnswer(ctx, 'hold', items, (n) => `${n} project sedang Hold:`, 'Tidak ada project yang sedang Hold.', (p) => p.statusReason ?? '—');
  }

  // Specific project: position / summary
  if (outOfScope) return notAccessible;
  if (mentioned.projects.length > 0) {
    const ps = mentioned.projects;
    if (ps.length === 1) return { intent: 'project_summary', blocks: projectPosition(ctx, ps[0]), basis: basis(ctx) };
    return {
      intent: 'project_summary',
      blocks: [text(`Ditemukan ${ps.length} project yang cocok:`), ...ps.flatMap((p) => projectPosition(ctx, p))],
      basis: basis(ctx),
    };
  }

  if (/summary|ringkasan|rangkum/.test(q)) {
    return { intent: 'summary_missing', blocks: [text('Sebutkan nama project, kode (mis. NPD-2026-001), atau customer yang ingin diringkas.')], basis: basis(ctx) };
  }

  // Listing by type
  if (type && /apa saja|daftar|list|mana saja/.test(q)) {
    const items = visible.filter((p) => p.type === type && isActiveProject(p));
    return listAnswer(ctx, 'list', items, (n) => `${n} project ${PROJECT_TYPE_LABEL[type]} aktif:`, `Tidak ada project ${PROJECT_TYPE_LABEL[type]} aktif.`);
  }

  if (/priority|prioritas|urgent/.test(q)) {
    const items = active.filter((p) => inType(p) && (p.priority === 'urgent' || p.priority === 'high'));
    return listAnswer(ctx, 'priority', items, (n) => `${n} project aktif dengan priority High/Urgent:`, 'Tidak ada project High/Urgent aktif.', (p) => `Priority ${PRIORITY_LABEL[p.priority]} · ${currentProcess(db, p)?.name ?? '—'}`);
  }

  return {
    intent: 'unknown',
    blocks: [text(NOT_AVAILABLE), text('Coba tanyakan tentang posisi project, approval, revisi dokumen, deadline, overdue, bottleneck, atau weekly report.')],
    basis: basis(ctx),
  };
}
