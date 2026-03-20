@extends('layouts.app')

@section('title', 'Configure Fields')

@section('content')
<div class="card">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h4 class="mb-0">Step 2: Review & Confirm Field Specifications</h4>
        <a href="{{ route('templates.upload') }}" class="btn btn-sm btn-outline-secondary">← Back</a>
    </div>
    <div class="card-body">

        <!-- Update Summary Alert -->
        @if(isset($summary))
        <div class="alert alert-success mb-4">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <h6 class="alert-heading fw-bold mb-2">✅ Updates Applied</h6>
                    <p class="mb-2 small">
                        <strong>{{ $summary['broker_filename'] ?? 'Broker File' }}</strong>
                        + corrections from
                        <strong>{{ $summary['update_filename'] ?? 'Update File' }}</strong>
                    </p>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#updateDetails">
                    View Details ↓
                </button>
            </div>

            <div class="row text-center mb-3">
                <div class="col-4 border-end">
                    <strong class="d-block h4 mb-0 text-primary">{{ $summary['total'] ?? count($specs) }}</strong>
                    <small class="text-muted">Total Fields</small>
                </div>
                <div class="col-4 border-end">
                    <strong class="d-block h4 mb-0 text-success">{{ $summary['corrected'] ?? 0 }}</strong>
                    <small class="text-muted">Corrected by Update</small>
                </div>
                <div class="col-4">
                    <strong class="d-block h4 mb-0 text-secondary">{{ $summary['preserved'] ?? 0 }}</strong>
                    <small class="text-muted">Preserved from Broker</small>
                </div>
            </div>

            <div class="collapse" id="updateDetails">
                <hr class="my-2">
                @if(isset($summary['corrected_column_names']) && count($summary['corrected_column_names']) > 0)
                <small class="d-block mb-1"><strong>✅ Corrected Fields:</strong></small>
                <div class="small text-muted mb-2" style="max-height: 100px; overflow-y: auto;">
                    {{ implode(', ', $summary['corrected_column_names']) }}
                </div>
                @endif

                @if(($summary['unknown_updates'] ?? 0) > 0)
                <div class="alert alert-warning mb-0 py-2">
                    <small>
                        ⚠️ <strong>{{ $summary['unknown_updates'] }} field(s)</strong> in update file not found in broker's file:
                        <em>{{ implode(', ', $summary['unknown_update_names'] ?? []) }}</em>
                    </small>
                </div>
                @endif
            </div>
        </div>
        @endif

        <!-- Instructions -->
        <div class="alert alert-info mb-4">
            <strong>📝 Instructions:</strong> Review each field below. For <strong>Dropdown</strong> fields,
            ensure all valid options are listed in "Allowed Values" (comma-separated).
            <br><small class="text-muted">
                Fields marked <span class="badge bg-success">✅ Corrected</span> were updated by the company's file.
                Fields without badges were preserved from the broker's spreadsheet.
            </small>
        </div>

        <!-- Search/Filter Bar -->
        <div class="mb-3">
            <input type="text" id="fieldSearch" class="form-control" placeholder="🔍 Search fields..." onkeyup="filterFields()">
        </div>

        <form action="{{ route('templates.save-config') }}" method="POST" id="configForm">
            @csrf
            <input type="hidden" name="config_token" value="{{ $configToken ?? session()->getId() }}">

            <div class="table-responsive">
                <table class="table table-hover align-middle" id="fieldsTable">
                    <thead class="table-light sticky-top" style="top: 0; z-index: 10; background: #f8f9fa;">
                        <tr>
                            <th style="width: 35%;">Column Name</th>
                            <th style="width: 20%;">Field Type</th>
                            <th>Allowed Values (Comma separated)</th>
                            <th style="width: 10%;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($specs as $index => $spec)
                        @php
                            $source = $spec['source'] ?? 'broker_preserved';
                            $isCorrected = $source === 'corrected';
                            $isUnknown = $source === 'update_unknown';
                            $isAutoCorrected = $spec['auto_corrected'] ?? false;
                        @endphp
                        <tr class="field-row" data-column="{{ strtolower($spec['column_name']) }}">
                            <td>
                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                    <strong>{{ $spec['column_name'] }}</strong>

                                    @if($isCorrected)
                                        <span class="badge bg-success" title="Corrected by company update file">✅ Corrected</span>
                                    @endif

                                    @if($isUnknown)
                                        <span class="badge bg-warning text-dark" title="Field only in update file, not in broker">⚠️ New</span>
                                    @endif

                                    @if($isAutoCorrected)
                                        <span class="badge bg-info" title="Auto-corrected: {{ $spec['correction_reason'] ?? 'Applied business rules' }}">
                                            🤖 Auto-fixed
                                        </span>
                                    @endif
                                </div>

                                @if(isset($spec['warning']))
                                    <div class="small text-warning mt-1">
                                        ⚠️ {{ $spec['warning'] }}
                                    </div>
                                @endif

                                @if($isCorrected && !empty($spec['updated_fields']))
                                    <div class="small text-muted mt-1">
                                        Updated: {{ implode(', ', $spec['updated_fields']) }}
                                    </div>
                                @endif

                                <input type="hidden" name="specs[{{ $index }}][column_name]" value="{{ $spec['column_name'] }}">
                            </td>

                            <td>
                                <select name="specs[{{ $index }}][field_type]"
                                        class="form-select form-select-sm field-type-select"
                                        data-index="{{ $index }}"
                                        onchange="toggleValuesInput(this, {{ $index }})">
                                    <option value="text" {{ $spec['field_type'] == 'text' ? 'selected' : '' }}>Text</option>
                                    <option value="dropdown" {{ $spec['field_type'] == 'dropdown' ? 'selected' : '' }}>Dropdown</option>
                                    <option value="date" {{ $spec['field_type'] == 'date' ? 'selected' : '' }}>Date</option>
                                    <option value="email" {{ $spec['field_type'] == 'email' ? 'selected' : '' }}>Email</option>
                                    <option value="number" {{ $spec['field_type'] == 'number' ? 'selected' : '' }}>Number</option>
                                    <option value="boolean" {{ $spec['field_type'] == 'boolean' ? 'selected' : '' }}>Boolean</option>
                                </select>
                            </td>

                            <td>
                                <input type="text"
                                       id="values_{{ $index }}"
                                       name="specs[{{ $index }}][allowed_values]"
                                       class="form-control form-control-sm allowed-values-input"
                                       value="{{ is_array($spec['allowed_values']) ? implode(',', $spec['allowed_values']) : $spec['allowed_values'] }}"
                                       placeholder="e.g. Self,Spouse,Child"
                                       {{ $spec['field_type'] !== 'dropdown' ? 'disabled' : '' }}
                                       {{ $isAutoCorrected ? 'title="Auto-corrected - review before saving"' : '' }}>
                                <small class="text-muted allowed-values-help" id="help_{{ $index }}">
                                    {{ $spec['field_type'] === 'dropdown' ? 'Required for dropdowns' : 'Not applicable' }}
                                </small>
                            </td>

                            <td>
                                @if($isCorrected)
                                    <small class="text-success fw-bold" title="Updated by company">Corrected</small>
                                @elseif($isUnknown)
                                    <small class="text-warning fw-bold" title="Only in update file">New</small>
                                @else
                                    <small class="text-secondary" title="Preserved from broker's file">Preserved</small>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <!-- Bulk Actions -->
            <div class="d-flex justify-content-between align-items-center mt-3 pt-3 border-top">
                <div>
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="resetToDefaults()">
                        ↺ Reset All to Detected
                    </button>
                    <button type="button" class="btn btn-outline-info btn-sm" onclick="highlightDropdowns()">
                        🔍 Show Only Dropdowns
                    </button>
                </div>

                <div class="d-flex gap-2">
                    <a href="{{ route('templates.upload') }}" class="btn btn-outline-secondary">
                        ← Cancel
                    </a>
                    <button type="submit" class="btn btn-success btn-lg">
                        Save Configuration & Generate →
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Confirmation Modal -->
<div class="modal fade" id="confirmModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm Configuration</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Ready to save field specifications?</p>
                <ul class="small text-muted">
                    <li><strong id="confirmTotal">0</strong> fields configured</li>
                    <li><strong id="confirmDropdowns">0</strong> dropdown fields</li>
                    <li><strong id="confirmCorrected">0</strong> fields corrected by update</li>
                    <li>Changes will apply to generated Excel file</li>
                </ul>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" onclick="document.getElementById('configForm').submit()">
                    Confirm & Save
                </button>
            </div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
    // Toggle allowed values input based on field type
    function toggleValuesInput(selectElement, index) {
        const inputField = document.getElementById('values_' + index);
        const helpText = document.getElementById('help_' + index);

        if (selectElement.value === 'dropdown') {
            inputField.disabled = false;
            inputField.classList.remove('text-muted');
            helpText.innerText = "Required: comma-separated values";
            helpText.classList.add('text-danger');
            inputField.focus();
        } else {
            inputField.disabled = true;
            if (!inputField.dataset.originalValue) {
                inputField.dataset.originalValue = inputField.value;
            }
            inputField.value = '';
            helpText.innerText = "Not applicable for this type";
            helpText.classList.remove('text-danger');
        }
    }

    // Search/filter fields
    function filterFields() {
        const searchTerm = document.getElementById('fieldSearch').value.toLowerCase();
        const rows = document.querySelectorAll('.field-row');

        rows.forEach(row => {
            const columnName = row.dataset.column;
            if (columnName.includes(searchTerm)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    }

    // Highlight only dropdown fields
    function highlightDropdowns() {
        const rows = document.querySelectorAll('.field-row');
        let visibleCount = 0;

        rows.forEach(row => {
            const select = row.querySelector('.field-type-select');
            if (select.value === 'dropdown') {
                row.style.display = '';
                row.classList.add('table-info');
                visibleCount++;
            } else {
                row.style.display = 'none';
                row.classList.remove('table-info');
            }
        });

        alert(`Showing ${visibleCount} dropdown field(s). Clear search to see all.`);
    }

    // Reset all fields to detected types
    function resetToDefaults() {
        if (!confirm('Reset all field types to auto-detected values from uploaded files?')) return;

        document.querySelectorAll('.field-type-select').forEach(select => {
            // Reset to first option (text) as fallback
            select.value = 'text';
            const index = select.dataset.index;
            toggleValuesInput(select, index);
        });
    }

    // Show confirmation modal before submit
    document.getElementById('configForm').addEventListener('submit', function(e) {
        e.preventDefault();

        // Count configured fields
        const totalFields = document.querySelectorAll('.field-row').length;
        const dropdownCount = document.querySelectorAll('select[value="dropdown"]').length;
        const correctedCount = document.querySelectorAll('.badge.bg-success').length;

        document.getElementById('confirmTotal').textContent = totalFields;
        document.getElementById('confirmDropdowns').textContent = dropdownCount;
        document.getElementById('confirmCorrected').textContent = correctedCount;

        // Show modal
        const modal = new bootstrap.Modal(document.getElementById('confirmModal'));
        modal.show();
    });

    // Initialize: set original values for non-dropdown fields
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.allowed-values-input').forEach(input => {
            if (input.disabled && input.value) {
                input.dataset.originalValue = input.value;
            }
        });
    });
</script>

<style>
    /* Sticky table header */
    .sticky-top {
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }

    /* Highlight updated rows on hover */
    .field-row:hover {
        background-color: #f8f9fa !important;
    }

    .field-row td:first-child strong {
        font-size: 0.95rem;
    }

    /* Badge spacing */
    .badge {
        font-size: 0.75rem;
        padding: 0.35em 0.5em;
    }

    /* Responsive table */
    @media (max-width: 768px) {
        .table-responsive {
            font-size: 0.9rem;
        }
        td, th {
            padding: 0.5rem !important;
        }
    }
</style>
@endsection
