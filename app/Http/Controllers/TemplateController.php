<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Services\ExcelSpecService;

class TemplateController extends Controller
{
    protected ExcelSpecService $excelSpecService;

    public function __construct(ExcelSpecService $excelSpecService)
    {
        $this->excelSpecService = $excelSpecService;
    }

    public function showUploadForm()
    {
        return view('templates.upload');
    }

    public function handleUpload(Request $request)
    {
        $request->validate([
            'broker_file' => 'required|file|mimes:xls,xlsx,xlsm,csv|max:10240',
            'update_file' => 'required|file|mimes:xls,xlsx,xlsm,csv|max:10240'
        ]);

        try {
            Log::info('Upload request', [
                'has_broker' => $request->hasFile('broker_file'),
                'has_update' => $request->hasFile('update_file'),
                'broker_name' => $request->file('broker_file')?->getClientOriginalName(),
                'broker_size' => $request->file('broker_file')?->getSize(),
                'update_name' => $request->file('update_file')?->getClientOriginalName(),
                'update_size' => $request->file('update_file')?->getSize(),
                'storage_path' => storage_path('app'),
                'storage_writable' => is_writable(storage_path('app')),
            ]);

            $brokerFile = $request->file('broker_file');
            $updateFile = $request->file('update_file');

            $brokerFilename = 'broker_' . time() . '_' . uniqid() . '.' . $brokerFile->getClientOriginalExtension();
            $updateFilename = 'update_' . time() . '_' . uniqid() . '.' . $updateFile->getClientOriginalExtension();

            $brokerPath = $brokerFile->storeAs('templates', $brokerFilename, 'local');
            $updatePath = $updateFile->storeAs('templates', $updateFilename, 'local');


            $brokerFullPath = Storage::disk('local')->path($brokerPath);
            $updateFullPath = Storage::disk('local')->path($updatePath);
            Log::info('File storage results', [
                'broker_stored_path' => $brokerPath,
                'broker_full_path' => $brokerFullPath,
                'broker_file_exists' => file_exists($brokerFullPath),
                'update_stored_path' => $updatePath,
                'update_full_path' => $updateFullPath,
                'update_file_exists' => file_exists($updateFullPath),
            ]);

            if (!file_exists($brokerFullPath)) {
                throw new \RuntimeException("Broker file not found at: {$brokerFullPath}");
            }
            if (!file_exists($updateFullPath)) {
                throw new \RuntimeException("Update file not found at: {$updateFullPath}");
            }

            $result = $this->excelSpecService->applyUpdatesToBrokerSpecs($brokerFullPath, $updateFullPath);
            $specs = $result['specs'];
            $summary = $result['summary'];

            $summary['broker_filename'] = $brokerFile->getClientOriginalName();
            $summary['update_filename'] = $updateFile->getClientOriginalName();

            session([
                'template_specs' => $specs,
                'template_summary' => $summary,
                'broker_file_path' => $brokerPath,
                'update_file_path' => $updatePath
            ]);

            Log::info('Updates applied successfully', [
                'broker' => $brokerPath,
                'update' => $updatePath,
                'total_fields' => count($specs),
                'corrected' => $summary['corrected']
            ]);

            return redirect()->route('templates.configure.show')
                ->with('specs', $specs)
                ->with('summary', $summary)
                ->with('configToken', session()->getId());

        } catch (\Exception $e) {
            Log::error('Upload/merge failed: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return back()->with('error', 'Failed to process files: ' . $e->getMessage())
                         ->withInput();
        }
    }

    public function showConfigureForm()
    {
        $specs = session('template_specs');
        $summary = session('template_summary');

        if (!$specs) {
            return redirect()->route('templates.upload')
                ->with('error', 'Please upload files first.');
        }

        return view('templates.configure', [
            'specs' => $specs,
            'summary' => $summary,
            'configToken' => session()->getId()
        ]);
    }


    public function saveConfig(Request $request)
    {
        $request->validate([
            'specs' => 'required|array',
            'specs.*.column_name' => 'required|string',
            'specs.*.field_type' => 'required|in:text,dropdown,date,email,number,boolean'
        ]);


        $sanitizedSpecs = array_map(function($spec) {

            if (!empty($spec['allowed_values']) && is_string($spec['allowed_values'])) {
                $values = array_map('trim', explode(',', $spec['allowed_values']));
                $spec['allowed_values'] = array_filter($values);
            } elseif (empty($spec['allowed_values'])) {
                $spec['allowed_values'] = [];
            }

            
            $validTypes = ['text', 'dropdown', 'date', 'email', 'number', 'boolean'];
            if (!in_array($spec['field_type'], $validTypes)) {
                $spec['field_type'] = 'text';
            }

            return $spec;
        }, $request->specs);

        session(['template_config' => $sanitizedSpecs]);

        Log::info('Configuration saved', [
            'fields_count' => count($sanitizedSpecs),
            'dropdown_count' => count(array_filter($sanitizedSpecs, fn($s) => $s['field_type'] === 'dropdown'))
        ]);

        return redirect()->route('templates.generate')
            ->with('configToken', session()->getId())
            ->with('success', 'Configuration saved! Ready to generate data.');
    }
}
