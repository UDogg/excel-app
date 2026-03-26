<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Services\FakeDataService;
use App\Services\EndorsementPreValidator;
use App\Exports\EndorsementExport;

class GenerationController extends Controller
{
    protected FakeDataService $fakeDataService;
    protected EndorsementPreValidator $preValidator;

    public function __construct(
        FakeDataService $fakeDataService,
        EndorsementPreValidator $preValidator
    ) {
        $this->fakeDataService = $fakeDataService;
        $this->preValidator = $preValidator;
    }

    public function showGenerateForm()
    {
        $config = session('template_config');
        $brokerFilePath = session('broker_file_path');

        if (!$config) {
            return redirect()->route('templates.upload')
                ->with('error', 'Please configure fields first.');
        }

        return view('templates.generate', [
            'configToken' => session()->getId(),
            'fields_count' => count($config),
            'broker_filename' => basename($brokerFilePath ?? 'broker file')
        ]);
    }

    public function download(Request $request)
    {
        $request->validate([
            'record_count' => 'required|integer|min:1|max:5000',
            'employer_id' => 'sometimes|integer|min:1',
            'skip_validation' => 'sometimes|boolean',
        ]);

        $config = session('template_config');
        $brokerFilePath = session('broker_file_path');
        $recordCount = $request->record_count;
        $employerId = $request->input('employer_id', 18); // Default to employer 18
        $skipValidation = $request->boolean('skip_validation', false);

        try {
            Log::info("Starting generation", [
                'records' => $recordCount,
                'fields' => count($config),
                'template' => $brokerFilePath,
                'employer_id' => $employerId,
            ]);

            // Verify template file exists
            if (!$brokerFilePath || !Storage::disk('local')->exists($brokerFilePath)) {
                throw new \RuntimeException('Broker template file not found. Please re-upload files.');
            }

            // Get full path to template
            $templateFullPath = Storage::disk('local')->path($brokerFilePath);

            // Generate fake data using constrained service
            $data = $this->fakeDataService->generateRows($config, $recordCount);

            // PRE-VALIDATION: Check data against backend expectations
            if (!$skipValidation) {
                $validationResult = $this->preValidator->validateRows($data, $employerId);

                Log::info('Pre-validation result', [
                    'success_rate' => $validationResult['success_rate'],
                    'invalid_count' => count($validationResult['invalid']),
                ]);

                // If validation fails, auto-fix and re-validate
                if ($validationResult['success_rate'] < 100) {
                    Log::warning('Pre-validation failed, auto-fixing...', [
                        'success_rate' => $validationResult['success_rate']
                    ]);

                    $data = $this->preValidator->autoFixRows($data, $employerId);

                    // Re-validate after fix
                    $validationResult = $this->preValidator->validateRows($data, $employerId);

                    if ($validationResult['success_rate'] < 100) {
                        return back()->with('warning',
                            "Generated {$recordCount} records but {$validationResult['invalid_count']} failed validation.
                             Some records may be rejected by backend. Errors: " .
                             implode(', ', array_unique($validationResult['errors']))
                        )->withInput();
                    }
                }
            }

            // Create export with template (preserves formulas)
            $export = new EndorsementExport($data, $config, $templateFullPath);

            // Populate the template with data (preserves formulas)
            $export->populateTemplate();

            // Generate filename
            $filename = 'endorsement_' . date('Y-m-d_H-i-s') . '.xlsx';

            // Download the populated template
            return $export->download($filename);

        } catch (\Exception $e) {
            Log::error('Generation failed: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return back()->with('error', 'Failed to generate file: ' . $e->getMessage());
        }
    }
}
