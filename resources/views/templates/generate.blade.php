<!-- resources/views/templates/generate.blade.php -->

@extends('layouts.app')
@section('title', 'Generate Data')
@section('content')
<div class="container mt-4">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card shadow">
                <div class="card-header bg-primary text-white">
                    <h4 class="mb-0">Step 3: Generate Data</h4>
                </div>
                <div class="card-body">
                    <div class="alert alert-info">
                        <strong>📝 Ready to Generate!</strong><br>
                        The system will create dummy records using Faker based on your configured specifications.
                        <br><small class="text-muted">
                            All records are pre-validated against backend requirements before export.
                        </small>
                    </div>

                    @if(session('warning'))
                        <div class="alert alert-warning">
                            {{ session('warning') }}
                        </div>
                    @endif

                    @if(session('error'))
                        <div class="alert alert-danger">
                            {{ session('error') }}
                        </div>
                    @endif

                    <form action="{{ route('templates.download') }}" method="POST">
                        @csrf
                        <input type="hidden" name="config_token" value="{{ $configToken ?? session()->getId() }}">

                        <div class="mb-4">
                            <label for="record_count" class="form-label fw-bold">Number of Records</label>
                            <input type="number"
                                   name="record_count"
                                   id="record_count"
                                   class="form-control form-control-lg"
                                   value="10"
                                   min="1"
                                   max="1000"
                                   required>
                            <div class="form-text">Enter how many employee records you need.</div>
                        </div>

                        <div class="mb-4">
                            <label for="employer_id" class="form-label fw-bold">Employer ID</label>
                            <input type="number"
                                   name="employer_id"
                                   id="employer_id"
                                   class="form-control"
                                   value="18"
                                   min="1">
                            <div class="form-text">
                                Backend employer ID for validation.
                                <strong>Employer 18</strong> uses grade logic: G1 (≤1.1M), G2 (1.1-2.1M), G3 (>2.1M)
                            </div>
                        </div>

                        <div class="mb-4">
                            <div class="form-check">
                                <input class="form-check-input"
                                       type="checkbox"
                                       name="skip_validation"
                                       id="skip_validation"
                                       value="1">
                                <label class="form-check-label" for="skip_validation">
                                    <strong>Skip pre-validation</strong>
                                </label>
                                <div class="form-text">
                                    If checked, data will be generated without pre-validation.
                                    <span class="text-danger">Not recommended - may cause backend rejection.</span>
                                </div>
                            </div>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"
                                     fill="currentColor" class="bi bi-download" viewBox="0 0 16 16">
                                    <path d="M.5 9.9a.5.5 0 0 1 .5.5v2.5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-2.5a.5.5 0 0 1 1 0v2.5a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2v-2.5a.5.5 0 0 1 .5-.5z"/>
                                    <path d="M7.646 11.854a.5.5 0 0 0 .708 0l3-3a.5.5 0 0 0-.708-.708L8.5 10.293V1.5a.5.5 0 0 0-1 0v8.793L5.354 8.146a.5.5 0 1 0-.708.708l3 3z"/>
                                </svg>
                                Generate & Download Excel
                            </button>
                        </div>

                        <div class="text-center mt-3">
                            <a href="{{ route('templates.upload') }}" class="text-decoration-none">Start Over</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
