import { type ApprovalRequestInput, type DecisionInput, decideApproval as decideCmd, requestApproval as requestCmd } from '@/domain/approvalCommands';
import { canDecideApproval, visibleProjects } from '@/domain/permissions';
import type { ProcessOutcome } from '@/types';
import { command, query } from './client';
import { type ApprovalView, toApprovalView } from './projects';

export interface ApprovalRow extends ApprovalView {
  canDecide: boolean;
  /** Workflow outcomes when the approval drives the workflow. */
  outcomes?: ProcessOutcome[];
  processActive: boolean;
  documentVersionLabel?: string;
}

export function listApprovals(): Promise<ApprovalRow[]> {
  return query((db, user) => {
    const visible = new Map(visibleProjects(user, db.projects).map((p) => [p.id, p]));
    return db.approvals
      .filter((a) => visible.has(a.projectId))
      .map((a) => {
        const project = visible.get(a.projectId)!;
        const proc = db.processes.find((p) => p.id === a.processId);
        const processActive = !!proc && project.currentProcessId === proc.id && ['current', 'revision', 'problem'].includes(proc.status);
        return {
          ...toApprovalView(db, a),
          canDecide:
            a.status === 'pending' &&
            canDecideApproval(user, project, a, proc) &&
            (!a.linkedToWorkflow || processActive) &&
            project.status !== 'hold' &&
            project.status !== 'cancelled' &&
            project.status !== 'completed',
          outcomes: a.linkedToWorkflow ? proc?.outcomes : undefined,
          processActive,
        };
      })
      .sort((a, b) => (a.status === 'pending' ? 0 : 1) - (b.status === 'pending' ? 0 : 1) || b.createdAt.localeCompare(a.createdAt));
  });
}

export const requestApproval = (input: ApprovalRequestInput) => command((db, ctx) => requestCmd(db, ctx, input));
export const decideApproval = (input: DecisionInput) => command((db, ctx) => decideCmd(db, ctx, input));
export type { ApprovalRequestInput, DecisionInput };
