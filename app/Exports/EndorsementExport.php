<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use Illuminate\Support\Collection;

class EndorsementExport implements FromCollection, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
{
    protected Collection $data;
    protected array $specs;

    public function __construct(array $data, array $specs)
    {
        $this->data = collect($data);
        $this->specs = $specs;
    }

    public function collection()
    {
        return $this->data;
    }

    public function headings(): array
    {
        return array_column($this->specs, 'column_name');
    }

    public function map($row): array
    {
        $mapped = [];
        foreach ($this->specs as $spec) {
            $value = $row[$spec['column_name']] ?? '';
            $mapped[] = $this->formatValue($value, $spec);
        }
        return $mapped;
    }

    /**
     * Format value based on field type for Excel compatibility
     */
    protected function formatValue($value, array $spec)
    {
        // Convert boolean Yes/No
        if ($spec['field_type'] === 'boolean') {
            return $value === 'Yes' ? 'Yes' : 'No';
        }

        // Ensure dates are in Excel-recognizable format
        if ($spec['field_type'] === 'date' && !empty($value)) {
            return \PhpOffice\PhpSpreadsheet\Shared\Date::stringToExcel($value);
        }

        // Keep numbers as numbers for calculations
        if ($spec['field_type'] === 'number' && is_numeric($value)) {
            return (float) $value;
        }

        return (string) $value;
    }

    public function styles(Worksheet $sheet)
    {
        // Style header row
        $sheet->getStyle('1:1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '4472C4']],
        ]);

        // Freeze header row
        $sheet->freezePane('A2');

        return [];
    }

    /**
     * Apply data validation rules to dropdown columns
     * This preserves the dropdown behavior in the generated Excel
     */
    public function withDataValidations(Worksheet $sheet)
    {
        $headerRow = $this->headings();

        foreach ($this->specs as $index => $spec) {
            if ($spec['field_type'] === 'dropdown' && !empty($spec['allowed_values'])) {
                $columnLetter = $this->getColumnLetter($index + 1);
                $formula = '"' . implode(',', $spec['allowed_values']) . '"';

                // Apply validation to data rows (row 2 onwards)
                $validation = $sheet->getCell($columnLetter . '2')->getDataValidation();
                $validation->setType(DataValidation::TYPE_LIST)
                          ->setErrorStyle(DataValidation::STYLE_STOP)
                          ->setAllowBlank(false)
                          ->setShowDropDown(true)
                          ->setFormula1($formula);

                // Copy validation down to all data rows
                $lastRow = $this->data->count() + 1;
                $sheet->getCell($columnLetter . '2')->getDataValidation()->setSqRef("{$columnLetter}2:{$columnLetter}{$lastRow}");
            }
        }
    }

    /**
     * Helper to convert column index to Excel letter
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
