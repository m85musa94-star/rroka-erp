<?php

namespace App\Imports;

use App\Support\Xlsx;

/**
 * The downloadable template of a screen: the data sheet (first, the one read on upload)
 * with dropdowns for lists, a sheet explaining every column, and the lists themselves
 * taken from the system at download time. The same layout, filled with the uploaded rows
 * and a result column, is the «file with its errors» to correct and upload again.
 */
class Template
{
    private const TYPES = [
        'text' => 'نص', 'number' => 'رقم', 'date' => 'تاريخ', 'datetime' => 'تاريخ ووقت',
        'bool' => 'نعم / لا', 'enum' => 'اختيار من قائمة', 'lookup' => 'من بيانات المنظومة',
    ];

    /**
     * @param  list<array{row: int, v: array<string, string>}>|null  $rows  rows to put back (the errors file)
     * @param  array<int, array>|null  $results  check results by row number
     */
    public static function build(string $key, ?array $rows = null, ?array $results = null): string
    {
        $def = ImportRegistry::get($key);
        $cols = Importer::columns($def);
        $rtl = app()->getLocale() === 'ar';
        $listsName = __('القوائم');

        // Lists: one column per dropdown, its header the column's name.
        $lists = [];
        $listCols = [];
        foreach ($cols as $i => $c) {
            $values = self::listValues($c);
            if ($values) {
                $listCols[$i] = count($lists);
                $lists[] = ['label' => __($c['label']), 'values' => $values];
            }
        }
        $listRows = [array_map(fn ($l) => ['v' => $l['label'], 's' => 'head'], $lists)];
        $longest = $lists ? max(array_map(fn ($l) => count($l['values']), $lists)) : 0;
        for ($r = 0; $r < $longest; $r++) {
            $listRows[] = array_map(fn ($l) => $l['values'][$r] ?? null, $lists);
        }
        $ranges = [];
        foreach ($listCols as $i => $li) {
            $letter = preg_replace('/\d+/', '', Xlsx::ref($li, 0));
            $ranges[$i] = "'".$listsName."'!\$".$letter.'$2:$'.$letter.'$'.(count($lists[$li]['values']) + 1);
        }

        // The data sheet.
        $header = array_map(fn ($c) => ['v' => __($c['label']).($c['required'] ? ' *' : ''), 's' => 'head'], $cols);
        $data = [$header];
        $widths = array_map(fn ($c) => $c['type'] === 'text' || $c['type'] === 'lookup' ? 26 : 18, $cols);
        $textCols = [];
        foreach ($cols as $i => $c) {
            if ($c['type'] !== 'number') {
                $textCols[] = $i;
            }
        }
        if ($rows !== null) {
            $header[] = ['v' => __(Importer::RESULT_LABEL), 's' => 'head'];
            $data = [$header];
            $widths[] = 60;
            foreach ($rows as $r) {
                $line = [];
                foreach ($cols as $c) {
                    $v = $r['v'][$c['field']] ?? null;
                    $line[] = $c['type'] === 'number' && is_numeric($v) ? (float) $v : $v;
                }
                $res = $results[$r['row']] ?? null;
                $line[] = $res === null ? null : ($res['ok'] ? ($res['messages'] ? implode(' | ', $res['messages']) : __('سليم'))
                    : implode(' | ', $res['messages']));
                $data[] = $line;
            }
        }

        $sheets = [
            ['name' => __('البيانات'), 'rows' => $data, 'widths' => $widths, 'freeze' => true, 'text_cols' => $textCols, 'lists' => $ranges],
            ['name' => __('الشرح'), 'rows' => self::guide($def, $cols), 'widths' => [30, 10, 18, 60, 24]],
        ];
        if ($lists) {
            $sheets[] = ['name' => $listsName, 'rows' => $listRows, 'widths' => array_fill(0, count($lists), 30)];
        }

        return Xlsx::book($sheets, $rtl);
    }

    public static function fileName(string $key, string $suffix = ''): string
    {
        return 'rroka-'.str_replace('_', '-', $key).($suffix ? '-'.$suffix : '').'-'.now()->format('Ymd').'.xlsx';
    }

    private static function listValues(array $c): array
    {
        if ($c['type'] === 'enum') {
            return array_values(array_map(fn ($l) => __($l), $c['options']));
        }
        if ($c['type'] === 'bool') {
            return [__('نعم'), __('لا')];
        }
        if ($c['type'] === 'lookup') {
            return [...array_values(array_map(fn ($l) => __($l), $c['extra'] ?? [])), ...Lookups::options($c['lookup'])];
        }

        return [];
    }

    private static function guide(array $def, array $cols): array
    {
        $rows = [
            [['v' => __('قالب استيراد: :name', ['name' => __($def['label'])]), 's' => 'title']],
            [],
            [['v' => __('الخطوات'), 's' => 'bold']],
            [__('١. املأ الورقة الأولى فقط: سطر لكل سجل. لا تغيّر عناوين الأعمدة ولا ترتيب الأوراق.')],
            [__('٢. الأعمدة المعلّمة بـ * مطلوبة. التاريخ هكذا: 2026-03-15. المبالغ أرقام بلا رمز عملة.')],
            [__('٣. القيم من بيانات المنظومة (عميل، مورد، حساب…) تُختار من القائمة المنسدلة، أو تُكتب برقمها أو رمزها أو اسمها كما هو في المنظومة.')],
            [__('٤. ارفع الملف من شاشة الاستيراد: يُفحص كل سطر أولًا بقواعد الإدخال اليدوي نفسها ولا يُحفظ شيء.')],
            [__('٥. إن ظهرت أخطاء: نزّل «الملف مع الأخطاء»، صحّح الأسطر المعلّمة، وارفعه من جديد. الاستيراد يتم للملف كله أو لا شيء.')],
            [__('٦. المستندات تُنشأ مسودات للمراجعة والاعتماد، كما في الإدخال اليدوي.')],
        ];
        if (! empty($def['lines'])) {
            $rows[] = [__('٧. المستند ذو البنود: كل بند في سطر، والأسطر التي تحمل «رقم المستند في الملف» نفسه مستند واحد؛ بيانات الرأس تُكتب في السطر الأول.')];
        }
        if (! empty($def['note'])) {
            $rows[] = [];
            $rows[] = [['v' => __('ملاحظة'), 's' => 'bold']];
            $rows[] = [['v' => __($def['note']), 's' => 'wrap']];
        }
        $rows[] = [];
        $rows[] = [['v' => __('العمود'), 's' => 'head'], ['v' => __('مطلوب'), 's' => 'head'], ['v' => __('النوع'), 's' => 'head'],
            ['v' => __('القيم المقبولة والملاحظات'), 's' => 'head'], ['v' => __('مثال'), 's' => 'head']];
        foreach ($cols as $c) {
            $accepted = match ($c['type']) {
                'enum' => implode(Importer::sep(), array_map(fn ($l) => __($l), $c['options'])),
                'bool' => __('نعم / لا'),
                'lookup' => __('من ورقة «القوائم»'),
                default => '',
            };
            $rows[] = [
                __($c['label']),
                $c['required'] ? __('نعم') : '',
                __(self::TYPES[$c['type']]),
                ['v' => trim($accepted.($c['help'] ? ($accepted ? ' — ' : '').__($c['help']) : '')), 's' => 'wrap'],
                $c['example'] ? __($c['example']) : '',
            ];
        }

        return $rows;
    }
}
