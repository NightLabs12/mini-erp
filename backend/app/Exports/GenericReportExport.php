<?php

declare(strict_types=1);

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Ekspor Excel generik untuk seluruh laporan.
 *
 * Baris sudah dinormalisasi oleh ReportExporter, sehingga kelas ini hanya
 * menulis judul, heading, dan data apa adanya.
 */
final class GenericReportExport implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    /**
     * @param  list<string>  $headings
     * @param  list<list<string|int|float|null>>  $rows
     */
    public function __construct(
        private readonly string $reportTitle,
        private readonly array $headings,
        private readonly array $rows,
    ) {}

    /**
     * @return list<list<string|int|float|null>>
     */
    public function array(): array
    {
        return $this->rows;
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return $this->headings;
    }

    public function title(): string
    {
        return mb_substr($this->reportTitle, 0, 31);
    }
}
