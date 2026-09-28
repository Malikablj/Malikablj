/** HTTP handlers for leads, activities and follow-ups. */
import * as activityService from '../services/activityService.js';
import * as followUpService from '../services/followUpService.js';
import * as leadService from '../services/leadService.js';
import { sendCreated, sendOk } from '../utils/http.js';

export const leads = {
  async list(req, res) {
    const { rows, meta } = await leadService.list(req.validatedQuery);
    sendOk(res, rows, meta);
  },
  async board(req, res) {
    sendOk(res, await leadService.board(req.validatedQuery));
  },
  async get(req, res) {
    sendOk(res, await leadService.get(req.params.id));
  },
  async create(req, res) {
    sendCreated(res, await leadService.create(req.body, req.user));
  },
  async update(req, res) {
    sendOk(res, await leadService.update(req.params.id, req.body, req.user));
  },
  async changeStatus(req, res) {
    sendOk(res, await leadService.changeStatus(req.params.id, req.body, req.user));
  },
};

export const activities = {
  async list(req, res) {
    const { rows, meta } = await activityService.list(req.validatedQuery);
    sendOk(res, rows, meta);
  },
  async get(req, res) {
    sendOk(res, await activityService.get(req.params.id));
  },
  async create(req, res) {
    sendCreated(res, await activityService.create(req.body, req.user));
  },
  async update(req, res) {
    sendOk(res, await activityService.update(req.params.id, req.body, req.user));
  },
};

export const followUps = {
  async list(req, res) {
    const { rows, meta } = await followUpService.list(req.validatedQuery);
    sendOk(res, rows, meta);
  },
  async summary(req, res) {
    const ownerId = req.query.owner === 'me' ? req.user.id : undefined;
    sendOk(res, await followUpService.summary(ownerId));
  },
  async get(req, res) {
    sendOk(res, await followUpService.get(req.params.id));
  },
  async create(req, res) {
    sendCreated(res, await followUpService.create(req.body, req.user));
  },
  async update(req, res) {
    sendOk(res, await followUpService.update(req.params.id, req.body, req.user));
  },
  async complete(req, res) {
    sendOk(res, await followUpService.complete(req.params.id, req.body, req.user));
  },
  async reschedule(req, res) {
    sendOk(res, await followUpService.reschedule(req.params.id, req.body, req.user));
  },
};
