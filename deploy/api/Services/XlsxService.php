<?php

namespace Services;

/**
 * Generates real .xlsx files without external libraries.
 * XLSX = ZIP archive containing XML files following the Office Open XML format.
 */
class XlsxService
{
    /**
     * Generate an XLSX file and stream it to output.
     *
     * @param array $columns Column names
     * @param array $rows Array of associative arrays
     * @param string $sheetName Name of the worksheet
     */
    public static function generate(array $columns, array $rows, string $sheetName = 'Datos'): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'xlsx_');

        $zip = new \ZipArchive();
        if ($zip->open($tmpFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('No se pudo crear el archivo XLSX');
        }

        // [Content_Types].xml
        $zip->addFromString('[Content_Types].xml', self::contentTypes());

        // _rels/.rels
        $zip->addFromString('_rels/.rels', self::rels());

        // xl/_rels/workbook.xml.rels
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRels());

        // xl/workbook.xml
        $zip->addFromString('xl/workbook.xml', self::workbook($sheetName));

        // xl/styles.xml
        $zip->addFromString('xl/styles.xml', self::styles());

        // xl/sharedStrings.xml + xl/worksheets/sheet1.xml
        $sharedStrings = [];
        $ssIndex = [];

        // Collect all unique strings
        foreach ($columns as $col) {
            $s = (string)$col;
            if (!isset($ssIndex[$s])) {
                $ssIndex[$s] = count($sharedStrings);
                $sharedStrings[] = $s;
            }
        }
        foreach ($rows as $row) {
            foreach ($row as $value) {
                $s = (string)($value ?? '');
                if (!is_numeric($value) && !isset($ssIndex[$s])) {
                    $ssIndex[$s] = count($sharedStrings);
                    $sharedStrings[] = $s;
                }
            }
        }

        $zip->addFromString('xl/sharedStrings.xml', self::sharedStrings($sharedStrings));
        $zip->addFromString('xl/worksheets/sheet1.xml', self::sheet($columns, $rows, $ssIndex));

        $zip->close();

        return $tmpFile;
    }

    private static function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Default Extension="xml" ContentType="application/xml"/>
    <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
    <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
    <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
    <Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>
</Types>';
    }

    private static function rels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>';
    }

    private static function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
    <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
    <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>
</Relationships>';
    }

    private static function workbook(string $sheetName): string
    {
        $name = htmlspecialchars($sheetName, ENT_XML1);
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
          xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <sheets>
        <sheet name="' . $name . '" sheetId="1" r:id="rId1"/>
    </sheets>
</workbook>';
    }

    private static function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
    <fonts count="2">
        <font><sz val="11"/><name val="Calibri"/></font>
        <font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>
    </fonts>
    <fills count="3">
        <fill><patternFill patternType="none"/></fill>
        <fill><patternFill patternType="gray125"/></fill>
        <fill><patternFill patternType="solid"><fgColor rgb="FF4472C4"/></patternFill></fill>
    </fills>
    <borders count="2">
        <border><left/><right/><top/><bottom/><diagonal/></border>
        <border>
            <left style="thin"><color rgb="FFB0B0B0"/></left>
            <right style="thin"><color rgb="FFB0B0B0"/></right>
            <top style="thin"><color rgb="FFB0B0B0"/></top>
            <bottom style="thin"><color rgb="FFB0B0B0"/></bottom>
            <diagonal/>
        </border>
    </borders>
    <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
    <cellXfs count="3">
        <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0"/>
        <xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>
        <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>
    </cellXfs>
</styleSheet>';
    }

    private static function sharedStrings(array $strings): string
    {
        $count = count($strings);
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $xml .= '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . $count . '" uniqueCount="' . $count . '">';

        foreach ($strings as $s) {
            $xml .= '<si><t>' . htmlspecialchars($s, ENT_XML1, 'UTF-8') . '</t></si>';
        }

        $xml .= '</sst>';
        return $xml;
    }

    private static function sheet(array $columns, array $rows, array $ssIndex): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

        // sheetViews MUST come before sheetData per OOXML spec
        $xml .= '<sheetViews><sheetView tabSelected="1" workbookViewId="0">';
        $xml .= '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>';
        $xml .= '</sheetView></sheetViews>';

        // Column widths
        $colCount = count($columns);
        if ($colCount > 0) {
            $xml .= '<cols>';
            for ($i = 1; $i <= $colCount; $i++) {
                $xml .= '<col min="' . $i . '" max="' . $i . '" width="18" customWidth="1"/>';
            }
            $xml .= '</cols>';
        }

        $xml .= '<sheetData>';

        // Header row (style 1 = bold white on blue)
        $xml .= '<row r="1">';
        foreach ($columns as $ci => $col) {
            $cellRef = self::cellRef($ci, 0);
            $idx = $ssIndex[(string)$col];
            $xml .= '<c r="' . $cellRef . '" t="s" s="1"><v>' . $idx . '</v></c>';
        }
        $xml .= '</row>';

        // Data rows (style 2 = bordered)
        foreach ($rows as $ri => $row) {
            $rowNum = $ri + 2;
            $xml .= '<row r="' . $rowNum . '">';
            $ci = 0;
            foreach ($row as $value) {
                $cellRef = self::cellRef($ci, $ri + 1);

                if ($value === null) {
                    $xml .= '<c r="' . $cellRef . '" s="2"/>';
                } elseif (is_numeric($value)) {
                    $xml .= '<c r="' . $cellRef . '" s="2"><v>' . $value . '</v></c>';
                } else {
                    $s = (string)$value;
                    $idx = $ssIndex[$s] ?? 0;
                    $xml .= '<c r="' . $cellRef . '" t="s" s="2"><v>' . $idx . '</v></c>';
                }
                $ci++;
            }
            $xml .= '</row>';
        }

        $xml .= '</sheetData>';

        // autoFilter MUST come after sheetData per OOXML spec
        if ($colCount > 0) {
            $lastCol = self::colLetter($colCount - 1);
            $xml .= '<autoFilter ref="A1:' . $lastCol . (count($rows) + 1) . '"/>';
        }

        $xml .= '</worksheet>';
        return $xml;
    }

    private static function cellRef(int $col, int $row): string
    {
        return self::colLetter($col) . ($row + 1);
    }

    private static function colLetter(int $col): string
    {
        $letter = '';
        $col++;
        while ($col > 0) {
            $col--;
            $letter = chr(65 + ($col % 26)) . $letter;
            $col = intdiv($col, 26);
        }
        return $letter;
    }
}
