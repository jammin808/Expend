<?php

namespace App\Domain\Import;

use RuntimeException;

/** Reads a bank CSV into headers + associative rows. Handles BOMs, delimiters and blank lines. */
final class CsvReader
{
    /** @return array{headers: list<string>, rows: list<array<string,string>>} */
    public static function read(string $path): array
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException("Couldn't read the uploaded file.");
        }

        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }
        $content = str_replace(["\r\n", "\r"], "\n", $content);

        $firstLine = strtok($content, "\n") ?: '';
        $delimiter = collect([',', ';', "\t"])->sortByDesc(fn ($d) => substr_count($firstLine, $d))->first();

        $handle = fopen('php://memory', 'r+');
        fwrite($handle, $content);
        rewind($handle);

        $headers = null;
        $rows = [];
        while (($cells = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            if ($cells === [null] || count(array_filter($cells, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue;
            }
            $cells = array_map(fn ($c) => trim((string) $c), $cells);
            if ($headers === null) {
                $headers = $cells;

                continue;
            }
            $row = [];
            foreach ($headers as $i => $header) {
                $row[$header] = $cells[$i] ?? '';
            }
            $rows[] = $row;
        }
        fclose($handle);

        if ($headers === null) {
            throw new RuntimeException('The file is empty.');
        }

        return ['headers' => $headers, 'rows' => $rows];
    }
}
