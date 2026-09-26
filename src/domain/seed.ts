import { defaultWorkflows } from '@/config/workflows';
import { addDays, todayISO } from '@/lib/date';
import type { AppSettings, DbState, DocType, Project, ProjectStatus, PublicUser, User } from '@/types';
import { type CommandContext, projectProcesses } from './context';
import { uploadDocument } from './documentCommands';
import { prefillData, docRevisionOptions } from './forms';
import { scanDeadlines } from './notificationScan';
import {
  addComment,
  createProject,
  type ProjectInput,
  updateNextAction,
  updateProjectStatus,
  updatePurchasingStatus,
} from './projectCommands';
import { completeProcess, setProblem } from './workflow';
import { saveEvent } from './adminCommands';

/**
 * Demo data. Every project is built by replaying real commands through the
 * workflow engine (relative to "today"), so processes, approvals, revision
 * history, records and activity history are always consistent.
 */

export const SCHEMA_VERSION = 1;

export const DEFAULT_SETTINGS: AppSettings = {
  dueSoonDays: 3,
  noUpdateDays: 7,
  simulatedFailureRate: 0,
  simulatedLatency: true,
};

const PASSWORD = 'demo123';

export const SEED_USERS: User[] = [
  { id: 'usr_admin', name: 'Andi Pratama', email: 'andi@npd.local', role: 'admin', title: 'System Administrator', active: true, password: PASSWORD },
  { id: 'usr_sales1', name: 'Sari Wulandari', email: 'sari@npd.local', role: 'admin_sales', title: 'Admin Sales', active: true, password: PASSWORD },
  { id: 'usr_sales2', name: 'Budi Santoso', email: 'budi@npd.local', role: 'admin_sales', title: 'Admin Sales', active: true, password: PASSWORD },
  { id: 'usr_npd1', name: 'Rizky Hidayat', email: 'rizky@npd.local', role: 'npd_staff', title: 'NPD Engineer', active: true, password: PASSWORD },
  { id: 'usr_npd2', name: 'Maya Putri', email: 'maya@npd.local', role: 'npd_staff', title: 'NPD Engineer', active: true, password: PASSWORD },
  { id: 'usr_drafter1', name: 'Dimas Saputra', email: 'dimas@npd.local', role: 'drafter', title: 'Drafter NPD', active: true, password: PASSWORD },
  { id: 'usr_drafter2', name: 'Nadia Kusuma', email: 'nadia@npd.local', role: 'drafter', title: 'Drafter NPD', active: true, password: PASSWORD },
  { id: 'usr_purch', name: 'Hendra Wijaya', email: 'hendra@npd.local', role: 'purchasing', title: 'Purchasing Officer', active: true, password: PASSWORD },
  { id: 'usr_prod', name: 'Agus Setiawan', email: 'agus@npd.local', role: 'production', title: 'Production Supervisor', active: true, password: PASSWORD },
  { id: 'usr_qa', name: 'Lestari Ayu', email: 'lestari@npd.local', role: 'quality', title: 'QA Engineer', active: true, password: PASSWORD },
  { id: 'usr_mgmt', name: 'Bambang Sutrisno', email: 'bambang@npd.local', role: 'management', title: 'Plant Manager', active: true, password: PASSWORD },
];

const CUSTOMERS: Array<[string, string, string, string]> = [
  ['cus_kymm', 'KYM', 'Kymm', 'Rara (Purchasing)'],
  ['cus_facetology', 'FCT', 'Facetology', 'Dinda (R&D Packaging)'],
  ['cus_aruna', 'ARN', 'Aruna Beauty', 'Mega (Brand Manager)'],
  ['cus_glowlab', 'GLW', 'Glowlab Indonesia', 'Yoga (Procurement)'],
  ['cus_natura', 'NTC', 'Natura Care', 'Fitri (QA Supplier)'],
  ['cus_sinar', 'SNR', 'Sinar Kosmetika', 'Hadi (Purchasing)'],
  ['cus_botanica', 'BTH', 'Botanica Home', 'Lina (Product Dev)'],
];

export function emptyDb(now: string): DbState {
  return {
    schemaVersion: SCHEMA_VERSION,
    seededAt: now,
    users: SEED_USERS.map((u) => ({ ...u })),
    customers: CUSTOMERS.map(([id, code, name, contactName]) => ({
      id,
      code,
      name,
      contactName,
      contactEmail: `${code.toLowerCase()}@customer.example`,
      active: true,
      createdAt: now,
    })),
    workflows: structuredClone(defaultWorkflows(now)),
    projects: [],
    processes: [],
    approvals: [],
    documents: [],
    documentVersions: [],
    records: [],
    activities: [],
    comments: [],
    notifications: [],
    events: [],
    settings: { ...DEFAULT_SETTINGS },
    counters: { project: {}, approval: 0, record: {} },
  };
}

// ---------------------------------------------------------------------------
// Scenario DSL

type ActorKey = 'admin' | 'sales1' | 'sales2' | 'npd1' | 'npd2' | 'drafter1' | 'drafter2' | 'purch' | 'prod' | 'qa';

