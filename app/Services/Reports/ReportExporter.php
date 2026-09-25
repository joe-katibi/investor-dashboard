<?php

namespace App\Services\Reports;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV / Excel (XLSX) / PDF export (spec §20). CSV and XLSX stream rows so very large
 * periods export with flat memory usage; PDF is capped because it renders in memory.
 */
class ReportExporter
{
    public const PDF_ROW_LIMIT = 2000;

    public function download(ReportResult $report, string $format, array $filters = []): Response
    {
        return match ($format) {
            'csv' => $this->csv($report),
            'xlsx' => $this->xlsx($report),
            'pdf' => $this->pdf($report, $filters),
            default => abort(400, 'Unsupported export format.'),
        };
    }

    public function filename(ReportResult $report, string $extension): string
    {
        return Str::slug($report->title).'-'.now()->format('Ymd-His').'.'.$extension;
    }

    public function csv(ReportResult $report): StreamedResponse
    {
        return response()->streamDownload(function () use ($report): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel opens accents correctly
            fputcsv($out, array_values($report->columns), escape: '\\');

            foreach ($report->rows() as $row) {
                fputcsv($out, array_map(fn ($v) => $this->safeCell($v), array_values($row)), escape: '\\');
            }

            fclose($out);
        }, $this->filename($report, 'csv'), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function xlsx(ReportResult $report): BinaryFileResponse
    {
        $path = $this->writeXlsx($report);

        return response()->download($path, $this->filename($report, 'xlsx'), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    /** Write the report to a temporary XLSX file (also used for email attachments). */
    public function writeXlsx(ReportResult $report): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'oms-report-'.Str::uuid().'.xlsx';

        $writer = new XlsxWriter;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName(Str::limit(preg_replace('/[\\\\\/\?\*\[\]:]/', '', $report->title), 28, ''));

        $header = (new Style)->setFontBold()->setFontColor('FFFFFF')->setBackgroundColor('B45309');
        $writer->addRow(Row::fromValues(array_values($report->columns), $header));

        foreach ($report->rows() as $row) {
            $writer->addRow(Row::fromValues(array_map(fn ($v) => is_int($v) || is_float($v) ? $v : $this->safeCell($v), array_values($row))));
        }

        if ($report->summary !== []) {
            $writer->addNewSheetAndMakeItCurrent()->setName('Summary');
            $writer->addRow(Row::fromValues(['Measure', 'Value'], $header));
            foreach ($report->summary as $label => $value) {
                $writer->addRow(Row::fromValues([$label, $value]));
            }
        }

        $writer->close();

        return $path;
    }

    public function pdf(ReportResult $report, array $filters): Response
    {
        $rows = $report->rowsArray(self::PDF_ROW_LIMIT);

        return Pdf::loadView('reports.pdf', [
            'report' => $report,
            'rows' => $rows,
            'filters' => array_filter($filters),
            'truncated' => count($rows) >= self::PDF_ROW_LIMIT,
        ])->setPaper('a4', 'landscape')->download($this->filename($report, 'pdf'));
    }

    /** Neutralise spreadsheet formula injection (=, +, -, @ prefixes) in exported text. */
    private function safeCell(mixed $value): string
    {
        $value = (string) ($value ?? '');

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($value)
            ? "'".$value
            : $value;
    }
}
