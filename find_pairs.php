<?php

/**
 * find_pairs.php
 *
 * Updated to support multiple common date formats (YYYY-MM-DD, DD/MM/YYYY, MM-DD-YY, etc.).
 */

// --- GLOBAL CONFIGURATION ---

// Define the supported date formats (in order of preference/commonality)
const SUPPORTED_DATE_FORMATS = [
    'Y-m-d',      // 2023-11-01 (Standard SQL/ISO)
    'm/d/Y',      // 11/01/2023
    'd/m/Y',      // 01/11/2023 (European standard)
    'd-M-y',      // 01-Nov-23
    'Y/m/d',      // 2023/11/01
    'm-d-Y',      // 11-01-2023
    'm-d-y',      // 11-01-23
    'd-m-Y',      // 01-11-2023
];


// --- Main Execution Router ---

if (php_sapi_name() === 'cli') {
    run_cli_mode($argv);
} else {
    run_web_mode();
}

// ---------------------------------------------------------------------
## 🛠️ Date Parsing Helper
// ---------------------------------------------------------------------

/**
 * Attempts to parse a date string using multiple defined formats.
 *
 * @param string $dateString The date string from the CSV.
 * @return string The date string in the standardized 'Y-m-d' format for SQLite.
 * @throws Exception If the date cannot be parsed by any supported format.
 */
function parse_multi_format_date(string $dateString): string
{
    // Handle the NULL indicator separately, as it is not a date string.
    if (strtoupper($dateString) === 'NULL' || empty($dateString)) {
        return 'NULL';
    }

    // Try each supported format
    foreach (SUPPORTED_DATE_FORMATS as $format) {
        $date = DateTime::createFromFormat($format, $dateString);

        // The check for $date && $date->format($format) === $dateString ensures the string
        // was consumed entirely (e.g., prevents 12/34/2023 from being partially accepted).
        if ($date && $date->format($format) === $dateString) {
            // Success! Return the date in the standardized Y-m-d format for SQLite.
            return $date->format('Y-m-d');
        }
    }

    throw new Exception("Date '$dateString' is not in a supported format.");
}


// ---------------------------------------------------------------------
## 📄 Core Data Handling (parse_csv_file MODIFIED)
// ---------------------------------------------------------------------

/**
 * Parses a CSV file and returns its data as an array, using the multi-format date parser.
 */
function parse_csv_file(string $filePath): array
{
    $data = [];

    if (!file_exists($filePath) || !is_readable($filePath)) {
        throw new Exception("File not found or is not readable: $filePath");
    }

    $file = fopen($filePath, 'r');
    if ($file === false) {
        throw new Exception("Failed to open file: $filePath");
    }

    $header = fgetcsv($file, escape: '\\');
    if ($header === false || !in_array('EmpID', $header) || !in_array('ProjectID', $header)) {
        throw new Exception("CSV file must contain 'EmpID' and 'ProjectID' columns.");
    }

    while (($row = fgetcsv($file, escape: '\\')) !== false) {
        if (count($row) === count($header)) {
            $row_assoc = array_combine($header, $row);

            // --- Crucial Change: Standardize date format during parsing ---
            try {
                $row_assoc['DateFrom'] = parse_multi_format_date($row_assoc['DateFrom']);
                $row_assoc['DateTo'] = parse_multi_format_date($row_assoc['DateTo']);
                $data[] = $row_assoc;
            } catch (Exception $e) {
                // Optionally log which row failed, but we stop execution for robustness
                throw new Exception("Data parsing error in row: " . $e->getMessage());
            }
        }
    }
    fclose($file);

    return $data;
}


// ---------------------------------------------------------------------
## 💾 Database Functions (MODIFIED to use standardized format)
// ---------------------------------------------------------------------

/**
 * Inserts data and finds ALL project-by-project overlaps for EVERY valid pair.
 * This function now expects all dates in the $dataRows to be 'Y-m-d' or 'NULL'.
 */
