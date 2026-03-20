<?php

namespace App\Services;

use Faker\Factory;
use Faker\Generator;

class FakeDataService
{
    protected Generator $faker;

    public function __construct()
    {
        // Use Indian locale for realistic test data
        $this->faker = Factory::create('en_IN');
        $this->faker->seed(42); // Optional: for reproducible test data
    }

    /**
     * Generate a single row of fake data based on field specifications
     */
    public function generateRow(array $specs): array
    {
        $row = [];

        foreach ($specs as $spec) {
            $value = $this->generateValue($spec);
            $row[$spec['column_name']] = $value;
        }

        return $row;
    }

    /**
     * Generate multiple rows
     */
    public function generateRows(array $specs, int $count): array
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = $this->generateRow($specs);
        }
        return $rows;
    }

    /**
     * Generate a single value based on field spec
     */
    protected function generateValue(array $spec)
    {
        $type = $spec['field_type'];
        $name = strtolower($spec['column_name']);

        // Dropdown: pick from allowed values
        if ($type === 'dropdown' && !empty($spec['allowed_values'])) {
            return $this->faker->randomElement($spec['allowed_values']);
        }

        // Boolean
        if ($type === 'boolean') {
            return $this->faker->randomElement(['Yes', 'No']);
        }

        // Date fields with context-aware ranges
        if ($type === 'date') {
            return $this->generateDate($name);
        }

        // Email
        if ($type === 'email') {
            return $this->generateEmail($name);
        }

        // Number/ID fields with format rules
        if ($type === 'number') {
            return $this->generateNumber($spec, $name);
        }

        // Text fields with context-aware generation
        if ($type === 'text') {
            return $this->generateText($spec, $name);
        }

        return '';
    }

    /**
     * Generate context-aware date values
     */
    protected function generateDate(string $columnName): string
    {
        $name = strtolower($columnName);

        // DOB: 18-60 years ago
        if (str_contains($name, 'dob')) {
            return $this->faker->dateTimeBetween('-60 years', '-18 years')->format('Y-m-d');
        }

        // Joining date: 1-20 years ago
        if (str_contains($name, 'joining')) {
            return $this->faker->dateTimeBetween('-20 years', '-1 day')->format('Y-m-d');
        }

        // Marriage date: 18-50 years ago, but after DOB assumption
        if (str_contains($name, 'marriage')) {
            return $this->faker->dateTimeBetween('-40 years', '-1 year')->format('Y-m-d');
        }

        // Future dates for expiry/effective
        if (preg_match('/(expiry|effective|inception)/', $name)) {
            return $this->faker->dateTimeBetween('now', '+2 years')->format('Y-m-d');
        }

        // Default: random date in last 10 years
        return $this->faker->dateTimeBetween('-10 years', 'now')->format('Y-m-d');
    }

    /**
     * Generate context-aware email values
     */
    protected function generateEmail(string $columnName): string
    {
        $name = strtolower($columnName);

        // Alternate/demo emails can be null sometimes
        if (preg_match('/(alternate|demo)/', $name) && $this->faker->boolean(20)) {
            return '';
        }

        // Use company domain for employee emails
        if (str_contains($name, 'employee') && !str_contains($name, 'insured')) {
            $firstName = $this->faker->firstName();
            $lastName = $this->faker->lastName();
            return strtolower("{$firstName}.{$lastName}@company.com");
        }

        return $this->faker->safeEmail();
    }

    /**
     * Generate context-aware number/ID values
     */
    protected function generateNumber(array $spec, string $columnName)
    {
        $name = strtolower($columnName);
        $maxLength = $spec['max_length'] ?? null;

        // Pin code: 6 digits
        if (str_contains($name, 'pin')) {
            return $this->faker->numerify('######');
        }

        // Mobile: 10 digits starting with 6-9
        if (str_contains($name, 'mobile')) {
            return $this->faker->numerify('9#########');
        }

        // Aadhar: 12 digits
        if (str_contains($name, 'aadhar')) {
            return $this->faker->numerify('############');
        }

        // ABHA ID: alphanumeric format
        if (str_contains($name, 'abha')) {
            return strtoupper($this->faker->bothify('??-####-????-####'));
        }

        // Salary/Suminsured/Premium: realistic ranges
        if (preg_match('/(salary|suminsured|premium|ctc)/', $name)) {
            $ranges = [
                'salary' => [15000, 500000],
                'suminsured' => [50000, 1000000],
                'premium' => [500, 50000],
                'ctc' => [20000, 800000],
            ];
            foreach ($ranges as $key => $range) {
                if (str_contains($name, $key)) {
                    return $this->faker->numberBetween($range[0], $range[1]);
                }
            }
            return $this->faker->numberBetween(1000, 100000);
        }

        // Employee code: alphanumeric
        if (str_contains($name, 'employee code')) {
            return strtoupper($this->faker->bothify('EMP-#####'));
        }

        // Default numeric with optional max length
        if ($maxLength) {
            $max = min(999999999, 10 ** $maxLength - 1);
            return $this->faker->numberBetween(1, $max);
        }

        return $this->faker->numberBetween(1, 999999);
    }

    /**
     * Generate context-aware text values
     */
    protected function generateText(array $spec, string $columnName)
    {
        $name = strtolower($columnName);

        // Names
        if (preg_match('/(first name|last name|member name|nominee|appointee)/', $name)) {
            if (str_contains($name, 'first')) return $this->faker->firstName();
            if (str_contains($name, 'last')) return $this->faker->lastName();
            return $this->faker->name();
        }

        // Gender: use dropdown values if available, else default
        if (str_contains($name, 'gender')) {
            if (!empty($spec['allowed_values'])) {
                return $this->faker->randomElement($spec['allowed_values']);
            }
            return $this->faker->randomElement(['Male', 'Female', 'Other']);
        }

        // Address components
        if (str_contains($name, 'address')) return $this->faker->address();
        if (str_contains($name, 'city')) return $this->faker->city();
        if (str_contains($name, 'state')) return $this->faker->state();

        // Job-related
        if (preg_match('/(designation|grade|occupation|unit|location)/', $name)) {
            $jobs = ['Manager', 'Developer', 'Analyst', 'Consultant', 'Executive', 'Associate'];
            return $this->faker->randomElement($jobs);
        }

        // Zone/Region
        if (str_contains($name, 'zone')) {
            return $this->faker->randomElement(['North', 'South', 'East', 'West', 'Central']);
        }

        // Relationship field (solves the bug dynamically!)
        if (str_contains($name, 'relationship')) {
            if (!empty($spec['allowed_values'])) {
                return $this->faker->randomElement($spec['allowed_values']);
            }
            // Fallback if no allowed values detected
            return $this->faker->randomElement(['Self', 'Spouse', 'Child', 'Parent']);
        }

        // ID strings
        if (preg_match('/(id|code|number|batch)/', $name) && !str_contains($name, 'mobile')) {
            return strtoupper($this->faker->bothify('???-#####'));
        }

        // Default text
        return $this->faker->words(3, true);
    }
}
