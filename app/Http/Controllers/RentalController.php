<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Room;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class RentalController extends Controller
{
    public function dashboard(): View
    {
        $user = request()->user();
        if ($user->role === 'tenant') {
            return $this->tenantHome();
        }
        $invoices = Invoice::with('contract.tenant', 'contract.room')->where('status', 'pending')->orderBy('due_at')->take(6)->get();

        return view('rental.dashboard', ['stats' => [
            'income' => Payment::where('status', 'approved')->whereMonth('paid_at', now()->month)->whereYear('paid_at', now()->year)->sum('amount'),
            'debt' => Invoice::where('status', 'pending')->sum('amount'), 'rooms' => Room::count(),
            'occupied' => Room::where('status', 'occupied')->count(), 'available' => Room::where('status', 'available')->count(),
            'tenants' => User::where('role', 'tenant')->where('active', true)->count(),
            'reviews' => Payment::where('status', 'pending')->count()], 'invoices' => $invoices,
            'recent' => Payment::with('invoice.contract.tenant')->latest()->take(5)->get()]);
    }

    private function tenantHome(): View
    {
        $ids = request()->user()->contracts()->pluck('id');
        $invoices = Invoice::with('contract.room', 'payments')->whereIn('contract_id', $ids)->latest()->get();

        return view('rental.tenant', ['invoices' => $invoices, 'debt' => $invoices->where('status', 'pending')->sum(fn ($i) => $i->total), 'payments' => Payment::with('invoice')->whereHas('invoice', fn ($q) => $q->whereIn('contract_id', $ids))->latest()->take(8)->get()]);
    }

    public function rooms(): View
    {
        return view('rental.rooms', ['rooms' => Room::withCount('contracts')->latest()->get(), 'edit' => request('edit') ? Room::findOrFail(request('edit')) : null]);
    }

    public function saveRoom(Request $r, ?Room $room = null): RedirectResponse
    {
        $data = $r->validate(['number' => ['required', 'max:40', Rule::unique('rooms', 'number')->ignore($room?->id)], 'description' => 'nullable|max:255', 'rent' => 'required|numeric|min:0', 'status' => 'required|in:available,occupied,maintenance']);
        abort_if($room && $data['status'] === 'available' && $room->contracts()->where('status', 'active')->exists(), 422, 'La habitación tiene un contrato activo y no se puede marcar como disponible.');
        $room?->update($data) ?? Room::create($data);

        return back()->with('success', $room ? 'Habitación actualizada.' : 'Habitación registrada.');
    }

    public function deleteRoom(Room $room): RedirectResponse
    {
        abort_if($room->contracts()->exists(), 422, 'No se puede eliminar: la habitación tiene contratos asociados.');
        $room->delete();

        return back()->with('success', 'Habitación eliminada.');
    }

    public function tenants(): View
    {
        return view('rental.tenants', ['tenants' => User::where('role', 'tenant')->withCount('contracts')->latest()->get(), 'edit' => request('edit') ? User::where('role', 'tenant')->findOrFail(request('edit')) : null]);
    }

    public function saveTenant(Request $r, ?User $tenant = null): RedirectResponse
    {
        $data = $r->validate(['name' => 'required|max:160', 'dni' => ['required', 'digits:8', Rule::unique('users', 'dni')->ignore($tenant?->id)], 'phone' => 'nullable|max:30', 'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($tenant?->id)], 'password' => [$tenant ? 'nullable' : 'required', 'min:8']]);
        $data['role'] = 'tenant';
        $data['active'] = true;
        if (empty($data['password'])) {
            unset($data['password']);
        }$tenant?->update($data) ?? User::create($data);

        return redirect()->route('tenants')->with('success', $tenant ? 'Inquilino actualizado.' : 'Inquilino registrado.');
    }

    public function deleteTenant(User $tenant): RedirectResponse
    {
        abort_unless($tenant->role === 'tenant', 404);
        abort_if($tenant->contracts()->whereHas('invoices', fn ($q) => $q->where('status', 'pending'))->exists(), 422, 'No se puede eliminar: el inquilino tiene facturas pendientes.');
        $tenant->update(['active' => false]);

        return back()->with('success', 'Acceso del inquilino desactivado.');
    }

    public function contracts(): View
    {
        return view('rental.contracts', ['contracts' => Contract::with('tenant', 'room')->latest()->get(), 'tenants' => User::where('role', 'tenant')->where(fn ($q) => $q->where('active', true)->when(request('edit'), fn ($q) => $q->orWhereKey(Contract::findOrFail(request('edit'))->user_id)))->orderBy('name')->get(), 'rooms' => Room::where('status', 'available')->orderBy('number')->get(), 'edit' => request('edit') ? Contract::findOrFail(request('edit')) : null]);
    }

    public function saveContract(Request $r, ?Contract $contract = null): RedirectResponse
    {
        $data = $r->validate(['user_id' => 'required|exists:users,id', 'room_id' => 'required|exists:rooms,id', 'starts_at' => 'required|date', 'ends_at' => 'nullable|date|after_or_equal:starts_at', 'rent' => 'required|numeric|min:0', 'deposit' => 'nullable|numeric|min:0', 'due_day' => 'required|integer|between:1,28', 'status' => 'required|in:active,ended']);
        abort_unless(User::whereKey($data['user_id'])->where('role', 'tenant')->exists(), 422);
        $oldRoom = $contract?->room;
        $room = Room::findOrFail($data['room_id']);
        abort_if($data['status'] === 'active' && $room->status === 'occupied' && $contract?->room_id !== $room->id, 422, 'La habitación ya está ocupada.');
        $contract?->update($data) ?? Contract::create($data);
        if ($oldRoom && $oldRoom->id !== $room->id) {
            $this->syncRoom($oldRoom->fresh());
        }$this->syncRoom($room->fresh());

        return redirect()->route('contracts')->with('success', 'Contrato guardado.');
    }

    public function deleteContract(Contract $contract): RedirectResponse
    {
        abort_if($contract->invoices()->where('status', 'pending')->exists(), 422, 'No se puede eliminar un contrato con facturas pendientes.');
        $room = $contract->room;
        $contract->delete();
        $this->syncRoom($room->fresh());

        return back()->with('success', 'Contrato eliminado.');
    }

    private function syncRoom(Room $room): void
    {
        $occupied = $room->contracts()->where('status', 'active')->exists();
        if ($room->status !== 'maintenance') {
            $room->update(['status' => $occupied ? 'occupied' : 'available']);
        }
    }

    public function invoices(): View
    {
        return view('rental.invoices', ['invoices' => Invoice::with('contract.tenant', 'contract.room')->latest()->paginate(12), 'contracts' => Contract::with('tenant', 'room')->where('status', 'active')->get(), 'edit' => request('edit') ? Invoice::findOrFail(request('edit')) : null]);
    }

    public function saveInvoice(Request $r, ?Invoice $invoice = null): RedirectResponse
    {
        $data = $r->validate(['contract_id' => 'required|exists:contracts,id', 'type' => 'required|in:rent,electricity,water', 'period' => ['required', 'date_format:Y-m', Rule::unique('invoices')->where(fn ($q) => $q->where('contract_id', $r->contract_id)->where('type', $r->type))->ignore($invoice?->id)], 'due_at' => 'required|date', 'amount' => 'nullable|numeric|min:0', 'previous_reading' => 'nullable|numeric|min:0', 'current_reading' => 'nullable|numeric|min:0', 'rate' => 'nullable|numeric|min:0']);
        $contract = Contract::findOrFail($data['contract_id']);
        if ($invoice) {
            abort_unless($invoice->status === 'pending', 422, 'Solo se editan facturas pendientes.');
        }if ($data['type'] === 'rent') {
            $amount = $data['amount'] ?? $contract->rent;
            $consumption = null;
        } else {
            $previous = $data['previous_reading'] ?? 0;
            $current = $data['current_reading'] ?? 0;
            abort_if($current < $previous, 422, 'La lectura actual no puede ser menor a la anterior.');
            $amount = round(($current - $previous) * ($data['rate'] ?? 0), 2);
            $consumption = $current - $previous;
        }
        $attrs = ['contract_id' => $contract->id, 'type' => $data['type'], 'period' => $data['period'], 'due_at' => $data['due_at'], 'amount' => $amount, 'previous_reading' => $data['previous_reading'] ?? null, 'current_reading' => $data['current_reading'] ?? null, 'rate' => $data['rate'] ?? null, 'consumption' => $consumption, 'status' => 'pending'];
        $invoice?->update($attrs) ?? Invoice::create($attrs);

        return redirect()->route('invoices')->with('success', 'Factura generada por S/ '.number_format($amount, 2).'.');
    }

    public function voidInvoice(Invoice $invoice): RedirectResponse
    {
        abort_if($invoice->payments()->where('status', 'approved')->exists(), 422, 'No se puede anular una factura con un pago aprobado.');
        abort_if($invoice->status === 'paid', 422, 'La factura ya está pagada.');
        $invoice->update(['status' => 'void']);

        return back()->with('success', 'Factura anulada.');
    }

    public function payments(): View
    {
        return view('rental.payments', ['payments' => Payment::with('invoice.contract.tenant', 'invoice.contract.room')->latest()->paginate(15)]);
    }

    public function uploadPayment(Request $r, Invoice $invoice): RedirectResponse
    {
        abort_unless($invoice->contract->user_id === $r->user()->id, 403);
        abort_unless($invoice->status === 'pending', 422, 'Esta factura no está pendiente.');
        $data = $r->validate(['amount' => 'required|numeric|min:0.01', 'paid_at' => 'required|date', 'voucher' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120']);
        $path = $data['voucher']->store('vouchers', 'private');
        Payment::create(['invoice_id' => $invoice->id, 'amount' => $data['amount'], 'paid_at' => $data['paid_at'], 'voucher_path' => $path, 'status' => 'pending']);

        return back()->with('success', 'Comprobante enviado para revisión.');
    }

    public function reviewPayment(Request $r, Payment $payment): RedirectResponse
    {
        abort_unless($payment->status === 'pending', 422, 'El comprobante ya fue revisado.');
        $data = $r->validate(['decision' => 'required|in:approve,reject', 'reason' => 'required_if:decision,reject|nullable|string|max:1000']);
        DB::transaction(function () use ($r, $payment, $data) {
            $lockedPayment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            abort_unless($lockedPayment->status === 'pending', 422, 'El comprobante ya fue revisado.');
            $invoice = $lockedPayment->invoice()->lockForUpdate()->firstOrFail();
            if ($data['decision'] === 'approve') {
                if ($invoice->status !== 'pending') {
                    throw ValidationException::withMessages(['decision' => 'La factura ya no está pendiente.']);
                }$dueAt = Carbon::parse($invoice->due_at)->startOfDay();
                $paidAt = Carbon::parse($lockedPayment->paid_at)->startOfDay();
                $days = max(0, $dueAt->diffInDays($paidAt, false));
                $expected = (float) $invoice->amount + (float) $invoice->late_fee + min(config('rental.late_fee_max_days'), $days) * config('rental.late_fee_per_day');
                if ((float) $lockedPayment->amount + 0.001 < $expected) {
                    throw ValidationException::withMessages(['decision' => 'El monto no cubre la factura y la mora correspondiente a la fecha de pago.']);
                }
            }$lockedPayment->update(['status' => $data['decision'] === 'approve' ? 'approved' : 'rejected', 'rejection_reason' => $data['decision'] === 'reject' ? $data['reason'] : null, 'reviewed_by' => $r->user()->id]);
            if ($data['decision'] === 'approve') {
                $invoice->update(['status' => 'paid', 'paid_at' => $lockedPayment->paid_at]);
            }
        });

        return back()->with('success', $data['decision'] === 'approve' ? 'Pago aprobado y factura saldada.' : 'Comprobante rechazado.');
    }

    public function voucher(Payment $payment): Response
    {
        $tenant = request()->user()->role === 'tenant';
        abort_if($tenant && $payment->invoice->contract->user_id !== request()->user()->id, 403);
        abort_unless(Storage::disk('private')->exists($payment->voucher_path), 404);

        return Storage::disk('private')->response($payment->voucher_path)->header('X-Content-Type-Options', 'nosniff');
    }

    public function receipt(Payment $payment): View
    {
        abort_unless($payment->status === 'approved', 404);
        abort_if(request()->user()->role === 'tenant' && $payment->invoice->contract->user_id !== request()->user()->id, 403);

        return view('rental.receipt', ['payment' => $payment->load('invoice.contract.tenant', 'invoice.contract.room')]);
    }

    public function reports(Request $r): View
    {
        $month = $r->input('month', now()->format('Y-m'));
        $from = $month.'-01';
        $to = date('Y-m-t', strtotime($from));
        $income = Payment::with('invoice.contract.tenant')->where('status', 'approved')->whereBetween('paid_at', [$from, $to])->get();
        $overdue = Invoice::with('contract.tenant', 'contract.room')->where('status', 'pending')->whereDate('due_at', '<', now()->toDateString())->orderBy('due_at')->get();
        $series = collect(range(5, 0))->map(function ($n) {
            $d = now()->startOfMonth()->subMonths($n);

            return ['label' => $d->translatedFormat('M'), 'total' => Payment::where('status', 'approved')->whereMonth('paid_at', $d->month)->whereYear('paid_at', $d->year)->sum('amount')];
        });

        return view('rental.reports', compact('month', 'income', 'overdue', 'series'));
    }
}
