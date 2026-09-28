/** HTTP handlers for dashboard, reports, search, notifications and migration issues. */
import * as dashboardService from '../services/dashboardService.js';
import * as insightService from '../services/insightService.js';
import * as reportService from '../services/reportService.js';
import { sendCsv, sendOk } from '../utils/http.js';

export async function dashboard(req, res) {
  sendOk(res, await dashboardService.summary(req.user, req.query.scope === 'me' ? 'me' : 'all'));
}

export async function report(req, res) {
  const { format, ...filters } = req.validatedQuery;
  if (format === 'csv') {
    const { filename, csv, truncated } = await reportService.exportCsv(req.params.type, filters, req.user);
    if (truncated) res.setHeader('X-Report-Truncated', 'true');
    sendCsv(res, filename, csv);
    return;
  }
  const { rows, meta } = await reportService.run(req.params.type, filters, req.user);
  sendOk(res, rows, meta);
}

export async function search(req, res) {
  sendOk(res, await insightService.search(req.validatedQuery.q, req.user));
}

export async function notifications(req, res) {
  sendOk(res, await insightService.notifications(req.user));
}

export async function listIssues(req, res) {
  const { rows, meta } = await insightService.listIssues(req.validatedQuery);
  sendOk(res, rows, meta);
}

export async function issueSummary(req, res) {
  sendOk(res, await insightService.issueSummary());
}

export async function resolveIssue(req, res) {
  sendOk(res, await insightService.resolveIssue(req.params.id, req.body, req.user));
}
