<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

class EndorsementExport implements WithMapping, WithStyles, ShouldAutoSize, WithColumnFormatting
{
    protected Collection $data;
    protected array $specs;
    protected string $templatePath;
    protected $spreadsheet;
    protected $sheet;

    // Characters that trigger formula parsing in Excel
    protected const FORMULA_PREFIXES = ['=', '+', '-', '@', '#'];
    protected const FORMULA_OPERATORS = ['%', '^', '&', '<', '>', '|'];

    public function __construct(array $data, array $specs, string $templatePath)
    {
        $this->data = collect($data);
        $this->specs = $specs;
        $this->templatePath = $templatePath;

        // Load the broker's original template (preserves all formulas)
        $this->spreadsheet = IOFactory::load($templatePath);
        $this->sheet = $this->spreadsheet->getActiveSheet();
    }

    /**
     * Get the loaded spreadsheet (required by Maatwebsite/Excel)
     */
    public function getSpreadsheet()
    {
        return $this->spreadsheet;
    }

    /**
     * Map row data with formula-safe escaping
     */
    public function map($row): array
    {
        $mapped = [];
        foreach ($this->specs as $spec) {
            $columnName = $spec['column_name'];
            $value = $row[$columnName] ?? '';
            $mapped[] = $this->escapeForExcel($value, $spec);
        }
        return $mapped;
    }

    /**
     * Escape values that could be interpreted as Excel formulas
     * BUT preserve cells that already contain formulas in the template
     */
    protected function escapeForExcel($value, array $spec)
    {
        // Handle null/empty
        if ($value === null || $value === '') {
            return '';
        }

        // Convert to string for text processing
        $stringValue = (string) $value;

        // Numbers should stay as numbers for calculations
        if (($spec['field_type'] ?? '') === 'number' && is_numeric($value)) {
            return (float) $value;
        }

        // DATES: Never escape with apostrophe - Excel recognizes Y-m-d format
        if (($spec['field_type'] ?? '') === 'date' && !empty($value)) {
            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                return date('Y-m-d', $timestamp); // Return clean date, NO apostrophe
            }
        }

        // Check if value looks like a formula (user data, not template formula)
        if ($this->looksLikeFormula($stringValue)) {
            // Prefix with single quote to force text interpretation
            return "'" . $stringValue;
        }

        return $stringValue;
    }

    /**
     * Detect if a value could be interpreted as an Excel formula
     */
    protected function looksLikeFormula(string $value): bool
    {
        $trimmed = trim($value);

        if (empty($trimmed)) {
            return false;
        }

        // Check for formula prefixes at start
        foreach (self::FORMULA_PREFIXES as $prefix) {
            if (str_starts_with($trimmed, $prefix)) {
                return true;
            }
        }

        // Check for percentage patterns
        if (preg_match('/^\d+%$/', $trimmed)) {
            return true;
        }

        // Check for expression-like patterns
        if (preg_match('/[+\-@#].*[+\-@#]/', $trimmed) || preg_match('/\w+\s*\(/', $trimmed)) {
            return true;
        }

        return false;
    }

    public function styles(Worksheet $sheet)
    {
        // Preserve existing styles from template
        // Only ensure header row is styled if not already
        $headerStyle = $sheet->getStyle('1:1');
        if (!$headerStyle->getFont()->getBold()) {
            $headerStyle->applyFromArray([
                'font' => ['bold' => true],
                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '4472C4']],
            ]);
        }

        // Freeze header row
        $sheet->freezePane('A2');

        return [];
    }

    /**
     * Apply column-specific formatting
     */
    public function columnFormats(): array
    {
        $formats = [];

        foreach ($this->specs as $index => $spec) {
            $columnLetter = $this->getColumnLetter($index + 1);

            // Only apply format if cell doesn't contain a formula
            $cellValue = $this->sheet->getCell($columnLetter . '2')->getValue();
            if ($this->cellContainsFormula($columnLetter . '2')) {
                // Skip formatting for formula cells
                continue;
            }

            switch ($spec['field_type'] ?? 'text') {
                case 'date':
                    $formats[$columnLetter] = NumberFormat::FORMAT_DATE_YYYYMMDD;
                    break;
                case 'number':
                    $name = strtolower($spec['column_name']);
                    if (preg_match('/(salary|suminsured|premium|ctc)/', $name)) {
                        $formats[$columnLetter] = NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1;
                    } elseif (preg_match('/(pin|mobile|aadhar)/', $name)) {
                        $formats[$columnLetter] = NumberFormat::FORMAT_TEXT;
                    } else {
                        $formats[$columnLetter] = NumberFormat::FORMAT_NUMBER;
                    }
                    break;
                case 'email':
                    $formats[$columnLetter] = NumberFormat::FORMAT_TEXT;
                    break;
                default:
                    $formats[$columnLetter] = NumberFormat::FORMAT_TEXT;
            }
        }

        return $formats;
    }

    /**
     * Check if a cell contains a formula (not just a value)
     */
    protected function cellContainsFormula(string $coordinate): bool
    {
        $cell = $this->sheet->getCell($coordinate);
        return $cell->isFormula() || str_starts_with((string) $cell->getValue(), '=');
    }

    /**
     * Apply data validation rules to dropdown columns
     * Only if not already present in template
     */
    public function withDataValidations(Worksheet $sheet)
    {
        foreach ($this->specs as $index => $spec) {
            if (($spec['field_type'] ?? '') === 'dropdown' && !empty($spec['allowed_values'])) {
                $columnLetter = $this->getColumnLetter($index + 1);

                // Check if validation already exists in template
                $existingValidation = $sheet->getCell("{$columnLetter}2")->getDataValidation();
                if ($existingValidation->getType() === DataValidation::TYPE_LIST) {
                    // Preserve existing validation from template
                    continue;
                }

                // Apply new validation only if template doesn't have it
                $values = array_map(fn($v) => '"' . str_replace('"', '""', $v) . '"', $spec['allowed_values']);
                $formula = implode(',', $values);

                $lastRow = $this->data->count() + 1;
                $range = "{$columnLetter}2:{$columnLetter}{$lastRow}";

                $validation = $sheet->getCell("{$columnLetter}2")->getDataValidation();
                $validation->setType(DataValidation::TYPE_LIST)
                          ->setErrorStyle(DataValidation::STYLE_STOP)
                          ->setAllowBlank(true)
                          ->setShowDropDown(true)
                          ->setFormula1($formula)
                          ->setSqRef($range);
            }
        }
    }

    /**
     * Convert column index to Excel letter
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

    /**
     * Populate the template with data (called by controller)
     */
