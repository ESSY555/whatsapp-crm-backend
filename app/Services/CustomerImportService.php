<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Bulk customer import from CSV. Each row is validated on its own so one bad
 * line never blocks the rest; the caller gets a per-row report back.
 */
class CustomerImportService
{
    public const MAX_ROWS = 2000;

    /** Accepted header spellings → customer attribute. */
    private const HEADER_ALIASES = [
        'name' => 'name', 'full name' => 'name', 'customer' => 'name', 'customer name' => 'name', 'contact' => 'name',
        'business_name' => 'business_name', 'business name' => 'business_name', 'company' => 'business_name', 'business' => 'business_name',
        'phone' => 'phone', 'phone number' => 'phone', 'whatsapp' => 'phone', 'mobile' => 'phone', 'telephone' => 'phone',
        'email' => 'email', 'e-mail' => 'email', 'email address' => 'email',
        'address' => 'address', 'city' => 'city', 'location' => 'address',
        'category' => 'category', 'type' => 'category',
        'notes' => 'notes', 'note' => 'notes', 'comments' => 'notes',
        'status' => 'status',
        'groups' => 'groups', 'group' => 'groups', 'tags' => 'groups',
    ];

    public const TEMPLATE_COLUMNS = ['name', 'business_name', 'phone', 'email', 'address', 'category', 'notes', 'groups'];

