<?php

namespace App\Support;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Reads the rows of one sheet of an uploaded XLSX (or a CSV) file, for the data import.
 * Values come back as strings exactly as stored (numbers as their stored digits, dates
 * typed as text as typed, date cells as Excel serial numbers); converting them is the
 * importer's job, by the column's type.
 */
class XlsxReader
{
    private const MAX_XML_BYTES = 40 * 1024 * 1024;

    /**
     * @return list<array<int, string>> rows of cells by column index (0 = A); the first row is the header
     */
    public static function rows(string $path, string $originalName, ?string $preferSheet = null): array
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (in_array($ext, ['csv', 'txt'], true)) {
            return self::csv($path);
        }
        if ($ext !== 'xlsx') {
            throw new RuntimeException(__('الملف يجب أن يكون Excel بصيغة xlsx أو CSV.'));
        }

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException(__('تعذّر فتح الملف؛ تأكد أنه ملف Excel سليم بصيغة xlsx.'));
        }
        try {
            $sheetPath = self::sheetPath($zip, $preferSheet);
            $shared = self::sharedStrings($zip);
            $xml = self::xml($zip, $sheetPath);
        } finally {
            $zip->close();
        }

        $rows = [];
        foreach ($xml->sheetData->row ?? [] as $row) {
            $r = (int) $row['r'] - 1;
            $cells = [];
            $next = 0;
            foreach ($row->c as $c) {
                $col = isset($c['r']) ? self::colIndex((string) $c['r']) : $next;
                $next = $col + 1;
                $value = self::cellValue($c, $shared);
                if ($value !== '') {
                    $cells[$col] = $value;
                }
            }
            if ($cells && $r >= 0) {
                $rows[$r] = $cells;
            }
        }
        ksort($rows);

        return self::fill($rows);
    }

    /** Rows keep their sheet position (row 1 = index 0) so errors name the right Excel row. */
    private static function fill(array $rows): array
    {
        if (! $rows) {
            return [];
        }
        $out = [];
        for ($i = 0, $last = max(array_keys($rows)); $i <= $last; $i++) {
            $out[] = $rows[$i] ?? [];
        }

        return $out;
    }

    private static function csv(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException(__('تعذّر فتح الملف؛ تأكد أنه ملف Excel سليم بصيغة xlsx.'));
        }
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        if (! mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1256');
        }
        $first = strtok($raw, "\n") ?: '';
        $sep = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        $h = fopen('php://memory', 'r+');
        fwrite($h, $raw);
        rewind($h);
        $rows = [];
        while (($line = fgetcsv($h, null, $sep, '"', '')) !== false) {
            $rows[] = array_filter(array_map(fn ($v) => trim((string) $v), $line), fn ($v) => $v !== '');
        }
        fclose($h);

        return $rows;
    }

    /** The named sheet if present, else the first one in the workbook. */
    private static function sheetPath(ZipArchive $zip, ?string $prefer): string
    {
        $wb = self::xml($zip, 'xl/workbook.xml');
        $rels = self::xml($zip, 'xl/_rels/workbook.xml.rels');
        $targets = [];
        foreach ($rels->Relationship as $rel) {
            $targets[(string) $rel['Id']] = (string) $rel['Target'];
        }
        $chosen = null;
        foreach ($wb->sheets->sheet ?? [] as $sheet) {
            $rid = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            $chosen ??= $rid;
            if ($prefer !== null && trim((string) $sheet['name']) === Xlsx::sheetName($prefer)) {
                $chosen = $rid;
                break;
            }
        }
        $target = $targets[$chosen] ?? null;
        if (! $target) {
            throw new RuntimeException(__('لا توجد ورقة بيانات في الملف.'));
        }
        $target = ltrim($target, '/');

        return str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
    }

    private static function sharedStrings(ZipArchive $zip): array
    {
        if ($zip->locateName('xl/sharedStrings.xml') === false) {
            return [];
        }
        $out = [];
        foreach (self::xml($zip, 'xl/sharedStrings.xml')->si as $si) {
            $out[] = self::richText($si);
        }

        return $out;
    }

    private static function cellValue(SimpleXMLElement $c, array $shared): string
    {
        $type = (string) $c['t'];
        $v = trim(match ($type) {
            's' => $shared[(int) $c->v] ?? '',
            'inlineStr' => self::richText($c->is),
            'b' => ((string) $c->v) === '1' ? '1' : '0',
            default => (string) $c->v,
        });
        // Numbers stored by Excel can carry binary noise (0.1 + 0.2): keep 6 decimals at most.
        if ($type === '' && is_numeric($v) && str_contains($v, '.') && ! str_contains(strtolower($v), 'e')) {
            $v = rtrim(rtrim(number_format((float) $v, 6, '.', ''), '0'), '.');
        }

        return $v;
    }

    private static function richText(?SimpleXMLElement $node): string
    {
        if (! $node) {
            return '';
        }
        if (isset($node->t)) {
            return (string) $node->t;
        }
        $s = '';
        foreach ($node->r as $run) {
            $s .= (string) $run->t;
        }

        return $s;
    }

    private static function xml(ZipArchive $zip, string $name): SimpleXMLElement
    {
        $stat = $zip->statName($name);
        if ($stat === false) {
            throw new RuntimeException(__('تعذّر فتح الملف؛ تأكد أنه ملف Excel سليم بصيغة xlsx.'));
        }
        if ($stat['size'] > self::MAX_XML_BYTES) {
            throw new RuntimeException(__('الملف أكبر من المسموح به.'));
        }
        $xml = simplexml_load_string((string) $zip->getFromName($name), SimpleXMLElement::class, LIBXML_NONET);
        if ($xml === false) {
            throw new RuntimeException(__('تعذّر فتح الملف؛ تأكد أنه ملف Excel سليم بصيغة xlsx.'));
        }

        return $xml;
    }

    private static function colIndex(string $ref): int
    {
        $letters = preg_replace('/[^A-Z]/', '', strtoupper($ref));
        $n = 0;
        foreach (str_split($letters) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return $n - 1;
    }
}