/**
 * Populate the template with data — SMART: preserve formulas, apply specs
 */
public function populateTemplate(): self
{
    $headers = array_column($this->specs, 'column_name');
    $headerRow = 1;
    $dataStartRow = 2;

    // Build column map from template headers
    $columnMap = [];
    $headerCells = $this->sheet->getRowIterator($headerRow, $headerRow)->current()->getCellIterator();
    foreach ($headerCells as $cell) {
        $headerName = trim($cell->getValue());
        if (!empty($headerName)) {
            $columnMap[$headerName] = $cell->getColumn();
        }
    }

    // Populate each data row
    foreach ($this->data as $rowIndex => $rowData) {
        $targetRow = $dataStartRow + $rowIndex;

        foreach ($headers as $header) {
            if (!isset($columnMap[$header])) {
                continue;
            }

            $column = $columnMap[$header];
            $coordinate = $column . $targetRow;
            $cell = $this->sheet->getCell($coordinate);

            // 🔑 CRITICAL: Check if this cell contains a formula in the template
            $cellIsFormula = $cell->isFormula() || str_starts_with((string) $cell->getValue(), '=');

            if ($cellIsFormula) {
                // ✅ PRESERVE FORMULA: Don't overwrite the cell value
                // BUT: Still apply data validation rules if spec changed
                $spec = $this->getSpecForColumn($header);
                if (($spec['field_type'] ?? '') === 'dropdown' && !empty($spec['allowed_values'])) {
                    $this->applyDropdownValidation($column, $targetRow, $spec['allowed_values']);
                }
                continue; // Skip value population, keep formula
            }

            // ✅ NON-FORMULA CELL: Populate with generated data
            $value = $rowData[$header] ?? '';
            $spec = $this->getSpecForColumn($header);

            if ($value === '' || $value === null) {
                $cell->setValueExplicit('', DataType::TYPE_STRING);
            } elseif (is_numeric($value) && $this->shouldKeepAsNumber($header, $value)) {
                $cell->setValueExplicit((float) $value, DataType::TYPE_NUMERIC);
            } else {
                // Escape formula-like user data
                $escapedValue = $this->escapeForExcel($value, $spec);
                $cell->setValueExplicit($escapedValue, DataType::TYPE_STRING);
            }

            // Apply dropdown validation if spec defines it
            if (($spec['field_type'] ?? '') === 'dropdown' && !empty($spec['allowed_values'])) {
                $this->applyDropdownValidation($column, $targetRow, $spec['allowed_values']);
            }
        }
    }

    return $this;
}

/**
 * Apply dropdown validation to a cell range (preserves existing if any)
 */
protected function applyDropdownValidation(string $column, int $startRow, array $allowedValues): void
{
    $cell = $this->sheet->getCell("{$column}{$startRow}");
    $existingValidation = $cell->getDataValidation();

    // Skip if validation already exists and matches (avoid overwriting template rules)
    if ($existingValidation->getType() === DataValidation::TYPE_LIST) {
        return;
    }

    // Build dropdown formula
    $values = array_map(fn($v) => '"' . str_replace('"', '""', $v) . '"', $allowedValues);
    $formula = implode(',', $values);

    // Apply to all data rows for this column
    $lastRow = $this->data->count() + 1;
    $range = "{$column}{$startRow}:{$column}{$lastRow}";

    $validation = $cell->getDataValidation();
    $validation->setType(DataValidation::TYPE_LIST)
              ->setErrorStyle(DataValidation::STYLE_STOP)
              ->setAllowBlank(true)
              ->setShowDropDown(true)
              ->setFormula1($formula)
              ->setSqRef($range);
}

/**
 * Decide if a numeric value should stay as number (for calculations) vs text (for IDs)
 */
protected function shouldKeepAsNumber(string $header, $value): bool
{
    $lowerHeader = strtolower($header);

    // Keep as number for calculable fields
    if (preg_match('/(salary|suminsured|premium|ctc|contribution|variables|number of time)/', $lowerHeader)) {
        return true;
    }

    // Force text for ID fields (preserve leading zeros, avoid scientific notation)
    if (preg_match('/(pin|mobile|aadhar|abha|code|id|serial|account|vpn|license)/', $lowerHeader)) {
        return false;
    }

    // Default: keep numbers as numbers
    return is_numeric($value);
}
    /**
     * Get spec for a column name
     */
    protected function getSpecForColumn(string $columnName): array
    {
        foreach ($this->specs as $spec) {
            if ($spec['column_name'] === $columnName) {
                return $spec;
            }
        }
        return ['field_type' => 'text', 'allowed_values' => []];
    }

    /**
     * Save and download the populated template
     */
    public function download(string $filename)
    {
        $writer = new Xlsx($this->spreadsheet);

        // Stream download
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        header('Pragma: public');

        $writer->save('php://output');
        exit;
    }
}