function find_all_project_overlaps(array $dataRows): array
{
    if (empty($dataRows)) return [];

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("
        CREATE TABLE ProjectWork (
            EmpID INT,
            ProjectID INT,
            DateFrom TEXT,
            DateTo TEXT
        )
    ");
    $stmt = $pdo->prepare("INSERT INTO ProjectWork (EmpID, ProjectID, DateFrom, DateTo) VALUES (?, ?, ?, ?)");

    // Insert Data
    foreach ($dataRows as $row) {
        $empID = $row['EmpID'] ?? null;
        $projectID = $row['ProjectID'] ?? null;
        $dateFrom = $row['DateFrom'] ?? null;

        // If DateTo is 'NULL' string, pass null to PDO to store as SQL NULL
        $dateTo = ($row['DateTo'] === 'NULL') ? null : $row['DateTo'];
        $stmt->execute([$empID, $projectID, $dateFrom, $dateTo]);
    }

    // Comprehensive Query: Select all pairs, all projects, and the overlap days.
    // (Query is unchanged from the last step, as it handles SQL NULL correctly)
    $sql = "
        WITH PairedDurations AS (
            SELECT
                t1.EmpID AS Emp1, t2.EmpID AS Emp2, t1.ProjectID,
                MAX(t1.DateFrom, t2.DateFrom) AS OverlapStart,
                MIN(COALESCE(t1.DateTo, 'now'), COALESCE(t2.DateTo, 'now')) AS OverlapEnd
            FROM
                ProjectWork AS t1
            JOIN
                ProjectWork AS t2 ON t1.ProjectID = t2.ProjectID
            WHERE
                t1.EmpID < t2.EmpID
        )
        
        SELECT
            Emp1 AS 'Employee ID #1',
            Emp2 AS 'Employee ID #2',
            ProjectID AS 'Project ID',
            ROUND(julianday(OverlapEnd) - julianday(OverlapStart)) AS 'Days Worked'
        FROM
            PairedDurations
        WHERE
            OverlapStart < OverlapEnd AND ROUND(julianday(OverlapEnd) - julianday(OverlapStart)) > 0
        ORDER BY
            'Employee ID #1', 'Employee ID #2', 'Project ID';
    ";

    $stmt = $pdo->query($sql);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}


/**
 * find_top_pair (Used by CLI Mode) - Logic remains the same, expects standardized dates.
 */
function find_top_pair(array $dataRows): ?array
{
    if (empty($dataRows)) return null;

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE ProjectWork (EmpID INT, ProjectID INT, DateFrom TEXT, DateTo TEXT)");
    $stmt = $pdo->prepare("INSERT INTO ProjectWork (EmpID, ProjectID, DateFrom, DateTo) VALUES (?, ?, ?, ?)");
    foreach ($dataRows as $row) {
        $empID = $row['EmpID'] ?? null;
        $projectID = $row['ProjectID'] ?? null;
        $dateFrom = $row['DateFrom'] ?? null;
        $dateTo = ($row['DateTo'] === 'NULL') ? null : $row['DateTo'];
        $stmt->execute([$empID, $projectID, $dateFrom, $dateTo]);
    }

    $sql = "
        WITH PairedDurations AS (
            SELECT t1.EmpID AS Emp1, t2.EmpID AS Emp2, MAX(t1.DateFrom, t2.DateFrom) AS OverlapStart,
                MIN(COALESCE(t1.DateTo, 'now'), COALESCE(t2.DateTo, 'now')) AS OverlapEnd
            FROM ProjectWork AS t1 JOIN ProjectWork AS t2 ON t1.ProjectID = t2.ProjectID WHERE t1.EmpID < t2.EmpID
        )
        SELECT Emp1, Emp2, SUM(ROUND(julianday(OverlapEnd) - julianday(OverlapStart))) AS TotalOverlapDays
        FROM PairedDurations
        WHERE OverlapStart < OverlapEnd
        GROUP BY Emp1, Emp2 ORDER BY TotalOverlapDays DESC LIMIT 1;
    ";

    $stmt = $pdo->query($sql);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ---------------------------------------------------------------------
## 🖥️ UI / CLI Execution (Unchanged logic)
// ---------------------------------------------------------------------

function run_cli_mode(array $argv)
{
    echo "--- Employee Pair Finder (CLI Mode: Top Pair Only) ---\n";
    if (!isset($argv[1])) {
        echo "Error: Missing CSV file argument.\nUsage: php find_pairs.php <path/to/your/file.csv>\n";
        exit(1);
    }
    $filePath = $argv[1];
    try {
        $data = parse_csv_file($filePath);
        $result = find_top_pair($data);

        echo "\n--- Final Result --- \n";
        if ($result) {
            echo "The pair of employees who have worked together the longest is: ({$result['Emp1']}, {$result['Emp2']})\n";
            echo "Total overlapping days: {$result['TotalOverlapDays']}\n";
        } else {
            echo "No employees found who worked together on any projects.\n";
        }
    } catch (Exception $e) {
        echo "An error occurred: " . $e->getMessage() . "\n";
        exit(1);
    }
}

function run_web_mode()
{
    $detail_results = [];
    $error_message = '';
    $result_message = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_FILES['csvFile']) && $_FILES['csvFile']['error'] === UPLOAD_ERR_OK) {

            $filePath = $_FILES['csvFile']['tmp_name'];

            try {
                // Data is parsed and standardized here
                $data = parse_csv_file($filePath);

                $detail_results = find_all_project_overlaps($data);

                if (empty($detail_results)) {
                    $result_message = "No pairs were found to have worked on a common project with an overlapping date range.";
                } else {
                    $result_message = "Displaying **" . count($detail_results) . "** common project overlaps found across all employee pairs.";
                }

            } catch (Exception $e) {
                $error_message = "An error occurred during file processing: " . $e->getMessage();
            }

        } else {
            $error_message = "Failed to upload file. Please ensure it's a valid CSV.";
        }
    }

    render_html_page_all_pairs($detail_results, $result_message, $error_message);
}

