<?php
// app/Services/EndorsementPreValidator.php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class EndorsementPreValidator
{
    protected const VALID_RELATIONSHIPS = [
        'Self', 'Spouse', 'Daughter', 'Son',
        'Father', 'Mother', 'Father-in-law', 'Mother-in-law'
    ];

    protected const VALID_GRADES = ['G1', 'G2', 'G3'];

    protected const VALID_GENDERS = ['Male', 'Female', 'Other'];

    /**
     * Validate generated data against backend expectations BEFORE export
     */
    public function validateRows(array $rows, int $employerId = 18): array
    {
        $validRows = [];
        $invalidRows = [];
        $errors = [];

        foreach ($rows as $index => $row) {
            $rowErrors = $this->validateRow($row, $employerId);

            if (empty($rowErrors)) {
                $validRows[] = $row;
            } else {
                $invalidRows[] = [
                    'row_index' => $index + 2, // Excel row number (header is row 1)
                    'employee_code' => $row['Employee Code'] ?? 'Unknown',
                    'errors' => $rowErrors
                ];
                $errors = array_merge($errors, $rowErrors);
            }
        }

        Log::info('Pre-validation complete', [
            'total' => count($rows),
            'valid' => count($validRows),
            'invalid' => count($invalidRows),
            'employer_id' => $employerId
        ]);

        return [
            'valid' => $validRows,
            'invalid' => $invalidRows,
            'errors' => $errors,
            'success_rate' => count($rows) > 0
                ? round((count($validRows) / count($rows)) * 100, 2)
                : 0
        ];
    }

    /**
     * Validate single row
     */
    protected function validateRow(array $row, int $employerId): array
    {
        $errors = [];

        // Required: Employee Code (EMP##### pattern)
        $empCode = $row['Employee Code'] ?? '';
        if (empty($empCode) || !preg_match('/^EMP\d{5}$/', $empCode)) {
            $errors[] = 'Employee Code must match EMP##### pattern';
        }

        // Required for Addition: Email
        $email = $row['Employee Email'] ?? '';
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Employee Email is required and must be valid';
        }

        // Required for Addition: First Name
        if (empty($row['Employee First Name'] ?? '')) {
            $errors[] = 'Employee First Name is required';
        }

        // Relationship must be valid
        $relationship = $row['Relationship with Employee'] ?? '';
        if (!empty($relationship) && !in_array($relationship, self::VALID_RELATIONSHIPS, true)) {
            $errors[] = "Relationship '{$relationship}' not valid. Must be: " . implode(', ', self::VALID_RELATIONSHIPS);
        }

        // Grade must match salary-based logic for employer 18/5178
        if (in_array($employerId, [18, 5178])) {
            $grade = $row['Employee Grade'] ?? '';
            $salary = (int) ($row['Employee Annual Salary'] ?? 0);

            $expectedGrade = $salary <= 1100000 ? 'G1'
                : ($salary <= 2100000 ? 'G2' : 'G3');

            if (!empty($grade) && $grade !== $expectedGrade) {
                $errors[] = "Grade '{$grade}' doesn't match salary {$salary}. Expected: {$expectedGrade}";
            }
        }

        // DOB/DOJ validation
        $dob = $row['Employee DOB'] ?? '';
        $doj = $row['Employee Date of Joining'] ?? '';

        if (!empty($dob) && !empty($doj)) {
            $dobTs = strtotime($dob);
            $dojTs = strtotime($doj);
            $minDojTs = strtotime('+18 years', $dobTs);

            if ($dojTs <= $dobTs) {
                $errors[] = 'Date of Joining must be after Date of Birth';
            }
            if ($dojTs < $minDojTs) {
                $errors[] = 'Date of Joining must be at least 18 years after Date of Birth';
            }
        }

        // Gender validation
        $gender = $row['Employee Gender'] ?? '';
        if (!empty($gender) && !in_array($gender, self::VALID_GENDERS, true)) {
            $errors[] = "Gender '{$gender}' not valid. Must be: " . implode(', ', self::VALID_GENDERS);
        }

        return $errors;
    }

    /**
     * Auto-fix invalid rows
     */
    public function autoFixRows(array $rows, int $employerId = 18): array
    {
        foreach ($rows as $index => $row) {
            // Fix relationship
            $relKey = $this->findKey($row, 'Relationship with Employee');
            if ($relKey && !in_array($row[$relKey], self::VALID_RELATIONSHIPS, true)) {
                $rows[$index][$relKey] = 'Self';
            }

            // Fix grade based on salary
            if (in_array($employerId, [18, 5178])) {
                $gradeKey = $this->findKey($row, 'Employee Grade');
                $salaryKey = $this->findKey($row, 'Employee Annual Salary');
                if ($gradeKey && $salaryKey && isset($row[$salaryKey])) {
                    $salary = (int) $row[$salaryKey];
                    $rows[$index][$gradeKey] = $salary <= 1100000 ? 'G1'
                        : ($salary <= 2100000 ? 'G2' : 'G3');
                }
            }

            // Fix employee code format
            $codeKey = $this->findKey($row, 'Employee Code');
            if ($codeKey && !preg_match('/^EMP\d{5}$/', $row[$codeKey])) {
                $rows[$index][$codeKey] = 'EMP' . str_pad($index + 1, 5, '0', STR_PAD_LEFT);
            }
        }

        return $rows;
    }

    protected function findKey(array $row, string $search): ?string
    {
        foreach (array_keys($row) as $key) {
            if (stripos($key, $search) !== false) {
                return $key;
            }
        }
        return null;
    }
}
