<?php

namespace App\Services;

use App\Models\StudioAsset;
use App\Support\AuditContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Sample records for hands-on viewing. Every row is labelled "تجريبي" and listed in
 * demo_records, so purge() removes exactly this set and nothing else. Records are
 * written through the same database rules as real work (no rule is bypassed on the
 * way in). Global settings are left alone: the overhead rate is not set, so profit
 * shows "—" as it would in a real workshop that has not entered it yet.
 */
class DemoData
{
    public const TAG = 'تجريبي';

    private const NOTE = 'تجريبي — أرقام افتراضية للاطلاع فقط';

    private int $user;

    private CarbonImmutable $today;

    public function __construct(private StudioStorage $storage) {}

    public static function loaded(): bool
    {
        return DB::table('demo_records')->exists();
    }

    /** @return array<string, int> rows per table */
    public static function summary(): array
    {
        return DB::table('demo_records')->selectRaw('table_name, count(*) n')->groupBy('table_name')
            ->orderBy('table_name')->pluck('n', 'table_name')->map(fn ($n) => (int) $n)->all();
    }

    public function seed(int $userId): int
    {
        if (self::loaded()) {
            throw new RuntimeException(__('البيانات التجريبية محمّلة بالفعل.'));
        }
        $this->user = $userId;
        $this->today = CarbonImmutable::today();
        $files = [];

        try {
            DB::transaction(function () use (&$files) {
                AuditContext::apply($this->user);
                DB::statement("SELECT set_config('rroka.demo', 'on', true)");   // demo rows never post to the books
                $this->seedAll($files);
            });
        } catch (\Throwable $e) {
            // Nothing was saved: remove any image file written before the failure.
            foreach ($files as $asset) {
                $this->storage->deleteFiles($asset);
            }
            throw $e;
        }

        return DB::table('demo_records')->count();
    }

