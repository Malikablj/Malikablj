import * as contactService from '../services/contactService.js';
import * as customerService from '../services/customerService.js';
import { sendCreated, sendOk } from '../utils/http.js';

export async function list(req, res) {
  const { rows, meta } = await customerService.list(req.validatedQuery);
  sendOk(res, rows, meta);
}

export async function facets(req, res) {
  sendOk(res, await customerService.facets());
}

export async function get(req, res) {
  sendOk(res, await customerService.getWorkspace(req.params.id));
}

export async function create(req, res) {
  sendCreated(res, await customerService.create(req.body, req.user));
}

export async function update(req, res) {
  sendOk(res, await customerService.update(req.params.id, req.body, req.user));
}

export async function archive(req, res) {
  sendOk(res, await customerService.setActive(req.params.id, false, req.user));
}

export async function restore(req, res) {
  sendOk(res, await customerService.setActive(req.params.id, true, req.user));
}

export async function listContacts(req, res) {
  const isActive = req.query.is_active === 'false' ? false : req.query.is_active === 'all' ? null : true;
  sendOk(res, await contactService.listForCustomer(req.params.customerId, { isActive }));
}

export async function createContact(req, res) {
  sendCreated(res, await contactService.create(req.params.customerId, req.body, req.user));
}

export async function updateContact(req, res) {
  sendOk(res, await contactService.update(req.params.id, req.body, req.user));
}

export async function archiveContact(req, res) {
  sendOk(res, await contactService.archive(req.params.id, req.user));
}
