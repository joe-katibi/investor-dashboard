<?php

namespace App\Services\Tickets;

/**
 * HTML for the ticket e-mails: tables, KPI tiles and bar charts built from plain tables with
 * inline styles, so they show in every mail client (Outlook included) without scripts. A line chart
 * is the one picture (see line()).
 * Everything is returned on ONE line: the template renderer turns line breaks into <br>.
 */
final class TicketTables
{
    private const HEAD = 'background:#1f3a5f;color:#ffffff;padding:6px 8px;font-size:12px;border:1px solid #1f3a5f;';

    private const CELL = 'padding:5px 8px;font-size:12px;border:1px solid #d9dee5;vertical-align:top;';

    private const TONES = ['ok' => '#dcfce7', 'warn' => '#fef3c7', 'bad' => '#fee2e2', 'total' => '#eef2f7'];

    private const TEXT = ['ok' => '#166534', 'warn' => '#92400e', 'bad' => '#991b1b'];

    /**
     * @param  list<string>  $headers
     * @param  list<list<scalar|null>>  $rows
     * @param  array{empty?: string, total?: list<scalar|null>, left?: int, tones?: list<?string>, bold_first?: bool}  $options  left = how many
     *                                                                                                                           leading columns are text (left aligned); tones = background per column for non-zero cells
     */
    public static function table(array $headers, array $rows, array $options = []): string
    {
        if ($rows === []) {
            return '<p style="color:#6b7280;margin:4px 0;font-size:12px;">'.e($options['empty'] ?? 'Nothing to show.').'</p>';
        }

        $left = $options['left'] ?? 1;
        $tones = $options['tones'] ?? [];
        $html = '<table cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin:6px 0 14px 0;"><thead><tr>';

        foreach ($headers as $i => $heading) {
            $html .= '<th style="'.self::HEAD.'text-align:'.($i < $left ? 'left' : 'center').';">'.e($heading).'</th>';
        }

        $html .= '</tr></thead><tbody>';

        foreach ($rows as $n => $row) {
            $html .= '<tr>'.self::cells($row, $left, $tones, $n % 2 === 1 ? '#f8fafc' : '#ffffff', false, ! empty($options['bold_first'])).'</tr>';
        }

        if (! empty($options['total'])) {
            $html .= '<tr>'.self::cells($options['total'], $left, [], self::TONES['total'], true).'</tr>';
        }

        return $html.'</tbody></table>';
    }

    /**
     * A cross-tab from TicketMetrics::crosstab(): one row per group, one column per bracket, totals.
     *
     * @param  array{heading: string, columns: list<string>, rows: list<array{label: string, cells: list<int>, total: int}>, totals: list<int>, total: int}  $crosstab
     * @param  list<?string>  $tones  tone per data column: ok | warn | bad | null
     */
    public static function crosstab(array $crosstab, array $tones = [], int $limit = 30, string $empty = 'No pending tickets.'): string
    {
        $rows = array_map(fn (array $line) => [$line['label'], ...$line['cells'], $line['total']], array_slice($crosstab['rows'], 0, $limit));

        if (count($crosstab['rows']) > $limit) {
            $rest = array_slice($crosstab['rows'], $limit);
            $rows[] = ['Others ('.count($rest).')', ...array_map(fn (int $i) => array_sum(array_column(array_column($rest, 'cells'), $i)), array_keys($crosstab['columns'])), array_sum(array_column($rest, 'total'))];
        }

        return self::table([$crosstab['heading'], ...$crosstab['columns'], 'Total'], $rows, [
            'empty' => $empty,
            'total' => ['Total', ...$crosstab['totals'], $crosstab['total']],
            'tones' => [null, ...$tones],
        ]);
    }

    /**
     * A row of headline figures.
     *
     * @param  list<array{0: string, 1: scalar, 2?: ?string, 3?: ?string}>  $tiles  label, value, hint, tone (ok | warn | bad)
     */
    public static function tiles(array $tiles): string
    {
        $html = '<table cellspacing="0" cellpadding="0" style="border-collapse:separate;border-spacing:6px;margin:4px 0 12px -6px;"><tr>';

        foreach ($tiles as $tile) {
            [$label, $value] = $tile;
            $tone = $tile[3] ?? null;

            $html .= '<td style="background:'.(self::TONES[$tone] ?? '#f1f5f9').';border:1px solid #d9dee5;border-radius:6px;padding:8px 12px;min-width:92px;text-align:center;">'
                .'<div style="font-size:11px;color:#475569;">'.e($label).'</div>'
                .'<div style="font-size:20px;font-weight:bold;color:'.(self::TEXT[$tone] ?? '#0f172a').';">'.e((string) $value).'</div>'
                .(filled($tile[2] ?? null) ? '<div style="font-size:11px;color:#64748b;">'.e((string) $tile[2]).'</div>' : '')
                .'</td>';
        }

        return $html.'</tr></table>';
    }

