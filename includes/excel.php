<?php

declare(strict_types=1);

/**
 * 读取 xlsx 文件，返回按行排列的二维数组。
 *
 * @throws RuntimeException 当文件无法解析时抛出。
 */
function read_xlsx(string $path): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('服务器缺少 ZipArchive 扩展，无法解析 Excel 文件。');
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('无法打开上传的 Excel 文件。');
    }

    $sharedStrings = [];
    $sharedStringsXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedStringsXml !== false) {
        $sharedDoc = simplexml_load_string($sharedStringsXml);
        if ($sharedDoc !== false) {
            foreach ($sharedDoc->si as $si) {
                $sharedStrings[] = extract_shared_string($si);
            }
        }
    }

    $workbookXml = $zip->getFromName('xl/workbook.xml');
    if ($workbookXml === false) {
        $zip->close();
        throw new RuntimeException('Excel 文件缺少 workbook 信息。');
    }

    $workbook = simplexml_load_string($workbookXml);
    if ($workbook === false) {
        $zip->close();
        throw new RuntimeException('无法解析 Excel workbook。');
    }
    $workbook->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

    $firstSheet = $workbook->sheets->sheet[0] ?? null;
    if ($firstSheet === null) {
        $zip->close();
        throw new RuntimeException('Excel 文件不包含任何工作表。');
    }

    $relId = (string) $firstSheet->attributes('r', true)->id;
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($relsXml === false) {
        $zip->close();
        throw new RuntimeException('Excel 文件缺少工作表关联信息。');
    }

    $rels = simplexml_load_string($relsXml);
    if ($rels === false) {
        $zip->close();
        throw new RuntimeException('无法解析 Excel 关联信息。');
    }

    $sheetPath = '';
    foreach ($rels->Relationship as $relationship) {
        if ((string) $relationship['Id'] === $relId) {
            $sheetPath = 'xl/' . ltrim((string) $relationship['Target'], '/');
            break;
        }
    }

    if ($sheetPath === '') {
        $zip->close();
        throw new RuntimeException('未找到工作表内容。');
    }

    $sheetXml = $zip->getFromName($sheetPath);
    if ($sheetXml === false) {
        $zip->close();
        throw new RuntimeException('无法读取工作表内容。');
    }

    $sheet = simplexml_load_string($sheetXml);
    if ($sheet === false) {
        $zip->close();
        throw new RuntimeException('无法解析工作表内容。');
    }

    $rows = [];
    $maxColumns = 0;
    if (isset($sheet->sheetData)) {
        foreach ($sheet->sheetData->row as $row) {
            $rowData = [];
            $currentColumn = 0;
            foreach ($row->c as $cell) {
                $columnIndex = column_reference_to_index((string) $cell['r']);
                while ($currentColumn < $columnIndex) {
                    $rowData[] = '';
                    $currentColumn++;
                }
                $rowData[] = extract_cell_value($cell, $sharedStrings);
                $currentColumn++;
            }

            $trimmed = array_filter($rowData, static function ($value) {
                return trim((string) $value) !== '';
            });
            if (!$trimmed) {
                continue;
            }

            $maxColumns = max($maxColumns, count($rowData));
            $rows[] = $rowData;
        }
    }

    foreach ($rows as &$rowData) {
        while (count($rowData) < $maxColumns) {
            $rowData[] = '';
        }
        foreach ($rowData as &$value) {
            if (is_string($value)) {
                $value = trim($value);
            } elseif ($value === null) {
                $value = '';
            } else {
                $value = trim((string) $value);
            }
        }
        unset($value);
    }
    unset($rowData);

    $zip->close();

    return $rows;
}

function extract_shared_string(SimpleXMLElement $si): string
{
    if (isset($si->t)) {
        return (string) $si->t;
    }

    $text = '';
    if (isset($si->r)) {
        foreach ($si->r as $run) {
            $text .= (string) $run->t;
        }
    }

    return $text;
}

function column_reference_to_index(string $reference): int
{
    $reference = strtoupper($reference);
    $letters = '';
    $length = strlen($reference);
    for ($i = 0; $i < $length; $i++) {
        $char = $reference[$i];
        if ($char >= 'A' && $char <= 'Z') {
            $letters .= $char;
        } else {
            break;
        }
    }

    if ($letters === '') {
        return 0;
    }

    $index = 0;
    $len = strlen($letters);
    for ($i = 0; $i < $len; $i++) {
        $index = $index * 26 + (ord($letters[$i]) - 64);
    }

    return max(0, $index - 1);
}

function extract_cell_value(SimpleXMLElement $cell, array $sharedStrings)
{
    $type = (string) $cell['t'];

    if ($type === 's') {
        $value = (int) ($cell->v ?? 0);
        return $sharedStrings[$value] ?? '';
    }

    if ($type === 'b') {
        return ((string) $cell->v) === '1' ? '1' : '0';
    }

    if ($type === 'inlineStr' && isset($cell->is)) {
        if (isset($cell->is->t)) {
            return (string) $cell->is->t;
        }
        $text = '';
        foreach ($cell->is->r as $run) {
            $text .= (string) $run->t;
        }
        return $text;
    }

    if (isset($cell->v)) {
        return (string) $cell->v;
    }

    return '';
}

function excel_serial_to_date_string($value): ?string
{
    if ($value === null) {
        return null;
    }

    if (is_numeric($value)) {
        $serial = (float) $value;
        if ($serial <= 0) {
            return null;
        }
        $timestamp = (int) round(($serial - 25569) * 86400);
        if ($timestamp <= 0) {
            return null;
        }
        return gmdate('Y-m-d', $timestamp);
    }

    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    if (preg_match('/^\d{8}$/', $value) === 1) {
        $year = substr($value, 0, 4);
        $month = substr($value, 4, 2);
        $day = substr($value, 6, 2);
        if (checkdate((int) $month, (int) $day, (int) $year)) {
            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }
    }

    if (preg_match('/^\d{4}-\d{1,2}-\d{1,2}$/', $value) === 1) {
        [$year, $month, $day] = array_map('intval', explode('-', $value));
        if (checkdate($month, $day, $year)) {
            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }
    }

    $date = date_create($value);
    if ($date !== false) {
        return $date->format('Y-m-d');
    }

    return null;
}
