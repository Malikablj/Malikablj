import { answerQuestion, type AssistantAnswer } from '@/assistant/engine';
import { computeAnalytics, weeklyReport } from '@/domain/analytics';
import { findProject } from '@/domain/context';
import { AppError } from '@/domain/errors';
import { canViewProject, visibleProjects } from '@/domain/permissions';
import type { ISODate } from '@/types';
import { query } from './client';

export const getWeeklyReport = (periodStart: ISODate) =>
  query((db, user, today) => weeklyReport(db, visibleProjects(user, db.projects), periodStart, today));

export const getAnalytics = () => query((db, user, today) => computeAnalytics(db, visibleProjects(user, db.projects), today));

/**
 * AI Assistant provider. The default provider is the deterministic,
 * database-grounded engine; an LLM-backed provider can implement the same
 * interface (using the same permission-scoped data as tools).
 */
export interface AssistantProvider {
  ask(question: string): Promise<AssistantAnswer>;
  summarizeProject(projectId: string): Promise<AssistantAnswer>;
}

export const assistant: AssistantProvider = {
  ask: (question) => query((db, user, today) => answerQuestion(db, user, question, today)),
  summarizeProject: (projectId) =>
    query((db, user, today) => {
      const p = findProject(db, projectId);
      if (!canViewProject(user, p)) throw new AppError('FORBIDDEN', 'Anda tidak memiliki akses ke project ini.');
      return answerQuestion(db, user, `ringkasan ${p.code}`, today);
    }),
};
