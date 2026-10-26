<?php
/**
 * Generates the frozen Hanzi-to-initial dictionary used only by the member search migration.
 * Runtime PHP/MySQL needs no intl extension: every customer instance imports the committed SQL.
 */
declare(strict_types=1);

if (!class_exists(Transliterator::class) || !class_exists(IntlChar::class)) {
    fwrite(STDERR, "PHP intl is required to regenerate the dictionary.\n");
    exit(1);
}

$transliterator = Transliterator::create('Han-Latin; Latin-ASCII; Upper()');
if (!$transliterator) {
    fwrite(STDERR, "ICU Han-Latin transliterator is unavailable.\n");
    exit(1);
}

$output = __DIR__ . '/../后端代码/database/upgrades/2026-10-06-会员姓名首字母检索/01-汉字首字母映射.sql';
$handle = fopen($output, 'wb');
if ($handle === false) {
    throw new RuntimeException('Cannot open generated migration output.');
}
fwrite($handle, "-- Frozen ICU " . INTL_ICU_VERSION . " Han-Latin initials; regenerate only as a reviewed migration change.\n");
fwrite($handle, "SET NAMES utf8mb4;\n");
fwrite($handle, "CREATE TABLE IF NOT EXISTS eb_member_pinyin_char_initial (\n");
fwrite($handle, "  hanzi VARCHAR(4) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL PRIMARY KEY,\n");
fwrite($handle, "  initial CHAR(1) CHARACTER SET ascii COLLATE ascii_bin NOT NULL\n");
fwrite($handle, ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n");
fwrite($handle, "START TRANSACTION;\n");

$values = [];
$count = 0;
$flush = static function () use (&$values, $handle): void {
    if (!$values) {
        return;
    }
    fwrite($handle, "INSERT INTO eb_member_pinyin_char_initial(hanzi,initial) VALUES\n  ");
    fwrite($handle, implode(",\n  ", $values));
    fwrite($handle, "\nON DUPLICATE KEY UPDATE initial=VALUES(initial);\n");
    $values = [];
};

// Unicode CJK Unified Ideographs + Extension A cover current names and future common surnames.
foreach ([[0x3400, 0x4DBF], [0x4E00, 0x9FFF]] as [$start, $end]) {
    for ($codepoint = $start; $codepoint <= $end; $codepoint++) {
        $hanzi = IntlChar::chr($codepoint);
        $latin = $transliterator->transliterate($hanzi);
        if (!preg_match('/^[A-Z]/', $latin, $match)) {
            continue;
        }
        $values[] = "('{$hanzi}','{$match[0]}')";
        $count++;
        if (count($values) === 500) {
            $flush();
        }
    }
}
$flush();
fwrite($handle, "COMMIT;\n");
fclose($handle);
fwrite(STDOUT, "Generated {$count} Hanzi initials in {$output}\n");
