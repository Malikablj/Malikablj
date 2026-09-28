/**
 * Request validation with zod. Validated values replace req.body; query strings are
 * stored on req.validatedQuery (Express 5 exposes req.query as read-only).
 */
import { z } from 'zod';
import { notFound } from '../utils/errors.js';

export function validateBody(schema) {
  return (req, res, next) => {
    req.body = schema.parse(req.body ?? {});
    next();
  };
}

export function validateQuery(schema) {
  return (req, res, next) => {
    req.validatedQuery = schema.parse(req.query ?? {});
    next();
  };
}

const idSchema = z.guid();

/** Path ids that are not UUIDs cannot exist, so they are answered with 404. */
export function validateIdParams(...names) {
  return (req, res, next) => {
    for (const name of names) {
      if (!idSchema.safeParse(req.params[name]).success) throw notFound();
    }
    next();
  };
}