    /**
     * @param  string  $contents   Raw CSV text
     * @param  array   $options    ['duplicates' => 'skip'|'update', 'group_ids' => int[], 'create_groups' => bool]
     * @return array{total: int, imported: int, updated: int, skipped: int, failed: int, errors: array<int, array{row: int, message: string}>}
     */
    public function import(string $contents, array $options = []): array
    {
        $rows = $this->parse($contents);
        if ($rows === []) {
            throw ValidationException::withMessages(['file' => ['The file has no data rows. Download the template to see the expected columns.']]);
        }
        if (count($rows) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => ['A single import is limited to ' . self::MAX_ROWS . ' rows. Split the file and try again.']]);
        }

        $duplicates = $options['duplicates'] ?? 'skip';
        $defaultGroupIds = CustomerGroup::query()->whereIn('id', $options['group_ids'] ?? [])->pluck('id')->all();
        $createGroups = (bool) ($options['create_groups'] ?? true);
        $groupCache = CustomerGroup::query()->get()->keyBy(fn ($g) => mb_strtolower($g->name));

        $summary = ['total' => count($rows), 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];
        $seenPhones = [];

        foreach ($rows as $row) {
            $line = $row['_line'];
            unset($row['_line']);

            $validator = Validator::make($row, [
                'name' => ['required', 'string', 'max:255'],
                'business_name' => ['nullable', 'string', 'max:255'],
                'phone' => ['nullable', 'string', 'max:50'],
                'email' => ['nullable', 'email', 'max:255'],
                'address' => ['nullable', 'string', 'max:1000'],
                'category' => ['nullable', 'string', 'max:100'],
                'notes' => ['nullable', 'string', 'max:5000'],
                'status' => ['nullable', 'in:active,inactive'],
            ]);
            if ($validator->fails()) {
                $summary['failed']++;
                $summary['errors'][] = ['row' => $line, 'message' => $validator->errors()->first()];
                continue;
            }

            $normalizedPhone = preg_replace('/\D+/', '', (string) ($row['phone'] ?? ''));
            if ($normalizedPhone !== '' && isset($seenPhones[$normalizedPhone])) {
                $summary['skipped']++;
                $summary['errors'][] = ['row' => $line, 'message' => "Duplicate phone within the file (first seen on row {$seenPhones[$normalizedPhone]})."];
                continue;
            }
            if ($normalizedPhone !== '') {
                $seenPhones[$normalizedPhone] = $line;
            }

            $existing = $this->findExisting($normalizedPhone, $row['email'] ?? null);

            try {
                DB::transaction(function () use ($row, $existing, $duplicates, $defaultGroupIds, $createGroups, &$groupCache, &$summary, $line) {
                    $attributes = [
                        'name' => trim($row['name']),
                        'business_name' => $this->nullable($row['business_name'] ?? null),
                        'phone' => $this->nullable($row['phone'] ?? null),
                        'email' => $this->nullable($row['email'] ?? null),
                        'address' => $this->nullable($row['address'] ?? null),
                        'category' => $this->nullable(isset($row['category']) ? mb_strtolower($row['category']) : null),
                        'notes' => $this->nullable($row['notes'] ?? null),
                        'status' => $this->nullable($row['status'] ?? null) ?? 'active',
                    ];

                    if ($existing && $duplicates === 'skip') {
                        $summary['skipped']++;
                        $summary['errors'][] = ['row' => $line, 'message' => "Already exists as \"{$existing->name}\" (matched by phone/email); skipped."];
                        return;
                    }

                    $groupIds = $defaultGroupIds;
                    foreach ($this->splitGroups($row['groups'] ?? '') as $groupName) {
                        $key = mb_strtolower($groupName);
                        if (!isset($groupCache[$key])) {
                            if (!$createGroups) {
                                continue;
                            }
                            $groupCache[$key] = CustomerGroup::create(['name' => $groupName]);
                        }
                        $groupIds[] = $groupCache[$key]->id;
                    }
                    $groupIds = array_values(array_unique($groupIds));

                    if ($existing) {
                        $existing->update(array_filter($attributes, fn ($v) => $v !== null));
                        if ($groupIds) {
                            $existing->groups()->syncWithoutDetaching($this->pivot($groupIds));
                        }
                        $summary['updated']++;
                        return;
                    }

                    $customer = Customer::create($attributes);
                    if ($groupIds) {
                        $customer->groups()->attach($this->pivot($groupIds));
                    }
                    $summary['imported']++;
                });
            } catch (\Throwable $e) {
                $summary['failed']++;
                $summary['errors'][] = ['row' => $line, 'message' => 'Could not save this row: ' . $e->getMessage()];
            }
        }

        return $summary;
    }

    /**
     * CSV template with the accepted headers and one example row.
     */
    public function template(): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, self::TEMPLATE_COLUMNS);
        fputcsv($out, ['Amaka Obi', 'Bright Foods Ltd', '+234 803 221 0091', 'amaka@brightfoods.ng', '12 Market Road, Lagos', 'distributor', 'Pays on Fridays', 'Distributors; VIP']);
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /**
     * Parse CSV text into rows keyed by customer attribute. Unknown columns are ignored.
     *
     * @return array<int, array<string, string>>
     */
    public function parse(string $contents): array
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents); // strip UTF-8 BOM
        if (!mb_check_encoding($contents, 'UTF-8')) {
            $contents = mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
        }

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $contents);
        rewind($handle);

        $delimiter = $this->detectDelimiter($contents);
        $header = null;
        $rows = [];
        $line = 0;

        while (($record = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            $line++;
            if ($record === [null] || count(array_filter($record, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue; // blank line
            }

            if ($header === null) {
                $header = array_map(fn ($h) => self::HEADER_ALIASES[mb_strtolower(trim((string) $h))] ?? null, $record);
                if (!in_array('name', $header, true)) {
                    throw ValidationException::withMessages(['file' => ['The first row must be a header containing at least a "name" column.']]);
                }
                continue;
            }

            $row = ['_line' => $line];
            foreach ($header as $index => $key) {
                if ($key === null) {
                    continue;
                }
                $value = trim((string) ($record[$index] ?? ''));
                if ($key === 'city') {
                    // Fold a separate city column into the address.
                    $row['address'] = trim(($row['address'] ?? '') . ($value !== '' ? ", {$value}" : ''), ', ');
                    continue;
                }
                $row[$key] = $value;
            }
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }

    private function findExisting(string $normalizedPhone, ?string $email): ?Customer
    {
        if ($normalizedPhone !== '') {
            $byPhone = Customer::query()->where('phone_normalized', $normalizedPhone)->first();
            if ($byPhone) {
                return $byPhone;
            }
        }
        $email = trim((string) $email);
        if ($email !== '') {
            return Customer::query()->whereRaw('lower(email) = ?', [mb_strtolower($email)])->first();
        }

        return null;
    }

    private function detectDelimiter(string $contents): string
    {
        $firstLine = strtok($contents, "\n") ?: '';
        $counts = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
        arsort($counts);

        return (string) array_key_first($counts);
    }

    /** @return string[] */
    private function splitGroups(string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[;|]/', $value) ?: [])));
    }

    private function pivot(array $groupIds): array
    {
        return array_fill_keys($groupIds, ['business_id' => TenantManager::businessId()]);
    }

    private function nullable(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
