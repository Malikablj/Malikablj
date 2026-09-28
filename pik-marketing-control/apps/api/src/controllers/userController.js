import * as userService from '../services/userService.js';
import { sendCreated, sendOk } from '../utils/http.js';

export async function list(req, res) {
  const { rows, meta } = await userService.list(req.validatedQuery);
  sendOk(res, rows, meta);
}

export async function options(req, res) {
  sendOk(res, await userService.options());
}

export async function get(req, res) {
  sendOk(res, await userService.get(req.params.id));
}

export async function create(req, res) {
  sendCreated(res, await userService.create(req.body, req.user));
}

export async function update(req, res) {
  sendOk(res, await userService.update(req.params.id, req.body, req.user));
}

export async function resetPassword(req, res) {
  await userService.resetPassword(req.params.id, req.body.password, req.user);
  sendOk(res, null);
}
