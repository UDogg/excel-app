<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use Illuminate\Support\Facades\Log;

class ExcelSpecService
{

    public function parseSpecs(string $filePath): array
    {
        try {
            $spreadsheet = IOFactory::load($filePath);
            $sheet = $spreadsheet->getActiveSheet();

            $headers = [];
            $headerRow = $sheet->getRowIterator(1)->current();

            foreach ($headerRow->getCellIterator() as $cell) {
                $value = trim($cell->getValue());
                if (!empty($value)) {
                    $headers[] = $value;
                }
            }

            if (empty($headers)) {
                throw new \RuntimeException('No headers found in spreadsheet');
            }

            $specs = [];

            foreach ($headers as $index => $header) {
                $columnLetter = $this->getColumnLetter($index + 1);
                $spec = $this->analyzeColumn($sheet, $header, $columnLetter);
                $specs[] = $spec;
            }

            Log::info('Parsed specs', [
                'file' => basename($filePath),
                'fields_count' => count($specs)
            ]);

            return $specs;

        } catch (\Exception $e) {
            Log::error('Failed to parse Excel: ' . $e->getMessage());
            throw new \RuntimeException('Failed to analyze spreadsheet: ' . $e->getMessage(), 0, $e);
        }
    }

    public function applyUpdatesToBrokerSpecs(string $brokerPath, string $updatePath): array
    {

        $brokerSpecs = $this->parseSpecs($brokerPath);
        $updateSpecs = $this->parseSpecs($updatePath);

        $updateMap = collect($updateSpecs)->keyBy('column_name')->toArray();

        $corrected = [];
        $correctedColumns = [];
        $preservedColumns = [];

        foreach ($brokerSpecs as $brokerSpec) {
            $columnName = $brokerSpec['column_name'];

            if (isset($updateMap[$columnName])) {
                // Apply update specs even for formula columns
                $updateSpec = $updateMap[$columnName];

                $correctedSpec = [
                    'column_name' => $columnName,
                    'field_type' => $updateSpec['field_type'] ?? $brokerSpec['field_type'],
                    'allowed_values' => !empty($updateSpec['allowed_values'])
                        ? $updateSpec['allowed_values']
                        : $brokerSpec['allowed_values'],
                    'is_required' => $updateSpec['is_required'] ?? $brokerSpec['is_required'],
                    'max_length' => $updateSpec['max_length'] ?? $brokerSpec['max_length'],
                    'source' => 'corrected',
                    'updated_fields' => array_keys(array_filter([
                        'field_type' => ($updateSpec['field_type'] ?? null) !== $brokerSpec['field_type'],
                        'allowed_values' => !empty($updateSpec['allowed_values']) &&
                                           $updateSpec['allowed_values'] !== $brokerSpec['allowed_values'],
                    ]))
                ];

                $corrected[] = $correctedSpec;
                $correctedColumns[] = $columnName;

                unset($updateMap[$columnName]);
            } else {
                $brokerSpec['source'] = 'broker_preserved';
                $corrected[] = $brokerSpec;
                $preservedColumns[] = $columnName;
            }
        }

        $unknownUpdates = [];
        foreach ($updateMap as $columnName => $updateSpec) {
            $unknownUpdates[] = $columnName;

        }

        Log::info('Updates applied to broker specs', [
            'broker_file' => basename($brokerPath),
            'update_file' => basename($updatePath),
            'total_fields' => count($corrected),
            'corrected_count' => count($correctedColumns),
            'preserved_count' => count($preservedColumns),
            'unknown_updates' => $unknownUpdates
        ]);

        return [
            'specs' => $corrected,
            'summary' => [
                'total' => count($corrected),
                'corrected' => count($correctedColumns),
                'preserved' => count($preservedColumns),
                'unknown_updates' => count($unknownUpdates),
                'corrected_column_names' => $correctedColumns,
                'unknown_update_names' => $unknownUpdates,
                'broker_filename' => basename($brokerPath),
                'update_filename' => basename($updatePath),
            ]
        ];
    }


