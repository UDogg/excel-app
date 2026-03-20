@extends('layouts.app')

@section('title', 'Apply Company Updates')

@section('content')
<div class="row justify-content-center">
    <div class="col-md-10">
        <div class="card">
            <div class="card-header bg-white">
                <h4 class="mb-0">Step 1: Apply Company Updates to Broker Spreadsheet</h4>
            </div>
            <div class="card-body p-4">
                <div class="alert alert-info">
                    <strong>How this works:</strong><br>
                    • Upload the <em>Broker's Spreadsheet</em> (current working file, may have issues)<br>
                    • Upload the <em>Company Update File</em> (corrections sent by company)<br>
                    • We'll apply the update: only fields in the update file will be corrected<br>
                    • All other fields keep their existing specifications from the broker's file
                </div>

                <form action="{{ route('templates.upload.post') }}" method="POST" enctype="multipart/form-data">
                    @csrf

                    <div class="row">
                        <!-- Broker's File Upload -->
                        <div class="col-md-6 mb-3">
                            <label for="broker_file" class="form-label fw-bold">
                                📋 Broker's Spreadsheet (Current File)
                            </label>
                            <input type="file" name="broker_file" id="broker_file" class="form-control" required accept=".xls,.xlsx,.xlsm">
                            <div class="form-text">The file you're currently using (may have dropdown/spec issues)</div>
                            @error('broker_file')
                                <div class="text-danger small">{{ $message }}</div>
                            @enderror
                        </div>

                        <!-- Company Update File Upload -->
                        <div class="col-md-6 mb-3">
                            <label for="update_file" class="form-label fw-bold">
                                🔄 Company Update File (Corrections)
                            </label>
                            <input type="file" name="update_file" id="update_file" class="form-control" required accept=".xls,.xlsx,.xlsm">
                            <div class="form-text">The correction file sent by the company</div>
                            @error('update_file')
                                <div class="text-danger small">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="alert alert-warning">
                        <small>
                            <strong>Note:</strong> Only fields that exist in the Company Update File will have their specifications corrected.
                            Fields not in the update file will remain exactly as they are in the broker's file.
                        </small>
                    </div>

                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary btn-lg">
                            Apply Updates & Review &nbsp; →
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
