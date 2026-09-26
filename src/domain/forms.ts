import { formatRevision } from '@/config/labels';
import type { DbState, FormFieldDef, ISODate, Project, ProjectProcess } from '@/types';
import { customerName } from './context';

/** Initial values for a process form: saved draft first, then project-derived prefills. */
export function prefillData(db: DbState, project: Project, proc: ProjectProcess, today: ISODate): Record<string, unknown> {
  const data: Record<string, unknown> = {};
  for (const f of proc.fields) {
    const v = prefillValue(db, project, f, today);
    if (v !== undefined) data[f.key] = v;
  }
  const merged: Record<string, unknown> = { ...data, ...proc.data };
  // Document revision pickers always point at a revision that is still valid (latest by default).
  for (const f of proc.fields) {
    if (f.type !== 'docRevision') continue;
    const options = docRevisionOptions(db, project.id, f);
    if (!options.some((o) => o.versionId === merged[f.key])) merged[f.key] = options[0]?.versionId;
  }
  return merged;
}

function prefillValue(db: DbState, project: Project, f: FormFieldDef, today: ISODate): unknown {
  switch (f.prefill) {
    case 'customer':
      return customerName(db, project.customerId);
    case 'projectName':
      return project.name;
    case 'productName':
      return project.productName;
    case 'customerRequest':
      return project.customerRequest;
    case 'salesPic':
      return project.salesPicId;
    case 'npdPic':
      return project.npdPicId;
    case 'drafter':
      return project.drafterId;
    case 'today':
      return today;
    case 'supplier':
      return project.supplier;
    default:
      return undefined;
  }
}

export interface DocRevisionOption {
  versionId: string;
  label: string;
  status: string;
}

/** Selectable document revisions for `docRevision` fields (e.g. Artwork Revision). */
export function docRevisionOptions(db: DbState, projectId: string, field: FormFieldDef): DocRevisionOption[] {
  const docs = db.documents.filter((d) => d.projectId === projectId && (!field.docType || d.type === field.docType));
  const options: DocRevisionOption[] = [];
  for (const d of docs) {
    const versions = db.documentVersions
      .filter((v) => v.documentId === d.id && v.status !== 'rejected' && v.status !== 'superseded')
      .sort((a, b) => b.uploadedAt.localeCompare(a.uploadedAt));
    for (const v of versions)
      options.push({ versionId: v.id, label: `${d.name} — ${formatRevision(v.revision)}${v.version > 1 ? ` v${v.version}` : ''}`, status: v.status });
  }
  return options;
}
