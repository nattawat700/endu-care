<?php

namespace App\Http\Controllers;

use App\Enums\BranchPermission;
use App\Models\Branch;
use App\Models\CashShift;
use App\Models\CatalogUnit;
use App\Models\Customer;
use App\Models\PosSale;
use App\Support\DocumentPdf;
use App\Support\DocumentPrintHistory;
use App\Support\PosCart;
use App\Support\PosDiscount;
use App\Support\PosStock;
use App\Support\QuotationAmounts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PosController extends Controller
{
    private const PRIVATE_HEADERS = ['Cache-Control' => 'private, no-store'];

    public function index(Request $request, Branch $branch): Response
    {
        return $request->has('status') ? $this->history($request, $branch) : $this->form($request, $branch);
    }

    public function history(Request $request, Branch $branch): Response
    {
        Gate::authorize('permission', [$branch, BranchPermission::UsePos]);
        $actor = $request->user();
        $shift = CashShift::active($branch, $actor);
        $status = $request->query('status');
        $status = is_string($status) && isset(PosSale::STATUSES[$status]) ? $status : 'paid';
        $sales = $branch->posSales()->where('status', $status)
            ->when($actor->cannot('permission', [$branch, BranchPermission::ManagePos]), fn ($query) => $query->where(fn ($owned) => $owned
                ->where('created_by', $actor->id)->orWhere('salesperson_id', $actor->id)->orWhere('paid_by', $actor->id)
                ->when($shift, fn ($counter) => $counter->orWhere('branch_counter_id', $shift->branch_counter_id))))
            ->with(['counter:id,name', 'salesperson:id,name', 'approval'])->latest('id')->cursorPaginate(20)->withQueryString();

        return response()->view('pos.index', compact('branch', 'status', 'sales', 'shift'), headers: self::PRIVATE_HEADERS);
    }

    public function features(Branch $branch): Response
    {
        Gate::authorize('permission', [$branch, BranchPermission::UsePos]);
        $features = File::json(resource_path('data/phase-one.json'))['POS'];

        return response()->view('pos.features', compact('branch', 'features'), headers: self::PRIVATE_HEADERS);
    }

    public function products(Request $request, Branch $branch): JsonResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::UsePos]);
        $fields = $request->validate([
            'q' => ['nullable', 'string', 'max:140'],
            'after' => ['nullable', 'integer', 'min:0'],
        ]);
        $canSetGroup = $request->user()->can('permission', [$branch, BranchPermission::SetPosPriceGroup]);
        $data = isset($fields['q'])
            ? PosCart::lookup($branch, $fields['q'], $canSetGroup)
            : PosCart::browse($branch, $canSetGroup, (int) ($fields['after'] ?? 0));

        return response()->json($data, headers: self::PRIVATE_HEADERS);
    }

    public function units(Request $request, Branch $branch, int $product): JsonResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::UsePos]);
        $fields = $request->validate(['q' => ['nullable', 'string', 'max:80']]);
        $item = $branch->catalogProducts()->where('tracking', 'serialized')->findOrFail($product);
        $units = PosCart::sellableUnits($item)->when(isset($fields['q']), fn ($query) => $query
            ->where(fn ($identifier) => $identifier->where('imei', $fields['q'])->orWhere('serial_number', strtoupper($fields['q']))))
            ->with('warehouse:id,name')->orderBy('id')->limit(25)->get();

        return response()->json($units->map(fn ($unit): array => ['id' => $unit->id, 'label' => implode(' · ', array_filter([
            $unit->imei, $unit->serial_number, $unit->warehouse->name,
        ]))]), headers: self::PRIVATE_HEADERS);
    }

    public function customers(Request $request, Branch $branch): JsonResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::UsePos]);
        $fields = $request->validate(['q' => ['required', 'string', 'min:2', 'max:150']]);
        $phone = preg_match('/^\+?[0-9][0-9 ()-]*$/D', $fields['q']);
        $customers = $branch->customers()
            ->when($phone, fn ($query) => $query->where('phone_key', Customer::phoneKey($fields['q'])),
                fn ($query) => $query->whereRaw("name_key LIKE ? ESCAPE '!'", [PosCart::pattern(Customer::nameKey($fields['q']))]))
            ->orderBy('name')->limit(15)->get(['id', 'name', 'phone_key']);

        return response()->json($customers->map(fn (Customer $customer): array => [
            'id' => $customer->id, 'label' => $customer->name.' · ••••'.substr($customer->phone_key, -4),
        ]), headers: self::PRIVATE_HEADERS);
    }

    public function form(Request $request, Branch $branch, ?PosSale $posSale = null): Response
    {
        Gate::authorize('permission', [$branch, BranchPermission::UsePos]);
        $actor = $request->user();
        $shift = CashShift::active($branch, $actor);
        $canSetGroup = $actor->can('permission', [$branch, BranchPermission::SetPosPriceGroup]);
        if ($posSale !== null) {
            $this->authorizeSale($branch, $posSale, $request, $shift);
            abort_unless($posSale->status === 'draft' && ($posSale->price_group === 'retail' || $canSetGroup), 403);
            $posSale->load(['lines', 'customer:id,name']);
        }
        $actorCanReceive = $actor->can('permission', [$branch, BranchPermission::ManagePaymentDocuments]);
        $counters = $branch->counters()
            ->when($posSale !== null, fn ($query) => $query->whereKey($posSale->branch_counter_id))
            ->when($posSale === null && $shift, fn ($query) => $query->whereKey($shift->branch_counter_id))
            ->orderBy('name')->get();
        $staff = $actorCanReceive ? $this->staff($branch) : collect();
        $canSetCredit = $actorCanReceive;
        $products = PosCart::browse($branch, $canSetGroup);
        $submissionId = (string) Str::uuid();
        $savedLines = $posSale?->lines->map(fn ($line) => [
            'product_id' => $line->catalog_product_id, 'barcode' => $line->barcode, 'unit_id' => $line->catalog_unit_id,
            'quantity' => $line->quantity, 'discount' => number_format($line->discount_satang / 100, 2, '.', ''),
        ])->all() ?? [];
        $inputLines = old('items', $savedLines);
        $lines = $this->formLines($branch, is_array($inputLines) ? array_slice($inputLines, 0, 40) : [], $posSale, $canSetGroup);

        return response()->view('pos.form', compact('branch', 'posSale', 'counters', 'staff', 'shift', 'canSetGroup', 'canSetCredit', 'submissionId', 'lines', 'products'), headers: self::PRIVATE_HEADERS);
    }

    public function save(Request $request, Branch $branch, ?PosSale $posSale = null): RedirectResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::UsePos]);
        $fields = $request->validate([
            'intent' => ['nullable', Rule::in(['hold', 'checkout'])],
            'submission_id' => [$posSale ? 'nullable' : 'required', 'uuid'],
            'version' => [$posSale ? 'required' : 'nullable', 'integer', 'min:1'],
            'counter_id' => ['required', 'integer', 'min:1'],
            'salesperson_id' => ['nullable', 'integer', 'min:1'],
            'price_group' => ['required', Rule::in(array_keys(PosCart::GROUPS))],
            'customer_mode' => ['required', Rule::in(['walkin', 'existing', 'new'])],
            'customer_id' => ['exclude_unless:customer_mode,existing', 'required', 'integer', 'min:1'],
            'new_customer' => ['exclude_unless:customer_mode,new', 'required', 'array:name,phone,email,billing_address'],
            'new_customer.name' => ['exclude_unless:customer_mode,new', 'required', 'string', 'max:150'],
            'new_customer.phone' => ['exclude_unless:customer_mode,new', 'required', 'string', 'max:30'],
            'new_customer.email' => ['exclude_unless:customer_mode,new', 'nullable', 'email', 'max:254'],
            'new_customer.billing_address' => ['exclude_unless:customer_mode,new', 'nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'list', 'min:1', 'max:40'],
            'items.*' => ['required', 'array:product_id,barcode,unit_id,quantity,discount'],
            'items.*.product_id' => ['required', 'integer', 'min:1'],
            'items.*.barcode' => ['nullable', 'string', 'max:40'],
            'items.*.unit_id' => ['nullable', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'integer', 'between:1,10000'],
            'items.*.discount' => ['nullable', QuotationAmounts::MONEY],
            'document_discount' => ['nullable', QuotationAmounts::MONEY],
        ]);
        $sale = DB::transaction(function () use ($request, $branch, $posSale, $fields): PosSale {
            $branch = Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            PosDiscount::lockRules($branch);
            Gate::authorize('permission', [$branch, BranchPermission::UsePos]);
            $actor = $request->user();
            $shift = CashShift::active($branch, $actor);
            if ($posSale !== null) {
                $sale = $branch->posSales()->lockForUpdate()->findOrFail($posSale->id);
                $this->authorizeSale($branch, $sale, $request, $shift);
                $this->assertDraft($sale, (int) $fields['version']);
                abort_if((int) $fields['counter_id'] !== $sale->branch_counter_id, 403);
                PosCart::checkPriceGroup($sale->price_group, $branch);
            } else {
                $prior = $branch->posSales()->where('submission_id', $fields['submission_id'])->first();
                if ($prior !== null) {
                    abort_unless($prior->created_by === $actor->id, 404);

                    return $prior;
                }
                abort_if($shift && $shift->branch_counter_id !== (int) $fields['counter_id'], 403);
                $sale = $branch->posSales()->make([
                    'submission_id' => $fields['submission_id'], 'created_by' => $actor->id, 'status' => 'draft',
                    'branch_counter_id' => $branch->counters()->findOrFail($fields['counter_id'])->id,
                ]);
            }

            PosCart::checkPriceGroup($fields['price_group'], $branch);
            $salesperson = PosCart::salesperson($branch, $actor, isset($fields['salesperson_id']) ? (int) $fields['salesperson_id'] : null, $sale->exists ? $sale : null);
            if ($salesperson !== ($sale->salesperson_id ?? $actor->id)) {
                Gate::authorize('permission', [$branch, BranchPermission::ManagePaymentDocuments]);
            }
            [$lines, $totals] = PosCart::calculate($branch, $fields);
            $customer = PosCart::customer($branch, $fields);
            $this->recordCredit($sale, $salesperson, $actor->id, 'แก้ไขผู้รับเครดิตขณะพักบิล');
            PosDiscount::withdraw($sale);
            $sale->fill($totals + [
                'salesperson_id' => $salesperson, 'version' => $sale->exists ? $sale->version + 1 : 1, 'approval_id' => null,
                'customer_id' => $customer?->id, 'buyer' => PosCart::buyer($customer),
            ])->save();
            $sale->lines()->delete();
            $sale->lines()->createMany($lines);

            return $sale;
        }, 3);

        return match ($sale->status === 'draft' ? ($fields['intent'] ?? null) : null) {
            'hold' => redirect()->route('pos.history', [$branch, 'status' => 'draft'])->with('status', 'พักบิล '.$sale->reference().' แล้ว'),
            'checkout' => redirect()->route('pos.show', [$branch, $sale, 'focus' => 'payment']),
            default => redirect()->route('pos.show', [$branch, $sale])->with('status', 'บันทึกตะกร้าไว้ที่เคาน์เตอร์แล้ว'),
        };
    }

    public function show(Request $request, Branch $branch, PosSale $posSale): Response
    {
        $shift = CashShift::active($branch, $request->user());
        $this->authorizeSale($branch, $posSale, $request, $shift);
        $sale = $posSale->load(['counter', 'lines', 'salesperson:id,name', 'approval', 'tenders', 'creditChanges.previous:id,name', 'creditChanges.next:id,name', 'creditChanges.actor:id,name']);
        $staff = $request->user()->can('permission', [$branch, BranchPermission::ManagePos]) ? $this->staff($branch) : collect();
        $canCheckout = PosDiscount::canCheckout($branch, $sale);

        return response()->view('pos.show', compact('branch', 'sale', 'shift', 'staff', 'canCheckout'), headers: self::PRIVATE_HEADERS);
    }

    public function checkout(Request $request, Branch $branch, PosSale $posSale): RedirectResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManagePaymentDocuments]);
        $fields = $request->validate(['version' => ['required', 'integer', 'min:1'], 'tendered' => ['required', QuotationAmounts::MONEY]]);
        $this->locked($request, $branch, $posSale, function (Branch $branch, PosSale $sale, ?CashShift $shift) use ($request, $fields): void {
            $actor = $request->user()->id;
            if ($sale->status === 'paid' && $sale->paid_by === $actor) {
                return;
            }
            $this->assertDraft($sale, (int) $fields['version']);
            if ($shift === null || $shift->branch_counter_id !== $sale->branch_counter_id) {
                PosCart::invalid('tendered', 'เปิดกะที่เคาน์เตอร์ของบิลก่อนรับเงิน');
            }
            if (! PosDiscount::canCheckout($branch, $sale)) {
                PosCart::invalid('tendered', 'ต้องได้รับอนุมัติส่วนลดของบิลฉบับนี้ก่อนรับเงิน');
            }
            $tendered = QuotationAmounts::hundredths($fields['tendered']);
            if ($tendered < $sale->total_satang || $tendered - $sale->total_satang > max(0, $shift->totals()['cash_expected'])) {
                PosCart::invalid('tendered', 'เงินรับไม่พอ หรือเงินทอนมากกว่ายอดเงินสดในลิ้นชัก');
            }
            PosStock::commit($branch, $sale, $actor);
            $sale->tenders()->create([
                'cash_shift_id' => $shift->id, 'direction' => 'received', 'amount_satang' => $sale->total_satang,
                'tendered_satang' => $tendered, 'recorded_by' => $actor,
            ]);
            $sale->update(['status' => 'paid', 'seller' => $branch->documentSeller(), 'paid_by' => $actor, 'paid_at' => now()]);
        });

        return redirect()->route('pos.show', [$branch, $posSale])->with('status', 'บันทึกรับเงินและตัดสต๊อกแล้ว');
    }

    public function requestApproval(Request $request, Branch $branch, PosSale $posSale): RedirectResponse
    {
        $fields = $request->validate(['version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:400']]);
        $this->locked($request, $branch, $posSale, function (Branch $branch, PosSale $sale) use ($fields, $request): void {
            $this->assertDraft($sale, (int) $fields['version']);
            PosDiscount::request($branch, $sale, $request->user(), $fields['reason']);
        });

        return back()->with('status', 'ส่งส่วนลดเข้าคิวอนุมัติของกิจการแล้ว');
    }

    public function void(Request $request, Branch $branch, PosSale $posSale): RedirectResponse
    {
        $fields = $request->validate(['version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:400']]);
        $this->locked($request, $branch, $posSale, function (Branch $branch, PosSale $sale, ?CashShift $shift) use ($request, $fields): void {
            if ($sale->paid_at !== null) {
                Gate::authorize('permission', [$branch, BranchPermission::ManagePos]);
            }
            if ($sale->status === 'voided') {
                return;
            }
            abort_unless($sale->version === (int) $fields['version'], 409);
            $actor = $request->user()->id;
            if ($sale->status === 'paid') {
                if ($sale->total_satang > 0 && ($shift === null || $sale->total_satang > $shift->totals()['cash_expected'])) {
                    PosCart::invalid('reason', 'เปิดกะและตรวจว่าเงินสดในลิ้นชักพอสำหรับคืนเต็มยอด');
                }
                PosStock::reverse($branch, $sale, $actor, $fields['reason']);
                if ($sale->total_satang > 0) {
                    $sale->tenders()->create([
                        'cash_shift_id' => $shift->id, 'direction' => 'returned', 'amount_satang' => $sale->total_satang, 'recorded_by' => $actor,
                    ]);
                }
            }
            PosDiscount::withdraw($sale);
            $sale->update(['status' => 'voided', 'voided_at' => now(), 'voided_by' => $actor, 'void_reason' => $fields['reason']]);
        });

        return back()->with('status', 'ยกเลิกบิลแล้ว');
    }

    public function credit(Request $request, Branch $branch, PosSale $posSale): RedirectResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManagePos]);
        $fields = $request->validate([
            'version' => ['required', 'integer', 'min:1'], 'salesperson_id' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:400'],
        ]);
        $this->locked($request, $branch, $posSale, function (Branch $branch, PosSale $sale) use ($request, $fields): void {
            abort_unless($sale->status === 'paid' && $sale->version === (int) $fields['version'], 409);
            $salesperson = PosCart::salesperson($branch, $request->user(), (int) $fields['salesperson_id'], $sale);
            $this->recordCredit($sale, $salesperson, $request->user()->id, $fields['reason']);
            if ($sale->salesperson_id !== $salesperson) {
                $sale->update(['salesperson_id' => $salesperson, 'version' => $sale->version + 1]);
            }
        });

        return back()->with('status', 'บันทึกผู้รับเครดิตการขายแล้ว');
    }

    public function receipt(Request $request, Branch $branch, PosSale $posSale, DocumentPdf $pdf): Response
    {
        $this->authorizeSale($branch, $posSale, $request, CashShift::active($branch, $request->user()));
        abort_unless($posSale->status === 'paid', 404);
        $sale = $posSale->load(['lines', 'cashier:id,name']);
        $tender = $sale->tenders()->where('direction', 'received')->firstOrFail();
        $sellerProfile = $branch->documentSeller($sale->seller);
        $canViewBuyer = $sale->paid_by === $request->user()->id || $request->user()->can('permission', [$branch, BranchPermission::PrivateCustomerDetails]);
        $data = compact('branch', 'sale', 'tender', 'sellerProfile', 'canViewBuyer');

        if ($request->query('format') === 'pdf') {
            return DocumentPrintHistory::record($pdf->render('pos.receipt', $data, $sale->reference()), $branch, 'pos_receipt', $sale->id, $sale->reference());
        }

        return response()->view('pos.receipt', $data, headers: self::PRIVATE_HEADERS);
    }

    private function locked(Request $request, Branch $branch, PosSale $sale, callable $action): void
    {
        DB::transaction(function () use ($request, $branch, $sale, $action): void {
            $branch = Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            PosDiscount::lockRules($branch);
            $sale = $branch->posSales()->lockForUpdate()->findOrFail($sale->id);
            $shift = CashShift::active($branch, $request->user());
            $this->authorizeSale($branch, $sale, $request, $shift);
            $action($branch, $sale, $shift);
        }, 3);
    }

    private function assertDraft(PosSale $sale, int $version): void
    {
        abort_unless($sale->status === 'draft' && $sale->version === $version, 409, 'บิลมีการเปลี่ยนแปลง กรุณาเปิดบิลใหม่อีกครั้ง');
    }

    private function authorizeSale(Branch $branch, PosSale $sale, Request $request, ?CashShift $shift): void
    {
        Gate::authorize('permission', [$branch, BranchPermission::UsePos]);
        $actor = $request->user();
        abort_unless($actor->can('permission', [$branch, BranchPermission::ManagePos])
            || in_array($actor->id, [$sale->created_by, $sale->salesperson_id, $sale->paid_by], true)
            || $shift?->branch_counter_id === $sale->branch_counter_id, 404);
    }

    private function staff(Branch $branch): Collection
    {
        return $branch->memberships()->where('role', 'pc')->whereHas('user', fn ($users) => $users->whereNull('suspended_at'))
            ->with('user:id,name')->orderBy('user_id')->get()->pluck('user');
    }

    private function recordCredit(PosSale $sale, int $new, int $actor, string $reason): void
    {
        if ($sale->exists && $sale->salesperson_id !== $new) {
            $sale->creditChanges()->create([
                'previous_user_id' => $sale->salesperson_id, 'next_user_id' => $new, 'changed_by' => $actor, 'reason' => $reason,
            ]);
        }
    }

    private function formLines(Branch $branch, array $items, ?PosSale $sale, bool $canSetGroup): array
    {
        $items = array_values(array_filter($items, fn ($item) => is_array($item) && is_scalar($item['product_id'] ?? null)));
        $products = $branch->catalogProducts()->whereIn('id', array_column($items, 'product_id'))->with('barcodes')->get()->keyBy('id');

        $unitIds = collect($items)->pluck('unit_id')->filter(fn ($id) => is_numeric($id))->unique();
        $units = CatalogUnit::where('branch_id', $branch->id)->whereKey($unitIds->all())->get(['id', 'catalog_product_id', 'imei', 'serial_number'])->keyBy('id');

        return collect($items)->map(function (array $item) use ($products, $units, $sale, $canSetGroup): ?array {
            $product = $products->get($item['product_id']);
            if (! $product) {
                return null;
            }
            $barcode = $product->barcodes->firstWhere('code', $item['barcode'] ?? null);
            $unitId = is_numeric($item['unit_id'] ?? null) ? (int) $item['unit_id'] : null;
            $saved = $sale?->lines->first(fn ($line) => $line->catalog_product_id === $product->id && $line->barcode === ($item['barcode'] ?? null)
                && $line->catalog_unit_id === $unitId);
            $unit = $unitId === null ? null : $units->get($unitId);
            $unit = $unit?->catalog_product_id === $product->id ? $unit : null;
            $presentation = PosCart::presentation($product, $barcode, $canSetGroup);
            if ($saved) {
                $presentation['prices'][$sale->price_group] = $saved->unit_satang;
                $presentation['unit_name'] = $saved->unit_name;
            }
            $presentation['barcode'] = is_string($item['barcode'] ?? null) ? $item['barcode'] : null;

            return $presentation + [
                'quantity' => is_scalar($item['quantity'] ?? null) ? $item['quantity'] : 1,
                'discount' => is_scalar($item['discount'] ?? null) ? $item['discount'] : '',
                'unit_id' => $unit?->id, 'unit_label' => $unit ? implode(' · ', array_filter([$unit->imei, $unit->serial_number])) : '',
            ];
        })->filter()->values()->all();
    }
}
