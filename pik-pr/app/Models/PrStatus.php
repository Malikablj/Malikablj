<?php

declare(strict_types=1);

namespace App\Models;

enum PrStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case InReview = 'in_review';
    case RevisionRequired = 'revision_required';
    case Rejected = 'rejected';
    case Approved = 'approved';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted',
            self::InReview => 'In Review',
            self::RevisionRequired => 'Revision Required',
            self::Rejected => 'Rejected',
            self::Approved => 'Approved',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Draft => 'Belum diajukan',
            self::Submitted => 'Menunggu approval tahap pertama',
            self::InReview => 'Sedang dalam proses approval',
            self::RevisionRequired => 'Perlu diperbaiki oleh pemohon',
            self::Rejected => 'Ditolak',
            self::Approved => 'Disetujui, PDF tersedia',
            self::Completed => 'Selesai dan diarsipkan',
            self::Cancelled => 'Dibatalkan',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        $labels = [];
        foreach (self::cases() as $case) {
            $labels[$case->value] = $case->label();
        }

        return $labels;
    }

    /**
     * Status yang sedang menunggu keputusan approver.
     *
     * @return list<string>
     */
    public static function pending(): array
    {
        return [self::Submitted->value, self::InReview->value];
    }

    /**
     * Status saat pemohon boleh mengubah isi PR.
     *
     * @return list<string>
     */
    public static function editable(): array
    {
        return [self::Draft->value, self::RevisionRequired->value];
    }
}
