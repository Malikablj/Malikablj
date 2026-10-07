<?php
declare(strict_types=1);

namespace App\Report;

use App\Core\Clock;
use App\Core\Db;
use App\Core\I18n;
use App\Core\NotFoundException;
use App\Core\User;
use App\Core\ValidationException;
use App\Timeline\TimelineService;

/**
 * Data export timeline (PRD §6.7): cakupan project (Level 1), satu part (Level 2), atau project + semua part.
 * Dipakai TimelinePdf & TimelineExcel agar isi keduanya identik.
 */
final class TimelineExport
{
    public const DOC_NO = 'PIK-FORM-NPD-07';
    public const SCOPES = ['project', 'part', 'all'];

    public function __construct(private TimelineService $timeline = new TimelineService())
    {
    }

    /**
     * @return array{project:array<string,mixed>,sections:list<array{title:string,level:int,data:array<string,mixed>}>,printed_at:string,printed_by:string,filename_base:string}
     */
    public function build(User $user, int $projectId, string $scope, ?int $partId = null): array
    {
        if (!in_array($scope, self::SCOPES, true)) {
            throw new ValidationException(['scope' => I18n::t('validation.invalid')]);
        }
        $sections = [];
        if ($scope === 'part') {
            $part = Db::fetch('SELECT id, project_id FROM project_parts WHERE id = ?', [(int) $partId]);
            if (!$part || (int) $part['project_id'] !== $projectId) {
                throw new NotFoundException(I18n::t('error.not_found'));
            }
            $data = $this->timeline->part((int) $part['id']);
            $sections[] = ['title' => $data['part']['name'], 'level' => 2, 'data' => $data];
            $project = $data['project'];
        } else {
            $data = $this->timeline->project($projectId);
            $project = $data['project'];
            $sections[] = ['title' => I18n::t('timeline.level1'), 'level' => 1, 'data' => $data];
            if ($scope === 'all') {
                foreach (Db::fetchAll('SELECT id FROM project_parts WHERE project_id = ? AND start_date IS NOT NULL ORDER BY sort_order, id', [$projectId]) as $pt) {
                    $pd = $this->timeline->part((int) $pt['id']);
                    $sections[] = ['title' => $pd['part']['name'], 'level' => 2, 'data' => $pd];
                }
            }
        }
        return [
            'project' => $project,
            'sections' => $sections,
            'printed_at' => Clock::nowString(),
            'printed_by' => $user->name,
            'filename_base' => 'Timeline_' . $project['code'] . '_' . Clock::todayString(),
        ];
    }

    /** Label status lintas domain. */
    public static function status(string $s): string
    {
        return I18n::has('status.' . $s) ? I18n::t('status.' . $s) : $s;
    }

    /** Teks dependency: "N4 FS, N2 SS+2". @param list<array<string,mixed>> $deps */
    public static function deps(array $deps): string
    {
        return implode(', ', array_map(static fn ($d) => $d['code'] . ' ' . $d['type'] . ($d['type'] !== 'PARALLEL' && (int) $d['lag'] !== 0 ? sprintf('%+d', (int) $d['lag']) : ''), $deps));
    }
}