interface Action {
  at: number;
  actor: ActorKey;
  order: number;
  run: (db: DbState, ctx: CommandContext) => void;
}

const DOC_FILE: Record<DocType, { ext: string; mime: string }> = {
  npr: { ext: 'pdf', mime: 'application/pdf' },
  feedback: { ext: 'pdf', mime: 'application/pdf' },
  artwork: { ext: 'svg', mime: 'image/svg+xml' },
  technical_drawing: { ext: 'pdf', mime: 'application/pdf' },
  drawing_3d: { ext: 'svg', mime: 'image/svg+xml' },
  drawing_2d: { ext: 'svg', mime: 'image/svg+xml' },
  mold_drawing: { ext: 'svg', mime: 'image/svg+xml' },
  approval: { ext: 'pdf', mime: 'application/pdf' },
  trial_report: { ext: 'pdf', mime: 'application/pdf' },
  trial_photo: { ext: 'svg', mime: 'image/svg+xml' },
  trial_video: { ext: 'mp4', mime: 'video/mp4' },
  material_request: { ext: 'pdf', mime: 'application/pdf' },
  coa: { ext: 'pdf', mime: 'application/pdf' },
  material_spec: { ext: 'pdf', mime: 'application/pdf' },
  validation_report: { ext: 'pdf', mime: 'application/pdf' },
  customer_document: { ext: 'pdf', mime: 'application/pdf' },
  supplier_document: { ext: 'pdf', mime: 'application/pdf' },
  other: { ext: 'pdf', mime: 'application/pdf' },
};

class Scenario {
  actions: Action[] = [];
  project?: Project;
  private order = 0;
  constructor(private readonly input: Omit<ProjectInput, 'startDate' | 'targetDate'> & { start: number; target: number; creator: ActorKey }) {}

  private push(at: number, actor: ActorKey, run: Action['run']) {
    this.actions.push({ at, actor, order: this.order++, run });
    return this;
  }

  create() {
    const { start, target, creator, ...rest } = this.input;
    return this.push(start, creator, (db, ctx) => {
      this.project = createProject(db, ctx, { ...rest, startDate: ctx.today, targetDate: addDays(ctx.today, target - start) });
    });
  }

  private proc(db: DbState, key: string) {
    const p = projectProcesses(db, this.project!.id).find((x) => x.key === key);
    if (!p) throw new Error(`seed: process ${key} not found`);
    return p;
  }

  upload(at: number, actor: ActorKey, processKey: string, type: DocType, name: string, opts: { revisionOf?: string; note?: string } = {}) {
    return this.push(at, actor, (db, ctx) => {
      const proc = this.proc(db, processKey);
      const existing = opts.revisionOf ? db.documents.find((d) => d.projectId === this.project!.id && d.name === opts.revisionOf) : undefined;
      const rev = existing ? db.documentVersions.filter((v) => v.documentId === existing.id).length : 0;
      const f = DOC_FILE[type];
      const base = name.replace(/[^A-Za-z0-9]+/g, '_').replace(/^_|_$/g, '');
      uploadDocument(db, ctx, {
        projectId: this.project!.id,
        processId: proc.id,
        mode: existing ? 'revision' : 'new',
        documentId: existing?.id,
        type,
        name,
        note: opts.note,
        file: {
          fileName: `${this.project!.code}_${base}_Rev${String(rev).padStart(2, '0')}.${f.ext}`,
          mimeType: f.mime,
          size: 1_200 + ((base.length * 37 + rev * 113) % 900),
          blobKey: 'seed',
        },
      });
    });
  }

  complete(at: number, actor: ActorKey, processKey: string, opts: { outcome?: string; comment?: string; data?: Record<string, unknown>; target?: string } = {}) {
    return this.push(at, actor, (db, ctx) => {
      const proc = this.proc(db, processKey);
      const data = { ...prefillData(db, this.project!, proc, ctx.today), ...opts.data };
      for (const f of proc.fields) {
        if (!f.required || (data[f.key] !== undefined && data[f.key] !== '')) continue;
        if (f.type === 'number') data[f.key] = 1000;
        else if (f.type === 'date') data[f.key] = ctx.today;
        else if (f.type === 'select') data[f.key] = f.options?.[0];
        else if (f.type === 'docRevision') data[f.key] = docRevisionOptions(db, this.project!.id, f)[0]?.versionId;
        else if (f.type === 'user') data[f.key] = this.project!.npdPicId;
        else data[f.key] = `${f.label} — ${this.project!.productName}`;
      }
      completeProcess(db, ctx, { processId: proc.id, outcomeKey: opts.outcome, comment: opts.comment, data, targetKey: opts.target });
    });
  }

  problem(at: number, actor: ActorKey, processKey: string, note: string) {
    return this.push(at, actor, (db, ctx) => setProblem(db, ctx, this.proc(db, processKey).id, true, note));
  }

