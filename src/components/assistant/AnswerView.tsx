import { Check, ChevronRight, Copy } from 'lucide-react';
import { useState } from 'react';
import { Link } from 'react-router-dom';
import type { AssistantAnswer } from '@/assistant/engine';
import { Badge } from '../ui/Badge';

/** Renders structured assistant blocks (text, project refs, summary, lists, stats, report). */
export function AnswerView({ answer, onNavigate }: { answer: AssistantAnswer; onNavigate?: () => void }) {
  return (
    <div className="space-y-3">
      {answer.blocks.map((b, i) => {
        switch (b.type) {
          case 'text':
            return (
              <p key={i} className="text-[14px] leading-relaxed text-ink">
                {b.text}
              </p>
            );
          case 'list':
            return (
              <ul key={i} className="space-y-1.5 text-[14px] text-ink">
                {b.items.map((it) => (
                  <li key={it} className="flex gap-2">
                    <span className="mt-2 size-1 shrink-0 rounded-full bg-ink-3" aria-hidden="true" />
                    <span>{it}</span>
                  </li>
                ))}
              </ul>
            );
          case 'stats':
            return (
              <dl key={i} className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                {b.items.map((s) => (
                  <div key={s.label} className="rounded-xl bg-surface-2 px-3 py-2">
                    <dt className="text-[11px] text-ink-3">{s.label}</dt>
                    <dd className="tabular text-[18px] font-semibold text-ink">{s.value}</dd>
                  </div>
                ))}
              </dl>
            );
          case 'projects':
            return (
              <ul key={i} className="divide-y divide-line overflow-hidden rounded-xl border border-line">
                {b.items.map((p) => (
                  <li key={p.code + p.meta}>
                    <Link to={`/projects/${p.code}`} onClick={onNavigate} className="flex items-center gap-3 px-3 py-2.5 hover:bg-surface-2">
                      <span className="min-w-0 flex-1">
                        <span className="block truncate text-[14px] font-medium text-ink">{p.name}</span>
                        <span className="block truncate text-[12px] text-ink-3">
                          {p.code} · {p.customer}
                        </span>
                        <span className="mt-0.5 block text-[12px] text-ink-2">{p.meta}</span>
                      </span>
                      {p.badge && (
                        <Badge tone={p.badge.tone} size="sm" dot>
                          {p.badge.label}
                        </Badge>
                      )}
                      <ChevronRight className="size-4 shrink-0 text-ink-3" aria-hidden="true" />
                    </Link>
                  </li>
                ))}
              </ul>
            );
          case 'summary':
            return (
              <div key={i} className="overflow-hidden rounded-xl border border-line">
                <div className="flex items-center justify-between bg-surface-2 px-4 py-2">
                  <p className="text-[12px] font-semibold tracking-wide text-ink-2">{b.title}</p>
                  {b.code && (
                    <Link to={`/projects/${b.code}`} onClick={onNavigate} className="text-[12px] font-medium text-accent-ink hover:underline">
                      Buka project
                    </Link>
                  )}
                </div>
                <dl className="divide-y divide-line">
                  {b.rows.map(([k, v]) => (
                    <div key={k} className="grid grid-cols-[130px_1fr] gap-3 px-4 py-2 text-[13px]">
                      <dt className="text-ink-3">{k}</dt>
                      <dd className="text-ink">{v}</dd>
                    </div>
                  ))}
                </dl>
              </div>
            );
          case 'report':
            return <ReportBlock key={i} text={b.text} />;
        }
      })}
      <p className="text-[11px] text-ink-3">{answer.basis}</p>
    </div>
  );
}

function ReportBlock({ text }: { text: string }) {
  const [copied, setCopied] = useState(false);
  return (
    <div className="relative rounded-xl border border-line bg-surface-2">
      <button
        type="button"
        onClick={() => {
          navigator.clipboard?.writeText(text).then(
            () => {
              setCopied(true);
              setTimeout(() => setCopied(false), 1500);
            },
            () => undefined,
          );
        }}
        className="absolute top-2 right-2 inline-flex h-8 items-center gap-1 rounded-lg bg-surface px-2 text-[12px] font-medium text-ink-2 shadow-card hover:text-ink"
      >
        {copied ? <Check className="size-3.5" /> : <Copy className="size-3.5" />} {copied ? 'Disalin' : 'Salin'}
      </button>
      <pre className="max-h-[360px] overflow-auto p-4 pr-20 font-mono text-[12px] leading-relaxed whitespace-pre-wrap text-ink">{text}</pre>
    </div>
  );
}
