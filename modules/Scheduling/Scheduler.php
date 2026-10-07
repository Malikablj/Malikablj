<?php
declare(strict_types=1);

namespace App\Scheduling;

/**
 * Mesin penjadwalan (PRD §6.1–6.4) — FUNGSI MURNI tanpa database, satu-satunya tempat
 * jadwal dihitung. Dipakai identik untuk pratinjau dan penyimpanan.
 *
 * Input node (per proses):
 *   id, part_id (null = level project), status (not_started|current|revision|problem|completed|skipped),
 *   activation (auto|loop_only), duration, manual_start, manual_finish, planned_start, planned_finish,
 *   actual_start, actual_finish, loop_after_id (null|int), excluded (bool, mis. part dibatalkan / belum diterima)
 * Input dependency: process_id, predecessor_id, type (FS|SS|FF|PARALLEL), lag (hari kerja, boleh negatif)
 *
 * Dua lintasan:
 *  - planned : predecessor yang sedang berjalan memakai Planned Finish → jadwal TIDAK bergeser selama
 *              proses masih berjalan (PRD §6.4); bergeser saat selesai (memakai tanggal aktual).
 *  - forecast: predecessor berjalan memakai max(Planned Finish, hari ini) → perkiraan selesai "langsung".
 */
final class Scheduler
{
    public const ACTIVE = ['current', 'revision', 'problem'];

    /** @var array<int,array<string,mixed>> */
    private array $nodes = [];
    /** @var array<int,list<array{pred:int,type:string,lag:int}>> */
    private array $preds = [];
    /** @var array<int,list<int>> */
    private array $succs = [];

    /**
     * @param array<int,array<string,mixed>> $nodes
     * @param list<array{process_id:int,predecessor_id:int,type:string,lag:int}> $deps
     * @param array<int,string|null> $partStart part_id => tanggal mulai part (null = belum mulai)
     */
    public function __construct(
        private WorkingCalendar $cal,
        array $nodes,
        array $deps,
        private array $partStart,
        private string $projectStart,
        private string $today,
        private bool $pullForward = true,
        private string $mode = 'plan',
    ) {
        foreach ($nodes as $id => $n) {
            $this->nodes[(int) $id] = $n + ['id' => (int) $id];
            $this->preds[(int) $id] = [];
            $this->succs[(int) $id] = [];
        }
        foreach ($deps as $d) {
            $p = (int) $d['process_id'];
            $q = (int) $d['predecessor_id'];
            if (!isset($this->nodes[$p], $this->nodes[$q])) {
                continue;
            }
            if (($this->nodes[$p]['activation'] ?? 'auto') === 'loop_only') {
                continue; // pemicu loop (mis. Mold Correction "loop dari T0"), bukan syarat jadwal: dijadwalkan sejak dipicu
            }
            $this->preds[$p][] = ['pred' => $q, 'type' => strtoupper((string) $d['type']), 'lag' => (int) $d['lag']];
            $this->succs[$q][] = $p;
        }
        // "tunggu proses loop" (mis. Mold Machining menunggu Mold Correction) = FS lag 0
        foreach ($this->nodes as $id => $n) {
            $wait = $n['loop_after_id'] ?? null;
            if ($wait !== null && isset($this->nodes[(int) $wait])) {
                $this->preds[$id][] = ['pred' => (int) $wait, 'type' => 'FS', 'lag' => 0];
                $this->succs[(int) $wait][] = $id;
            }
        }
    }

    /**
     * Urutan topologis (Kahn). @return list<int>
     * @throws CycleException
     */
    public function topologicalOrder(): array
    {
        $in = [];
        foreach ($this->nodes as $id => $_) {
            $in[$id] = 0;
        }
        foreach ($this->preds as $id => $list) {
            $in[$id] = count($list);
        }
        $queue = [];
        foreach ($in as $id => $deg) {
            if ($deg === 0) {
                $queue[] = $id;
            }
        }
        sort($queue);
        $order = [];
        while ($queue) {
            $id = array_shift($queue);
            $order[] = $id;
            foreach ($this->succs[$id] as $s) {
                if (--$in[$s] === 0) {
                    $queue[] = $s;
                }
            }
        }
        if (count($order) !== count($this->nodes)) {
            $cyclic = array_keys(array_filter($in, static fn ($d) => $d > 0));
            throw new CycleException($cyclic);
        }
        return $order;
    }

