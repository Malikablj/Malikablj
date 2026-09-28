/**
 * Express application factory. Used by server.js and by the API tests (supertest),
 * so it must not start listening or connect to the database by itself.
 */
import fs from 'node:fs';
import path from 'node:path';
import cookieParser from 'cookie-parser';
import cors from 'cors';
import express from 'express';
import helmet from 'helmet';
import config from './config/index.js';
import { apiNotFound, errorHandler } from './middleware/errorHandler.js';
import { requestLogger } from './middleware/requestLogger.js';
import { createApiRouter } from './routes/index.js';

export function createApp() {
  const app = express();
  app.disable('x-powered-by');
  if (config.trustProxy) app.set('trust proxy', 1);

  app.use(
    helmet({
      contentSecurityPolicy: {
        directives: {
          'img-src': ["'self'", 'data:'],
        },
      },
    }),
  );
  app.use(
    cors({
      origin: config.corsOrigin.split(',').map((origin) => origin.trim()),
      credentials: true,
    }),
  );
  app.use(express.json({ limit: '1mb' }));
  app.use(cookieParser());
  app.use(requestLogger);

  app.use('/api', createApiRouter());
  app.use('/api', apiNotFound);

  // In production the API also serves the built web app (single deployable unit).
  const indexHtml = path.join(config.webDistDir, 'index.html');
  if (fs.existsSync(indexHtml)) {
    app.use(
      express.static(config.webDistDir, {
        index: false,
        // Built assets have content hashes in their names, so they can be cached for good.
        setHeaders: (res, filePath) => {
          res.setHeader('Cache-Control', filePath.includes(`${path.sep}assets${path.sep}`) ? 'public, max-age=31536000, immutable' : 'no-cache');
        },
      }),
    );
    app.use((req, res, next) => {
      if (req.method !== 'GET' || req.path.startsWith('/api')) return next();
      res.setHeader('Cache-Control', 'no-cache');
      return res.sendFile(indexHtml);
    });
  }

  app.use(errorHandler);
  return app;
}
