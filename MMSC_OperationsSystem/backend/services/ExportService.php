<?php
// Excel (.xlsx) export without any library or PHP extension.
// An .xlsx file is a ZIP of a few XML files; this writes that ZIP directly.

class ExportService
{
    /** Column names that hold codes or numbers that must stay as text (leading zeros, dashes). */
    private const TEXT_KEYS = '/(number|_no$|^or_|code|barcode|contact|phone|reference|employee_id|dr_po)/i';
    /** Column names that hold pesos. */
    private const MONEY_KEYS = '/(amount|price|cost|total|fee|cash|float|vat_amount|change|refund|difference|charge|gross|taxable|due|applied|held|revenue|sales$|net_sales|discount)/i';

    /**
     * Sends an .xlsx download. $sheets is a list of ['name' => ..., 'title' => ..., 'subtitle' => ..., 'rows' => [assoc rows]].
     */
    public static function download(string $filename, array $sheets): never
    {
        $bin = self::build($sheets);
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $filename) . '"');
        header('Content-Length: ' . strlen($bin));
        header('Cache-Control: no-store');
        echo $bin;
        exit;
    }

    public static function build(array $sheets): string
    {
        $files = [];
        $sheetXml = [];
        $wbSheets = '';
        $wbRels = '';
        $ctSheets = '';
        foreach (array_values($sheets) as $n => $sh) {
            $idx = $n + 1;
            $files["xl/worksheets/sheet$idx.xml"] = self::sheet($sh);
            $name = self::x(mb_substr(preg_replace('/[\[\]\*\?\/\\\\:]/', ' ', $sh['name'] ?? "Sheet$idx"), 0, 31));
            $wbSheets .= "<sheet name=\"$name\" sheetId=\"$idx\" r:id=\"rId$idx\"/>";
            $wbRels .= "<Relationship Id=\"rId$idx\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet$idx.xml\"/>";
            $ctSheets .= "<Override PartName=\"/xl/worksheets/sheet$idx.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>";
        }
        $stylesId = count($sheets) + 1;
        $files['[Content_Types].xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . $ctSheets . '</Types>';
        $files['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
        $files['xl/workbook.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $wbSheets . '</sheets></workbook>';
        $files['xl/_rels/workbook.xml.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $wbRels
            . "<Relationship Id=\"rId$stylesId\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles\" Target=\"styles.xml\"/></Relationships>";
        // Styles: 0 normal, 1 bold header (filled), 2 money, 3 title, 4 subtitle, 5 number 0.##
        $files['xl/styles.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="2"><numFmt numFmtId="164" formatCode="&quot;₱&quot;#,##0.00"/><numFmt numFmtId="165" formatCode="#,##0.##"/></numFmts>'
            . '<fonts count="4"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="14"/><name val="Calibri"/></font><font><i/><sz val="10"/><color rgb="FF555555"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF071A2E"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border><left style="thin"><color rgb="FFD9DFE7"/></left><right style="thin"><color rgb="FFD9DFE7"/></right><top style="thin"><color rgb="FFD9DFE7"/></top><bottom style="thin"><color rgb="FFD9DFE7"/></bottom><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="6">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" applyBorder="1"><alignment vertical="top" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" applyFont="1" applyFill="1" applyBorder="1"><alignment vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" applyNumberFormat="1" applyBorder="1"><alignment vertical="top"/></xf>'
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" applyFont="1"/>'
            . '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" applyFont="1"/>'
            . '<xf numFmtId="165" fontId="0" fillId="0" borderId="1" applyNumberFormat="1" applyBorder="1"><alignment vertical="top"/></xf>'
            . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
        return self::zip($files);
    }

    /** Turns a column key like "qty_received" into "Qty Received". */
    public static function heading(string $key): string
    {
        return ucwords(str_replace('_', ' ', $key));
    }

    private static function sheet(array $sh): string
    {
        $rows = array_values($sh['rows'] ?? []);
        $keys = $rows ? array_values(array_filter(array_keys($rows[0]), fn($k) => !str_ends_with($k, '_key') && $k !== 'password_hash')) : ['Result'];
        $out = [];
        $r = 1;
        $out[] = self::row($r++, [[$sh['title'] ?? ($sh['name'] ?? 'Report'), 3]]);
        if (!empty($sh['subtitle'])) $out[] = self::row($r++, [[$sh['subtitle'], 4]]);
        $r++;
        $headerRow = $r;
        $out[] = self::row($r++, array_map(fn($k) => [self::heading($k), 1], $keys));
        $widths = array_map(fn($k) => min(60, max(10, mb_strlen(self::heading($k)) + 2)), $keys);
        if (!$rows) $out[] = self::row($r++, [['No records for the selected period.', 0]]);
        foreach ($rows as $row) {
            $cells = [];
            foreach ($keys as $c => $k) {
                $v = $row[$k] ?? null;
                $isText = preg_match(self::TEXT_KEYS, $k) || !is_numeric($v) || (is_string($v) && strlen($v) > 1 && $v[0] === '0' && !str_contains($v, '.'));
                if ($v === null || $v === '') { $cells[] = ['', 0]; continue; }
                if ($isText) { $cells[] = [(string)$v, 0]; $widths[$c] = min(60, max($widths[$c], mb_strlen((string)$v) + 2)); continue; }
                $cells[] = [(float)$v, preg_match(self::MONEY_KEYS, $k) && !preg_match('/^(qty_|is_)|_qty$|_count$/', $k) ? 2 : 5];
                $widths[$c] = max($widths[$c], 14);
            }
            $out[] = self::row($r++, $cells);
        }
        $cols = '';
        foreach ($widths as $i => $w) $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
        $lastCol = self::col(count($keys) - 1);
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="' . $headerRow . '" topLeftCell="A' . ($headerRow + 1) . '" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<cols>' . $cols . '</cols><sheetData>' . implode('', $out) . '</sheetData>'
            . ($rows ? '<autoFilter ref="A' . $headerRow . ':' . $lastCol . ($r - 1) . '"/>' : '')
            . '<pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0"/></worksheet>';
    }

    private static function row(int $r, array $cells): string
    {
        $x = '<row r="' . $r . '">';
        foreach ($cells as $i => [$v, $style]) {
            $ref = self::col($i) . $r;
            if (is_float($v) || is_int($v)) $x .= '<c r="' . $ref . '" s="' . $style . '"><v>' . $v . '</v></c>';
            else $x .= '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . self::x((string)$v) . '</t></is></c>';
        }
        return $x . '</row>';
    }

    private static function col(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s;
        return $s;
    }

    private static function x(string $s): string
    {
        $s = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $s) ?? '';
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** Minimal ZIP (stored, no compression) — enough for .xlsx and needs no zip extension. */
    private static function zip(array $files): string
    {
        $data = ''; $central = ''; $offset = 0;
        $t = getdate();
        $dosTime = ($t['hours'] << 11) | ($t['minutes'] << 5) | intdiv($t['seconds'], 2);
        $dosDate = (($t['year'] - 1980) << 9) | ($t['mon'] << 5) | $t['mday'];
        foreach ($files as $name => $content) {
            $crc = crc32($content); $len = strlen($content); $nlen = strlen($name);
            $local = pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 0, $dosTime, $dosDate, $crc, $len, $len, $nlen, 0) . $name;
            $data .= $local . $content;
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 0, $dosTime, $dosDate, $crc, $len, $len, $nlen, 0, 0, 0, 0, 0, $offset) . $name;
            $offset += strlen($local) + $len;
        }
        return $data . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, count($files), count($files), strlen($central), $offset, 0);
    }
}
