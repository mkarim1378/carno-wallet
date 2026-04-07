<?php
if (!defined('ABSPATH')) exit;

/**
 * خواننده فایل XLSX بدون dependency خارجی
 * از ZipArchive و SimpleXML داخلی PHP استفاده می‌کند.
 *
 * نکته حفظ صفر اول: اگر ستون A در اکسل به فرمت «Text» تنظیم شده باشد،
 * مقدار به عنوان shared string ذخیره می‌شود و صفر اول حفظ می‌گردد.
 */
class Carno_Wallet_XLSX_Reader {

    /**
     * @param string $file_path مسیر فایل xlsx
     * @return array ['rows' => [...]] یا ['error' => '...']
     */
    public static function read($file_path) {
        if (!class_exists('ZipArchive')) {
            return ['error' => 'zip_extension_missing'];
        }

        $zip = new ZipArchive();
        if ($zip->open($file_path) !== true) {
            return ['error' => 'cannot_open_file'];
        }

        $shared_strings = self::parse_shared_strings($zip);
        $rows           = self::parse_sheet($zip, $shared_strings);

        $zip->close();

        return ['rows' => $rows];
    }

    // ─── پارس shared strings (مقادیر متنی) ────────────────────

    private static function parse_shared_strings(ZipArchive $zip) {
        $strings = [];
        $xml_str = $zip->getFromName('xl/sharedStrings.xml');
        if (!$xml_str) return $strings;

        $xml = @simplexml_load_string($xml_str);
        if (!$xml) return $strings;

        foreach ($xml->si as $si) {
            // حالت ساده: <si><t>مقدار</t></si>
            if (isset($si->t)) {
                $strings[] = (string) $si->t;
            } else {
                // حالت rich text: <si><r><t>...</t></r><r><t>...</t></r></si>
                $text = '';
                foreach ($si->r as $r) {
                    $text .= (string) $r->t;
                }
                $strings[] = $text;
            }
        }

        return $strings;
    }

    // ─── پارس داده‌های شیت اول ─────────────────────────────────

    private static function parse_sheet(ZipArchive $zip, array $shared_strings) {
        $rows    = [];
        $xml_str = $zip->getFromName('xl/worksheets/sheet1.xml');
        if (!$xml_str) return $rows;

        $xml = @simplexml_load_string($xml_str);
        if (!$xml) return $rows;

        foreach ($xml->sheetData->row as $row_node) {
            $row_index  = (int) $row_node['r'];
            $row_values = [];

            foreach ($row_node->c as $cell) {
                $col_idx = self::col_letter_to_index((string) $cell['r']);
                $type    = (string) $cell['t'];
                $value   = isset($cell->v) ? (string) $cell->v : '';

                if ($type === 's') {
                    // shared string — مقدار متنی (صفر اول حفظ می‌شود)
                    $value = $shared_strings[(int) $value] ?? '';
                } elseif ($type === 'inlineStr') {
                    $value = isset($cell->is->t) ? (string) $cell->is->t : '';
                }
                // type خالی یا 'n' = عدد، مقدار همان value است

                $row_values[$col_idx] = $value;
            }

            if (!empty($row_values)) {
                // مرتب‌سازی بر اساس ایندکس ستون
                ksort($row_values);
                $rows[$row_index] = $row_values;
            }
        }

        return $rows;
    }

    // ─── تبدیل حرف ستون به ایندکس (A=0, B=1, AA=26, ...) ──────

    private static function col_letter_to_index($cell_ref) {
        // استخراج حروف از ref مثل "A2" -> "A"، "AB10" -> "AB"
        preg_match('/^([A-Z]+)/', strtoupper($cell_ref), $m);
        $letters = $m[1] ?? 'A';

        $index = 0;
        $len   = strlen($letters);
        for ($i = 0; $i < $len; $i++) {
            $index = $index * 26 + (ord($letters[$i]) - ord('A') + 1);
        }
        return $index - 1;
    }
}