    /** Removes every demo row; refuses (and changes nothing) if real records point at one of them. */
    public function purge(int $userId): int
    {
        $rows = DB::table('demo_records')->orderByDesc('id')->get(['table_name', 'row_id']);
        if ($rows->isEmpty()) {
            return 0;
        }
        $assets = StudioAsset::whereIn('id', $rows->where('table_name', 'studio_assets')->pluck('row_id'))->get();

        try {
            DB::transaction(function () use ($rows, $userId) {
                AuditContext::apply($userId);
                $tables = $rows->pluck('table_name')->unique()->push('stock_balances')->all();
                // Posted documents, movements and history are immutable by rule; the rules are
                // lifted for this transaction only (ALTER TABLE is transactional) and only for demo rows.
                foreach ($tables as $t) {
                    DB::statement("ALTER TABLE {$t} DISABLE TRIGGER USER");
                }
                $ids = fn (string $t) => $rows->where('table_name', $t)->pluck('row_id')->all();
                DB::table('departments')->whereIn('id', $ids('departments'))->update(['manager_id' => null]);
                DB::table('workers')->whereIn('id', $ids('workers'))->update(['manager_id' => null]);
                DB::table('stock_balances')->whereIn('material_id', $ids('raw_materials'))->delete();
                foreach ($rows as $r) {
                    DB::table($r->table_name)->where('id', $r->row_id)->delete();
                }
                foreach (['EXPENSE' => 'expenses', 'PURCHASE' => 'purchase_invoices', 'TRANSFER' => 'treasury_transfers', 'STOCK' => 'stock_movements'] as $type => $table) {
                    DB::table('posting_exclusions')->where('source_type', $type)->whereIn('source_id', $ids($table))->delete();
                }
                DB::table('demo_records')->delete();
                foreach ($tables as $t) {
                    DB::statement("ALTER TABLE {$t} ENABLE TRIGGER USER");
                }
                DB::table('audit_log')->insert(['table_name' => 'demo_records', 'action' => 'DELETE', 'user_id' => $userId,
                    'old_data' => json_encode(['removed' => $rows->countBy('table_name')->all()])]);
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23503') {
                preg_match('/on table "(\w+)"/', $e->getMessage(), $m);
                throw new RuntimeException(__('لا يمكن حذف البيانات التجريبية: توجد سجلات حقيقية مرتبطة بها (:table). احذف الربط أولًا.', ['table' => $m[1] ?? '?']));
            }
            throw $e;
        }
        foreach ($assets as $asset) {
            $this->storage->deleteFiles($asset);
        }

        return $rows->count();
    }

    private function add(string $table, array $values): int
    {
        $id = DB::table($table)->insertGetId($values);
        $this->track($table, [$id]);

        return $id;
    }

    private function track(string $table, array $ids): void
    {
        DB::table('demo_records')->insert(array_map(fn ($id) => ['table_name' => $table, 'row_id' => $id], $ids));
    }

    private function day(int $offset): string
    {
        return $this->today->addDays($offset)->toDateString();
    }

    private function at(int $offset, string $time = '10:00'): string
    {
        // With the offset, so the database stores the workshop's local time whatever its own zone.
        return CarbonImmutable::parse($this->today->addDays($offset)->format('Y-m-d').' '.$time)->toIso8601String();
    }

    private function name(string $s): string
    {
        return self::TAG.' — '.$s;
    }

    private function seedAll(array &$files): void
    {
        $u = $this->user;

        // ---- Clients ------------------------------------------------------------
        $c1 = $this->add('clients', ['business_name' => $this->name('مؤسسة الواحة للمقاولات'), 'client_type' => 'COMPANY', 'phone' => '0110000001',
            'email' => 'demo.alwaha@example.com', 'city' => 'الرياض', 'address' => $this->name('حي الملقا'), 'notes' => self::NOTE, 'created_by' => $u]);
        $c2 = $this->add('clients', ['business_name' => $this->name('عبدالله السالم'), 'client_type' => 'INDIVIDUAL', 'phone' => '0500000002',
            'city' => 'الرياض', 'notes' => self::NOTE, 'created_by' => $u]);
        $c3 = $this->add('clients', ['business_name' => $this->name('نورة العتيبي'), 'client_type' => 'INDIVIDUAL', 'phone' => '0500000003',
            'city' => 'جدة', 'notes' => self::NOTE, 'created_by' => $u]);

        // ---- Studio (only when image storage is ready) ---------------------------
        $catalog = null;
        if (StudioStorage::isReady() && function_exists('imagecreatetruecolor')) {
            $img = function (string $title, string $cat, array $rgb, array $extra = []) use (&$files) {
                return $this->image($files, $title, $cat, $rgb, $extra);
            };
            $img($this->name('صورة مرجعية أرسلها العميل للمطبخ'), 'CLIENT_REFERENCE', [196, 164, 132], ['client_id' => $c1]);
            $catalog = $img($this->name('كتالوج خزانة ملابس بابين'), 'CATALOG', [120, 144, 156]);
            $img($this->name('عينة خشب ولون الدهان'), 'MATERIAL', [161, 110, 72]);
        }

        // ---- Materials, suppliers, purchase invoices ------------------------------
        $mat = fn ($code, $name, $cat, $uom) => $this->add('raw_materials', ['code' => $code, 'name' => $this->name($name), 'category' => $cat, 'uom' => $uom]);
        $mdf = $mat('DEMO-MDF18', 'لوح MDF مقاس 18 مم', 'ألواح', 'لوح');
        $hng = $mat('DEMO-HNG', 'مفصلة هيدروليك', 'إكسسوارات', 'حبة');
        $pnt = $mat('DEMO-PNT', 'دهان بولي يوريثان', 'دهانات', 'لتر');
        $edg = $mat('DEMO-EDG', 'شريط حواف PVC', 'ألواح', 'متر');

        $sup = fn ($name, $vat, $city) => $this->add('suppliers', ['name' => $this->name($name), 'vat_number' => $vat, 'phone' => '0110000010',
            'city' => $city, 'notes' => self::NOTE, 'is_active' => true, 'created_by' => $u]);
        $s1 = $sup('مؤسسة الأخشاب الحديثة', '399999999900003', 'الرياض');
        $s2 = $sup('متجر الإكسسوارات والدهانات', '399999999900013', 'الرياض');
        $s3 = $sup('ورشة النقل السريع', null, 'الرياض');

        $purchase = function (int $supplier, string $no, int $day, float $discount, float $vat, array $lines, bool $approve) use ($u) {
            $p = $this->add('purchase_invoices', ['supplier_id' => $supplier, 'supplier_invoice_no' => $no, 'invoice_date' => $this->day($day),
                'due_date' => $this->day($day + 30), 'discount_amount' => $discount, 'vat_amount' => $vat, 'notes' => self::NOTE, 'created_by' => $u]);
            $ids = [];
            foreach ($lines as $i => [$m, $q, $price]) {
                $ids[] = $this->add('purchase_invoice_lines', ['purchase_invoice_id' => $p, 'line_no' => $i + 1, 'material_id' => $m, 'quantity' => $q, 'unit_price' => $price]);
            }
            if ($approve) {
                DB::table('purchase_invoices')->where('id', $p)->update(['status' => 'APPROVED', 'approved_by' => $u, 'approved_at' => $this->at($day + 1)]);
                $this->track('stock_movements', DB::table('stock_movements')->whereIn('purchase_invoice_line_id', $ids)->pluck('id')->all());
            }
        };
        $purchase($s1, 'DEMO-1001', -35, 100, 630, [[$mdf, 40, 95], [$edg, 200, 2.5]], true);
        $purchase($s2, 'DEMO-1002', -30, 0, 315, [[$hng, 200, 6], [$pnt, 20, 45]], true);
        $purchase($s1, 'DEMO-1003', -2, 0, 291, [[$mdf, 20, 97]], false);

        // ---- HR: departments, jobs, employees, documents, contracts ----------------
        $d1 = $this->add('departments', ['name' => $this->name('الإنتاج')]);
        $d2 = $this->add('departments', ['name' => $this->name('الدهان والتشطيب'), 'parent_id' => $d1]);
        $d3 = $this->add('departments', ['name' => $this->name('الإدارة')]);
        $j1 = $this->add('job_positions', ['name' => $this->name('نجار'), 'department_id' => $d1]);
        $j2 = $this->add('job_positions', ['name' => $this->name('دهّان'), 'department_id' => $d2]);
        $j3 = $this->add('job_positions', ['name' => $this->name('مشرف ورشة'), 'department_id' => $d1]);

        $emp = fn (string $name, string $trade, int $dept, int $job, int $hired, bool $direct, ?int $manager, string $n) => $this->add('workers', [
            'name' => $this->name($name), 'trade' => $trade, 'department_id' => $dept, 'job_id' => $job, 'manager_id' => $manager,
            'mobile' => '05000000'.$n, 'nationality' => 'تجريبي', 'id_type' => 'IQAMA', 'id_number' => 'DEMO-00'.$n, 'gender' => 'M',
            'hire_date' => $this->day($hired), 'employment_type' => 'FULL_TIME', 'is_direct_labor' => $direct, 'is_active' => true, 'notes' => self::NOTE,
        ]);
        $khaled = $emp('خالد المشرف', 'إشراف', $d1, $j3, -400, false, null, '11');
        $ahmad = $emp('أحمد النجار', 'نجارة', $d1, $j1, -300, true, $khaled, '12');
        $salem = $emp('سالم الدهّان', 'دهان', $d2, $j2, -200, true, $khaled, '13');
        DB::table('departments')->where('id', $d1)->update(['manager_id' => $khaled]);

        $doc = fn ($e, $type, $n, $issued, $expires) => $this->add('employee_documents', ['employee_id' => $e, 'doc_type' => $type, 'doc_number' => $n,
            'issue_date' => $this->day($issued), 'expiry_date' => $this->day($expires), 'notes' => self::NOTE]);
        $doc($ahmad, 'IQAMA', 'DEMO-IQ-12', -340, 25); // expires soon: shows in "documents to renew"
        $doc($salem, 'IQAMA', 'DEMO-IQ-13', -65, 300);
        $doc($khaled, 'PASSPORT', 'DEMO-PP-11', -1000, 700);

        $contract = function ($e, $type, $start, $end, $basic, $housing, $transport, bool $run) use ($u) {
            $id = $this->add('employee_contracts', ['employee_id' => $e, 'contract_type' => $type, 'start_date' => $this->day($start),
                'end_date' => $end === null ? null : $this->day($end), 'basic_salary' => $basic, 'housing_allowance' => $housing,
                'transport_allowance' => $transport, 'other_allowance' => 0, 'weekly_hours' => 48, 'notes' => self::NOTE, 'created_by' => $u]);
            if ($run) {
                DB::table('employee_contracts')->where('id', $id)->update(['status' => 'RUNNING']);
            }
        };
        $contract($ahmad, 'FIXED_TERM', -300, 65, 3500, 875, 350, true);
        $contract($salem, 'INDEFINITE', -200, null, 3200, 800, 300, true);
        $contract($khaled, 'FIXED_TERM', 0, 365, 6000, 1500, 500, false);

        // Hourly costs for the demo workers and one machine only (the overhead rate stays unset).
        $this->add('worker_rates', ['worker_id' => $ahmad, 'hourly_cost' => 28.50, 'effective_from' => $this->day(-300), 'basis_note' => self::NOTE, 'entered_by' => $u]);
        $this->add('worker_rates', ['worker_id' => $salem, 'hourly_cost' => 26.00, 'effective_from' => $this->day(-200), 'basis_note' => self::NOTE, 'entered_by' => $u]);
        $cnc = $this->add('machines', ['code' => 'DEMO-CNC', 'name' => $this->name('راوتر CNC')]);
        $this->add('machines', ['code' => 'DEMO-EDGE', 'name' => $this->name('مكينة لصق الحواف')]);
        $this->add('machine_rates', ['machine_id' => $cnc, 'hourly_cost' => 45, 'effective_from' => $this->day(-300), 'basis_note' => self::NOTE, 'entered_by' => $u]);

        // ---- Quotations and projects ---------------------------------------------
        $quote = function (int $client, int $day, float $discount, array $lines, string $to) use ($u) {
            // Demo quotations carry no cost estimate (no real rates exist to build one from).
            $q = $this->add('quotations', ['client_id' => $client, 'issue_date' => $this->day($day), 'valid_until' => $this->day($day + 30),
                'discount_amount' => $discount, 'notes' => self::NOTE, 'created_by' => $u, 'requires_costing' => false]);
            foreach ($lines as $i => [$desc, $qty, $unit, $price, $asset]) {
                $this->add('quotation_lines', ['quotation_id' => $q, 'line_no' => $i + 1, 'description' => $this->name($desc), 'quantity' => $qty,
                    'unit' => $unit, 'unit_price' => $price, 'studio_asset_id' => $asset]);
            }
            if ($to !== 'DRAFT') {
                DB::table('quotations')->where('id', $q)->update(['status' => 'SENT']);
            }
            if ($to === 'APPROVED') {
                DB::table('quotations')->where('id', $q)->update(['status' => 'APPROVED', 'approved_by' => $u, 'approved_at' => $this->at($day + 4)]);
            }

            return $q;
        };
        $q1 = $quote($c1, -45, 700, [['مطبخ MDF مدهون بولي يوريثان مع رخام', 1, 'طقم', 18500, null], ['خزائن غرفة الغسيل', 1, 'طقم', 4200, null]], 'APPROVED');
        $q2 = $quote($c2, -25, 0, [['غرفة نوم رئيسية', 1, 'طقم', 12800, null], ['خزانة ملابس بابين', 2, 'حبة', 3500, $catalog?->id]], 'APPROVED');
        $quote($c3, -6, 300, [['مكتبة جدارية للمجلس', 1, 'حبة', 6500, null]], 'SENT');
        $quote($c3, -1, 0, [['طاولة طعام 8 كراسي', 1, 'حبة', 5200, null]], 'DRAFT');

        $p1 = $this->add('projects', ['quotation_id' => $q1, 'client_id' => $c1, 'contract_value' => 0, 'title' => $this->name('مطبخ فيلا الواحة'),
            'start_date' => $this->day(-38), 'target_date' => $this->day(10), 'manager_id' => $u, 'created_by' => $u]);
        $p2 = $this->add('projects', ['quotation_id' => $q2, 'client_id' => $c2, 'contract_value' => 0, 'title' => $this->name('غرفة نوم فيلا السالم'),
            'start_date' => $this->day(-18), 'target_date' => $this->day(30), 'manager_id' => $u, 'created_by' => $u]);
        DB::table('projects')->where('id', $p1)->update(['status' => 'IN_PRODUCTION']);

        // ---- Designs and bills of materials ----------------------------------------
        $design = function (int $project, string $title, array $bom, string $to, int $approvedDay) use ($u) {
            $d = $this->add('designs', ['project_id' => $project, 'title' => $this->name($title)]);
            $v = $this->add('design_versions', ['design_id' => $d, 'version_no' => 1, 'change_notes' => self::NOTE, 'created_by' => $u]);
            foreach ($bom as [$m, $q, $w]) {
                $this->add('design_bom_lines', ['design_version_id' => $v, 'material_id' => $m, 'quantity' => $q, 'waste_pct' => $w]);
            }
            $set = fn (array $x) => DB::table('design_versions')->where('id', $v)->update($x);
            $set(['status' => 'CLIENT_REVIEW']);
            if ($to === 'RELEASED_FOR_PRODUCTION') {
                $set(['status' => 'CLIENT_APPROVED', 'client_approved_at' => $this->at($approvedDay)]);
                $set(['status' => 'RELEASED_FOR_PRODUCTION', 'released_at' => $this->at($approvedDay + 1), 'released_by' => $u]);
            }

            return $v;
        };
        $kitchen = $design($p1, 'المطبخ الرئيسي', [[$mdf, 25, 10], [$hng, 40, 0], [$pnt, 8, 5], [$edg, 120, 5]], 'RELEASED_FOR_PRODUCTION', -34);
        $laundry = $design($p1, 'خزائن غرفة الغسيل', [[$mdf, 8, 10], [$hng, 12, 0], [$edg, 40, 5]], 'RELEASED_FOR_PRODUCTION', -34);
        $design($p2, 'غرفة النوم الرئيسية', [[$mdf, 18, 10], [$hng, 16, 0], [$pnt, 6, 5]], 'CLIENT_REVIEW', 0);

        // ---- Manufacturing orders -----------------------------------------------------
        $order = function (int $version, int $start, int $end) use ($p1, $u) {
            $o = $this->add('production_orders', ['project_id' => $p1, 'design_version_id' => $version, 'planned_start' => $this->day($start),
                'planned_end' => $this->day($end), 'notes' => self::NOTE, 'created_by' => $u]);
            DB::table('production_orders')->where('id', $o)->update(['status' => 'IN_PROGRESS', 'started_at' => $this->at($start, '08:00')]);

            return $o;
        };
        $move = function (int $o, int $m, string $type, float $q, int $day, bool $fromRes = false) use ($p1, $u) {
            $no = DB::table('production_orders')->where('id', $o)->value('order_no');
            $this->add('stock_movements', ['material_id' => $m, 'movement_type' => $type, 'quantity' => $q, 'project_id' => $p1, 'production_order_id' => $o,
                'from_reservation' => $fromRes, 'reference' => $no, 'moved_at' => $this->at($day), 'created_by' => $u]);
        };
        $labor = fn (int $o, int $w, int $day, float $h, string $what) => $this->add('labor_logs', ['production_order_id' => $o, 'worker_id' => $w,
            'work_date' => $this->day($day), 'hours' => $h, 'activity' => $this->name($what), 'created_by' => $u]);
        $machine = fn (int $o, int $m, int $day, float $h) => $this->add('machine_logs', ['production_order_id' => $o, 'machine_id' => $m,
            'work_date' => $this->day($day), 'hours' => $h, 'created_by' => $u]);
        $inspect = fn (int $o, string $stage, string $result, ?string $findings, int $day) => $this->add('quality_inspections', ['production_order_id' => $o,
            'stage' => $stage, 'result' => $result, 'findings' => $findings, 'inspector_id' => $u, 'inspected_at' => $this->at($day, '15:00')]);

        // Order 1 (laundry cabinets): materials reserved and issued, time logged, final QC passed, completed.
        $o1 = $order($laundry, -27, -22);
        foreach ([[$mdf, 8.8], [$hng, 12], [$edg, 42]] as [$m, $q]) {
            $move($o1, $m, 'RESERVE', $q, -27);
            $move($o1, $m, 'ISSUE', $q, -26, true);
        }
        $labor($o1, $ahmad, -26, 7, 'قص وتجميع الهياكل');
        $labor($o1, $ahmad, -25, 6, 'تركيب الأبواب والمفصلات');
        $labor($o1, $salem, -24, 5, 'تشطيب وتنظيف');
        $machine($o1, $cnc, -26, 3);
        $inspect($o1, 'IN_PROCESS', 'PASS', null, -25);
        $inspect($o1, 'FINAL', 'PASS', null, -23);
        DB::table('production_orders')->where('id', $o1)->update(['status' => 'COMPLETED', 'completed_at' => $this->at(-22, '12:00')]);

        // Order 2 (kitchen): in progress, part of the material issued, a rework finding open.
        $o2 = $order($kitchen, -12, 5);
        foreach ([[$mdf, 27.5], [$hng, 40], [$pnt, 8.4], [$edg, 126]] as [$m, $q]) {
            $move($o2, $m, 'RESERVE', $q, -12);
        }
        $move($o2, $mdf, 'ISSUE', 20, -11, true);
        $move($o2, $hng, 'ISSUE', 40, -11, true);
        $move($o2, $edg, 'ISSUE', 90, -11, true);
        $labor($o2, $ahmad, -11, 8, 'قص ألواح المطبخ على CNC');
        $labor($o2, $ahmad, -10, 8, 'تجميع الخزائن السفلية');
        $labor($o2, $salem, -9, 6, 'تحضير الأسطح للدهان');
        $machine($o2, $cnc, -11, 4);
        $inspect($o2, 'IN_PROCESS', 'REWORK', $this->name('تفاوت 3 مم في مقاس باب الخزانة العلوية — يُعاد القص'), -9);

        // ---- Expenses -------------------------------------------------------------------
        $cat = fn ($name, $overhead) => $this->add('expense_categories', ['name' => $this->name($name), 'is_overhead' => $overhead]);
        $transport = $cat('نقل وتوصيل', false);
        $rent = $cat('إيجار الورشة', true);
        $power = $cat('كهرباء وماء', true);
        // ---- Treasury: cash box, bank, a supervisor's custody --------------------------------
        $acc = fn (array $x) => $this->add('payment_accounts', $x + ['notes' => self::NOTE, 'created_by' => $u]);
        $cash = $acc(['name' => $this->name('صندوق الورشة'), 'kind' => 'CASH']);
        $bank = $acc(['name' => $this->name('الحساب البنكي الرئيسي'), 'kind' => 'BANK', 'bank_name' => $this->name('بنك افتراضي')]);
        $custody = $acc(['name' => $this->name('عهدة خالد المشرف'), 'kind' => 'CUSTODY', 'employee_id' => $khaled, 'custody_limit' => 3000]);
        $transfer = function (int $from, int $to, float $amount, int $day, string $note, bool $approve) use ($u) {
            $id = $this->add('treasury_transfers', ['transfer_date' => $this->day($day), 'from_account_id' => $from, 'to_account_id' => $to,
                'amount' => $amount, 'reference' => 'DEMO-V-'.abs($day), 'notes' => $this->name($note), 'created_by' => $u]);
            if ($approve) {
                DB::table('treasury_transfers')->where('id', $id)->update(['status' => 'APPROVED', 'approved_by' => $u, 'approved_at' => $this->at($day)]);
            }
        };
        $transfer($cash, $custody, 2000, -10, 'صرف عهدة لمشتريات الموقع الصغيرة', true);
        $transfer($cash, $bank, 5000, -1, 'إيداع نقدية الصندوق في البنك', false);

        $expense = function (int $category, int $day, string $desc, float $amount, float $vat, int $account, string $method, array $extra, bool $approve) use ($u) {
            $id = $this->add('expenses', ['expense_date' => $this->day($day), 'category_id' => $category, 'description' => $this->name($desc), 'amount' => $amount,
                'vat_amount' => $vat, 'payment_account_id' => $account, 'payment_method' => $method, 'created_by' => $u] + $extra);
            if ($approve) {
                DB::table('expenses')->where('id', $id)->update(['status' => 'APPROVED', 'approved_by' => $u, 'approved_at' => $this->at($day + 1)]);
            }
        };
        $expense($transport, -20, 'نقل ألواح من المورد إلى الورشة', 350, 52.5, $cash, 'CASH', ['supplier_id' => $s3, 'project_id' => $p1, 'reference' => 'DEMO-R-01'], true);
        $expense($rent, -29, 'إيجار الورشة لهذا الشهر', 4000, 0, $bank, 'BANK', ['payee' => $this->name('مالك المستودع'), 'reference' => 'DEMO-R-02'], true);
        $expense($transport, -6, 'أجرة نقل باب الخزانة المعاد قصه', 180, 27, $custody, 'PETTY_CASH', ['payee' => $this->name('سائق نقل'), 'project_id' => $p1, 'reference' => 'DEMO-R-03'], true);
        $expense($power, -3, 'فاتورة الكهرباء', 780, 117, $custody, 'PETTY_CASH', ['payee' => $this->name('شركة الكهرباء')], false);

        // ---- Time off -------------------------------------------------------------------
        $annual = $this->add('leave_types', ['name' => $this->name('إجازة سنوية'), 'is_paid' => true, 'requires_allocation' => true]);
        $sick = $this->add('leave_types', ['name' => $this->name('إجازة مرضية'), 'is_paid' => true, 'requires_allocation' => false]);
        foreach ([$ahmad, $salem] as $e) {
            $this->add('leave_allocations', ['employee_id' => $e, 'leave_type_id' => $annual, 'days' => 21, 'valid_from' => $this->today->startOfYear()->toDateString(),
                'valid_to' => $this->today->endOfYear()->toDateString(), 'reason' => self::NOTE, 'approved_by' => $u]);
        }
        $leave = function (int $e, int $type, int $from, int $to, string $status) use ($u) {
            $id = $this->add('leave_requests', ['employee_id' => $e, 'leave_type_id' => $type, 'date_from' => $this->day($from), 'date_to' => $this->day($to),
                'days' => $to - $from + 1, 'reason' => self::NOTE, 'created_by' => $u]);
            if ($status === 'APPROVED') {
                DB::table('leave_requests')->where('id', $id)->update(['status' => 'APPROVED', 'approved_by' => $u, 'approved_at' => $this->at($from - 3)]);
            }
        };
        $leave($salem, $annual, -16, -14, 'APPROVED');
        $leave($khaled, $sick, -5, -5, 'APPROVED');
        $leave($ahmad, $annual, 12, 15, 'SUBMITTED');

        // ---- Attendance: the last three days, and today's check-in once the day has begun ----
        foreach ([-3, -2, -1] as $day) {
            foreach ([$ahmad, $salem, $khaled] as $e) {
                $this->add('attendances', ['employee_id' => $e, 'check_in' => $this->at($day, '07:30'), 'check_out' => $this->at($day, '16:30'),
                    'notes' => self::TAG, 'created_by' => $u]);
            }
        }
        if (now()->greaterThan($this->today->setTime(7, 35))) {
            $this->add('attendances', ['employee_id' => $ahmad, 'check_in' => $this->at(0, '07:30'), 'notes' => self::TAG, 'created_by' => $u]);
        }
    }

    /** A plain illustrative picture (coloured panels), clearly titled as a demo. */
    private function image(array &$files, string $title, string $category, array $rgb, array $extra): ?StudioAsset
    {
        $im = imagecreatetruecolor(800, 600);
        [$r, $g, $b] = $rgb;
        imagefill($im, 0, 0, imagecolorallocate($im, $r, $g, $b));
        $light = imagecolorallocate($im, min(255, $r + 45), min(255, $g + 45), min(255, $b + 45));
        $dark = imagecolorallocate($im, (int) ($r * .6), (int) ($g * .6), (int) ($b * .6));
        foreach ([[60, 80, 360, 520], [440, 80, 740, 300], [440, 330, 740, 520]] as [$x1, $y1, $x2, $y2]) {
            imagefilledrectangle($im, $x1, $y1, $x2, $y2, $light);
            imagerectangle($im, $x1, $y1, $x2, $y2, $dark);
        }
        // A random pixel keeps the file unique, so it never matches a real photo already stored.
        imagesetpixel($im, random_int(0, 799), random_int(0, 599), imagecolorallocate($im, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
        $path = tempnam(sys_get_temp_dir(), 'demo').'.png';
        imagepng($im, $path);
        imagedestroy($im);

        try {
            [$asset, $existed] = $this->storage->store(new UploadedFile($path, 'demo.png', 'image/png', null, true),
                ['title' => $title, 'category' => $category, 'tags' => self::TAG, 'notes' => self::NOTE] + $extra, $this->user);
        } finally {
            @unlink($path);
        }
        if ($existed) {
            return null;
        }
        $files[] = $asset;
        $this->track('studio_assets', [$asset->id]);

        return $asset;
    }
}
