<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Employee;
use Illuminate\Support\Facades\DB;

/**
 * Bulk CSV import for the Employee Directory (PRD §9.1, §10.7).
 *
 * Import columns: name, whatsapp_number (E.164), department, location, email, is_active.
 * Department names must map to existing departments — the import fails with a report of
 * unmapped departments rather than creating ad-hoc values. The whole import is
 * transactional: it either applies completely or not at all.
 */
class EmployeeImportService
{
    /**
     * @return array{ok: bool, imported: int, errors: array<int, array{row: int, message: string}>, unmapped_departments: array<string>}
     */
    public function import(string $csv): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($csv)) ?: [];
        $header = array_shift($lines);

        if ($header === null) {
            return $this->failure(['row' => 1, 'message' => 'The file is empty.']);
        }

        $columns = array_map(fn (string $c) => strtolower(trim($c)), str_getcsv($header));

        foreach (['name', 'whatsapp_number', 'department'] as $required) {
            if (! in_array($required, $columns, true)) {
                return $this->failure(['row' => 1, 'message' => "Missing required column: {$required}."]);
            }
        }

        $errors = [];
        $unmapped = [];
        $validated = [];
        $seenNumbers = [];

        foreach ($lines as $offset => $line) {
            if (trim($line) === '') {
                continue;
            }

            $rowNo = $offset + 2; // +1 for header, +1 for 1-based
            $cells = str_getcsv($line);
            $row = [];
            foreach ($columns as $i => $col) {
                $row[$col] = trim($cells[$i] ?? '');
            }

            $name = $row['name'] ?? '';
            $rawNumber = $row['whatsapp_number'] ?? '';
            $deptName = $row['department'] ?? '';
            $normalized = Employee::normalizeNumber($rawNumber);
            $digits = ltrim($normalized, '+');

            if ($name === '') {
                $errors[] = ['row' => $rowNo, 'message' => 'Name is required.'];
            }

            if ($digits === '' || ! ctype_digit($digits) || strlen($digits) < 8 || strlen($digits) > 15) {
                $errors[] = ['row' => $rowNo, 'message' => "whatsapp_number '{$rawNumber}' is not valid E.164."];
            } elseif (isset($seenNumbers[$digits])) {
                $errors[] = ['row' => $rowNo, 'message' => "Duplicate number within the file: +{$digits}."];
            } elseif (Employee::query()->where('whatsapp_number', $normalized)->where('is_active', true)->exists()) {
                $errors[] = ['row' => $rowNo, 'message' => "An active employee already uses +{$digits}."];
            } else {
                $seenNumbers[$digits] = true;
            }

            $department = $deptName === ''
                ? null
                : Department::query()->where('name', $deptName)->first();

            if ($department === null) {
                $unmapped[$deptName] = $deptName;
                $errors[] = ['row' => $rowNo, 'message' => "Unmapped department: '{$deptName}'. Create it before importing."];
            }

            // Only stage the row for commit when nothing above failed for it.
            if ($name !== '' && $digits !== '' && ctype_digit($digits) && $department !== null) {
                $validated[] = [
                    'name' => $name,
                    'whatsapp_number' => $normalized,
                    'department_id' => $department->id,
                    'location' => ($row['location'] ?? '') ?: null,
                    'email' => ($row['email'] ?? '') ?: null,
                    'is_active' => filter_var($row['is_active'] ?? '1', FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true,
                ];
            }
        }

        if ($errors !== []) {
            return [
                'ok' => false,
                'imported' => 0,
                'errors' => $errors,
                'unmapped_departments' => array_values($unmapped),
            ];
        }

        DB::transaction(function () use ($validated) {
            foreach ($validated as $attributes) {
                Employee::create($attributes);
            }
        });

        return [
            'ok' => true,
            'imported' => count($validated),
            'errors' => [],
            'unmapped_departments' => [],
        ];
    }

    /**
     * @param  array{row: int, message: string}  $error
     * @return array{ok: bool, imported: int, errors: array<int, array{row: int, message: string}>, unmapped_departments: array<string>}
     */
    private function failure(array $error): array
    {
        return [
            'ok' => false,
            'imported' => 0,
            'errors' => [$error],
            'unmapped_departments' => [],
        ];
    }
}
