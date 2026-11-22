<?php

namespace App\Domain\Documents;

use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Smalot\PdfParser\Parser as PdfParser;
use Throwable;
use ZipArchive;

/**
 * Pulls the text out of a document so extracted details can be checked against it word for word.
 * Pages are marked "[page N]" so quotes can be traced. Images and scanned PDFs have no text layer.
 */
final class TextExtractor
{
    /** @return array{text:?string, status:string} */
    public static function extract(string $path, ?string $mime, string $name): array
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        try {
            $text = match (true) {
                $mime === 'application/pdf' || $ext === 'pdf' => self::pdf($path),
                $ext === 'docx' => self::docx($path),
                $ext === 'xlsx' => self::xlsx($path),
                in_array($ext, ['txt', 'csv', 'md'], true) || str_starts_with((string) $mime, 'text/') => file_get_contents($path),
                default => null,
            };
        } catch (Throwable) {
            $text = null;
        }

        $text = $text !== null ? trim(preg_replace("/[ \t]+/", ' ', str_replace("\r", '', $text))) : null;
        if ($text === null || mb_strlen(preg_replace('/\[page \d+\]|\s/', '', $text)) < 20) {
            return ['text' => $text ?: null, 'status' => 'image_only'];
        }

        return ['text' => mb_substr($text, 0, 400_000), 'status' => 'extracted'];
    }

    private static function pdf(string $path): string
    {
        $pdf = (new PdfParser)->parseFile($path);
        $out = [];
        foreach ($pdf->getPages() as $i => $page) {
            $out[] = '[page '.($i + 1)."]\n".$page->getText();
        }

        return implode("\n\n", $out);
    }

    private static function docx(string $path): ?string
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            return null;
        }
        $xml = $zip->getFromName('word/document.xml') ?: '';
        $zip->close();
        $xml = preg_replace(['/<w:tab\/>/', '/<\/w:p>/', '/<w:br\/>/'], ["\t", "\n", "\n"], $xml);

        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1);
    }

    private static function xlsx(string $path): string
    {
        $reader = new XlsxReader;
        $reader->open($path);
        $out = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $out[] = '[sheet '.$sheet->getName().']';
            foreach ($sheet->getRowIterator() as $row) {
                $cells = array_map(fn ($c) => $c instanceof \DateTimeInterface ? $c->format('Y-m-d H:i') : (string) $c, $row->toArray());
                if (array_filter($cells, fn ($c) => trim($c) !== '')) {
                    $out[] = implode("\t", $cells);
                }
            }
        }
        $reader->close();

        return implode("\n", $out);
    }
}
