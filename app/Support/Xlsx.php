<?php

namespace App\Support;

use RuntimeException;
use ZipArchive;

/**
 * A minimal XLSX writer (one sheet, inline strings, a few styles) so reports open in Excel as
 * real spreadsheets: numbers stay numbers, Arabic sheets read right to left, totals are bold.
 *
 * A row is a list of cells; a cell is a scalar or ['v' => value, 's' => style].
 * Styles: 'text', 'bold', 'money', 'money_bold', 'pct', 'title', 'muted'.
 */
class Xlsx
{
    private const STYLES = ['text' => 0, 'bold' => 1, 'money' => 2, 'money_bold' => 3, 'pct' => 4, 'title' => 5, 'muted' => 6, 'head' => 7, 'textfmt' => 8, 'wrap' => 9];

    /** @param list<list<mixed>> $rows  @param list<float> $widths */
    public static function build(string $sheetName, array $rows, array $widths = [], bool $rtl = true): string
    {
        return self::book([['name' => $sheetName, 'rows' => $rows, 'widths' => $widths]], $rtl);
    }

    /**
     * A workbook of several sheets. Each sheet: name, rows, widths, and optionally
     * `freeze` (keep the first row in view), `text_cols` (column indexes formatted as text,
     * so numbers like VAT numbers and dates stay as typed) and `lists` (dropdowns:
     * column index => range on another sheet, e.g. "'Lists'!$A$2:$A$40").
     *
     * @param  list<array{name: string, rows: list<list<mixed>>, widths?: list<float>, freeze?: bool, text_cols?: list<int>, lists?: array<int, string>}>  $sheets
     */
    public static function book(array $sheets, bool $rtl = true): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create the spreadsheet');
        }
        $sheets = array_values($sheets);
        $overrides = $entries = $rels = '';
        foreach ($sheets as $i => $sheet) {
            $n = $i + 1;
            $overrides .= '<Override PartName="/xl/worksheets/sheet'.$n.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $entries .= '<sheet name="'.self::esc(self::sheetName($sheet['name'])).'" sheetId="'.$n.'" r:id="rId'.$n.'"/>';
            $rels .= '<Relationship Id="rId'.$n.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$n.'.xml"/>';
        }
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .$overrides
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.$entries.'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .$rels
            .'<Relationship Id="rId'.(count($sheets) + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/styles.xml', self::styles());
        foreach ($sheets as $i => $sheet) {
            $zip->addFromString('xl/worksheets/sheet'.($i + 1).'.xml', self::sheet($sheet['rows'], $sheet['widths'] ?? [], $rtl, $sheet));
        }
        $zip->close();
        $bytes = file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    /** Excel's sheet-name rules: no \ / ? * [ ] :, at most 31 characters. */
    public static function sheetName(string $name): string
    {
        return mb_substr(preg_replace('/[\\\\\/?*\[\]:]/u', ' ', $name), 0, 31);
    }

    private static function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="2"><numFmt numFmtId="164" formatCode="#,##0.00;(#,##0.00);&quot;-&quot;"/><numFmt numFmtId="165" formatCode="0.0&quot;%&quot;"/></numFmts>'
            .'<fonts count="5"><font><sz val="11"/><name val="Arial"/></font><font><b/><sz val="11"/><name val="Arial"/></font>'
            .'<font><b/><sz val="14"/><name val="Arial"/></font><font><sz val="10"/><color rgb="FF6B6B6B"/><name val="Arial"/></font>'
            .'<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Arial"/></font></fonts>'
            .'<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FF1D2230"/><bgColor indexed="64"/></patternFill></fill></fills>'
            .'<borders count="2"><border/><border><top style="thin"><color rgb="FF999999"/></top></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="10">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .'<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="164" fontId="1" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyBorder="1"/>'
            .'<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .'<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .'<xf numFmtId="0" fontId="4" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            .'<xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf>'
            .'</cellXfs></styleSheet>';
    }

    private static function sheet(array $rows, array $widths, bool $rtl, array $opt = []): string
    {
        $text = array_flip($opt['text_cols'] ?? []);
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetViews><sheetView workbookViewId="0"'.($rtl ? ' rightToLeft="1"' : '').'>'
            .(! empty($opt['freeze']) ? '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>' : '')
            .'</sheetView></sheetViews>';
        if ($widths) {
            $x .= '<cols>';
            foreach ($widths as $i => $w) {
                $x .= '<col min="'.($i + 1).'" max="'.($i + 1).'" width="'.(float) $w.'" customWidth="1"'
                    .(isset($text[$i]) ? ' style="'.self::STYLES['textfmt'].'"' : '').'/>';
            }
            $x .= '</cols>';
        }
        $x .= '<sheetData>';
        foreach (array_values($rows) as $r => $cells) {
            $x .= '<row r="'.($r + 1).'">';
            foreach (array_values($cells) as $c => $cell) {
                [$v, $style] = is_array($cell) ? [$cell['v'] ?? null, $cell['s'] ?? 'text'] : [$cell, 'text'];
                if ($v === null || $v === '') {
                    if ($style !== 'text') {
                        $x .= '<c r="'.self::ref($c, $r).'" s="'.self::STYLES[$style].'"/>';
                    }

                    continue;
                }
                $s = self::STYLES[$style] ?? 0;
                if (is_int($v) || is_float($v)) {
                    $x .= '<c r="'.self::ref($c, $r).'" s="'.$s.'"><v>'.round((float) $v, 4).'</v></c>';
                } else {
                    $x .= '<c r="'.self::ref($c, $r).'" s="'.$s.'" t="inlineStr"><is><t xml:space="preserve">'.self::esc((string) $v).'</t></is></c>';
                }
            }
            $x .= '</row>';
        }

        $x .= '</sheetData>';
        if (! empty($opt['lists'])) {
            // Dropdowns warn on a value outside the list but accept it (the import checks it).
            $x .= '<dataValidations count="'.count($opt['lists']).'">';
            foreach ($opt['lists'] as $col => $range) {
                $x .= '<dataValidation type="list" allowBlank="1" showErrorMessage="1" errorStyle="warning" sqref="'
                    .self::ref($col, 1).':'.self::ref($col, 1000).'"><formula1>'.self::esc($range).'</formula1></dataValidation>';
            }
            $x .= '</dataValidations>';
        }

        return $x.'</worksheet>';
    }

    public static function ref(int $col, int $row): string
    {
        $s = '';
        for ($n = $col + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $s = chr(65 + ($n - 1) % 26).$s;
        }

        return $s.($row + 1);
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
