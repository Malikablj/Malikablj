import { describe, expect, it } from 'vitest';
import { addDays } from '@/lib/date';
import type { DbState, PublicUser } from '@/types';
import { type CommandContext, projectProcesses } from './context';
import { uploadDocument } from './documentCommands';
import { AppError } from './errors';
import { displayStatus, isOverdue } from './metrics';
import { createProject, updateProjectStatus } from './projectCommands';
import { createSeedDb, emptyDb, SEED_USERS } from './seed';
import { completeProcess, finishProject, overrideCurrentProcess } from './workflow';
import { decideApproval } from './approvalCommands';
import { answerQuestion } from '@/assistant/engine';

const TODAY = '2026-09-26';
const user = (id: string): PublicUser => SEED_USERS.find((u) => u.id === id)!;
const ctx = (id: string, today = TODAY): CommandContext => ({ actor: user(id), today, now: `${today}T09:00:00.000Z` });

function newSubcont(db: DbState) {
  return createProject(db, ctx('usr_sales1'), {
    type: 'subcont',
    customerId: 'cus_kymm',
    name: 'Test Tube',
    productName: 'Tube 10 ml',
    customerRequest: 'Tube test',
    npdPicId: 'usr_npd1',
    salesPicId: 'usr_sales1',
    drafterId: 'usr_drafter1',
    priority: 'medium',
    startDate: TODAY,
    targetDate: addDays(TODAY, 60),
  });
}

const file = { fileName: 'x.pdf', mimeType: 'application/pdf', size: 1000 };
const proc = (db: DbState, projectId: string, key: string) => projectProcesses(db, projectId).find((p) => p.key === key)!;

describe('seed', () => {
  it('builds a consistent demo database', () => {
    const db = createSeedDb(TODAY);
    expect(db.projects).toHaveLength(13);
    expect(db.projects.map((p) => p.code)).toContain('NPD-2026-001');
    for (const p of db.projects) {
      if (p.status === 'completed' || p.status === 'cancelled') continue;
      // Business rule: every active project has PIC, status, current process, target and next action.
      expect(p.currentProcessId).toBeTruthy();
      expect(p.nextAction).toBeTruthy();
      expect(p.targetDate).toBeTruthy();
      if (p.status === 'waiting_external') expect(p.waitingFor).toBeTruthy();
      if (p.status === 'waiting_approval') expect(db.approvals.some((a) => a.projectId === p.id && a.status === 'pending')).toBe(true);
    }
    const kymm = db.projects.find((p) => p.name === 'Kymm Here We Glow Pink')!;
    expect(proc(db, kymm.id, 'customer_artwork_approval').status).toBe('current');
    expect(kymm.status).toBe('waiting_approval');
    const artworkApprovals = db.approvals.filter((a) => a.projectId === kymm.id && a.type === 'artwork');
    expect(artworkApprovals.map((a) => a.status)).toEqual(['rejected', 'rejected', 'pending']);
    expect(artworkApprovals[2].revision).toContain('Rev 02');
    const completed = db.projects.filter((p) => p.status === 'completed');
    expect(completed).toHaveLength(2);
    const overdue = db.projects.filter((p) => isOverdue(p, TODAY));
    expect(overdue.length).toBeGreaterThanOrEqual(2);
    expect(displayStatus(overdue[0], TODAY)).toBe('overdue');
  });
});

