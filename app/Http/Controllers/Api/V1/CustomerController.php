<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Customer\ImportCustomersRequest;
use App\Http\Requests\Customer\StoreCustomerRequest;
use App\Http\Requests\Customer\UpdateCustomerRequest;
use App\Models\Customer;
use App\Models\Invoice;
use App\Services\AuditLogger;
use App\Services\CustomerImportService;
use App\Services\CustomerService;
use Illuminate\Http\Request;

class CustomerController extends ApiController
{
    public function __construct(private CustomerService $customers, private AuditLogger $audit)
    {
    }

    public function index(Request $request)
    {
        $customers = Customer::query()
            ->with('groups:id,name')
            ->withCount('invoices')
            ->withSum(['invoices as outstanding_balance' => fn ($q) => $q->whereIn('status', Invoice::OPEN_STATUSES)], 'balance_due')
            ->withSum(['invoices as total_invoiced' => fn ($q) => $q->where('status', '!=', 'cancelled')], 'total')
            ->when($request->string('search')->trim()->value(), function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('business_name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('category'), fn ($query) => $query->where('category', $request->string('category')))
            ->when($request->filled('group_id'), fn ($query) => $query->whereHas('groups', fn ($g) => $g->where('customer_groups.id', $request->integer('group_id'))))
            ->when($request->boolean('with_balance'), fn ($query) => $query->whereHas('invoices', fn ($q) => $q->open()))
            ->latest('id')
            ->paginate(min($request->integer('per_page', 15), 100));

        return $this->successResponse('Customers retrieved successfully.', $customers);
    }

    public function store(StoreCustomerRequest $request)
    {
        $customer = $this->customers->create($request->validated());
        $this->audit->log('customer.created', $customer, null, null, $customer->only('name', 'phone', 'email', 'business_name'));

        return $this->successResponse('Customer created successfully.', $customer, 201);
    }

    /**
     * Bulk import from a CSV upload. Rows are validated individually and the
     * response lists what was imported, updated, skipped or rejected.
     */
    public function import(ImportCustomersRequest $request, CustomerImportService $importer)
    {
        $summary = $importer->import($request->file('file')->get(), [
            'duplicates' => $request->input('duplicates', 'skip'),
            'create_groups' => $request->boolean('create_groups', true),
            'group_ids' => $request->input('group_ids', []),
        ]);

        $this->audit->log('customer.imported', 'Customer', null, null, array_diff_key($summary, ['errors' => true]));

        return $this->successResponse(
            sprintf('%d customer(s) imported, %d updated, %d skipped, %d failed.', $summary['imported'], $summary['updated'], $summary['skipped'], $summary['failed']),
            $summary,
        );
    }

    /**
     * Blank CSV with the accepted columns and one example row.
     */
    public function importTemplate(CustomerImportService $importer)
    {
        return response($importer->template(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="crediping-customers-template.csv"',
        ]);
    }

    public function show(int $customer)
    {
        $customer = $this->customer($customer)->load([
            'groups:id,name',
            'invoices' => fn ($q) => $q->latest('id')->limit(50),
            'payments' => fn ($q) => $q->with('invoice:id,invoice_number')->latest('id')->limit(50),
        ]);

        $customer->setAttribute('stats', $this->customers->stats($customer));

        return $this->successResponse('Customer retrieved successfully.', $customer);
    }

    public function update(UpdateCustomerRequest $request, int $customer)
    {
        $customer = $this->customer($customer);
        $before = $customer->only('name', 'phone', 'email', 'business_name', 'status', 'category');
        $customer = $this->customers->update($customer, $request->validated());
        $this->audit->log('customer.updated', $customer, null, $before, $customer->only('name', 'phone', 'email', 'business_name', 'status', 'category'));

        return $this->successResponse('Customer updated successfully.', $customer);
    }

    public function destroy(int $customer)
    {
        $customer = $this->customer($customer);

        if ($customer->invoices()->open()->exists()) {
            return $this->errorResponse('This customer still has unpaid invoices and cannot be deleted.', 422);
        }

        $this->audit->log('customer.deleted', $customer, null, $customer->only('name', 'phone', 'email'));
        $customer->delete();

        return $this->successResponse('Customer deleted successfully.');
    }

    private function customer(int $id): Customer
    {
        return Customer::query()->findOrFail($id);
    }
}