  nextAction(at: number, actor: ActorKey, text: string, dueOffset: number, waitingFor?: string) {
    return this.push(at, actor, (db, ctx) =>
      updateNextAction(db, ctx, this.project!.id, {
        nextAction: text,
        nextActionDue: addDays(ctx.today, dueOffset - at),
        waitingFor: waitingFor ?? this.project!.waitingFor,
      }),
    );
  }

  status(at: number, actor: ActorKey, status: ProjectStatus, reason?: string, waitingFor?: string) {
    return this.push(at, actor, (db, ctx) => updateProjectStatus(db, ctx, this.project!.id, { status, reason, waitingFor }));
  }

  comment(at: number, actor: ActorKey, body: string) {
    return this.push(at, actor, (db, ctx) => addComment(db, ctx, { projectId: this.project!.id, body }));
  }

  purchasing(at: number, actor: ActorKey, status: 'po_issued' | 'in_transit' | 'received') {
    return this.push(at, actor, (db, ctx) => {
      const rec = db.records.filter((r) => r.projectId === this.project!.id && r.recordType === 'material_request').at(-1);
      if (rec) updatePurchasingStatus(db, ctx, rec.id, status);
    });
  }

  event(at: number, actor: ActorKey, title: string, category: 'meeting' | 'follow_up', dateOffset: number, time?: string) {
    return this.push(at, actor, (db, ctx) =>
      saveEvent(db, ctx, { title, category, date: addDays(ctx.today, dateOffset - at), time, projectId: this.project!.id }),
    );
  }
}

const U: Record<ActorKey, string> = {
  admin: 'usr_admin',
  sales1: 'usr_sales1',
  sales2: 'usr_sales2',
  npd1: 'usr_npd1',
  npd2: 'usr_npd2',
  drafter1: 'usr_drafter1',
  drafter2: 'usr_drafter2',
  purch: 'usr_purch',
  prod: 'usr_prod',
  qa: 'usr_qa',
};

interface Crew {
  sales: ActorKey;
  npd: ActorKey;
  drafter: ActorKey;
}

/** Subcont happy path up to (and including) customer artwork approval. */
function subcontFront(s: Scenario, c: Crew, t: number, artworkName: string, opts: { rejections?: string[]; approve?: boolean } = {}) {
  s.upload(t, c.sales, 'npr', 'npr', 'NPR Document')
    .complete(t + 1, c.sales, 'npr', { data: { requestNumber: `NPR-${1000 + t * -1}` } })
    .complete(t + 3, c.npd, 'npd_feedback', { outcome: 'accepted', data: { feedback: 'Feasible dengan mesin & mold existing.', feasibility: 'Feasible' } })
    .upload(t + 6, c.drafter, 'artwork', 'artwork', artworkName)
    .complete(t + 7, c.drafter, 'artwork', { data: { artworkName } })
    .complete(t + 8, c.sales, 'sales_submit_artwork');
  let cursor = t + 8;
  (opts.rejections ?? []).forEach((reason) => {
    s.complete(cursor + 4, c.sales, 'customer_artwork_approval', { outcome: 'not_approved', comment: reason })
      .upload(cursor + 7, c.drafter, 'artwork', 'artwork', artworkName, { revisionOf: artworkName, note: `Revisi: ${reason}` })
      .complete(cursor + 8, c.drafter, 'artwork')
      .complete(cursor + 9, c.sales, 'sales_submit_artwork');
    cursor += 9;
  });
  if (opts.approve) s.complete(cursor + 3, c.sales, 'customer_artwork_approval', { outcome: 'approved', comment: 'Artwork disetujui customer.' });
  return cursor + 3;
}

function subcontTrial(s: Scenario, c: Crew, t: number) {
  s.upload(t, c.npd, 'trial_material_prep', 'material_spec', 'Material Specification')
    .complete(t + 2, c.npd, 'trial_material_prep', { data: { material: 'PP Random Copolymer', materialType: 'PP', trialDate: undefined } })
    .upload(t + 5, 'qa', 'trial_evaluation', 'trial_report', 'Trial Report')
    .complete(t + 6, c.npd, 'trial_evaluation', { data: { machine: 'Injection 150T #3', trialResult: 'OK', tests: ['Visual', 'Dimension', 'Leak', 'Drop'] } })
    .complete(t + 7, c.sales, 'sales_submit_trial');
  return t + 7;
}

function subcontBack(s: Scenario, c: Crew, t: number) {
  s.complete(t, c.sales, 'customer_trial_approval', { outcome: 'approved', comment: 'Hasil trial diterima.' })
    .upload(t + 1, c.npd, 'bulk_material_request', 'material_request', 'Material Request FORM-NPD-12')
    .complete(t + 2, c.npd, 'bulk_material_request', { data: { mrNumber: `MR-${2600 - t}`, material: 'PP Homopolymer', supplier: 'PT Chandra Asri', quantity: 2500, requiredDate: undefined } })
    .purchasing(t + 4, 'purch', 'po_issued')
    .purchasing(t + 8, 'purch', 'in_transit');
  return t + 8;
}

