import { ArrowUp, RotateCcw, ShieldCheck, Sparkles } from 'lucide-react';
import { type FormEvent, useEffect, useRef, useState } from 'react';
import { type AssistantAnswer, SUGGESTED_QUESTIONS } from '@/assistant/engine';
import { AnswerView } from '@/components/assistant/AnswerView';
import { PageHeader } from '@/components/layout/PageHeader';
import { Button, IconButton } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { ErrorState } from '@/components/ui/Feedback';
import { Avatar } from '@/components/ui/Misc';
import { projectScopeLabel } from '@/domain/permissions';
import { useUser } from '@/hooks/useAuth';
import { cn, uid } from '@/lib/utils';
import { assistant } from '@/services/api/reports';

interface Message {
  id: string;
  role: 'user' | 'assistant';
  text?: string;
  answer?: AssistantAnswer;
  error?: unknown;
  question?: string;
}

const storeKey = (userId: string) => `npd-assistant:${userId}`;

export default function AssistantPage() {
  const user = useUser();
  const [messages, setMessages] = useState<Message[]>(() => {
    try {
      return JSON.parse(sessionStorage.getItem(storeKey(user.id)) ?? '[]') as Message[];
    } catch {
      return [];
    }
  });
  const [input, setInput] = useState('');
  const [pending, setPending] = useState(false);
  const endRef = useRef<HTMLDivElement>(null);
  const inputRef = useRef<HTMLTextAreaElement>(null);

  useEffect(() => {
    try {
      sessionStorage.setItem(storeKey(user.id), JSON.stringify(messages.filter((m) => !m.error).slice(-40)));
    } catch {
      /* ignore */
    }
    endRef.current?.scrollIntoView({ behavior: 'smooth', block: 'end' });
  }, [messages, user.id]);

  const ask = async (q: string) => {
    const question = q.trim();
    if (!question || pending) return;
    setInput('');
    setMessages((m) => [...m, { id: uid('m'), role: 'user', text: question }]);
    setPending(true);
    try {
      const answer = await assistant.ask(question);
      setMessages((m) => [...m, { id: uid('m'), role: 'assistant', answer }]);
    } catch (error) {
      setMessages((m) => [...m, { id: uid('m'), role: 'assistant', error, question }]);
    } finally {
      setPending(false);
      inputRef.current?.focus();
    }
  };

  const submit = (e: FormEvent) => {
    e.preventDefault();
    ask(input);
  };

  return (
    <div className="mx-auto flex max-w-3xl flex-col">
      <PageHeader
        title="AI Assistant"
        description="Tanya apa saja tentang project NPD. Jawaban disusun dari database project — bukan chatbot umum."
        actions={
          messages.length > 0 && (
            <Button variant="ghost" icon={<RotateCcw className="size-4" />} onClick={() => setMessages([])}>
              Percakapan baru
            </Button>
          )
        }
      />
      <div className="mb-4 flex items-start gap-2 rounded-2xl bg-surface-2 px-4 py-3 text-[13px] text-ink-2">
        <ShieldCheck className="mt-0.5 size-4 shrink-0 text-tone-green" aria-hidden="true" />
        <p>
          Scope data: <span className="font-medium text-ink">{projectScopeLabel(user)}</span>. Assistant tidak mengarang data dan tidak mengubah data project. Jika data tidak ada, jawabannya: “Data tersebut belum tersedia di sistem.”
        </p>
      </div>

      <div className="space-y-5 pb-4" aria-live="polite">
        {messages.length === 0 && (
          <Card className="p-5 md:p-6">
            <div className="flex items-center gap-2">
              <span className="flex size-9 items-center justify-center rounded-xl bg-accent-soft text-accent-ink">
                <Sparkles className="size-5" />
              </span>
              <p className="text-[15px] font-semibold text-ink">Contoh pertanyaan</p>
            </div>
            <div className="mt-4 flex flex-wrap gap-2">
              {SUGGESTED_QUESTIONS.map((s) => (
                <button key={s} type="button" onClick={() => ask(s)} className="rounded-full border border-line-strong bg-surface px-3 py-2 text-left text-[13px] text-ink transition-colors hover:border-accent hover:bg-accent-soft">
                  {s}
                </button>
              ))}
            </div>
          </Card>
        )}
        {messages.map((m) =>
          m.role === 'user' ? (
            <div key={m.id} className="flex justify-end gap-2">
              <p className="max-w-[85%] rounded-2xl rounded-br-md bg-accent px-4 py-2.5 text-[14px] text-white">{m.text}</p>
              <Avatar name={user.name} className="max-sm:hidden" />
            </div>
          ) : (
            <div key={m.id} className="flex gap-2.5">
              <span className="flex size-8 shrink-0 items-center justify-center rounded-full bg-ink text-surface" aria-hidden="true">
                <Sparkles className="size-4" />
              </span>
              <Card className="min-w-0 flex-1 rounded-tl-md p-4">
                {m.answer ? (
                  <AnswerView answer={m.answer} />
                ) : (
                  <ErrorState compact error={m.error} onRetry={m.question ? () => ask(m.question!) : undefined} />
                )}
              </Card>
            </div>
          ),
        )}
        {pending && (
          <div className="flex gap-2.5" role="status">
            <span className="flex size-8 shrink-0 items-center justify-center rounded-full bg-ink text-surface" aria-hidden="true">
              <Sparkles className="size-4" />
            </span>
            <Card className="flex items-center gap-1.5 rounded-tl-md px-4 py-3">
              <span className="sr-only">Mencari di data project…</span>
              {[0, 1, 2].map((d) => (
                <span key={d} className="size-2 animate-pulse rounded-full bg-ink-3" style={{ animationDelay: `${d * 150}ms` }} />
              ))}
            </Card>
          </div>
        )}
        <div ref={endRef} />
      </div>

      <form onSubmit={submit} className="sticky bottom-0 -mx-4 border-t border-line bg-canvas/90 px-4 pt-3 pb-[max(12px,env(safe-area-inset-bottom))] backdrop-blur md:bottom-4 md:mx-0 md:rounded-2xl md:border md:bg-surface md:p-2 md:shadow-pop">
        {messages.length > 0 && (
          <div className="mb-2 flex gap-1.5 overflow-x-auto no-scrollbar md:px-1">
            {SUGGESTED_QUESTIONS.slice(0, 6).map((s) => (
              <button key={s} type="button" onClick={() => ask(s)} disabled={pending} className="shrink-0 rounded-full bg-surface-2 px-3 py-1.5 text-[12px] text-ink-2 hover:text-ink disabled:opacity-50">
                {s}
              </button>
            ))}
          </div>
        )}
        <div className="flex items-end gap-2">
          <label htmlFor="ask" className="sr-only">
            Pertanyaan
          </label>
          <textarea
            id="ask"
            ref={inputRef}
            rows={1}
            value={input}
            onChange={(e) => setInput(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                ask(input);
              }
            }}
            placeholder="mis. Project Kymm sekarang sampai mana?"
            maxLength={500}
            className="max-h-32 min-h-11 flex-1 resize-none rounded-xl border border-line-strong bg-surface px-3.5 py-2.5 text-[15px] text-ink outline-none placeholder:text-ink-3 focus:border-accent focus:ring-4 focus:ring-[var(--c-focus)] md:border-transparent md:focus:ring-0"
          />
          <IconButton type="submit" label="Kirim pertanyaan" disabled={!input.trim() || pending} className={cn('bg-accent text-white hover:bg-accent-hover hover:text-white disabled:bg-line-strong')}>
            <ArrowUp className="size-5" />
          </IconButton>
        </div>
      </form>
    </div>
  );
}