describe('subcont workflow', () => {
  it('requires documents and loops back to artwork on customer rejection', () => {
    const db = emptyDb(`${TODAY}T00:00:00Z`);
    const p = newSubcont(db);
    expect(p.code).toBe('NPD-2026-001');
    const npr = proc(db, p.id, 'npr');
    expect(() => completeProcess(db, ctx('usr_sales1'), { processId: npr.id, data: { requestNumber: 'N1', quantity: 1, material: 'PP' } })).toThrow(/dokumen wajib/i);
    uploadDocument(db, ctx('usr_sales1'), { projectId: p.id, processId: npr.id, mode: 'new', type: 'npr', name: 'NPR', file });
    completeProcess(db, ctx('usr_sales1'), { processId: npr.id, data: { requestNumber: 'N1', quantity: 1, material: 'PP' } });
    expect(p.currentProcessId).toBe(proc(db, p.id, 'npd_feedback').id);

    // Drafter cannot decide NPD feedback.
    expect(() => completeProcess(db, ctx('usr_drafter1'), { processId: proc(db, p.id, 'npd_feedback').id, outcomeKey: 'accepted' })).toThrow(AppError);
    completeProcess(db, ctx('usr_npd1'), { processId: proc(db, p.id, 'npd_feedback').id, outcomeKey: 'accepted', data: { feedback: 'ok', feasibility: 'Feasible' } });

    const artwork = proc(db, p.id, 'artwork');
    uploadDocument(db, ctx('usr_drafter1'), { projectId: p.id, processId: artwork.id, mode: 'new', type: 'artwork', name: 'Artwork', file });
    completeProcess(db, ctx('usr_drafter1'), { processId: artwork.id, data: { artworkName: 'A' } });
    const submit = proc(db, p.id, 'sales_submit_artwork');
    const artDoc = db.documents.find((d) => d.projectId === p.id && d.type === 'artwork')!;
    completeProcess(db, ctx('usr_sales1'), { processId: submit.id, data: { artworkRevision: artDoc.latestVersionId } });
    expect(p.status).toBe('waiting_approval');
    const approval = db.approvals.find((a) => a.projectId === p.id && a.type === 'artwork')!;
    expect(approval.status).toBe('pending');
    expect(approval.revision).toBe('Artwork Rev 00');

    // Rejection requires a comment and loops back to Artwork.
    expect(() => decideApproval(db, ctx('usr_sales1'), { approvalId: approval.id, decision: 'rejected' })).toThrow(/Komentar/);
    decideApproval(db, ctx('usr_sales1'), { approvalId: approval.id, decision: 'rejected', comment: 'Warna salah' });
    expect(approval.status).toBe('rejected');
    expect(p.currentProcessId).toBe(artwork.id);
    expect(artwork.status).toBe('revision');
    expect(submit.status).toBe('not_started');
    expect(db.documentVersions.find((v) => v.id === artDoc.latestVersionId)!.status).toBe('rejected');
    // Old artwork revision is rejected → a new revision is required.
    expect(() => completeProcess(db, ctx('usr_drafter1'), { processId: artwork.id })).toThrow(/dokumen wajib/i);
    uploadDocument(db, ctx('usr_drafter1'), { projectId: p.id, processId: artwork.id, mode: 'revision', documentId: artDoc.id, type: 'artwork', name: 'Artwork', file });
    expect(db.documentVersions.filter((v) => v.documentId === artDoc.id)).toHaveLength(2);
    completeProcess(db, ctx('usr_drafter1'), { processId: artwork.id });
    completeProcess(db, ctx('usr_sales1'), { processId: submit.id, data: { artworkRevision: artDoc.latestVersionId } });
    const second = db.approvals.filter((a) => a.projectId === p.id && a.type === 'artwork').at(-1)!;
    expect(second.revision).toBe('Artwork Rev 01');
    decideApproval(db, ctx('usr_sales1'), { approvalId: second.id, decision: 'approved' });
    expect(p.currentProcessId).toBe(proc(db, p.id, 'trial_material_prep').id);
    expect(p.status).toBe('on_progress');

    // Finish is blocked while mandatory processes are incomplete.
    try {
      finishProject(db, ctx('usr_npd1'), p.id);
      throw new Error('should fail');
    } catch (e) {
      expect(e).toBeInstanceOf(AppError);
      expect((e as AppError).details!.some((d) => d.includes('Trial Material Preparation'))).toBe(true);
    }
    // Admin override forward does not bypass the mandatory rule.
    overrideCurrentProcess(db, ctx('usr_admin'), p.id, proc(db, p.id, 'finish').id, 'test');
    expect(() => completeProcess(db, ctx('usr_npd1'), { processId: proc(db, p.id, 'finish').id })).toThrow(/belum dapat diselesaikan/);
  });

  it('enforces status business rules', () => {
    const db = emptyDb(`${TODAY}T00:00:00Z`);
    const p = newSubcont(db);
    try {
      updateProjectStatus(db, ctx('usr_npd1'), p.id, { status: 'waiting_external' });
      throw new Error('should fail');
    } catch (e) {
      expect((e as AppError).fieldErrors?.waitingFor).toMatch(/Waiting For/);
    }
    expect(() => updateProjectStatus(db, ctx('usr_npd1'), p.id, { status: 'waiting_approval' })).toThrow(/approval record/);
    expect(() => updateProjectStatus(db, ctx('usr_mgmt'), p.id, { status: 'hold', reason: 'x' })).toThrow(AppError);
    updateProjectStatus(db, ctx('usr_npd1'), p.id, { status: 'hold', reason: 'Customer postpone' });
    expect(() => completeProcess(db, ctx('usr_sales1'), { processId: proc(db, p.id, 'npr').id })).toThrow(/Hold/);
    updateProjectStatus(db, ctx('usr_npd1'), p.id, { status: 'on_progress' });
    expect(p.status).toBe('on_progress');
  });

  it('rejects duplicate projects for the same customer', () => {
    const db = emptyDb(`${TODAY}T00:00:00Z`);
    newSubcont(db);
    expect(() => newSubcont(db)).toThrow(/sudah ada/);
  });
});