function buildScenarios(): Scenario[] {
  const list: Scenario[] = [];
  const base = { productDescription: undefined, remarks: undefined } as const;

  // 1 — Kymm Here We Glow Pink: artwork loop Rev 00 → Rev 01 → Rev 02, waiting customer.
  const kymm = new Scenario({
    ...base,
    type: 'subcont',
    customerId: 'cus_kymm',
    name: 'Kymm Here We Glow Pink',
    productName: 'Lip Tint Tube 8 ml',
    customerRequest: 'Tube lip tint 8 ml warna pink glossy dengan hot stamping logo silver.',
    npdPicId: U.npd1,
    salesPicId: U.sales1,
    drafterId: U.drafter1,
    supplier: 'PT Warna Plastindo',
    priority: 'high',
    start: -40,
    target: 9,
    creator: 'sales1',
  }).create();
  subcontFront(kymm, { sales: 'sales1', npd: 'npd1', drafter: 'drafter1' }, -40, 'Artwork Kymm Here We Glow Pink', {
    rejections: ['Warna pink terlalu gelap, minta lebih soft.', 'Posisi logo terlalu ke bawah, naikkan 5 mm.'],
  });
  kymm.nextAction(-4, 'sales1', 'Follow up customer approval', -1).comment(-4, 'sales1', 'Rev 02 sudah dikirim via email ke Rara, menunggu konfirmasi brand team.');
  list.push(kymm);

  // 2 — Facetology sunscreen: overdue, trial with problem.
  const fct = new Scenario({
    ...base,
    type: 'subcont',
    customerId: 'cus_facetology',
    name: 'Facetology Triple Care Sunscreen Tube',
    productName: 'Sunscreen Tube 50 ml',
    customerRequest: 'Tube 50 ml dengan flip-top cap, printing offset 4 warna.',
    npdPicId: U.npd2,
    salesPicId: U.sales1,
    drafterId: U.drafter1,
    supplier: 'PT Tubindo Jaya',
    priority: 'urgent',
    start: -55,
    target: -3,
    creator: 'sales1',
  }).create();
  const fctT = subcontFront(fct, { sales: 'sales1', npd: 'npd2', drafter: 'drafter1' }, -55, 'Artwork Facetology Sunscreen', { rejections: ['Font ingredient list terlalu kecil.'], approve: true });
  fct
    .upload(fctT + 1, 'npd2', 'trial_material_prep', 'material_spec', 'Material Specification')
    .complete(fctT + 3, 'npd2', 'trial_material_prep', { data: { material: 'LDPE/HDPE blend', materialType: 'LDPE' } })
    .upload(fctT + 7, 'qa', 'trial_evaluation', 'trial_report', 'Trial Report')
    .problem(fctT + 8, 'qa', 'trial_evaluation', 'Leak test NG pada 3 dari 50 sampel — seal cap kurang rapat.')
    .nextAction(fctT + 8, 'npd2', 'Adjust parameter sealing & re-trial leak test', fctT + 14)
    .nextAction(-4, 'npd2', 'Re-trial leak test setelah adjust parameter sealing', 1)
    .comment(-4, 'qa', 'Sampel re-trial siap, menunggu slot mesin line tube #1.')
    .event(fctT + 8, 'npd2', 'Review hasil leak test dengan QA', 'meeting', 2, '10:00');
  list.push(fct);

  // 3 — Aruna serum: trial rejected once, waiting customer trial approval.
  const aruna = new Scenario({
    ...base,
    type: 'subcont',
    customerId: 'cus_aruna',
    name: 'Aruna Serum Dropper Bottle',
    productName: 'Dropper Bottle 30 ml',
    customerRequest: 'Botol serum 30 ml frosted dengan dropper, silk screen 1 warna.',
    npdPicId: U.npd1,
    salesPicId: U.sales2,
    drafterId: U.drafter2,
    supplier: 'PT Kaca Prima',
    priority: 'medium',
    start: -70,
    target: 20,
    creator: 'sales2',
  }).create();
  aruna
    .upload(-70, 'sales2', 'npr', 'npr', 'NPR Document')
    .complete(-69, 'sales2', 'npr')
    .complete(-66, 'npd1', 'npd_feedback', { outcome: 'accepted', data: { feedback: 'Feasible.', feasibility: 'Feasible' } })
    .upload(-62, 'drafter2', 'artwork', 'artwork', 'Artwork Aruna Serum')
    .complete(-61, 'drafter2', 'artwork', { data: { artworkName: 'Artwork Aruna Serum' } })
    .complete(-60, 'sales2', 'sales_submit_artwork')
    .complete(-55, 'sales2', 'customer_artwork_approval', { outcome: 'approved' });
  aruna
    .upload(-52, 'npd1', 'trial_material_prep', 'material_spec', 'Material Specification')
    .complete(-50, 'npd1', 'trial_material_prep', { data: { material: 'PETG', materialType: 'PETG' } })
    .upload(-45, 'qa', 'trial_evaluation', 'trial_report', 'Trial Report TR-01')
    .complete(-44, 'npd1', 'trial_evaluation', { data: { machine: 'ISBM 2 cavity', trialResult: 'OK dengan catatan', problem: 'Neck finish mendekati batas toleransi' } })
    .complete(-43, 'sales2', 'sales_submit_trial')
    .complete(-36, 'sales2', 'customer_trial_approval', { outcome: 'not_approved', comment: 'Dimensi neck di luar toleransi, dropper tidak rapat.' })
    .upload(-28, 'qa', 'trial_evaluation', 'trial_report', 'Trial Report TR-02')
    .complete(-27, 'npd1', 'trial_evaluation', { data: { machine: 'ISBM 2 cavity', trialResult: 'OK', tests: ['Dimension', 'Leak', 'Assembly'] } })
    .complete(-26, 'sales2', 'sales_submit_trial')
    .nextAction(-26, 'sales2', 'Follow up approval hasil trial TR-02', 2)
    .comment(-3, 'sales2', 'Customer minta sampel tambahan 20 pcs untuk uji kompatibilitas serum.');
  list.push(aruna);

  // 4 — Glowlab body mist: material preparation, waiting supplier, due soon.
  const glow = new Scenario({
    ...base,
    type: 'subcont',
    customerId: 'cus_glowlab',
    name: 'Glowlab Body Mist Bottle',
    productName: 'Spray Bottle 100 ml',
    customerRequest: 'Botol PET 100 ml clear dengan fine mist sprayer.',
    npdPicId: U.npd2,
    salesPicId: U.sales2,
    drafterId: U.drafter1,
    supplier: 'PT Polyprima',
    priority: 'high',
    start: -90,
    target: 2,
    creator: 'sales2',
  }).create();
  glow
    .upload(-90, 'sales2', 'npr', 'npr', 'NPR Document')
    .complete(-89, 'sales2', 'npr')
    .complete(-86, 'npd2', 'npd_feedback', { outcome: 'accepted', data: { feedback: 'Feasible.', feasibility: 'Feasible' } })
    .upload(-80, 'drafter1', 'artwork', 'artwork', 'Artwork Glowlab Body Mist')
    .complete(-79, 'drafter1', 'artwork', { data: { artworkName: 'Artwork Glowlab Body Mist' } })
    .complete(-78, 'sales2', 'sales_submit_artwork')
    .complete(-72, 'sales2', 'customer_artwork_approval', { outcome: 'approved' });
  const glowCrew: Crew = { sales: 'sales2', npd: 'npd2', drafter: 'drafter1' };
  const gT = subcontTrial(glow, glowCrew, -68);
  const gB = subcontBack(glow, glowCrew, gT + 5);
  glow.nextAction(gB, 'npd2', 'Follow up kedatangan material ke Purchasing', 1).comment(-1, 'purch', 'ETA material dari supplier H+2, dokumen COA menyusul.');
  list.push(glow);

  // 5 — Natura hand cream: completed.
  const natura = new Scenario({
    ...base,
    type: 'subcont',
    customerId: 'cus_natura',
    name: 'Natura Care Hand Cream Tube',
    productName: 'Tube 75 ml',
    customerRequest: 'Tube 75 ml matte dengan screw cap.',
    npdPicId: U.npd1,
    salesPicId: U.sales1,
    drafterId: U.drafter2,
    supplier: 'PT Tubindo Jaya',
    priority: 'low',
    start: -120,
    target: -10,
    creator: 'sales1',
  }).create();
  const naturaCrew: Crew = { sales: 'sales1', npd: 'npd1', drafter: 'drafter2' };
  const nT = subcontFront(natura, naturaCrew, -120, 'Artwork Natura Hand Cream', { approve: true });
  const nT2 = subcontTrial(natura, naturaCrew, nT + 3);
  const nB = subcontBack(natura, naturaCrew, nT2 + 6);
  natura
    .upload(nB + 3, 'npd1', 'material_preparation', 'coa', 'COA Material Batch 2409')
    .complete(nB + 4, 'npd1', 'material_preparation', { data: { materialReceived: 'PP Homopolymer', quantity: 2500 } })
    .upload(nB + 8, 'qa', 'validation_mass_production', 'validation_report', 'Validation Report')
    .complete(nB + 9, 'qa', 'validation_mass_production', { outcome: 'pass', data: { machine: 'Tube line #2', productionQuantity: 20000 } })
    .complete(nB + 10, 'npd1', 'finish', { comment: 'Semua proses selesai, siap mass production.' });
  list.push(natura);

  // 6 — Sinar compact powder: just started, NPD feedback.
  const sinar = new Scenario({
    ...base,
    type: 'subcont',
    customerId: 'cus_sinar',
    name: 'Sinar Compact Powder Case',
    productName: 'Compact Case 12 g',
    customerRequest: 'Compact powder case dengan mirror dan magnet closure.',
    npdPicId: U.npd2,
    salesPicId: U.sales2,
    drafterId: U.drafter2,
    priority: 'medium',
    start: -3,
    target: 60,
    creator: 'sales2',
  }).create();
  sinar.upload(-3, 'sales2', 'npr', 'npr', 'NPR Document').complete(-2, 'sales2', 'npr');
  list.push(sinar);

  // 7 — Kymm lip serum: artwork not uploaded, no update (missing mandatory document).
  const kymm2 = new Scenario({
    ...base,
    type: 'subcont',
    customerId: 'cus_kymm',
    name: 'Kymm Lip Serum Tube',
    productName: 'Lip Serum Tube 10 ml',
    customerRequest: 'Tube 10 ml dengan applicator doe foot, warna nude.',
    npdPicId: U.npd1,
    salesPicId: U.sales1,
    drafterId: U.drafter2,
    priority: 'medium',
    start: -20,
    target: 40,
    creator: 'sales1',
  }).create();
  kymm2
    .upload(-20, 'sales1', 'npr', 'npr', 'NPR Document')
    .complete(-19, 'sales1', 'npr')
    .complete(-15, 'npd1', 'npd_feedback', { outcome: 'accepted', data: { feedback: 'Feasible, gunakan applicator existing.', feasibility: 'Feasible dengan catatan' } })
    .nextAction(-12, 'npd1', 'Drafter menyiapkan artwork Rev 00', -9);
  list.push(kymm2);

  // 8 — Facetology toner: on hold.
  const toner = new Scenario({
    ...base,
    type: 'subcont',
    customerId: 'cus_facetology',
    name: 'Facetology Toner Bottle',
    productName: 'Bottle 150 ml',
    customerRequest: 'Botol toner 150 ml PET clear, disc cap.',
    npdPicId: U.npd2,
    salesPicId: U.sales1,
    drafterId: U.drafter1,
    priority: 'medium',
    start: -60,
    target: 15,
    creator: 'sales1',
  }).create();
  subcontFront(toner, { sales: 'sales1', npd: 'npd2', drafter: 'drafter1' }, -60, 'Artwork Facetology Toner', { approve: true });
  toner.status(-30, 'npd2', 'hold', 'Customer menunda launching ke Q1 — menunggu konfirmasi jadwal baru.');
  list.push(toner);

  // 9 — Botanica diffuser cap: New Mold, 3D loop, T0 NG → correction → T0 OK, mold shipment.
  const bot = new Scenario({
    ...base,
    type: 'new_mold',
    customerId: 'cus_botanica',
    name: 'Botanica Diffuser Cap',
    productName: 'Diffuser Cap 28 mm',
    customerRequest: 'Cap diffuser 28 mm dengan tekstur kayu, 4 cavity.',
    npdPicId: U.npd1,
    salesPicId: U.sales2,
    drafterId: U.drafter1,
    supplier: 'PT Presisi Mold Teknik',
    priority: 'high',
    start: -100,
    target: 15,
    creator: 'sales2',
  }).create();
  bot
    .upload(-100, 'sales2', 'project_request', 'customer_document', 'Customer Brief')
    .complete(-99, 'sales2', 'project_request')
    .complete(-96, 'npd1', 'npd_feedback', { outcome: 'accepted', data: { feedback: 'Feasible 4 cavity hot runner.', feasibility: 'Feasible' } })
    .upload(-90, 'drafter1', 'prototype_3d', 'drawing_3d', '3D Diffuser Cap')
    .complete(-89, 'drafter1', 'prototype_3d', { data: { designName: 'Diffuser Cap 28 mm' } })
    .complete(-84, 'sales2', 'approval_3d', { outcome: 'not_approved', comment: 'Tekstur kayu kurang dalam, grip kurang terasa.' })
    .upload(-80, 'drafter1', 'prototype_3d', 'drawing_3d', '3D Diffuser Cap', { revisionOf: '3D Diffuser Cap' })
    .complete(-79, 'drafter1', 'prototype_3d')
    .complete(-75, 'sales2', 'approval_3d', { outcome: 'approved' })
    .upload(-72, 'drafter1', 'drawing_2d', 'drawing_2d', '2D Drawing Diffuser Cap')
    .complete(-71, 'drafter1', 'drawing_2d', { data: { drawingNumber: 'DWG-BTH-028' } })
    .complete(-67, 'npd1', 'mold_drawing_approval', { outcome: 'approved' })
    .complete(-35, 'npd1', 'mold_machining', { data: { progress: 100 } })
    .upload(-33, 'prod', 't0_trial', 'trial_report', 'T0 Trial Report')
    .complete(-32, 'npd1', 't0_trial', { outcome: 't0_ng', comment: 'Flash di parting line & short shot pada cavity 3.', data: { machine: 'Injection 220T #5', trialResult: 'NG' } })
    .complete(-20, 'npd1', 'mold_correction', { data: { correctionItems: 'Perbaikan parting line & venting cavity 3.' } })
    .complete(-12, 'npd1', 'mold_machining')
    .upload(-9, 'prod', 't0_trial', 'trial_report', 'T0 Trial Report', { revisionOf: 'T0 Trial Report' })
    .complete(-8, 'npd1', 't0_trial', { outcome: 't0_ok', data: { machine: 'Injection 220T #5', trialResult: 'OK' } })
    .event(-8, 'npd1', 'Konfirmasi jadwal kirim mold', 'follow_up', 1)
    .comment(-2, 'npd1', 'Mold maker konfirmasi pengiriman minggu depan, packing sedang disiapkan.');
  list.push(bot);

  // 10 — Aruna cushion case: New Mold, new masterbatch, waiting customer.
  const cushion = new Scenario({
    ...base,
    type: 'new_mold',
    customerId: 'cus_aruna',
    name: 'Aruna Cushion Case',
    productName: 'Cushion Compact 15 g',
    customerRequest: 'Cushion case custom shape dengan warna signature Aruna Rose.',
    npdPicId: U.npd2,
    salesPicId: U.sales2,
    drafterId: U.drafter2,
    supplier: 'PT Presisi Mold Teknik',
    priority: 'urgent',
    start: -50,
    target: 45,
    creator: 'sales2',
  }).create();
  cushion
    .upload(-50, 'sales2', 'project_request', 'npr', 'Project Request Document')
    .complete(-49, 'sales2', 'project_request')
    .complete(-45, 'npd2', 'npd_feedback', { outcome: 'accepted_masterbatch', data: { feedback: 'Butuh masterbatch baru Aruna Rose.', feasibility: 'Feasible dengan catatan' } })
    .upload(-35, 'npd2', 'masterbatch_dev', 'material_spec', 'Masterbatch Color Chip Aruna Rose')
    .complete(-34, 'npd2', 'masterbatch_dev', { data: { masterbatchCode: 'MB-ARN-ROSE-01', colorTarget: 'Aruna Rose (Pantone 7430 C)' } })
    .complete(-28, 'sales2', 'masterbatch_approval', { outcome: 'not_approved', comment: 'Warna kurang hangat dibanding standar.' })
    .upload(-18, 'npd2', 'masterbatch_dev', 'material_spec', 'Masterbatch Color Chip Aruna Rose', { revisionOf: 'Masterbatch Color Chip Aruna Rose' })
    .complete(-17, 'npd2', 'masterbatch_dev', { data: { masterbatchCode: 'MB-ARN-ROSE-02' } })
    .nextAction(-17, 'sales2', 'Kirim color chip Rev 01 & follow up customer', 3);
  list.push(cushion);

  // 11 — Glowlab jar: New Mold completed, T0 OK first time.
  const jar = new Scenario({
    ...base,
    type: 'new_mold',
    customerId: 'cus_glowlab',
    name: 'Glowlab Jar 50 g',
    productName: 'Cream Jar 50 g',
    customerRequest: 'Jar double wall 50 g dengan inner PP.',
    npdPicId: U.npd1,
    salesPicId: U.sales2,
    drafterId: U.drafter1,
    supplier: 'CV Mitra Mold',
    priority: 'medium',
    start: -160,
    target: -20,
    creator: 'npd1',
  }).create();
  jar
    .upload(-160, 'sales2', 'project_request', 'npr', 'Project Request Document')
    .complete(-159, 'sales2', 'project_request')
    .complete(-156, 'npd1', 'npd_feedback', { outcome: 'accepted', data: { feedback: 'Feasible.', feasibility: 'Feasible' } })
    .upload(-150, 'drafter1', 'prototype_3d', 'drawing_3d', '3D Jar 50 g')
    .complete(-149, 'drafter1', 'prototype_3d', { data: { designName: 'Jar 50 g' } })
    .complete(-144, 'sales2', 'approval_3d', { outcome: 'approved' })
    .upload(-140, 'drafter1', 'drawing_2d', 'drawing_2d', '2D Drawing Jar 50 g')
    .complete(-139, 'drafter1', 'drawing_2d', { data: { drawingNumber: 'DWG-GLW-050' } })
    .complete(-134, 'npd1', 'mold_drawing_approval', { outcome: 'approved' })
    .complete(-96, 'npd1', 'mold_machining')
    .upload(-93, 'prod', 't0_trial', 'trial_report', 'T0 Trial Report')
    .complete(-92, 'npd1', 't0_trial', { outcome: 't0_ok', data: { machine: 'Injection 180T #2', trialResult: 'OK' } })
    .complete(-86, 'npd1', 'mold_shipment', { data: { condition: 'Baik' } })
    .upload(-80, 'prod', 'commissioning_trial', 'trial_report', 'Commissioning Report')
    .complete(-79, 'npd1', 'commissioning_trial', { outcome: 'ok', data: { machine: 'Injection 180T #2', trialResult: 'OK' } })
    .upload(-60, 'npd1', 'material_preparation', 'coa', 'COA Material')
    .complete(-58, 'npd1', 'material_preparation', { data: { materialReceived: 'PP + AS', quantity: 1800 } })
    .upload(-35, 'qa', 'validation_mass_production', 'validation_report', 'Validation Report')
    .complete(-34, 'qa', 'validation_mass_production', { outcome: 'pass_condition', comment: 'Lolos dengan catatan: monitor warna inner.', data: { machine: 'Injection 180T #2', productionQuantity: 15000 } })
    .complete(-25, 'npd1', 'finish');
  list.push(jar);

  // 12 — Sinar lipstick case: New Mold, overdue, T0 NG twice, machining.
  const lip = new Scenario({
    ...base,
    type: 'new_mold',
    customerId: 'cus_sinar',
    name: 'Sinar Lipstick Case',
    productName: 'Lipstick Case Magnetic',
    customerRequest: 'Lipstick case magnetic closure, finishing metallic.',
    npdPicId: U.npd2,
    salesPicId: U.sales2,
    drafterId: U.drafter2,
    supplier: 'CV Mitra Mold',
    priority: 'high',
    start: -75,
    target: -5,
    creator: 'sales2',
  }).create();
  lip
    .upload(-75, 'sales2', 'project_request', 'npr', 'Project Request Document')
    .complete(-74, 'sales2', 'project_request')
    .complete(-72, 'npd2', 'npd_feedback', { outcome: 'accepted', data: { feedback: 'Feasible.', feasibility: 'Feasible' } })
    .upload(-68, 'drafter2', 'prototype_3d', 'drawing_3d', '3D Lipstick Case')
    .complete(-67, 'drafter2', 'prototype_3d', { data: { designName: 'Lipstick Case Magnetic' } })
    .complete(-64, 'sales2', 'approval_3d', { outcome: 'approved' })
    .upload(-62, 'drafter2', 'drawing_2d', 'drawing_2d', '2D Drawing Lipstick Case')
    .complete(-61, 'drafter2', 'drawing_2d', { data: { drawingNumber: 'DWG-SNR-011' } })
    .complete(-58, 'npd2', 'mold_drawing_approval', { outcome: 'approved' })
    .complete(-36, 'npd2', 'mold_machining')
    .upload(-34, 'prod', 't0_trial', 'trial_report', 'T0 Trial Report')
    .complete(-33, 'npd2', 't0_trial', { outcome: 't0_ng', comment: 'Magnet housing tidak presisi, clearance 0.3 mm.', data: { machine: 'Injection 150T #1', trialResult: 'NG' } })
    .complete(-25, 'npd2', 'mold_correction', { data: { correctionItems: 'Adjust core magnet housing.' } })
    .complete(-18, 'npd2', 'mold_machining')
    .upload(-16, 'prod', 't0_trial', 'trial_report', 'T0 Trial Report', { revisionOf: 'T0 Trial Report' })
    .complete(-15, 'npd2', 't0_trial', { outcome: 't0_ng', comment: 'Sink mark pada permukaan cap.', data: { machine: 'Injection 150T #1', trialResult: 'NG' } })
    .complete(-8, 'npd2', 'mold_correction', { data: { correctionItems: 'Tambah cooling channel area cap.' } })
    .nextAction(-8, 'npd2', 'Follow up mold maker — jadwal T0 ketiga', -2);
  list.push(lip);

  // 13 — Natura pump head: New Mold just started, 3D in progress.
  const pump = new Scenario({
    ...base,
    type: 'new_mold',
    customerId: 'cus_natura',
    name: 'Natura Pump Head 24/410',
    productName: 'Lotion Pump 24/410',
    customerRequest: 'Pump head 24/410 custom actuator dengan lock-down.',
    npdPicId: U.npd1,
    salesPicId: U.sales1,
    drafterId: U.drafter2,
    priority: 'medium',
    start: -10,
    target: 80,
    creator: 'sales1',
  }).create();
  pump
    .upload(-10, 'sales1', 'project_request', 'npr', 'Project Request Document')
    .complete(-9, 'sales1', 'project_request')
    .complete(-7, 'npd1', 'npd_feedback', { outcome: 'accepted', data: { feedback: 'Feasible, actuator custom.', feasibility: 'Feasible' } })
    .upload(-2, 'drafter2', 'prototype_3d', 'drawing_3d', '3D Pump Actuator');
  list.push(pump);

  return list;
}

/** Creates a fully seeded database relative to `today`. */
export function createSeedDb(today: string = todayISO()): DbState {
  const nowIso = new Date().toISOString();
  const db = emptyDb(nowIso);
  const actors = new Map<string, PublicUser>(db.users.map((u) => [u.id, u]));
  const scenarios = buildScenarios();
  const actions = scenarios
    .flatMap((s, si) => s.actions.map((a) => ({ ...a, si })))
    .sort((a, b) => a.at - b.at || a.si - b.si || a.order - b.order);
  actions.forEach((a, i) => {
    const day = addDays(today, a.at);
    const minutes = 8 * 60 + ((i * 17) % (9 * 60));
    const now = new Date(`${day}T${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}:00`).toISOString();
    a.run(db, { actor: actors.get(U[a.actor])!, now, today: day });
  });
  const admin = actors.get('usr_admin')!;
  scanDeadlines(db, { actor: admin, now: nowIso, today });
  // Older notifications are already read so the inbox starts realistic.
  const cutoff = new Date(`${addDays(today, -5)}T00:00:00`).toISOString();
  for (const n of db.notifications) if (n.createdAt < cutoff) n.read = true;
  db.seededAt = nowIso;
  return db;
}
