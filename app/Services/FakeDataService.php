<?php

namespace App\Services;

use Faker\Factory;
use Faker\Generator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FakeDataService
{
    protected Generator $faker;

    // =========================================================================
    // BUSINESS RULE CONSTANTS
    // =========================================================================

    protected const MAX_ENDORSEMENT_DAYS_OLD = 90;
    protected const PASSWORD_MIN_LENGTH = 8;

    // Valid values for dropdown/constrained fields
    // protected const VALID_RELATIONSHIPS = ['Self', 'Spouse', 'Child', 'Parent', 'Dependent'];
    protected const VALID_RELATIONSHIPS = ['Self', 'Spouse', 'Daughter', 'Son', 'Father', 'Mother', 'Father-in-law', 'Mother-in-law']; // Removed 'Child', 'Parent', 'Dependent'
    protected const VALID_EMPLOYEE_UNITS = ['HQ', 'North', 'South', 'East', 'West', 'Central', 'Branch-01', 'Branch-02'];
    protected const VALID_ZONES = ['North', 'South', 'East', 'West', 'Central'];
    protected const VALID_LOCATIONS = ['Mumbai', 'Delhi', 'Bangalore', 'Chennai', 'Hyderabad', 'Pune', 'Kolkata', 'Ahmedabad'];
    protected const VALID_MOBILE_COUNTRY_CODES = ['+91', '+1', '+44', '+971', '+65', '+60', '+966', '+968', '+974'];
    protected const VALID_GENDERS = ['Male', 'Female', 'Other'];
    protected const VALID_DESIGNATIONS = ['Manager', 'Developer', 'Analyst', 'Consultant', 'Executive', 'Associate', 'Team Lead', 'Director', 'VP', 'CEO'];
    // protected const VALID_GRADES = ['L1', 'L2', 'L3', 'M1', 'M2', 'M3', 'S1', 'S2', 'S3'];
    protected const VALID_GRADES = ['G1', 'G2', 'G3'];
    protected const VALID_LICENSE_TYPES = ['Professional', 'Commercial', 'Personal', 'Temporary', 'Permanent'];
    protected const VALID_ISSUING_AUTHORITIES = ['IRDAI', 'State Govt', 'Central Govt', 'Private Agency', 'Insurance Company'];
    protected const VALID_COVER_TYPES = ['Individual', 'Family Floater', 'Top-up', 'Critical Illness', 'Personal Accident'];
    protected const VALID_FLEX_PLANS = ['Basic', 'Standard', 'Premium', 'Platinum', 'Custom'];
    protected const VALID_SALUTATIONS = ['Mr.', 'Mrs.', 'Ms.', 'Dr.', 'Prof.', 'Mx.'];

    // Salary/premium ranges (INR)
    protected const SALARY_RANGES = [
        'min' => 15000,
        'max' => 500000,
        'ctc_min' => 20000,
        'ctc_max' => 800000,
    ];

    protected const SUMINSURED_RANGES = [
        'min' => 50000,
        'max' => 1000000,
        'opd_min' => 1000,
        'opd_max' => 25000,
    ];

    protected const PREMIUM_RANGES = [
        'employee_min' => 500,
        'employee_max' => 50000,
        'employer_min' => 1000,
        'employer_max' => 100000,
    ];

    // =========================================================================
    // CONSTRUCTOR
    // =========================================================================

    public function __construct(?int $seed = null)
    {
        $this->faker = Factory::create('en_IN');

        // Use provided seed for reproducibility, or randomize
        $this->faker->seed($seed ?? random_int(1, 99999));
    }

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    /**
     * Generate a single row of fake data based on field specifications.
     * Applies all business rule constraints and cross-field validations.
     */
    public function generateRow(array $specs, array $contextOverrides = []): array
    {
        $row = [];
        $context = $contextOverrides;

        // Phase 1: Generate initial values for all fields
        foreach ($specs as $spec) {
            $columnName = $spec['column_name'];

            // Generate value for ALL columns (including potential formula columns)
            $value = $this->generateValue($spec, $context);
            $row[$columnName] = $value;
            $context[$columnName] = $value;

            // Add metadata: is this column likely to contain formulas in the template?
            $row['_formula_column_' . $columnName] = $this->isLikelyFormulaColumn($columnName);
        }

        // Phase 2: Apply cross-field validations and corrections
        $row = $this->applyCrossFieldValidations($row, $specs, $context);

        // Phase 3: Final sanitization and format enforcement
        $row = $this->sanitizeRow($row, $specs);

        return $row;
    }

    /**
 * Detect columns that typically contain Excel formulas in insurance templates
 * Used to decide whether to overwrite cell values during export
 */
    protected function isLikelyFormulaColumn(string $columnName): bool
    {
        $formulaIndicators = [
            'premium', 'suminsured.*premium', 'no of time', 'employer premium',
            'employee premium', 'opd.*premium', 'endorsement id', 'removal id',
            'correction id', 'insurer.*id'
        ];

        $lowerName = strtolower($columnName);

        foreach ($formulaIndicators as $indicator) {
            if (preg_match('/' . str_replace('*', '.*', $indicator) . '/i', $lowerName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate multiple rows of fake data.
     */
    public function generateRows(array $specs, int $count, array $globalOverrides = []): array
    {
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            // Allow per-row overrides if needed
            $rowOverrides = $globalOverrides[$i] ?? [];
            $rows[] = $this->generateRow($specs, $rowOverrides);
        }

        return $rows;
    }

    // =========================================================================
    // VALUE GENERATION (Phase 1)
    // =========================================================================

    protected function generateValue(array $spec, array &$context)
    {
        $type = $spec['field_type'] ?? 'text';
        $name = strtolower($spec['column_name']);
        $allowedValues = $spec['allowed_values'] ?? [];

        return match ($type) {
            'dropdown' => $this->generateDropdownValue($name, $allowedValues),
            'boolean' => $this->generateBooleanValue(),
            'date' => $this->generateConstrainedDate($name, $context),
            'email' => $this->generateConstrainedEmail($name),
            'number' => $this->generateConstrainedNumber($name, $spec),
            'text' => $this->generateConstrainedText($name, $spec, $context),
            default => $this->generateDefaultValue($type),
        };
    }

    protected function generateDefaultValue(string $type): string
    {
        return match ($type) {
            'text' => $this->faker->words(3, true),
            'number' => $this->faker->numberBetween(1, 1000),
            'date' => $this->faker->dateTimeThisYear()->format('Y-m-d'),
            'email' => $this->faker->safeEmail(),
            default => '',
        };
    }

    // -------------------------------------------------------------------------
    // Dropdown Generation
    // -------------------------------------------------------------------------

    protected function generateDropdownValue(string $name, array $allowedValues): string
    {
        if (empty($allowedValues)) {
            return $this->getFallbackForDropdown($name);
        }

        // Apply business rule filters based on field name
        $filtered = match (true) {
            $this->isRelationshipField($name) => $this->filterByValidValues($allowedValues, self::VALID_RELATIONSHIPS),
            $this->isZoneField($name) => $this->filterByValidValues($allowedValues, self::VALID_ZONES),
            $this->isGenderField($name) => $this->filterByValidValues($allowedValues, self::VALID_GENDERS),
            $this->isUnitField($name) => $this->filterByValidValues($allowedValues, self::VALID_EMPLOYEE_UNITS),
            $this->isLocationField($name) => $this->filterByValidValues($allowedValues, self::VALID_LOCATIONS),
            $this->isDesignationField($name) => $this->filterByValidValues($allowedValues, self::VALID_DESIGNATIONS),
            $this->isGradeField($name) => $this->filterByValidValues($allowedValues, self::VALID_GRADES),
            $this->isCoverTypeField($name) => $this->filterByValidValues($allowedValues, self::VALID_COVER_TYPES),
            $this->isFlexPlanField($name) => $this->filterByValidValues($allowedValues, self::VALID_FLEX_PLANS),
            default => $allowedValues,
        };

        // Return random element from filtered list, or fallback
        return $this->faker->randomElement(
            !empty($filtered) ? array_values($filtered) : $allowedValues
        );
    }

    protected function getFallbackForDropdown(string $name): string
    {
        return match (true) {
            $this->isRelationshipField($name) => 'Self',
            $this->isGenderField($name) => 'Male',
            $this->isZoneField($name) => 'North',
            $this->isUnitField($name) => 'HQ',
            $this->isBooleanLikeField($name) => $this->faker->randomElement(['Yes', 'No']),
            default => 'Default',
        };
    }

    protected function filterByValidValues(array $allowed, array $valid): array
    {
        $intersection = array_intersect($allowed, $valid);
        return !empty($intersection) ? $intersection : $valid;
    }

    // -------------------------------------------------------------------------
    // Boolean Generation
    // -------------------------------------------------------------------------

    protected function generateBooleanValue(): string
    {
        return $this->faker->randomElement(['Yes', 'No']);
    }

    // -------------------------------------------------------------------------
    // Date Generation with Constraints
    // -------------------------------------------------------------------------

    protected function generateConstrainedDate(string $name, array $context): string
    {
        // Demo Email Expiry: must be after today
        if ($this->isDemoEmailExpiryField($name)) {
            return $this->faker->dateTimeBetween('+1 day', '+2 years')->format('Y-m-d');
        }

        // Effective Date / Inception: must be before policy end (~1 year from now)
        if ($this->isEffectiveDateField($name) || $this->isInceptionField($name)) {
            return $this->faker->dateTimeBetween('now', '+11 months')->format('Y-m-d');
        }

        // Data Received On: within endorsement window + ISO format
        if ($this->isDataReceivedOnField($name)) {
            return $this->faker->dateTimeBetween(
                '-' . self::MAX_ENDORSEMENT_DAYS_OLD . ' days',
                'now'
            )->format('Y-m-d');
        }

        // License dates
        if ($this->isLicenseStartDateField($name)) {
            return $this->faker->dateTimeBetween('-5 years', '-1 day')->format('Y-m-d');
        }
        if ($this->isLicenseExpiryField($name)) {
            return $this->faker->dateTimeBetween('now', '+5 years')->format('Y-m-d');
        }
        if ($this->isLicenseIssuanceField($name)) {
            return $this->faker->dateTimeBetween('-10 years', '-1 day')->format('Y-m-d');
        }
        if ($this->isCoverEffectiveField($name)) {
            return $this->faker->dateTimeBetween('-1 year', '+6 months')->format('Y-m-d');
        }

        // Marriage date: adult range
        if ($this->isMarriageDateField($name)) {
            return $this->faker->dateTimeBetween('-50 years', '-18 years')->format('Y-m-d');
        }

        // Employee DOB: 18-65 years old
        if ($this->isEmployeeDobField($name)) {
            return $this->faker->dateTimeBetween('-65 years', '-18 years')->format('Y-m-d');
        }

        // Employee DOJ: 1-20 years ago (DOB divergence handled in cross-validation)
        if ($this->isEmployeeDojField($name)) {
            return $this->faker->dateTimeBetween('-20 years', '-1 day')->format('Y-m-d');
        }

        // Insured/Nominee DOB: broader age range
        if ($this->isInsuredMemberDobField($name)) {
            return $this->faker->dateTimeBetween('-80 years', 'now')->format('Y-m-d');
        }
        if ($this->isNomineeDobField($name)) {
            return $this->faker->dateTimeBetween('-70 years', 'now')->format('Y-m-d');
        }

        // Insurer dates (endorsement/correction/removal)
        if ($this->isInsurerDateField($name)) {
            return $this->faker->dateTimeBetween('-2 years', 'now')->format('Y-m-d');
        }

        // Default: reasonable range
        return $this->faker->dateTimeBetween('-10 years', '+2 years')->format('Y-m-d');
    }

    // -------------------------------------------------------------------------
    // Email Generation with Constraints
    // -------------------------------------------------------------------------

    protected function generateConstrainedEmail(string $name): string
    {
        // Demo emails: 30% chance of being empty
        if ($this->isDemoField($name) && $this->faker->boolean(30)) {
            return '';
        }

        // Employee emails: company domain for consistency
        if ($this->isEmployeeField($name) && !$this->isInsuredField($name)) {
            $first = Str::slug($this->faker->firstName());
            $last = Str::slug($this->faker->lastName());
            return "{$first}.{$last}@company.com";
        }

        // Insured/Nominee/Appointee: standard format
        return $this->faker->safeEmail();
    }

    // -------------------------------------------------------------------------
    // Number/ID Generation with Constraints
    // -------------------------------------------------------------------------

    protected function generateConstrainedNumber(string $name, array $spec)
    {
        $maxLength = $spec['max_length'] ?? null;

        // Mobile No Code: valid country codes only
        if ($this->isMobileCodeField($name)) {
            return $this->faker->randomElement(self::VALID_MOBILE_COUNTRY_CODES);
        }

        // Mobile Number: 10 digits, India format (starts with 6-9)
        if ($this->isMobileNumberField($name) && !$this->isMobileCodeField($name)) {
            return $this->faker->numerify('9#########');
        }

        // Pin Code: exactly 6 digits
        if ($this->isPinCodeField($name)) {
            return $this->faker->numerify('######');
        }

        // Aadhar: exactly 12 digits
        if ($this->isAadharField($name)) {
            return $this->faker->numerify('############');
        }

        // ABHA ID: alphanumeric with hyphens
        if ($this->isAbhaField($name)) {
            return strtoupper($this->faker->bothify('??-####-????-####'));
        }

        // Batch ID: alphanumeric only (constraint enforced)
        if ($this->isBatchIdField($name)) {
            return $this->generateAlphanumericBatchId();
        }

        // Insurer Client Id: only [A-Za-z0-9_]
        if ($this->isInsurerClientIdField($name)) {
            return $this->generateInsurerClientId();
        }

        // Nominee Contribution: 0-100, integer only
        if ($this->isNomineeContributionField($name)) {
            return $this->faker->numberBetween(0, 100);
        }

        // Salary/CTC/Suminsured/Premium: realistic ranges
        if ($this->isSalaryField($name)) {
            return $this->faker->numberBetween(
                self::SALARY_RANGES['min'],
                self::SALARY_RANGES['max']
            );
        }
        if ($this->isCtcField($name)) {
            return $this->faker->numberBetween(
                self::SALARY_RANGES['ctc_min'],
                self::SALARY_RANGES['ctc_max']
            );
        }
        if ($this->isSuminsuredField($name)) {
            $isOpd = str_contains($name, 'opd');
            return $this->faker->numberBetween(
                $isOpd ? self::SUMINSURED_RANGES['opd_min'] : self::SUMINSURED_RANGES['min'],
                $isOpd ? self::SUMINSURED_RANGES['opd_max'] : self::SUMINSURED_RANGES['max']
            );
        }
        if ($this->isPremiumField($name)) {
            $isEmployer = str_contains($name, 'employer');
            return $this->faker->numberBetween(
                $isEmployer ? self::PREMIUM_RANGES['employer_min'] : self::PREMIUM_RANGES['employee_min'],
                $isEmployer ? self::PREMIUM_RANGES['employer_max'] : self::PREMIUM_RANGES['employee_max']
            );
        }

        // Employee Code: EMP##### format
        if ($this->isEmployeeCodeField($name)) {
            return strtoupper($this->faker->bothify('EMP#####'));
        }

        // Family/Installment numbers: small positive integers
        if ($this->isFamilyNumberField($name) || $this->isInstallmentField($name)) {
            return $this->faker->numberBetween(1, 12);
        }

        // Variables in Salary / Number of Time Salary: small integers
        if (str_contains($name, 'variables in salary') || str_contains($name, 'number of time salary')) {
            return $this->faker->numberBetween(1, 5);
        }

        // Default numeric with optional max length
        if ($maxLength) {
            $max = min(999999999, (10 ** $maxLength) - 1);
            return $this->faker->numberBetween(1, $max);
        }

        return $this->faker->numberBetween(1, 999999);
    }

    // -------------------------------------------------------------------------
    // Text Generation with Constraints
    // -------------------------------------------------------------------------

    protected function generateConstrainedText(string $name, array $spec, array $context): string
    {
        // Names (first/last/member/nominee/appointee)
        if ($this->isFirstNameField($name)) {
            return $this->faker->firstName();
        }
        if ($this->isLastNameField($name)) {
            return $this->faker->lastName();
        }
        if ($this->isMemberNameField($name) || $this->isNomineeField($name) || $this->isAppointeeField($name)) {
            return $this->faker->name();
        }

        // Salutation
        if ($this->isSalutationField($name)) {
            return $this->faker->randomElement(self::VALID_SALUTATIONS);
        }

        // Gender: use allowed values if provided
        if ($this->isGenderField($name)) {
            $allowed = $spec['allowed_values'] ?? [];
            return $this->faker->randomElement(
                !empty($allowed) ? $allowed : self::VALID_GENDERS
            );
        }

        // Employee Unit: must be from valid list
        if ($this->isUnitField($name)) {
            return $this->faker->randomElement(self::VALID_EMPLOYEE_UNITS);
        }

        // Employee Code: enforce EMP##### pattern
        if ($this->isEmployeeCodeField($name)) {
            return strtoupper($this->faker->bothify('EMP#####'));
        }

        // Designation/Grade/Occupation
        if ($this->isDesignationField($name)) {
            return $this->faker->randomElement(self::VALID_DESIGNATIONS);
        }
        if ($this->isGradeField($name)) {
        // Match backend salary-based grade logic for employer 18/5178
            $salary = $context['Employee Annual Salary'] ?? 0;
            if ($salary <= 1100000) {
                return 'G1';
            } elseif ($salary <= 2100000) {
                return 'G2';
            } else {
                return 'G3';
            }
        }

        if ($this->isRelationshipField($name)) {
            // Backend expects specific relationship values with IDs
            $allowed = $spec['allowed_values'] ?? [];
            $filtered = $this->filterByValidValues($allowed, self::VALID_RELATIONSHIPS);

            // Weight 'Self' higher (80% of records should be employees)
            if ($this->faker->boolean(80)) {
                return 'Self';
            }

            return $this->faker->randomElement(
                !empty($filtered) ? array_values($filtered) : ['Self']
            );
        }

        if ($this->isOccupationField($name)) {
            return $this->faker->randomElement(self::VALID_DESIGNATIONS);
        }

        // Location/Zone
        if ($this->isLocationField($name)) {
            return $this->faker->randomElement(self::VALID_LOCATIONS);
        }
        if ($this->isZoneField($name)) {
            return $this->faker->randomElement(self::VALID_ZONES);
        }

        // Address components
        if ($this->isAddressField($name)) {
            return $this->faker->address();
        }
        if ($this->isCityField($name)) {
            return $this->faker->city();
        }
        if ($this->isStateField($name)) {
            return $this->faker->state();
        }

        // Password: must meet complexity requirements
        if ($this->isPasswordField($name)) {
            return $this->generateSecurePassword();
        }

        // Cover Type / Flex Plan / Self SI Selection
        if ($this->isCoverTypeField($name)) {
            return $this->faker->randomElement(self::VALID_COVER_TYPES);
        }
        if ($this->isFlexPlanField($name)) {
            return $this->faker->randomElement(self::VALID_FLEX_PLANS);
        }
        if ($this->isSelfSiSelectionField($name)) {
            return $this->faker->randomElement(['Yes', 'No', 'Partial']);
        }

        // License fields
        if ($this->isLicenseTypeField($name)) {
            return $this->faker->randomElement(self::VALID_LICENSE_TYPES);
        }
        if ($this->isIssuingAuthorityField($name)) {
            return $this->faker->randomElement(self::VALID_ISSUING_AUTHORITIES);
        }

        // Ecard URL
        if ($this->isEcardUrlField($name)) {
            return 'https://ecards.insurance.com/member/' . $this->faker->uuid();
        }

        // IDs: Serial Number, VPN ID, Account Number, TPA Member ID, etc.
        if ($this->isIdField($name)) {
            return strtoupper($this->faker->bothify('???-#####'));
        }

        // Boolean-like text fields (Is VIP, Has Death Certificate, etc.)
        if ($this->isBooleanLikeField($name)) {
            return $this->faker->randomElement(['Yes', 'No']);
        }

        // Other 1-5 fields: generic placeholder values
        if (preg_match('/^other\s*[1-5]$/i', trim($name))) {
            return $this->faker->words(2, true);
        }

        // Default text
        return $this->faker->words(3, true);
    }

    // =========================================================================
    // CROSS-FIELD VALIDATIONS (Phase 2)
    // =========================================================================

    protected function applyCrossFieldValidations(array $row, array $specs, array $context): array
    {
        // Constraint: Employee DOB & DOJ cannot be same + DOJ must be after DOB + at least 18 years after DOB
        $dobKey = $this->findKey($row, ['Employee DOB', 'employee dob']);
        $dojKey = $this->findKey($row, ['Employee Date of Joining', 'employee date of joining', 'DOJ', 'doj']);

        if ($dobKey && $dojKey && !empty($row[$dobKey]) && !empty($row[$dojKey])) {
            $dob = $row[$dobKey];
            $doj = $row[$dojKey];

            $dobTs = strtotime($dob);
            $dojTs = strtotime($doj);
            $minDojTs = strtotime('+18 years', $dobTs);
            $today = time();

            // Regenerate if: same date, DOJ before DOB, or DOJ before 18th birthday
            if ($dobTs === $dojTs || $dojTs <= $dobTs || $dojTs < $minDojTs) {
                // Ensure min DOJ is in the past
                $minDojDate = $minDojTs < $today ? date('Y-m-d', $minDojTs) : date('Y-m-d', strtotime('-1 year'));
                $maxDojDate = date('Y-m-d', strtotime('-1 day'));

                $row[$dojKey] = $this->faker->dateTimeBetween($minDojDate, $maxDojDate)->format('Y-m-d');
            }
        }

        // Constraint: Suminsured must match policy (minimum threshold)
        $siKey = $this->findKey($row, ['Suminsured', 'suminsured', 'Sum Insured', 'sum insured']);
        if ($siKey && isset($row[$siKey]) && (int) $row[$siKey] < self::SUMINSURED_RANGES['min']) {
            $row[$siKey] = $this->faker->numberBetween(
                self::SUMINSURED_RANGES['min'],
                self::SUMINSURED_RANGES['max']
            );
        }

        $gradeKey = $this->findKey($row, ['Employee Grade', 'employee grade']);
        $salaryKey = $this->findKey($row, ['Employee Annual Salary', 'employee annual salary']);
        if ($gradeKey && $salaryKey && isset($row[$gradeKey]) && isset($row[$salaryKey])) {
            $salary = (int) $row[$salaryKey];
            if ($salary <= 1100000) {
                $row[$gradeKey] = 'G1';
            } elseif ($salary <= 2100000) {
                $row[$gradeKey] = 'G2';
            } else {
                $row[$gradeKey] = 'G3';
            }
        }

        // Constraint: Employee Code must match EMP##### pattern for lookup success
        $empCodeKey = $this->findKey($row, ['Employee Code', 'employee code']);
        if ($empCodeKey && isset($row[$empCodeKey]) && !preg_match('/^EMP\d{5}$/', $row[$empCodeKey])) {
            $row[$empCodeKey] = strtoupper($this->faker->bothify('EMP#####'));
        }

        // Constraint: Relationship must be covered by policy
        $relKey = $this->findKey($row, [
            'Relationship with Employee',
            'relationship with employee',
            'Relationship With Insured',
            'Relation Of Appointee With Nominee'
        ]);
        if ($relKey && isset($row[$relKey]) && !in_array($row[$relKey], self::VALID_RELATIONSHIPS, true)) {
            // Map 'Child' to 'Daughter' or 'Son' based on gender
            if ($row[$relKey] === 'Child') {
                $genderKey = $this->findKey($row, ['Insured Member Gender', 'insured member gender']);
                $row[$relKey] = ($genderKey && $row[$genderKey] === 'Female') ? 'Daughter' : 'Son';
            } else {
                $row[$relKey] = 'Self'; // Safe default
            }
        }

        // Constraint: Nominee Contribution must be numeric 0-100
        $ncKey = $this->findKey($row, ['Nominee Contribution', 'nominee contribution']);
        if ($ncKey && isset($row[$ncKey])) {
            $nc = $row[$ncKey];
            if (!is_numeric($nc) || (int) $nc < 0 || (int) $nc > 100) {
                $row[$ncKey] = $this->faker->numberBetween(0, 100);
            }
        }

        // Constraint: Batch ID must be alphanumeric
        $batchKey = $this->findKey($row, ['Batch Id', 'batch id', 'Batch', 'batch']);
        if ($batchKey && isset($row[$batchKey])) {
            $sanitized = $this->sanitizeAlphanumeric($row[$batchKey]);
            $row[$batchKey] = !empty($sanitized) ? $sanitized : $this->generateAlphanumericBatchId();
        }

        // Constraint: Insurer Client Id must be [A-Za-z0-9_]{8,16}
        $iciKey = $this->findKey($row, ['Insurer Client Id', 'insurer client id']);
        if ($iciKey && isset($row[$iciKey]) && !preg_match('/^[A-Za-z0-9_]{8,16}$/', $row[$iciKey])) {
            $row[$iciKey] = $this->generateInsurerClientId();
        }

        // Constraint: Mobile country code must be valid
        $codeKey = $this->findKey($row, ['Mobile No Code', 'mobile no code', 'Country Code', 'country code']);
        if ($codeKey && isset($row[$codeKey]) && !in_array($row[$codeKey], self::VALID_MOBILE_COUNTRY_CODES, true)) {
            $row[$codeKey] = $this->faker->randomElement(self::VALID_MOBILE_COUNTRY_CODES);
        }

        // Constraint: Effective Date must be before policy end (~1 year from now)
        $effKey = $this->findKey($row, ['Effective Date', 'effective date', 'Cover Effective Date', 'cover effective date']);
        if ($effKey && isset($row[$effKey])) {
            $policyEnd = date('Y-m-d', strtotime('+1 year'));
            if ($row[$effKey] >= $policyEnd) {
                $row[$effKey] = $this->faker->dateTimeBetween('now', '+11 months')->format('Y-m-d');
            }
        }

        // Constraint: Demo Email Expiry must be after today
        $demoExpKey = $this->findKey($row, [
            'Demo Email Expiry Date',
            'demo email expiry date',
            'Demo Email Expiry',
            'demo email expiry'
        ]);
        if ($demoExpKey && isset($row[$demoExpKey]) && !empty($row[$demoExpKey])) {
            if ($row[$demoExpKey] <= date('Y-m-d')) {
                $row[$demoExpKey] = $this->faker->dateTimeBetween('+1 day', '+2 years')->format('Y-m-d');
            }
        }

        // Constraint: Data Received On must be within window AND ISO format
        $drKey = $this->findKey($row, ['Data Received On', 'data received on']);
        if ($drKey && isset($row[$drKey])) {
            $cutoff = date('Y-m-d', strtotime('-' . self::MAX_ENDORSEMENT_DAYS_OLD . ' days'));
            $today = date('Y-m-d');

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $row[$drKey]) ||
                $row[$drKey] < $cutoff ||
                $row[$drKey] > $today) {
                $row[$drKey] = $this->faker->dateTimeBetween(
                    '-' . self::MAX_ENDORSEMENT_DAYS_OLD . ' days',
                    'now'
                )->format('Y-m-d');
            }
        }

        // Constraint: Password must have lowercase+uppercase+number+symbol
        $pwKey = $this->findKey($row, [
            'Default Password',
            'default password',
            'Password',
            'password'
        ]);
        if ($pwKey && isset($row[$pwKey]) && !$this->isSecurePassword($row[$pwKey])) {
            $row[$pwKey] = $this->generateSecurePassword();
        }

        // Constraint: Employee Unit must be valid
        $unitKey = $this->findKey($row, ['Employee Unit', 'employee unit']);
        if ($unitKey && isset($row[$unitKey]) && !in_array($row[$unitKey], self::VALID_EMPLOYEE_UNITS, true)) {
            $row[$unitKey] = $this->faker->randomElement(self::VALID_EMPLOYEE_UNITS);
        }

        // Constraint: Gender must be valid
        $genderKey = $this->findKey($row, [
            'Employee Gender',
            'Insured Member Gender',
            'employee gender',
            'insured member gender'
        ]);
        if ($genderKey && isset($row[$genderKey]) && !in_array($row[$genderKey], self::VALID_GENDERS, true)) {
            $row[$genderKey] = $this->faker->randomElement(self::VALID_GENDERS);
        }

        // Constraint: Zone must be valid
        $zoneKey = $this->findKey($row, ['Zone', 'zone']);
        if ($zoneKey && isset($row[$zoneKey]) && !in_array($row[$zoneKey], self::VALID_ZONES, true)) {
            $row[$zoneKey] = $this->faker->randomElement(self::VALID_ZONES);
        }

        return $row;
    }

    // =========================================================================
    // FINAL SANITIZATION (Phase 3)
    // =========================================================================

    protected function sanitizeRow(array $row, array $specs): array
    {
        foreach ($specs as $spec) {
            $columnName = $spec['column_name'];
            $value = $row[$columnName] ?? null;

            if ($value === null) {
                continue;
            }

            // Trim all string values
            if (is_string($value)) {
                $row[$columnName] = trim($value);
            }

            // Ensure dates are in Y-m-d format
            if (($spec['field_type'] ?? '') === 'date' && !empty($row[$columnName])) {
                $ts = strtotime($row[$columnName]);
                if ($ts !== false) {
                    $row[$columnName] = date('Y-m-d', $ts);
                }
            }

            // Ensure numbers are numeric
            if (($spec['field_type'] ?? '') === 'number' && !empty($row[$columnName])) {
                if (is_numeric($row[$columnName])) {
                    $row[$columnName] = (int) $row[$columnName];
                }
            }
        }

        return $row;
    }

    // =========================================================================
    // HELPER METHODS - Field Detection
    // =========================================================================

    protected function findKey(array $row, array $candidates): ?string
    {
        $lowerRow = array_change_key_case($row, CASE_LOWER);

        foreach ($candidates as $candidate) {
            $lower = strtolower($candidate);
            if (array_key_exists($lower, $lowerRow)) {
                // Return the original-cased key from $row
                foreach (array_keys($row) as $k) {
                    if (strtolower($k) === $lower) {
                        return $k;
                    }
                }
            }
        }
        return null;
    }

    // Field detection helpers (case-insensitive)
    protected function isRelationshipField(string $name): bool {
        return Str::contains($name, 'relationship');
    }
    protected function isZoneField(string $name): bool {
        return Str::contains($name, 'zone');
    }
    protected function isGenderField(string $name): bool {
        return Str::contains($name, 'gender');
    }
    protected function isUnitField(string $name): bool {
        return Str::contains($name, 'employee unit');
    }
    protected function isLocationField(string $name): bool {
        return Str::contains($name, 'location') && !Str::contains($name, 'address');
    }
    protected function isDesignationField(string $name): bool {
        return Str::contains($name, 'designation');
    }
    protected function isGradeField(string $name): bool {
        return Str::contains($name, 'grade');
    }
    protected function isCoverTypeField(string $name): bool {
        return Str::contains($name, 'cover type');
    }
    protected function isFlexPlanField(string $name): bool {
        return Str::contains($name, 'flex plan');
    }
    protected function isBooleanLikeField(string $name): bool {
        return preg_match('/\b(is_|has_|should_)/i', $name);
    }

    protected function isDemoEmailExpiryField(string $name): bool {
        return Str::contains($name, 'demo email expiry');
    }
    protected function isEffectiveDateField(string $name): bool {
        return Str::contains($name, 'effective date');
    }
    protected function isInceptionField(string $name): bool {
        return Str::contains($name, 'inception') || Str::contains($name, 'endorsement date');
    }
    protected function isDataReceivedOnField(string $name): bool {
        return Str::contains($name, 'data received on');
    }
    protected function isLicenseStartDateField(string $name): bool {
        return Str::contains($name, 'license start');
    }
    protected function isLicenseExpiryField(string $name): bool {
        return Str::contains($name, 'license expiry');
    }
    protected function isLicenseIssuanceField(string $name): bool {
        return Str::contains($name, 'license issuance');
    }
    protected function isCoverEffectiveField(string $name): bool {
        return Str::contains($name, 'cover effective');
    }
    protected function isMarriageDateField(string $name): bool {
        return Str::contains($name, 'marriage');
    }
    protected function isEmployeeDobField(string $name): bool {
        return Str::contains($name, 'employee dob');
    }
    protected function isEmployeeDojField(string $name): bool {
        return Str::contains($name, 'employee date of joining') ||
               (Str::contains($name, 'doj') && Str::contains($name, 'employee'));
    }
    protected function isInsuredMemberDobField(string $name): bool {
        return Str::contains($name, 'insured member dob');
    }
    protected function isNomineeDobField(string $name): bool {
        return Str::contains($name, 'nominee dob');
    }
    protected function isInsurerDateField(string $name): bool {
        return Str::contains($name, 'insurer') && Str::contains($name, 'date');
    }

    protected function isDemoField(string $name): bool {
        return Str::contains($name, 'demo');
    }
    protected function isEmployeeField(string $name): bool {
        return Str::contains($name, 'employee') && !Str::contains($name, 'insured');
    }
    protected function isInsuredField(string $name): bool {
        return Str::contains($name, 'insured');
    }

    protected function isMobileCodeField(string $name): bool {
        return Str::contains($name, 'mobile no code') || Str::contains($name, 'country code');
    }
    protected function isMobileNumberField(string $name): bool {
        return Str::contains($name, 'mobile number') || Str::contains($name, 'mobile');
    }
    protected function isPinCodeField(string $name): bool {
        return Str::contains($name, 'pin code');
    }
    protected function isAadharField(string $name): bool {
        return Str::contains($name, 'aadhar');
    }
    protected function isAbhaField(string $name): bool {
        return Str::contains($name, 'abha');
    }
    protected function isBatchIdField(string $name): bool {
        return Str::contains($name, 'batch id') || Str::contains($name, 'batch');
    }
    protected function isInsurerClientIdField(string $name): bool {
        return Str::contains($name, 'insurer client id');
    }
    protected function isNomineeContributionField(string $name): bool {
        return Str::contains($name, 'nominee contribution');
    }
    protected function isSalaryField(string $name): bool {
        return Str::contains($name, 'salary') && !Str::contains($name, 'variables');
    }
    protected function isCtcField(string $name): bool {
        return Str::contains($name, 'ctc');
    }
    protected function isSuminsuredField(string $name): bool {
        return Str::contains($name, 'suminsured') || Str::contains($name, 'sum insured');
    }
    protected function isPremiumField(string $name): bool {
        return Str::contains($name, 'premium');
    }
    protected function isEmployeeCodeField(string $name): bool {
        return Str::contains($name, 'employee code');
    }
    protected function isFamilyNumberField(string $name): bool {
        return Str::contains($name, 'family number');
    }
    protected function isInstallmentField(string $name): bool {
        return Str::contains($name, 'installment');
    }

    protected function isFirstNameField(string $name): bool {
        return Str::contains($name, 'first name');
    }
    protected function isLastNameField(string $name): bool {
        return Str::contains($name, 'last name');
    }
    protected function isMemberNameField(string $name): bool {
        return Str::contains($name, 'member name') || Str::contains($name, 'insured member first') || Str::contains($name, 'insured member last');
    }
    protected function isNomineeField(string $name): bool {
        return Str::contains($name, 'nominee') && !Str::contains($name, 'contribution');
    }
    protected function isAppointeeField(string $name): bool {
        return Str::contains($name, 'appointee');
    }
    protected function isSalutationField(string $name): bool {
        return Str::contains($name, 'salutation');
    }
    protected function isAddressField(string $name): bool {
        return Str::contains($name, 'address') && !Str::contains($name, 'email');
    }
    protected function isCityField(string $name): bool {
        return Str::contains($name, 'city');
    }
    protected function isStateField(string $name): bool {
        return Str::contains($name, 'state');
    }
    protected function isPasswordField(string $name): bool {
        return Str::contains($name, 'password');
    }
    protected function isSelfSiSelectionField(string $name): bool {
        return Str::contains($name, 'self si selection');
    }
    protected function isLicenseTypeField(string $name): bool {
        return Str::contains($name, 'type of license');
    }
    protected function isIssuingAuthorityField(string $name): bool {
        return Str::contains($name, 'issuing authority');
    }
    protected function isEcardUrlField(string $name): bool {
        return Str::contains($name, 'ecard url');
    }
    protected function isIdField(string $name): bool {
        return preg_match('/(serial number|vpn id|account number|tpa member id|insurer.*id)/i', $name);
    }
    protected function isOccupationField(string $name): bool {
        return Str::contains($name, 'occupation');
    }

    // =========================================================================
    // HELPER METHODS - Value Generation & Validation
    // =========================================================================

    protected function generateAlphanumericBatchId(): string
    {
        return 'BATCH' . strtoupper($this->faker->bothify('#####'));
    }

    protected function sanitizeAlphanumeric(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9]/', '', (string) $value);
    }

    protected function generateInsurerClientId(): string
    {
        return $this->faker->regexify('[A-Za-z0-9_]{8,16}');
    }

    protected function isSecurePassword(string $password): bool
    {
        return strlen($password) >= self::PASSWORD_MIN_LENGTH
            && preg_match('/[a-z]/', $password)
            && preg_match('/[A-Z]/', $password)
            && preg_match('/[0-9]/', $password)
            && preg_match('/[^A-Za-z0-9]/', $password);
    }

    protected function generateSecurePassword(): string
    {
        $charSets = [
            'lower' => 'abcdefghijklmnopqrstuvwxyz',
            'upper' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
            'num' => '0123456789',
            'sym' => '!@#$%^&*()_+-=[]{}|;:,.<>?',
        ];

        // Guarantee one character from each required set
        $password = [
            $charSets['lower'][random_int(0, strlen($charSets['lower']) - 1)],
            $charSets['upper'][random_int(0, strlen($charSets['upper']) - 1)],
            $charSets['num'][random_int(0, strlen($charSets['num']) - 1)],
            $charSets['sym'][random_int(0, strlen($charSets['sym']) - 1)],
        ];

        // Fill remaining length
        $allChars = implode('', $charSets);
        for ($i = 4; $i < self::PASSWORD_MIN_LENGTH; $i++) {
            $password[] = $allChars[random_int(0, strlen($allChars) - 1)];
        }

        shuffle($password);
        return implode('', $password);
    }

    // =========================================================================
    // EXTENSION POINTS (Override in child class if needed)
    // =========================================================================

    /**
     * Hook for custom field generation logic.
     * Override in a subclass to add domain-specific rules.
     */
    protected function generateCustomValue(string $columnName, array $spec, array $context)
    {
        // Example:
        // if ($columnName === 'Custom_Field_X') {
        //     return 'custom_value';
        // }
        return null; // Return null to use default generation
    }

    /**
     * Hook for custom cross-field validations.
     * Override in a subclass to add domain-specific rules.
     */
    protected function applyCustomValidations(array $row, array $specs, array $context): array
    {
        // Example:
        // if (isset($row['Field_A']) && isset($row['Field_B'])) {
        //     // Custom validation logic
        // }
        return $row;
    }
    /**
 * Check if a column typically contains Excel formulas (should not be overwritten)
 */
    protected function isFormulaColumn(string $columnName): bool
    {
        $formulaColumns = [
            'Employee Premium',
            'Employer Premium',
            'OPD Employer Premium',
            'OPD Employee Premium',
            'Employee No of Time Suminsured',
            'Insurer Endorsement Id',
            'Insurer Removal Id',
            'Insurer Correction Id',
            // Add any other columns you know contain formulas
        ];

        foreach ($formulaColumns as $formulaCol) {
            if (stripos($columnName, $formulaCol) !== false) {
                return true;
            }
        }

        return false;
    }
}
