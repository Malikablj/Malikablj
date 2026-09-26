import { formatRevision } from '@/config/labels';
import { makeArtworkSvg, makeDrawingSvg, makePdf } from '@/lib/generatedFiles';
import { uid } from '@/lib/utils';
import type { DocumentVersion, ProjectDocument } from '@/types';

/**
 * Binary storage for uploaded files (IndexedDB), standing in for object
 * storage (S3/GCS). Falls back to memory when IndexedDB is unavailable.
 */

const DB_NAME = 'npd-project-control-files';
const STORE = 'files';
const memory = new Map<string, Blob>();

function openDb(): Promise<IDBDatabase | null> {
  return new Promise((resolve) => {
    try {
      if (typeof indexedDB === 'undefined') return resolve(null);
      const req = indexedDB.open(DB_NAME, 1);
      req.onupgradeneeded = () => req.result.createObjectStore(STORE);
      req.onsuccess = () => resolve(req.result);
      req.onerror = () => resolve(null);
    } catch {
      resolve(null);
    }
  });
}

export async function putFile(blob: Blob): Promise<string> {
  const key = uid('file');
  const db = await openDb();
  if (!db) {
    memory.set(key, blob);
    return key;
  }
  await new Promise<void>((resolve, reject) => {
    const tx = db.transaction(STORE, 'readwrite');
    tx.objectStore(STORE).put(blob, key);
    tx.oncomplete = () => resolve();
    tx.onerror = () => reject(tx.error);
  }).catch(() => memory.set(key, blob));
  return key;
}

async function getFile(key: string): Promise<Blob | null> {
  if (memory.has(key)) return memory.get(key)!;
  const db = await openDb();
  if (!db) return null;
  return new Promise((resolve) => {
    const req = db.transaction(STORE, 'readonly').objectStore(STORE).get(key);
    req.onsuccess = () => resolve((req.result as Blob | undefined) ?? null);
    req.onerror = () => resolve(null);
  });
}

export async function clearFiles(): Promise<void> {
  memory.clear();
  const db = await openDb();
  if (!db) return;
  await new Promise<void>((resolve) => {
    const tx = db.transaction(STORE, 'readwrite');
    tx.objectStore(STORE).clear();
    tx.oncomplete = () => resolve();
    tx.onerror = () => resolve();
  });
}

/** Demo documents are generated on the fly so preview/download still works. */
function generateSeedFile(version: DocumentVersion, doc: ProjectDocument, context: { projectCode: string; projectName: string; customer: string; processName: string }): Blob {
  const rev = formatRevision(version.revision);
  if (doc.type === 'artwork' || doc.type === 'trial_photo')
    return makeArtworkSvg({ product: context.projectName, brand: context.customer, revision: `${doc.name} · ${rev}`, seed: version.revision + doc.name.length });
  if (doc.type === 'drawing_3d') return makeDrawingSvg({ title: doc.name, revision: rev, kind: '3D' });
  if (doc.type === 'drawing_2d') return makeDrawingSvg({ title: doc.name, revision: rev, kind: '2D' });
  if (doc.type === 'mold_drawing') return makeDrawingSvg({ title: doc.name, revision: rev, kind: 'MOLD' });
  return makePdf(doc.name, [
    `Project      : ${context.projectCode} - ${context.projectName}`,
    `Customer     : ${context.customer}`,
    `Process      : ${context.processName}`,
    `Revision     : ${rev}${version.version > 1 ? ` v${version.version}` : ''}`,
    `File         : ${version.fileName}`,
    `Uploaded at  : ${version.uploadedAt.slice(0, 10)}`,
    '',
    version.note ? `Catatan: ${version.note}` : 'Dokumen contoh yang dibuat otomatis untuk demo NPD Project Control.',
  ]);
}

export async function resolveFile(
  version: DocumentVersion,
  doc: ProjectDocument,
  context: { projectCode: string; projectName: string; customer: string; processName: string },
): Promise<Blob | null> {
  if (!version.blobKey) return null;
  if (version.blobKey === 'seed') return generateSeedFile(version, doc, context);
  return getFile(version.blobKey);
}