    /**
     * Vertical bar chart: one group of bars per category.
     *
     * @param  list<string>  $categories
     * @param  array<string, array{color: string, values: list<int|float|null>}>  $series  name => colour + one value per category
     */
    public static function bars(array $categories, array $series, string $suffix = '', ?float $max = null, int $height = 120): string
    {
        $all = array_merge(...array_values(array_map(fn (array $s) => array_map(fn ($value) => (float) ($value ?? 0), $s['values']), $series)));

        if ($categories === [] || $all === [] || max($all) <= 0) {
            return '<p style="color:#6b7280;margin:4px 0;font-size:12px;">No data for the period.</p>';
        }

        $max ??= max($all);
        $html = '<table cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin:6px 0 4px 0;"><tr>';

        foreach ($categories as $i => $category) {
            $html .= '<td style="vertical-align:bottom;padding:0 5px;border-bottom:2px solid #94a3b8;"><table cellspacing="0" cellpadding="0" style="border-collapse:collapse;"><tr>';

            foreach ($series as $s) {
                $value = $s['values'][$i] ?? null;
                $pixels = $value === null ? 0 : max((float) $value > 0 ? 2 : 0, (int) round((float) $value / $max * $height));

                $html .= '<td style="vertical-align:bottom;padding:0 1px;text-align:center;">'
                    .'<div style="font-size:10px;color:#334155;">'.($value === null ? '&nbsp;' : e(self::number($value).$suffix)).'</div>'
                    .'<table cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin:0 auto;"><tr>'
                    .'<td height="'.$pixels.'" width="16" bgcolor="'.$s['color'].'" style="height:'.$pixels.'px;width:16px;background:'.$s['color'].';font-size:1px;line-height:1px;">&nbsp;</td>'
                    .'</tr></table></td>';
            }

            $html .= '</tr></table></td>';
        }

        $html .= '</tr><tr>';

        foreach ($categories as $category) {
            $html .= '<td style="text-align:center;font-size:10px;color:#475569;padding:3px 5px 0 5px;white-space:nowrap;">'.e($category).'</td>';
        }

        $html .= '</tr></table><div style="font-size:11px;color:#475569;margin:2px 0 14px 0;">';

        foreach ($series as $name => $s) {
            $html .= '<span style="display:inline-block;width:10px;height:10px;background:'.$s['color'].';">&nbsp;</span>&nbsp;'.e($name).' &nbsp;&nbsp;';
        }

        return $html.'</div>';
    }

