<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use SimpleXMLElement;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class CatalogSpreadsheet
{
    public static function read(
        UploadedFile $file,
        array $columns = CatalogProductData::COLUMNS,
        array $required = ['sku', 'name', 'kind', 'tracking', 'unit', 'retail'],
    ): array {
        $rows = strtolower($file->getClientOriginalExtension()) === 'csv' ? self::csv($file) : self::xlsx($file);
        $headerRow = array_key_first($rows);
        $header = array_map(fn ($value) => trim($value), $rows[$headerRow] ?? []);
        unset($rows[$headerRow]);
        if ($header === [] || count($header) !== count(array_unique($header))
            || array_diff($header, $columns) !== []
            || array_diff($required, $header) !== []) {
            self::invalid('หัวคอลัมน์ไม่ตรงกับแบบฟอร์มนำเข้า กรุณาดาวน์โหลดไฟล์ตัวอย่าง');
        }
        if ($rows === []) {
            self::invalid('ไม่พบข้อมูลในไฟล์');
        }

        return array_map(function (array $row) use ($header): array {
            if (count($row) > count($header)) {
                self::invalid('มีแถวที่มีจำนวนคอลัมน์เกินหัวตาราง');
            }

            return array_combine($header, array_pad($row, count($header), ''));
        }, $rows);
    }

    private static function csv(UploadedFile $file): array
    {
        $stream = fopen($file->getRealPath(), 'rb');
        $rows = [];
        try {
            $number = 0;
            while (($row = fgetcsv($stream, escape: '')) !== false) {
                $row = array_map(fn ($value) => (string) $value, $row);
                if ($number === 0) {
                    $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', $row[0]);
                }
                self::collect($rows, ++$number, $row);
            }
        } finally {
            fclose($stream);
        }

        return $rows;
    }

    private static function xlsx(UploadedFile $file): array
    {
        if (! class_exists(ZipArchive::class) || ! function_exists('simplexml_load_string')) {
            self::invalid('PHP ต้องเปิดส่วนขยาย zip และ SimpleXML เพื่ออ่าน XLSX หรือใช้ไฟล์ CSV');
        }
        $zip = new ZipArchive;
        if ($zip->open($file->getRealPath()) !== true) {
            self::invalid('อ่านไฟล์ Excel ไม่ได้');
        }
        try {
            $book = self::xml($zip, 'xl/workbook.xml');
            $links = self::xml($zip, 'xl/_rels/workbook.xml.rels');
            $sheet = $book->xpath('//*[local-name()="sheet"]')[0] ?? null;
            $linkId = isset($sheet) ? (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] : '';
            $target = '';
            foreach ($links->Relationship as $link) {
                if ((string) $link['Id'] === $linkId && (string) $link['TargetMode'] !== 'External') {
                    $target = (string) $link['Target'];
                    break;
                }
            }
            $target = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target;
            if (! preg_match('~\Axl/worksheets/[A-Za-z0-9_.-]+\.xml\z~', $target)) {
                self::invalid('ไม่พบชีตข้อมูลในไฟล์ Excel');
            }
            $strings = [];
            if ($zip->locateName('xl/sharedStrings.xml') !== false) {
                foreach (self::xml($zip, 'xl/sharedStrings.xml')->si as $item) {
                    $strings[] = self::cellText($item);
                }
            }
            $rows = [];
            foreach (self::xml($zip, $target)->sheetData->row as $row) {
                $values = [];
                foreach ($row->c as $cell) {
                    if (! preg_match('/\A([A-Z]{1,2})\d+\z/', (string) $cell['r'], $position)) {
                        self::invalid('ตำแหน่งคอลัมน์ใน Excel ไม่ถูกต้อง');
                    }
                    $index = 0;
                    foreach (str_split($position[1]) as $letter) {
                        $index = $index * 26 + ord($letter) - ord('A') + 1;
                    }
                    if ($index > 12) {
                        self::invalid('ไฟล์มีคอลัมน์เกินแบบนำเข้า');
                    }
                    if (isset($cell->f) || (string) $cell['t'] === 'e') {
                        self::invalid('กรุณาวางค่าเป็นข้อความหรือตัวเลขแทนสูตรหรือค่า Error ใน Excel');
                    }
                    $values[$index - 1] = match ((string) $cell['t']) {
                        's' => $strings[(int) $cell->v] ?? '',
                        'inlineStr' => self::cellText($cell->is),
                        default => (string) $cell->v,
                    };
                }
                $size = $values === [] ? 0 : max(array_keys($values)) + 1;
                $cells = array_replace(array_fill(0, $size, ''), $values);
                self::collect($rows, (int) $row['r'], $cells);
            }

            return $rows;
        } finally {
            $zip->close();
        }
    }

    private static function cellText(SimpleXMLElement $node): string
    {
        return implode('', array_map(strval(...), $node->xpath('./*[local-name()="t"] | ./*[local-name()="r"]/*[local-name()="t"]') ?: []));
    }

    private static function xml(ZipArchive $zip, string $path): SimpleXMLElement
    {
        $info = $zip->statName($path);
        if ($info === false || $info['size'] > 2000000) {
            self::invalid('โครงสร้าง Excel ไม่ถูกต้องหรือข้อมูลในชีตใหญ่เกินกำหนด');
        }
        $data = $zip->getFromName($path);
        if ($data === false || str_contains($data, "\0") || ! mb_check_encoding($data, 'UTF-8') || stripos($data, '<!DOCTYPE') !== false) {
            self::invalid('ไฟล์ Excel มีข้อมูลที่อ่านไม่ได้');
        }
        $xml = @simplexml_load_string($data, options: LIBXML_NONET);
        if ($xml === false) {
            self::invalid('อ่านข้อมูล Excel ไม่ได้');
        }

        return $xml;
    }

    private static function collect(array &$rows, int $number, array $cells): void
    {
        if ($cells === [] || ! array_filter($cells, fn ($value) => trim($value) !== '')) {
            return;
        }
        if (count($rows) >= 101 || count($cells) > 12 || ! mb_check_encoding($cells, 'UTF-8')) {
            self::invalid('ใช้ข้อมูล UTF-8 ไม่เกิน 100 รายการและ 12 คอลัมน์ต่อไฟล์');
        }
        $rows[$number] = $cells;
    }

    private static function invalid(string $message): never
    {
        throw ValidationException::withMessages(['sheet' => $message]);
    }

    public static function download(string $name, array $columns, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($columns, $rows): void {
            $stream = fopen('php://output', 'wb');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, $columns, escape: '');
            foreach ($rows as $row) {
                fputcsv($stream, array_map(fn ($value) => preg_match('/\A(?:[\t\r\n]|\s*[=+\-@])/', $value) ? "'".$value : $value, $row), escape: '');
            }
            fclose($stream);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