function render_html_page_all_pairs(array $detail_results, string $result_message, string $error_message)
{
    $datagrid_html = '';
    if (!empty($detail_results)) {
        $datagrid_html .= '<h3>All Common Project Overlaps</h3>';
        $datagrid_html .= '<table>';

        $datagrid_html .= '<thead><tr>';
        foreach (array_keys($detail_results[0]) as $header) {
            $datagrid_html .= "<th>{$header}</th>";
        }
        $datagrid_html .= '</tr></thead>';

        $datagrid_html .= '<tbody>';
        foreach ($detail_results as $row) {
            $datagrid_html .= '<tr>';
            foreach ($row as $value) {
                $datagrid_html .= "<td>{$value}</td>";
            }
            $datagrid_html .= '</tr>';
        }
        $datagrid_html .= '</tbody>';
        $datagrid_html .= '</table>';
    }

    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-M">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Pair Finder (All Pairs)</title>
    <style>
        body { 
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            margin: 0;
            padding: 2em;
            background-color: #f4f7f6;
            color: #333;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 2em;
            background-color: #fff;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        h1 {
            border-bottom: 2px solid #e0e0e0;
            padding-bottom: 10px;
            color: #111;
        }
        form {
            margin-bottom: 2em;
        }
        button {
            background-color: #007bff;
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 1em;
        }
        #result, #error {
            padding: 1em;
            border-radius: 4px;
            margin-top: 1em;
        }
        #result {
            background-color: #e6f7ff;
            border: 1px solid #b3e0ff;
        }
        #error {
            background-color: #ffe6e6;
            border: 1px solid #ffb3b3;
            color: #c00;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1em;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: center;
        }
        th {
            background-color: #f2f2f2;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Employee Pair Finder 📊</h1>
        <p>Upload your CSV file to view all employee pairs who worked together on a common project.</p>
        
        <form action="find_pairs.php" method="POST" enctype="multipart/form-data">
            <label for="csvFile">CSV File:</label>
            <input type="file" id="csvFile" name="csvFile" accept=".csv" required>
            <button type="submit">Analyze</button>
        </form>

        $datagrid_html
HTML;
    if ($error_message) {
        echo <<<HTML
            <div id="error">
                <strong>Error:</strong>
                <p>$error_message</p>
            </div>
            HTML;
    }
    echo <<<HTML
    <div >
</body >
</html >
HTML;
}