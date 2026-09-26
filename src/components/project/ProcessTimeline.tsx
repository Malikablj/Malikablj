import { useState } from 'react';
import { diffDays, formatDate, formatDateShort, maxDate, minDate } from '@/lib/date';
import { cn } from '@/lib/utils';
import type { Project } from '@/types';
import type { ProcessView } from '@/services/api/projects';
import { SegmentedControl } from '../ui/Form';
import { ProcessStatusChip } from './Chips';

/** Planned vs actual per process (PRD §7 Timeline; acceptance: planned/actual date and duration). */
export function ProcessTimeline({ project, processes, today, onSelect }: { project: Project; processes: ProcessView[]; today: string; onSelect: (p: ProcessView) => void }) {
  const [view, setView] = useState<'chart' | 'table'>('chart');
  const rows = processes.filter((p) => !(p.loopOnly && p.status === 'not_started'));
  let from = project.startDate;
  let to = maxDate(project.targetDate, today);
  for (const p of rows) {
    from = minDate(from, p.plannedStart);
    if (p.actualStart) from = minDate(from, p.actualStart);
    to = maxDate(to, p.plannedFinish);
    if (p.actualFinish) to = maxDate(to, p.actualFinish);
  }
  const span = Math.max(1, diffDays(from, to));
  const pos = (d: string) => (diffDays(from, d) / span) * 100;
  const todayPos = pos(today);

  return (
    <div>
      <div className="mb-3 flex items-center justify-between gap-2">
        <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-[12px] text-ink-3">
          <span className="inline-flex items-center gap-1.5">
            <span className="h-2 w-5 rounded-sm bg-mark-track" aria-hidden="true" /> Planned
          </span>
          <span className="inline-flex items-center gap-1.5">
            <span className="h-2 w-5 rounded-sm bg-mark-blue" aria-hidden="true" /> Actual
          </span>
          <span className="inline-flex items-center gap-1.5">
            <span className="h-3 w-[2px] bg-mark-red" aria-hidden="true" /> Hari ini
          </span>
        </div>
        <SegmentedControl
          label="Tampilan timeline"
          size="sm"
          value={view}
          onChange={setView}
          options={[
            { value: 'chart', label: 'Grafik' },
            { value: 'table', label: 'Tabel' },
          ]}
        />
      </div>

      {view === 'chart' ? (
        <div>
          <div className="mb-1 flex justify-between pl-0 text-[11px] text-ink-3 md:pl-[34%]">
            <span>{formatDateShort(from)}</span>
            <span>{formatDateShort(to)}</span>
          </div>
          <ul className="space-y-1">
            {rows.map((p) => {
              const skipped = p.status === 'skipped';
              const actualEnd = p.actualFinish ?? (p.actualStart && ['current', 'revision', 'problem'].includes(p.status) ? today : undefined);
              const late = p.actualFinish ? p.actualFinish > p.plannedFinish : !!actualEnd && today > p.plannedFinish;
              return (
                <li key={p.id}>
                  <button type="button" onClick={() => onSelect(p)} className={cn('grid w-full grid-cols-1 items-center gap-x-3 gap-y-1 rounded-lg px-2 py-1.5 text-left hover:bg-surface-2 md:grid-cols-[34%_1fr]', skipped && 'opacity-50')}>
                    <span className="flex min-w-0 items-baseline justify-between gap-2">
                      <span className="truncate text-[13px] text-ink">
                        <span className="tabular mr-1.5 text-ink-3">{String(p.sequence).padStart(2, '0')}</span>
                        {p.shortName}
                      </span>
                      <span className="tabular shrink-0 text-[11px] text-ink-3">
                        {p.duration !== null ? `${p.duration} hari` : skipped ? '—' : `${Math.max(1, diffDays(p.plannedStart, p.plannedFinish))} hari plan`}
                      </span>
                    </span>
                    <span className="relative block h-5" aria-hidden="true">
                      <span className="absolute top-1/2 h-2 -translate-y-1/2 rounded-full bg-mark-track" style={{ left: `${pos(p.plannedStart)}%`, width: `${Math.max(0.8, pos(p.plannedFinish) - pos(p.plannedStart))}%` }} />
                      {p.actualStart && actualEnd && (
                        <span
                          className={cn('absolute top-1/2 h-2 -translate-y-1/2 rounded-full', late ? 'bg-mark-orange' : p.status === 'completed' ? 'bg-mark-green' : 'bg-mark-blue')}
                          style={{ left: `${pos(p.actualStart)}%`, width: `${Math.max(0.8, pos(actualEnd) - pos(p.actualStart))}%` }}
                        />
                      )}
                      {todayPos >= 0 && todayPos <= 100 && <span className="absolute inset-y-0 w-[2px] bg-mark-red/70" style={{ left: `${todayPos}%` }} />}
                    </span>
                    <span className="sr-only">
                      Planned {formatDate(p.plannedStart)} – {formatDate(p.plannedFinish)}; actual {p.actualStart ? formatDate(p.actualStart) : 'belum mulai'} – {p.actualFinish ? formatDate(p.actualFinish) : 'berjalan'}
                    </span>
                  </button>
                </li>
              );
            })}
          </ul>
          <p className="mt-2 text-[12px] text-ink-3">Bar oranye = aktual melewati planned finish. Klik baris untuk detail proses.</p>
        </div>
      ) : (
        <div className="-mx-5 overflow-x-auto">
          <table className="w-full min-w-[720px] text-left text-[13px]">
            <thead>
              <tr className="border-y border-line text-[12px] text-ink-3">
                <th scope="col" className="px-5 py-2 font-medium">Proses</th>
                <th scope="col" className="px-3 py-2 font-medium">Status</th>
                <th scope="col" className="px-3 py-2 font-medium">Planned Start</th>
                <th scope="col" className="px-3 py-2 font-medium">Planned Finish</th>
                <th scope="col" className="px-3 py-2 font-medium">Actual Start</th>
                <th scope="col" className="px-3 py-2 font-medium">Actual Finish</th>
                <th scope="col" className="px-5 py-2 text-right font-medium">Durasi</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-line">
              {rows.map((p) => (
                <tr key={p.id} className="cursor-pointer hover:bg-surface-2" onClick={() => onSelect(p)}>
                  <td className="px-5 py-2 text-ink">
                    <span className="tabular mr-1.5 text-ink-3">{String(p.sequence).padStart(2, '0')}</span>
                    {p.name}
                  </td>
                  <td className="px-3 py-2">
                    <ProcessStatusChip status={p.status} size="sm" />
                  </td>
                  <td className="tabular px-3 py-2 text-ink-2">{formatDate(p.plannedStart)}</td>
                  <td className="tabular px-3 py-2 text-ink-2">{formatDate(p.plannedFinish)}</td>
                  <td className="tabular px-3 py-2 text-ink-2">{formatDate(p.actualStart)}</td>
                  <td className="tabular px-3 py-2 text-ink-2">{formatDate(p.actualFinish)}</td>
                  <td className="tabular px-5 py-2 text-right text-ink">{p.duration !== null ? `${p.duration} hari` : '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