    protected function analyzeColumn($sheet, string $columnName, string $columnLetter): array
    {
        $dropdownColumns = [
            'Suminsured',
            'Employee Premium',
            'Employer Premium',
            'OPD Suminsured',
            'OPD Employee Premium',
            'OPD Employer Premium'
        ];
        $spec = [
            'column_name' => $columnName,
            'field_type' => 'text',
            'allowed_values' => [],
            'is_required' => false,
            'max_length' => null,
        ];

        $cellCoordinate = $columnLetter . '2';

        if (!$sheet->cellExists($cellCoordinate)) {
            return $this->inferFieldType($spec, $columnName);
        }

        $cell = $sheet->getCell($cellCoordinate);
        $dataValidation = $cell->getDataValidation();

        if ($dataValidation && $dataValidation->getType() === DataValidation::TYPE_LIST) {
            $spec['field_type'] = 'dropdown';
            $formula = $dataValidation->getFormula1();
            $spec['allowed_values'] = $this->parseDropdownFormula($formula, $sheet);
        }

        $sampleValue = $sheet->getCell($cellCoordinate)->getCalculatedValue();
        if ($sampleValue && empty($spec['allowed_values'])) {
            $spec = $this->inferFromSample($spec, $sampleValue, $columnName);
        }
        if (in_array($columnName, $dropdownColumns) && empty($spec['allowed_values'])) {
        // Extract unique values from column to build dropdown list
            $spec['field_type'] = 'dropdown';
            $spec['allowed_values'] = $this->extractUniqueValuesFromColumn($sheet, $columnLetter);
        }

        $spec = $this->inferFieldType($spec, $columnName);



        return $spec;
    }

    protected function parseDropdownFormula(string $formula, $sheet): array
    {
        if (empty($formula)) return [];

        $formula = ltrim($formula, '=');

        if (preg_match('/\$?[A-Z]+\$?\d+:\$?[A-Z]+\$?\d+/', $formula)) {
            return $this->extractValuesFromRange($formula, $sheet);
        }

        $values = str_getcsv($formula);
        return array_map('trim', array_filter($values));
    }


    protected function extractValuesFromRange(string $range, $sheet): array
    {
        try {
            $values = [];
            $range = str_replace('$', '', $range);

            foreach ($sheet->getCellIterator($range) as $cell) {
                $value = trim($cell->getCalculatedValue());
                if (!empty($value)) {
                    $values[] = $value;
                }
            }
            return $values;
        } catch (\Exception $e) {
            Log::warning('Failed to extract range values: ' . $e->getMessage());
            return [];
        }
    }

    protected function inferFieldType(array $spec, string $columnName): array
    {
        $name = strtolower($columnName);

        if (preg_match('/\b(dob|date|joining|marriage|expiry|inception|effective|issuance|license)\b/', $name)) {
            $spec['field_type'] = 'date';
            return $spec;
        }

        if (str_contains($name, 'email')) {
            $spec['field_type'] = 'email';
            return $spec;
        }

        if (preg_match('/\b(code|number|mobile|pin|aadhar|abha|account|vpn|serial|family|installment|ctc|salary|premium|suminsured)\b/', $name)) {
            $spec['field_type'] = 'number';
            if (str_contains($name, 'pin')) $spec['max_length'] = 6;
            if (str_contains($name, 'mobile')) $spec['max_length'] = 10;
            if (str_contains($name, 'aadhar')) $spec['max_length'] = 12;
            return $spec;
        }

        if (preg_match('/\b(is_|should_|has_)\b/', $name)) {
            $spec['field_type'] = 'boolean';
            $spec['allowed_values'] = ['Yes', 'No'];
            return $spec;
        }

        return $spec;
    }

    protected function extractUniqueValuesFromColumn($sheet, string $columnLetter): array
    {
        $values = [];
        try {
            foreach ($sheet->getColumnIterator($columnLetter, $columnLetter) as $col) {
                foreach ($col->getCellIterator(2, 10) as $cell) { // Check rows 2-10 for sample values
                    $value = trim($cell->getCalculatedValue());
                    if (!empty($value) && !in_array($value, $values)) {
                        $values[] = $value;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to extract column values: ' . $e->getMessage());
        }
        return $values;
    }


    protected function inferFromSample(array $spec, $sampleValue, string $columnName): array
    {
        if (!is_string($sampleValue) && !is_numeric($sampleValue)) return $spec;

        if (is_string($sampleValue) && filter_var($sampleValue, FILTER_VALIDATE_EMAIL)) {
            $spec['field_type'] = 'email';
            return $spec;
        }

        if (is_string($sampleValue) && strtotime($sampleValue) !== false) {
            $spec['field_type'] = 'date';
            return $spec;
        }

        if (is_numeric($sampleValue)) {
            $spec['field_type'] = 'number';
            $spec['max_length'] = strlen(preg_replace('/[^0-9]/', '', (string)$sampleValue));
            return $spec;
        }

        return $spec;
    }

    protected function getColumnLetter(int $index): string
    {
        $letter = '';
        while ($index > 0) {
            $modulo = ($index - 1) % 26;
            $letter = chr(65 + $modulo) . $letter;
            $index = intdiv($index - $modulo, 26);
        }
        return $letter;
    }
}
