<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Services\FakeDataService;
use App\Exports\EndorsementExport;
use Maatwebsite\Excel\Facades\Excel;

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

        if (!$config) {
            return redirect()->route('templates.upload')
                ->with('error', 'Please configure fields first.');
        }

        return view('templates.generate', [
            'configToken' => session()->getId(),
            'fields_count' => count($config)
        ]);
    }

    public function download(Request $request)
    {
        $request->validate([
            'record_count' => 'required|integer|min:1|max:5000'
        ]);

        $config = session('template_config');
        $recordCount = $request->record_count;

        try {
            Log::info("Starting generation", [
                'records' => $recordCount,
                'fields' => count($config)
            ]);

            // Generate fake data
            $data = $this->fakeDataService->generateRows($config, $recordCount);

            // Export to Excel with proper formatting
            $filename = 'endorsement_' . date('Y-m-d_H-i-s') . '.xlsx';

            return Excel::download(
                new EndorsementExport($data, $config),
                $filename
            );

        } catch (\Exception $e) {
            Log::error('Generation failed: ' . $e->getMessage());
            return back()->with('error', 'Failed to generate file: ' . $e->getMessage());
        }
    }
}
