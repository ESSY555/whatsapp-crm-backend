<?php

namespace Tests\Feature\Api\V1;

use App\Models\Customer;
use App\Models\CustomerGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class CustomerImportTest extends TestCase
{
    use RefreshDatabase, CreatesTenants;

    private function csv(string $contents, string $name = 'customers.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $contents);
    }

    public function test_csv_import_creates_customers_with_groups_and_reports_bad_rows(): void
    {
        [$user, $business] = $this->membership();
        $this->asTenant($business->id, fn () => CustomerGroup::create(['name' => 'VIP']));

        $csv = implode("\n", [
            'Name,Company,Phone,Email,Address,Category,Notes,Groups',
            'Amaka Obi,Bright Foods Ltd,+234 803 221 0091,amaka@example.com,"12 Market Road, Lagos",Distributor,Pays on Fridays,VIP; Wholesale',
            'Tunde Bakare,,+234 706 884 2210,,,retailer,,',
            ',Missing Name Ltd,+234 700 000 0000,,,,,',
            'Bad Email,,+234 700 000 0001,not-an-email,,,,',
            'Dup Phone,,+234 803 221 0091,,,,,',
            '',
        ]);

        $response = $this->actingAs($user)->post('/api/v1/customers/import', ['file' => $this->csv($csv)], ['Accept' => 'application/json'])
            ->assertOk()->json('data');

        $this->assertSame(['total' => 5, 'imported' => 2, 'updated' => 0, 'skipped' => 1, 'failed' => 2], array_diff_key($response, ['errors' => true]));
        $this->assertCount(3, $response['errors']);
        $this->assertSame(4, $response['errors'][0]['row']);

        $amaka = Customer::withoutGlobalScopes()->where('business_id', $business->id)->where('name', 'Amaka Obi')->firstOrFail();
        $this->assertSame('Bright Foods Ltd', $amaka->business_name);
        $this->assertSame('12 Market Road, Lagos', $amaka->address);
        $this->assertSame('distributor', $amaka->category);
        $this->assertSame('2348032210091', $amaka->phone_normalized);
        $this->assertEqualsCanonicalizing(['VIP', 'Wholesale'], $this->asTenant($business->id, fn () => $amaka->groups()->pluck('name')->all()));
        $this->assertSame(2, CustomerGroup::withoutGlobalScopes()->where('business_id', $business->id)->count());
        $this->assertDatabaseHas('audit_logs', ['business_id' => $business->id, 'action' => 'customer.imported']);
    }

    public function test_duplicates_are_skipped_by_default_or_updated_on_request(): void
    {
        [$user, $business] = $this->membership();
        $existing = $this->customerFor($business, ['name' => 'Old Name', 'phone' => '+234 803 000 0009', 'email' => 'old@example.com']);
        $csv = "name,phone,business_name\nNew Name,+2348030000009,Renamed Ltd\n";

        $skip = $this->actingAs($user)->post('/api/v1/customers/import', ['file' => $this->csv($csv)], ['Accept' => 'application/json'])->assertOk()->json('data');
        $this->assertSame(1, $skip['skipped']);
        $this->assertDatabaseHas('customers', ['id' => $existing->id, 'name' => 'Old Name']);

        $update = $this->actingAs($user)->post('/api/v1/customers/import', ['file' => $this->csv($csv), 'duplicates' => 'update'], ['Accept' => 'application/json'])->assertOk()->json('data');
        $this->assertSame(1, $update['updated']);
        $this->assertDatabaseHas('customers', ['id' => $existing->id, 'name' => 'New Name', 'business_name' => 'Renamed Ltd', 'email' => 'old@example.com']);
        $this->assertSame(1, Customer::withoutGlobalScopes()->where('business_id', $business->id)->count());
    }

    public function test_import_is_tenant_scoped_and_validates_the_upload(): void
    {
        [$userA, $businessA] = $this->membership('A');
        [$userB, $businessB] = $this->membership('B');
        $foreignGroup = $this->asTenant($businessB->id, fn () => CustomerGroup::create(['name' => 'Theirs']));
        $ownGroup = $this->asTenant($businessA->id, fn () => CustomerGroup::create(['name' => 'Ours']));

        $this->actingAs($userA)->post('/api/v1/customers/import', [], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->actingAs($userA)->post('/api/v1/customers/import', ['file' => $this->csv("phone\n123\n")], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->actingAs($userA)->post('/api/v1/customers/import', ['file' => UploadedFile::fake()->createWithContent('report.pdf', '%PDF-1.4 not a csv')], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->actingAs($userA)->post('/api/v1/customers/import', ['file' => $this->csv("name\nX\n"), 'group_ids' => [$foreignGroup->id]], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('group_ids.0');

        $this->actingAs($userA)->post('/api/v1/customers/import', ['file' => $this->csv("name;phone\nSemi Colon;+1 555 0100\n"), 'group_ids' => [$ownGroup->id]], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.imported', 1);

        $created = Customer::withoutGlobalScopes()->where('name', 'Semi Colon')->firstOrFail();
        $this->assertSame($businessA->id, $created->business_id);
        $this->assertSame(0, Customer::withoutGlobalScopes()->where('business_id', $businessB->id)->count());
        $this->assertTrue($this->asTenant($businessA->id, fn () => $created->groups()->where('customer_groups.id', $ownGroup->id)->exists()));
        $this->actingAs($userB)->getJson('/api/v1/customers')->assertOk()->assertJsonCount(0, 'data.data');
    }

    public function test_template_download(): void
    {
        [$user] = $this->membership();

        $response = $this->actingAs($user)->get('/api/v1/customers/import/template')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringStartsWith('name,business_name,phone,email,address,category,notes,groups', $response->getContent());
    }
}
