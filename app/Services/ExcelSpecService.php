<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use Illuminate\Support\Facades\Log;

class ExcelSpecService
{
    /**
     * MAIN ENTRY POINT: Parse a single Excel file and extract field specifications
     */
    public function parseSpecs(string $filePath): array
    {
        try {
            $spreadsheet = IOFactory::load($filePath);
            $sheet = $spreadsheet->getActiveSheet();

            // Get headers from first row
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

            // Analyze each column for specs
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

    /**
     * Parse and merge specs from default template and broker's modified file
     * Only broker's fields override defaults; unmatched default fields are preserved
     */
    /**
     * Apply company update specs to broker's spreadsheet specs
     * Only fields in update file are corrected; all others preserved from broker
     */
    public function applyUpdatesToBrokerSpecs(string $brokerPath, string $updatePath): array
    {
        // Parse both files
        $brokerSpecs = $this->parseSpecs($brokerPath);
        $updateSpecs = $this->parseSpecs($updatePath);

        // Create lookup map for update specs by column name
        $updateMap = collect($updateSpecs)->keyBy('column_name')->toArray();

        $corrected = [];
        $correctedColumns = [];
        $preservedColumns = [];

        // Start with broker specs as base (preserve everything)
        foreach ($brokerSpecs as $brokerSpec) {
            $columnName = $brokerSpec['column_name'];

            if (isset($updateMap[$columnName])) {
                // Field exists in update file: apply correction
                $updateSpec = $updateMap[$columnName];

                $correctedSpec = [
                    'column_name' => $columnName,
                    'field_type' => $updateSpec['field_type'] ?? $brokerSpec['field_type'],
                    'allowed_values' => !empty($updateSpec['allowed_values'])
                        ? $updateSpec['allowed_values']
                        : $brokerSpec['allowed_values'],
                    'is_required' => $updateSpec['is_required'] ?? $brokerSpec['is_required'],
                    'max_length' => $updateSpec['max_length'] ?? $brokerSpec['max_length'],
                    'source' => 'corrected', // This field was updated by company
                    'updated_fields' => array_keys(array_filter([
                        'field_type' => ($updateSpec['field_type'] ?? null) !== $brokerSpec['field_type'],
                        'allowed_values' => !empty($updateSpec['allowed_values']) &&
                                           $updateSpec['allowed_values'] !== $brokerSpec['allowed_values'],
                    ]))
                ];

                $corrected[] = $correctedSpec;
                $correctedColumns[] = $columnName;

                // Remove from update map to track extras
                unset($updateMap[$columnName]);
            } else {
                // Field not in update: preserve broker's spec exactly
                $brokerSpec['source'] = 'broker_preserved';
                $corrected[] = $brokerSpec;
                $preservedColumns[] = $columnName;
            }
        }

        // Optional: Warn if update file has fields not in broker's file
        $unknownUpdates = [];
        foreach ($updateMap as $columnName => $updateSpec) {
            $unknownUpdates[] = $columnName;
            // You could add these as new fields, or ignore them
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

    /**
     * Analyze a single column for field type and validation rules
     */
    protected function analyzeColumn($sheet, string $columnName, string $columnLetter): array
    {
        $spec = [
            'column_name' => $columnName,
            'field_type' => 'text',
            'allowed_values' => [],
            'is_required' => false,
            'max_length' => null,
        ];

        // Check data validation (dropdowns) in row 2 (first data row)
        $cellCoordinate = $columnLetter . '2';

        // Safety check: ensure cell exists
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

        // Infer field type from column name patterns
        $spec = $this->inferFieldType($spec, $columnName);

        // Check if column has sample data to detect patterns
        $sampleValue = $sheet->getCell($cellCoordinate)->getCalculatedValue();
        if ($sampleValue && empty($spec['allowed_values'])) {
            $spec = $this->inferFromSample($spec, $sampleValue, $columnName);
        }

        return $spec;
    }

    /**
     * Parse dropdown formula (could be comma-separated or cell range reference)
     */
    protected function parseDropdownFormula(string $formula, $sheet): array
    {
        if (empty($formula)) return [];

        // Remove leading '=' if present
        $formula = ltrim($formula, '=');

        // Check if it's a cell range reference (e.g., $A$1:$A$3)
        if (preg_match('/\$?[A-Z]+\$?\d+:\$?[A-Z]+\$?\d+/', $formula)) {
            return $this->extractValuesFromRange($formula, $sheet);
        }

        // Otherwise treat as comma-separated list
        $values = str_getcsv($formula);
        return array_map('trim', array_filter($values));
    }

    /**
     * Extract values from a cell range reference
     */
    protected function extractValuesFromRange(string $range, $sheet): array
    {
        try {
            $values = [];
            $range = str_replace('$', '', $range); // Remove absolute references

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

    /**
     * Infer field type based on column name patterns (using your Knowledge Base columns)
     */
    protected function inferFieldType(array $spec, string $columnName): array
    {
        $name = strtolower($columnName);

        // Date fields (from your KB: DOB, Date of Joining, Date of Marriage, etc.)
        if (preg_match('/\b(dob|date|joining|marriage|expiry|inception|effective|issuance|license)\b/', $name)) {
            $spec['field_type'] = 'date';
            return $spec;
        }

        // Email fields
        if (str_contains($name, 'email')) {
            $spec['field_type'] = 'email';
            return $spec;
        }

        // Number/ID fields (from your KB: Mobile, Pin, Aadhar, ABHA, Salary, Premium, etc.)
        if (preg_match('/\b(code|number|mobile|pin|aadhar|abha|account|vpn|serial|family|installment|ctc|salary|premium|suminsured)\b/', $name)) {
            $spec['field_type'] = 'number';
            if (str_contains($name, 'pin')) $spec['max_length'] = 6;
            if (str_contains($name, 'mobile')) $spec['max_length'] = 10;
            if (str_contains($name, 'aadhar')) $spec['max_length'] = 12;
            return $spec;
        }

        // Boolean fields (from your KB: Is VIP, Has Death Certificate, etc.)
        if (preg_match('/\b(is_|should_|has_)\b/', $name)) {
            $spec['field_type'] = 'boolean';
            $spec['allowed_values'] = ['Yes', 'No'];
            return $spec;
        }

        return $spec;
    }

    /**
     * Further refine spec based on sample data value
     */
    protected function inferFromSample(array $spec, $sampleValue, string $columnName): array
    {
        if (!is_string($sampleValue) && !is_numeric($sampleValue)) return $spec;

        // Detect email format
        if (is_string($sampleValue) && filter_var($sampleValue, FILTER_VALIDATE_EMAIL)) {
            $spec['field_type'] = 'email';
            return $spec;
        }

        // Detect date format
        if (is_string($sampleValue) && strtotime($sampleValue) !== false) {
            $spec['field_type'] = 'date';
            return $spec;
        }

        // Detect numeric
        if (is_numeric($sampleValue)) {
            $spec['field_type'] = 'number';
            $spec['max_length'] = strlen(preg_replace('/[^0-9]/', '', (string)$sampleValue));
            return $spec;
        }

        return $spec;
    }

    /**
     * Convert column index to Excel letter (1=A, 2=B, 27=AA, etc.)
     */
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