    /**
     * Hitung jadwal.
     * @return array{processes:array<int,array<string,mixed>>,parts:array<int,string|null>,project:string|null}
     */
    public function run(): array
    {
        $order = $this->topologicalOrder();
        $planned = $this->pass($order, false);
        $forecast = $this->pass($order, true);
        $out = [];
        foreach ($order as $id) {
            $n = $this->nodes[$id];
            $p = $planned[$id];
            $f = $forecast[$id];
            $hidden = $p['hidden'];
            $out[$id] = [
                'planned_start' => $hidden ? null : $p['start'],
                'planned_finish' => $hidden ? null : $p['finish'],
                'forecast_start' => $hidden ? null : $f['start'],
                'forecast_finish' => $hidden ? null : $f['finish'],
                'duration' => $p['duration'],
                'warning' => $p['warning'],
                'blocking_id' => $p['blocking'],
                'excluded' => !empty($n['excluded']),
            ];
        }
        $parts = [];
        $project = null;
        foreach ($out as $id => $r) {
            if ($r['excluded'] || $r['forecast_finish'] === null) {
                continue;
            }
            $partId = $this->nodes[$id]['part_id'] ?? null;
            if ($partId !== null) {
                $parts[(int) $partId] = max($parts[(int) $partId] ?? '', $r['forecast_finish']);
            }
            $project = $project === null ? $r['forecast_finish'] : max($project, $r['forecast_finish']);
        }
        return ['processes' => $out, 'parts' => $parts, 'project' => $project];
    }

    /**
     * @param list<int> $order
     * @return array<int,array{start:?string,finish:?string,duration:int,hidden:bool,warning:?string,blocking:?int}>
     */
    private function pass(array $order, bool $forecast): array
    {
        $r = [];
        $todayWd = $this->cal->nextWorkingDay($this->today);
        foreach ($order as $id) {
            $n = $this->nodes[$id];
            $duration = max(1, (int) ($n['duration'] ?? 1));
            $status = (string) $n['status'];
            $dormant = ($n['activation'] ?? 'auto') === 'loop_only' && !in_array($status, array_merge(self::ACTIVE, ['completed']), true);

            if (!empty($n['excluded']) || $this->floorFor($n) === null) {
                $r[$id] = ['start' => null, 'finish' => null, 'duration' => $duration, 'hidden' => true, 'warning' => null, 'blocking' => null];
                continue;
            }
            if ($status === 'completed') {
                $s = $n['actual_start'] ?? $n['planned_start'] ?? $n['actual_finish'];
                $f = $n['actual_finish'] ?? $n['planned_finish'] ?? $s;
                $r[$id] = ['start' => $s, 'finish' => $f, 'duration' => $duration, 'hidden' => false, 'warning' => null, 'blocking' => null];
                continue;
            }
            [$allowed, $ffLimit, $blocking] = $this->constraints($id, $r);
            if ($status === 'skipped' || $dormant) {
                // durasi nol: meneruskan tanggal predecessor (finish = hari kerja sebelum allowed)
                $r[$id] = ['start' => $allowed, 'finish' => $this->cal->addWorkingDays($allowed, -1), 'duration' => 0, 'hidden' => true, 'warning' => null, 'blocking' => null];
                continue;
            }
            if (in_array($status, self::ACTIVE, true)) {
                $s = $n['actual_start'] ?? $n['planned_start'] ?? $allowed;
                $pf = $n['planned_finish'] ?? $this->cal->finishFromStart($this->cal->nextWorkingDay((string) $s), $duration);
                $f = $forecast ? max($pf, $todayWd) : $pf;
                $r[$id] = ['start' => $s, 'finish' => $f, 'duration' => $duration, 'hidden' => false, 'warning' => null, 'blocking' => null];
                continue;
            }

            // ---- belum dimulai ----
            $warning = null;
            $ms = $n['manual_start'] ?? null;
            $mf = $n['manual_finish'] ?? null;
            $dur = $duration;
            if ($ms !== null && $mf !== null) {
                $dur = max(1, $this->cal->countWorkingDays($ms, $mf));
                $want = $this->cal->nextWorkingDay($ms);
            } elseif ($ms !== null) {
                $want = $this->cal->nextWorkingDay($ms);
            } elseif ($mf !== null) {
                $want = $this->cal->startFromFinish($this->cal->prevWorkingDay($mf), $dur);
            } else {
                $want = null;
            }
            if ($want !== null && $want < $allowed) {
                $warning = 'manual_before_dependency';
            }
            $start = $want !== null ? max($want, $allowed) : $allowed;
            if ($forecast) {
                $start = max($start, $todayWd);
            } elseif ($this->mode === 'event' && !$this->pullForward && !empty($n['planned_start']) && $start < $n['planned_start']) {
                // "Tarik maju jadwal" nonaktif: proses tidak maju karena predecessor selesai lebih awal
                $start = $this->cal->nextWorkingDay((string) $n['planned_start']);
            }
            $finish = $this->cal->finishFromStart($start, $dur);
            if ($ffLimit !== null && $finish < $ffLimit) {
                $finish = $this->cal->nextWorkingDay($ffLimit); // FF memperpanjang durasi
            }
            $effDur = $this->cal->countWorkingDays($start, $finish);
            $r[$id] = ['start' => $start, 'finish' => $finish, 'duration' => max(1, $effDur), 'hidden' => false, 'warning' => $warning, 'blocking' => $warning ? $blocking : null];
        }
        return $r;
    }

