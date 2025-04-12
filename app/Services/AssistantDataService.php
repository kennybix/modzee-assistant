<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage; // Use the Storage facade
use Exception; // Import Exception class
use JsonException; // Import JsonException class


class AssistantDataService
{
    // Base path within the default storage disk (usually storage/app)
    protected string $basePath = 'assistant_data';

    /**
     * Get all available datasets combined into one array.
     *
     * @return array
     */
    public function getAllData(): array
    {
        return [
            'sales_data' => $this->getSalesData(),
            'sales_targets' => $this->getSalesTargets(),
            'teams' => $this->getTeamsData(),
            'employees' => $this->getEmployeesData(),
            'customers' => $this->getCustomersData(),
        ];
    }

    /**
     * Get a specific dataset by name.
     *
     * @param string $datasetName ('sales_data', 'sales_targets', 'teams', 'employees', 'customers')
     * @return array|null
     */
    public function getDataset(string $datasetName): ?array
    {
        switch ($datasetName) {
            case 'sales_data':
                return $this->getSalesData();
            case 'sales_targets':
                return $this->getSalesTargets();
            case 'teams':
                return $this->getTeamsData();
            case 'employees':
                return $this->getEmployeesData();
            case 'customers':
                return $this->getCustomersData();
            default:
                Log::warning("Attempted to load unknown dataset: {$datasetName}");
                return null;
        }
    }

    // --- Public methods to get specific datasets ---

    /**
     * Get parsed sales data.
     *
     * @return array
     */
    public function getSalesData(): array
    {
        // Use the correct filename ending in .json
        return $this->loadAndParseJsonFile('sales_data.json'); // Changed extension
    }

    /**
     * Get parsed sales targets.
     *
     * @return array
     */
    public function getSalesTargets(): array
    {
        return $this->loadAndParseJsonFile('sales_targets.json'); // Changed extension
    }

    /**
     * Get parsed teams data.
     *
     * @return array
     */
    public function getTeamsData(): array
    {
        return $this->loadAndParseJsonFile('teams.json'); // Changed extension
    }

    /**
     * Get parsed employees data.
     *
     * @return array
     */
    public function getEmployeesData(): array
    {
        return $this->loadAndParseJsonFile('employees.json'); // Changed extension
    }

    /**
     * Get parsed customers data.
     *
     * @return array
     */
    public function getCustomersData(): array
    {
        return $this->loadAndParseJsonFile('customers.json'); // Changed extension
    }

    // --- Example methods for getting specific/filtered data ---

    /**
     * Example: Get sales data for a specific year and quarter.
     *
     * @param string $quarter (e.g., "Q1")
     * @param int $year (e.g., 2024)
     * @return array|null Returns the data for that quarter or null if not found.
     */
    public function getSalesDataForQuarter(string $quarter, int $year): ?array
    {
        $allSalesData = $this->getSalesData();
        foreach ($allSalesData as $data) {
            if (isset($data['year'], $data['quarter']) && $data['year'] === $year && $data['quarter'] === $quarter) {
                return $data;
            }
        }
        return null; // Return null if no matching data found
    }

     /**
      * Example: Get employee data by name.
      *
      * @param string $employeeName
      * @return array|null Returns the employee's data array or null if not found.
      */
    public function getEmployeeByName(string $employeeName): ?array
    {
        $allEmployees = $this->getEmployeesData();
        foreach ($allEmployees as $employee) {
            if (isset($employee['name']) && strcasecmp($employee['name'], $employeeName) === 0) { // Case-insensitive compare
                return $employee;
            }
        }
        return null;
    }

    // --- Helper method to load and parse files ---
/**
     * Load JSON file from storage/app/assistant_data directory.
     *
     * @param string $filename (e.g., 'sales_data.json')
     * @return array Returns parsed array or empty array on failure.
     */
    // protected function loadAndParseJsonFile(string $filename): array
    // {
    //     // Construct path relative to the disk root (storage/app/)
    //     $path = $this->basePath . '/' . $filename; // This should result in e.g., "assistant_data/employees.json"

    //     // --- Add logging to see the final path being used ---
    //     Log::debug("Attempting to load assistant data file from storage path: {$path}");

    //     try {
    //         // Check existence using the default disk ('local' maps to storage/app)
    //         // Pass the path relative to the disk root
    //         if (!Storage::disk('local')->exists($path)) { // Explicitly use 'local' disk for clarity
    //             Log::warning("Assistant data file not found at path: {$path}");
    //             return [];
    //         }

    //         // Get file content using the relative path
    //         $content = Storage::disk('local')->get($path); // Explicitly use 'local' disk

    //         // Decode JSON content into an associative array
    //         // Add JSON_THROW_ON_ERROR for better error detection during decoding
    //         $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

    //         return $data ?? []; // Return decoded data or empty array if null (though throw_on_error makes ?? less needed)

    //     } catch (\JsonException $e) { // Catch specific JSON errors
    //         Log::error("Error decoding JSON from file: {$path}. Error: " . $e->getMessage());
    //         return [];
    //     } catch (\Exception $e) { // Catch other file access errors
    //         // Use context for better logging
    //         Log::error("Error loading or parsing file", [
    //             'path' => $path,
    //             'error' => $e->getMessage(),
    //             'trace' => $e->getTraceAsString() // Optional: include trace for deeper debug
    //         ]);
    //         return []; // Return empty array on any exception
    //     }
    // }

    protected function loadAndParseJsonFile(string $filename): array
{
    // Construct ABSOLUTE path using storage_path() helper
    // storage_path('app') points to the storage/app directory
    $absolutePath = storage_path('app/' . $this->basePath . '/' . $filename);

    Log::debug("Attempting to load using absolute path: {$absolutePath}");

    try {
        // Use native PHP file functions for testing
        if (!file_exists($absolutePath)) {
            Log::warning("NATIVE CHECK: File not found using file_exists at path: {$absolutePath}");
            return [];
        }
        Log::info("NATIVE CHECK: File confirmed to exist using file_exists: {$absolutePath}");

        $content = file_get_contents($absolutePath);
        if ($content === false) {
             Log::error("NATIVE CHECK: Failed to read file content using file_get_contents: {$absolutePath}");
             return [];
        }
        Log::info("NATIVE CHECK: Successfully read file content.", ['path' => $absolutePath]);


        // Decode JSON content into an associative array
        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        return $data ?? []; // Return decoded data or empty array if null

    } catch (\JsonException $e) { // Catch specific JSON errors
        Log::error("NATIVE CHECK: Error decoding JSON from file: {$absolutePath}", ['error' => $e->getMessage()]);
        return [];
    } catch (\Exception $e) { // Catch other file access errors
        Log::error("NATIVE CHECK: Error loading or parsing file", [
            'path' => $absolutePath,
            'error' => $e->getMessage()
        ]);
        return []; // Return empty array on any exception
    }
}
}