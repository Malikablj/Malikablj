<?php
declare(strict_types=1);

namespace App\Scheduling;

use App\Core\BusinessRuleException;
use App\Core\I18n;

/** Dependency melingkar (A → B → A) terdeteksi (FR-WF-04). */
final class CycleException extends BusinessRuleException
{
    /** @param list<int> $processIds proses yang terlibat dalam siklus */
    public function __construct(private array $processIds)
    {
        parent::__construct(I18n::t('dep.cycle'));
    }

    /** @return list<int> */
    public function processIds(): array
    {
        return $this->processIds;
    }
}