describe('new mold workflow', () => {
  it('handles masterbatch branch and T0 correction loop', () => {
    const db = createSeedDb(TODAY);
    const bot = db.projects.find((p) => p.name === 'Botanica Diffuser Cap')!;
    expect(proc(db, bot.id, 'masterbatch_dev').status).toBe('skipped');
    expect(proc(db, bot.id, 'mold_correction').status).toBe('completed');
    expect(proc(db, bot.id, 'mold_shipment').status).toBe('current');
    expect(bot.status).toBe('waiting_external');
    const t0 = db.approvals.filter((a) => a.projectId === bot.id && a.type === 't0');
    expect(t0.map((a) => a.status)).toEqual(['rejected', 'approved']);
    const cushion = db.projects.find((p) => p.name === 'Aruna Cushion Case')!;
    expect(cushion.newMasterbatch).toBe(true);
    expect(proc(db, cushion.id, 'prototype_3d').status).toBe('skipped');
    expect(proc(db, cushion.id, 'masterbatch_approval').status).toBe('current');
  });
});

describe('assistant', () => {
  const db = createSeedDb(TODAY);
  it('answers from data and respects scope', () => {
    const admin = answerQuestion(db, user('usr_admin'), 'Project mana yang overdue?', TODAY);
    expect(admin.blocks.some((b) => b.type === 'projects' && b.items.length > 0)).toBe(true);
    const kymm = answerQuestion(db, user('usr_admin'), 'Artwork Kymm Here We Glow Pink terakhir revisi berapa?', TODAY);
    expect(JSON.stringify(kymm)).toContain('Rev 02');
    // Drafter 2 cannot see Kymm Here We Glow Pink (drafter 1's project).
    const scoped = answerQuestion(db, user('usr_drafter2'), 'Project Kymm Here We Glow Pink sekarang sampai mana?', TODAY);
    expect(JSON.stringify(scoped)).not.toContain('Customer Artwork Approval');
    const unknown = answerQuestion(db, user('usr_admin'), 'Berapa harga saham perusahaan?', TODAY);
    expect(JSON.stringify(unknown)).toContain('Data tersebut belum tersedia di sistem');
  });
});
