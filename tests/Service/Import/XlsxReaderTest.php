<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Service\Import\XlsxReader;
use PHPUnit\Framework\TestCase;

final class XlsxReaderTest extends TestCase
{
    public function testReadsSharedStringsNumbersAndKeepsColumnsAlignedAroundEmptyCells(): void
    {
        $path = $this->writeXlsx(
            '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row>'
            // B2 is omitted and D2 is self-closing: the values after them must keep their column.
            .'<row r="2"><c r="A2"><v>42</v></c><c r="C2" t="s"><v>2</v></c><c r="D2" s="1"/><c r="E2"><v>7</v></c></row>'
            .'<row r="3"/>'
            .'<row r="4"><c r="A4" t="s"><v>0</v></c></row>',
            ['Code', 'Libellé', "L'Abergement <&> Co"],
        );

        $rows = iterator_to_array(new XlsxReader()->read($path, 'Data'), false);

        self::assertSame(
            [['Code', 'Libellé'], ['42', '', "L'Abergement <&> Co", '', '7'], [], ['Code']],
            $rows,
        );

        unlink($path);
    }

    public function testUnknownSheetThrows(): void
    {
        $path = $this->writeXlsx('', []);

        $this->expectException(\RuntimeException::class);

        try {
            iterator_to_array(new XlsxReader()->read($path, 'Missing'));
        } finally {
            unlink($path);
        }
    }

    /**
     * @param list<string> $sharedStrings
     */
    private function writeXlsx(string $rowsXml, array $sharedStrings): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx-reader-test-');
        \assert(false !== $path);

        $strings = implode('', array_map(static fn (string $s): string => '<si><t>'.htmlspecialchars($s, \ENT_XML1).'</t></si>', $sharedStrings));

        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Data" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/sharedStrings.xml', '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.$strings.'</sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$rowsXml.'</sheetData></worksheet>');
        $zip->close();

        return $path;
    }
}