    /**
     * A line chart: the line and its points drawn as a PNG (no text in it — no fonts needed), with each
     * day's value and date in a row of table cells under it, one per point. The picture goes as a data URI;
     * the e-mail view turns it into an inline (cid:) attachment, which Outlook and Gmail show. Without the GD
     * extension it falls back to bars.
     *
     * @param  list<string>  $categories
     * @param  array<string, array{color: string, values: list<int|float|null>}>  $series
     */
    public static function line(array $categories, array $series, string $suffix = '', ?float $max = null, int $height = 130): string
    {
        $all = array_filter(array_merge(...array_values(array_map(fn (array $s) => $s['values'], $series))), fn ($value) => $value !== null);

        if ($categories === [] || $all === [] || ! function_exists('imagecreatetruecolor')) {
            return self::bars($categories, $series, $suffix, $max, $height);
        }

        $max = max($max ?? max($all), 1.0);
        $count = count($categories);
        [$column, $scale, $pad] = [62, 2, 10];
        [$width, $plot] = [$count * $column, $height - 2 * $pad];

        $image = imagecreatetruecolor($width * $scale, $height * $scale);
        imagesavealpha($image, true);
        imagealphablending($image, false);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 255, 255, 255, 127));
        imagealphablending($image, true);
        // Drawn at twice the size and shown at half: smooth without GD's anti-aliasing (which ignores line thickness).
        imageantialias($image, false);

        $grid = imagecolorallocatealpha($image, 148, 163, 184, 70);
        foreach ([0, 0.5, 1] as $step) {
            $y = (int) round(($pad + $plot * (1 - $step)) * $scale);
            imagefilledrectangle($image, 0, $y, $width * $scale, $y + ($step === 0 ? 3 : 1), $grid);
        }

        foreach ($series as $s) {
            [$r, $g, $b] = sscanf($s['color'], '#%02x%02x%02x');
            $points = [];

            foreach ($categories as $i => $category) {
                $value = $s['values'][$i] ?? null;
                if ($value !== null) {
                    $points[] = [(int) round(($i + 0.5) * $column * $scale), (int) round(($pad + $plot * (1 - min((float) $value, $max) / $max)) * $scale)];
                }
            }

            // A light area under the line, the line, then the points. Days without a value are stepped over.
            if (count($points) > 1) {
                $area = [$points[0][0], ($pad + $plot) * $scale];
                foreach ($points as [$x, $y]) {
                    array_push($area, $x, $y);
                }
                array_push($area, end($points)[0], ($pad + $plot) * $scale);
                imagefilledpolygon($image, $area, imagecolorallocatealpha($image, $r, $g, $b, 108));

                $stroke = imagecolorallocate($image, $r, $g, $b);
                imagesetthickness($image, 3 * $scale);
                for ($k = 1; $k < count($points); $k++) {
                    imageline($image, $points[$k - 1][0], $points[$k - 1][1], $points[$k][0], $points[$k][1], $stroke);
                }
                imagesetthickness($image, 1);
            }

            foreach ($points as [$x, $y]) {
                imagefilledellipse($image, $x, $y, 9 * $scale, 9 * $scale, imagecolorallocate($image, 255, 255, 255));
                imagefilledellipse($image, $x, $y, 6 * $scale, 6 * $scale, imagecolorallocate($image, $r, $g, $b));
            }
        }

        ob_start();
        imagepng($image);
        $png = base64_encode((string) ob_get_clean());
        imagedestroy($image);

        $cell = 'width:'.$column.'px;text-align:center;padding:2px 0 0 0;white-space:nowrap;';
        $first = array_key_first($series);
        $html = '<table cellspacing="0" cellpadding="0" style="border-collapse:collapse;table-layout:fixed;width:'.$width.'px;margin:6px 0 4px 0;">'
            .'<tr><td colspan="'.$count.'" style="padding:0;line-height:0;"><img src="data:image/png;base64,'.$png.'" width="'.$width.'" height="'.$height.'" alt="'.e((string) $first).'" style="display:block;width:'.$width.'px;height:'.$height.'px;border:0;"></td></tr>';

        foreach ($series as $s) {
            $html .= '<tr>';
            foreach ($categories as $i => $category) {
                $value = $s['values'][$i] ?? null;
                $html .= '<td style="'.$cell.'font-size:11px;font-weight:bold;color:'.($value === null ? '#b6bfcb' : $s['color']).';">'.($value === null ? '–' : e(self::number($value).$suffix)).'</td>';
            }
            $html .= '</tr>';
        }

        $html .= '<tr>';
        foreach ($categories as $category) {
            $html .= '<td style="'.$cell.'font-size:10px;color:#475569;">'.e($category).'</td>';
        }
        $html .= '</tr></table><div style="font-size:11px;color:#475569;margin:2px 0 14px 0;">';

        foreach ($series as $name => $s) {
            $html .= '<span style="display:inline-block;width:14px;height:3px;background:'.$s['color'].';vertical-align:middle;">&nbsp;</span>&nbsp;'.e($name).' &nbsp;&nbsp;';
        }

        return $html.'</div>';
    }

    /** @param  list<?string>  $tones */
    private static function cells(array $row, int $left, array $tones, string $background, bool $bold, bool $boldFirst = false): string
    {
        $html = '';

        foreach (array_values($row) as $i => $value) {
            $tone = $tones[$i] ?? null;
            $zero = $value === 0 || $value === '0';
            $style = self::CELL
                .'text-align:'.($i < $left ? 'left' : 'center').';'
                .'background:'.($tone && ! $zero && $i >= $left ? self::TONES[$tone] : $background).';'
                .($zero && ! $bold ? 'color:#b6bfcb;' : '')
                .($bold || ($boldFirst && $i === 0) ? 'font-weight:bold;' : '');

            $html .= '<td style="'.$style.'">'.e(self::number($value)).'</td>';
        }

        return $html;
    }

    private static function number(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_float($value) => rtrim(rtrim(number_format($value, 1), '0'), '.'),
            default => (string) $value,
        };
    }
}
