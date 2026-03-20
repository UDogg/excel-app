<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Services\FakeDataService;
use App\Exports\EndorsementExport;
use PhpOffice\PhpSpreadsheet\IOFactory;

class GenerationController extends Controller
{
    protected FakeDataService $fakeDataService;

    public function __construct(FakeDataService $fakeDataService)
    {
        $this->fakeDataService = $fakeDataService;
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
            'record_count' => 'required|integer|min:1|max:5000'
        ]);

        $config = session('template_config');
        $brokerFilePath = session('broker_file_path');
        $recordCount = $request->record_count;

        try {
            Log::info("Starting generation", [
                'records' => $recordCount,
                'fields' => count($config),
                'template' => $brokerFilePath
            ]);

            // Verify template file exists
            if (!$brokerFilePath || !Storage::disk('local')->exists($brokerFilePath)) {
                throw new \RuntimeException('Broker template file not found. Please re-upload files.');
            }

            // Get full path to template
            $templateFullPath = Storage::disk('local')->path($brokerFilePath);

            // Generate fake data using constrained service
            $data = $this->fakeDataService->generateRows($config, $recordCount);

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
