import {
  addWorkflowProcess as addWfCmd,
  type CustomerInput,
  deleteCustomer as deleteCustomerCmd,
  moveWorkflowProcess as moveWfCmd,
  removeWorkflowProcess as removeWfCmd,
  saveCustomer as saveCustomerCmd,
  saveUser as saveUserCmd,
  updateSettings as updateSettingsCmd,
  updateWorkflowProcess as updateWfCmd,
  type UserInput,
  type WorkflowProcessPatch,
} from '@/domain/adminCommands';
import { AppError } from '@/domain/errors';
import { canManageSettings } from '@/domain/permissions';
import type { AppSettings, Customer, PublicUser, WorkflowTemplate } from '@/types';
import { clearFiles } from '../storage/fileStore';
import { resetDatabase } from '../storage/database';
import { command, query, toPublic } from './client';

/** Reference data needed across the UI (users & customers are small). */
export interface Lookups {
  users: PublicUser[];
  customers: Customer[];
  settings: AppSettings;
}

export const getLookups = () =>
  query((db) => ({ users: db.users.map(toPublic), customers: db.customers, settings: db.settings }) satisfies Lookups);

export const getWorkflows = () => query((db): WorkflowTemplate[] => db.workflows);

export const saveUser = (input: UserInput, id?: string) => command((db, ctx) => toPublic(saveUserCmd(db, ctx, input, id)));
export const saveCustomer = (input: CustomerInput, id?: string) => command((db, ctx) => saveCustomerCmd(db, ctx, input, id));
export const deleteCustomer = (id: string) => command((db, ctx) => deleteCustomerCmd(db, ctx, id));
export const updateSettings = (patch: Partial<AppSettings>) => command((db, ctx) => updateSettingsCmd(db, ctx, patch));
export const updateWorkflowProcess = (workflowId: string, key: string, patch: WorkflowProcessPatch) =>
  command((db, ctx) => updateWfCmd(db, ctx, workflowId, key, patch));
export const addWorkflowProcess = (workflowId: string, afterKey: string, input: Parameters<typeof addWfCmd>[4]) =>
  command((db, ctx) => addWfCmd(db, ctx, workflowId, afterKey, input));
export const moveWorkflowProcess = (workflowId: string, key: string, direction: -1 | 1) =>
  command((db, ctx) => moveWfCmd(db, ctx, workflowId, key, direction));
export const removeWorkflowProcess = (workflowId: string, key: string) => command((db, ctx) => removeWfCmd(db, ctx, workflowId, key));

export async function resetDemoData(): Promise<void> {
  await query((_db, user) => {
    if (!canManageSettings(user)) throw new AppError('FORBIDDEN', 'Hanya Admin yang dapat mereset data demo.');
  });
  await clearFiles();
  resetDatabase();
}

export type { UserInput, CustomerInput, WorkflowProcessPatch };
