<?php

namespace App\Domain\Import;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Reads the first sheet of an Excel (.xlsx) statement into headers and rows, as CsvReader does for CSV. Revolut's
 * "Excel" statement download is one of these on some devices. Numbers stay as written; cells formatted as dates
 * become "YYYY-MM-DD HH:MM:SS".
 */
final class XlsxReader
{
    /** @return array{headers: list<string>, rows: list<array<string,string>>} */
    public static function read(string $path): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('This server can’t open Excel files. Download the statement as CSV instead.');
        }
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('This Excel file can’t be opened. Download the statement as CSV instead.');
        }
        try {
            $sheetPath = self::firstSheet($zip);
            $sheet = $sheetPath ? $zip->getFromName($sheetPath) : false;
            if ($sheet === false) {
                throw new RuntimeException('This Excel file has no sheet to read.');
            }
            $strings = self::strings($zip->getFromName('xl/sharedStrings.xml') ?: '');
            $dateStyles = self::dateStyles($zip->getFromName('xl/styles.xml') ?: '');
        } finally {
            $zip->close();
        }

        $xml = self::xml($sheet);
        $grid = [];
        foreach ($xml->sheetData->row ?? [] as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                $col = self::column((string) $c['r']);
                $type = (string) $c['t'];
                $value = match ($type) {
                    's' => $strings[(int) $c->v] ?? '',
                    'inlineStr' => self::text($c->is),
                    'b' => ((string) $c->v) === '1' ? 'TRUE' : 'FALSE',
                    default => (string) $c->v,
                };
                if ($type === '' && $value !== '' && is_numeric($value) && isset($dateStyles[(int) $c['s']])) {
                    $value = self::excelDate((float) $value);
                }
                $cells[$col] = trim($value);
            }
            if (array_filter($cells, fn ($v) => $v !== '') === []) {
                continue;
            }
            $max = max(array_keys($cells));
            $line = [];
            for ($i = 0; $i <= $max; $i++) {
                $line[] = $cells[$i] ?? '';
            }
            $grid[] = $line;
        }
        if (! $grid) {
            throw new RuntimeException('The file is empty.');
        }
        $headers = array_map(fn ($h) => preg_replace('/^\xEF\xBB\xBF/', '', $h), array_shift($grid));
        $rows = [];
        foreach ($grid as $cells) {
            $row = [];
            foreach ($headers as $i => $h) {
                $row[$h] = $cells[$i] ?? '';
            }
            $rows[] = $row;
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    public static function isXlsx(string $path): bool
    {
        $f = @fopen($path, 'rb');
        $head = $f ? fread($f, 4) : '';
        if ($f) {
            fclose($f);
        }

        return $head === "PK\x03\x04";
    }

    private static function firstSheet(ZipArchive $zip): ?string
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbook && $rels) {
            $wb = self::xml($workbook);
            $first = $wb->sheets->sheet[0] ?? null;
            $rid = $first ? (string) ($first->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] ?? '') : '';
            foreach (self::xml($rels)->Relationship as $r) {
                if ((string) $r['Id'] === $rid) {
                    $target = ltrim((string) $r['Target'], '/');

                    return str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
                }
            }
        }

        return $zip->locateName('xl/worksheets/sheet1.xml') !== false ? 'xl/worksheets/sheet1.xml' : null;
    }

    /** @return list<string> */
    private static function strings(string $xml): array
    {
        if ($xml === '') {
            return [];
        }
        $out = [];
        foreach (self::xml($xml)->si as $si) {
            $out[] = self::text($si);
        }

        return $out;
    }

    private static function text(?SimpleXMLElement $node): string
    {
        if (! $node) {
            return '';
        }
        if (isset($node->t)) {
            $text = (string) $node->t;
        } else {
            $text = '';
        }
        foreach ($node->r ?? [] as $run) {
            $text .= (string) $run->t;
        }

        return $text;
    }

    /** @return array<int, true> the style indexes that show a date */
    private static function dateStyles(string $xml): array
    {
        if ($xml === '') {
            return [];
        }
        $styles = self::xml($xml);
        $custom = [];
        foreach ($styles->numFmts->numFmt ?? [] as $f) {
            $code = preg_replace('/"[^"]*"|\[[^\]]*\]/', '', (string) $f['formatCode']);
            if (preg_match('/[dmyhs]/i', $code)) {
                $custom[(int) $f['numFmtId']] = true;
            }
        }
        $out = [];
        $i = 0;
        foreach ($styles->cellXfs->xf ?? [] as $xf) {
            $id = (int) $xf['numFmtId'];
            if (($id >= 14 && $id <= 22) || ($id >= 45 && $id <= 47) || isset($custom[$id])) {
                $out[$i] = true;
            }
            $i++;
        }

        return $out;
    }

    private static function excelDate(float $serial): string
    {
        $seconds = (int) round(($serial - 25569) * 86400); // days from 1899-12-30 to 1970-01-01

        return gmdate($serial == floor($serial) ? 'Y-m-d' : 'Y-m-d H:i:s', $seconds);
    }

    private static function column(string $ref): int
    {
        preg_match('/^[A-Z]+/', $ref, $m);
        $n = 0;
        foreach (str_split($m[0] ?? 'A') as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return $n - 1;
    }

    private static function xml(string $xml): SimpleXMLElement
    {
        $prev = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        libxml_use_internal_errors($prev);
        if ($doc === false) {
            throw new RuntimeException('This Excel file is damaged. Download the statement as CSV instead.');
        }

        return $doc;
    }
}
