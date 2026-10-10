<?php

declare(strict_types=1);

namespace App\Service\Import;

/**
 * Streams one sheet of an .xlsx file as rows of strings, without a
 * spreadsheet library: INSEE publishes some reference data as XLSX only.
 * Knows just enough of the format for such files — shared-string and numeric
 * cells, no formulas or dates. Empty cells come back as ''.
 */
final class XlsxReader
{
    /**
     * @return iterable<list<string>>
     */
    public function read(string $xlsxPath, string $sheetName): iterable
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($xlsxPath)) {
            throw new \RuntimeException("Cannot open {$xlsxPath}");
        }

        try {
            $sheetPath = $this->sheetPath($zip, $sheetName);
            $sharedStrings = $this->sharedStrings($zip);
        } finally {
            $zip->close();
        }

        $reader = new \XMLReader();
        if (!$reader->open('zip://'.$xlsxPath.'#'.$sheetPath)) {
            throw new \RuntimeException("Cannot read {$sheetPath} in {$xlsxPath}");
        }

        try {
            while ($reader->read()) {
                if (\XMLReader::ELEMENT === $reader->nodeType && 'row' === $reader->name) {
                    yield $this->readRow($reader, $sharedStrings);
                }
            }
        } finally {
            $reader->close();
        }
    }

    private function sheetPath(\ZipArchive $zip, string $sheetName): string
    {
        $workbook = simplexml_load_string($this->entry($zip, 'xl/workbook.xml')) ?: throw new \RuntimeException('Invalid workbook.xml');
        $relationships = simplexml_load_string($this->entry($zip, 'xl/_rels/workbook.xml.rels')) ?: throw new \RuntimeException('Invalid workbook.xml.rels');

        $relationshipId = null;
        foreach ($workbook->sheets->sheet as $sheet) {
            if ($sheetName === (string) $sheet['name']) {
                $relationshipId = (string) $sheet->attributes('r', true)['id'];
            }
        }

        if (null === $relationshipId) {
            throw new \RuntimeException("Sheet \"{$sheetName}\" not found.");
        }

        foreach ($relationships->Relationship as $relationship) {
            if ($relationshipId === (string) $relationship['Id']) {
                return 'xl/'.ltrim((string) $relationship['Target'], '/');
            }
        }

        throw new \RuntimeException("No target for sheet \"{$sheetName}\".");
    }

    /**
     * @return list<string>
     */
    private function sharedStrings(\ZipArchive $zip): array
    {
        if (false === $zip->locateName('xl/sharedStrings.xml')) {
            return [];
        }

        $xml = simplexml_load_string($this->entry($zip, 'xl/sharedStrings.xml')) ?: throw new \RuntimeException('Invalid sharedStrings.xml');

        $strings = [];
        foreach ($xml->si as $item) {
            // A string is either a single <t> or several rich-text <r><t> runs.
            $text = (string) $item->t;
            foreach ($item->r as $run) {
                $text .= (string) $run->t;
            }

            $strings[] = $text;
        }

        return $strings;
    }

    private function entry(\ZipArchive $zip, string $name): string
    {
        return $zip->getFromName($name) ?: throw new \RuntimeException("Missing {$name} in the XLSX file.");
    }

    /**
     * @param list<string> $sharedStrings
     *
     * @return list<string>
     */
    private function readRow(\XMLReader $reader, array $sharedStrings): array
    {
        $row = [];
        $depth = $reader->depth;
        if ($reader->isEmptyElement) {
            return $row;
        }

        while ($reader->read() && $reader->depth > $depth) {
            if (\XMLReader::ELEMENT !== $reader->nodeType || 'c' !== $reader->name) {
                continue;
            }

            // Empty cells are omitted from the file: the cell reference ("C5")
            // gives the real column, so the following ones don't shift left.
            $column = $this->columnIndex((string) $reader->getAttribute('r'));
            $isShared = 's' === $reader->getAttribute('t');
            $value = '';

            // A self-closing <c/> has no content to consume: reading on would swallow the next cell.
            $cellDepth = $reader->depth;
            $hasContent = !$reader->isEmptyElement;
            while ($hasContent && $reader->read() && $reader->depth > $cellDepth) {
                if (\XMLReader::ELEMENT === $reader->nodeType && 'v' === $reader->name) {
                    $raw = $reader->readString();
                    $value = $isShared ? ($sharedStrings[(int) $raw] ?? '') : $raw;
                }
            }

            $row = array_pad($row, $column, '');
            $row[$column] = $value;
        }

        return array_values($row);
    }

    private function columnIndex(string $cellReference): int
    {
        $letters = preg_replace('/\d+/', '', $cellReference) ?? '';
        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + (\ord($letter[0]) - \ord('A') + 1);
        }

        return $index - 1;
    }
}