    /** Tanggal paling awal dari part/project (null = part belum mulai). @param array<string,mixed> $n */
    private function floorFor(array $n): ?string
    {
        if (($n['part_id'] ?? null) === null) {
            return $this->projectStart;
        }
        return $this->partStart[(int) $n['part_id']] ?? null;
    }

    /**
     * Batas dependency untuk node: [allowed start (hari kerja), batas FF, predecessor penghalang].
     * @param array<int,array<string,mixed>> $r
     * @return array{0:string,1:?string,2:?int}
     */
    private function constraints(int $id, array $r): array
    {
        $floor = (string) $this->floorFor($this->nodes[$id]);
        $allowed = $this->cal->nextWorkingDay($floor);
        $blocking = null;
        $ff = null;
        foreach ($this->preds[$id] as $d) {
            $p = $r[$d['pred']] ?? null;
            if ($p === null || $p['start'] === null) {
                continue; // predecessor dikecualikan (part batal / belum mulai)
            }
            $cand = null;
            switch ($d['type']) {
                case 'FS':
                    $cand = $this->cal->addWorkingDays((string) $p['finish'], 1 + $d['lag']);
                    $cand = max($cand, (string) $p['start']); // lag negatif tidak sebelum predecessor mulai
                    break;
                case 'SS':
                    $cand = max($this->cal->addWorkingDays((string) $p['start'], $d['lag']), (string) $p['start']);
                    break;
                case 'FF':
                    $lim = $this->cal->addWorkingDays((string) $p['finish'], $d['lag']);
                    $ff = $ff === null ? $lim : max($ff, $lim);
                    break;
                case 'PARALLEL':
                default:
                    break;
            }
            if ($cand !== null) {
                $cand = $this->cal->nextWorkingDay($cand);
                if ($cand > $allowed) {
                    $allowed = $cand;
                    $blocking = $d['pred'];
                }
            }
        }
        return [$allowed, $ff, $blocking];
    }

    /**
     * Seluruh turunan (langsung & tidak langsung) sebuah proses.
     * @return list<int>
     */
    public function descendants(int $id): array
    {
        $seen = [];
        $stack = [$id];
        while ($stack) {
            $x = array_pop($stack);
            foreach ($this->succs[$x] ?? [] as $s) {
                if (!isset($seen[$s])) {
                    $seen[$s] = true;
                    $stack[] = $s;
                }
            }
        }
        return array_keys($seen);
    }

    /**
     * Jalur kritis (Could, FR-SCH-09): proses dengan forecast finish yang menentukan selesai project,
     * ditelusuri mundur lewat predecessor penentu.
     * @param array<int,array<string,mixed>> $result hasil run()['processes']
     * @return list<int>
     */
    public function criticalPath(array $result): array
    {
        $end = null;
        $endId = null;
        foreach ($result as $id => $r) {
            if ($r['forecast_finish'] !== null && ($end === null || $r['forecast_finish'] > $end)) {
                $end = $r['forecast_finish'];
                $endId = $id;
            }
        }
        $path = [];
        $guard = 0;
        while ($endId !== null && $guard++ < 1000) {
            $path[] = $endId;
            $next = null;
            $best = null;
            foreach ($this->preds[$endId] as $d) {
                $p = $result[$d['pred']] ?? null;
                if ($p && $p['forecast_finish'] !== null && ($best === null || $p['forecast_finish'] > $best)) {
                    $best = $p['forecast_finish'];
                    $next = $d['pred'];
                }
            }
            $endId = $next;
        }
        return array_reverse($path);
    }
}
