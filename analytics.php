<?php
session_start();
require_once 'config.php';

// Check if user is logged in
$is_logged_in = isset($_SESSION['user']);
$user_role = $_SESSION['user']['role'] ?? null;
$user_barangay_id = $_SESSION['user']['barangay_id'] ?? null;

// Set is_super_admin for compatibility
$is_super_admin = ($user_role === 'super_admin');

// Set is_captain for sidebar compatibility
$is_captain = ($user_role === 'captain');

// Get barangay name for captain display
$captain_barangay_name = null;
if ($is_captain && $user_barangay_id) {
    $barangayQuery = "SELECT barangay_name FROM barangay_registration WHERE id = ?";
    $stmt = $conn->prepare($barangayQuery);
    $stmt->bind_param("i", $user_barangay_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $barangay = $result->fetch_assoc();
    if ($barangay) {
        $captain_barangay_name = $barangay['barangay_name'];
    }
}

// Get analytics data with proper barangay filtering using census data
function getAnalyticsData($barangay_id = null, $year = null) {
    global $conn;
    
    $data = [];
    
    // Build WHERE clause based on filters
    $whereClause = "";
    $params = [];
    $types = "";
    
    if ($barangay_id) {
        // Get barangay name for filtering
        $barangayNameQuery = "SELECT barangay_name FROM barangay_registration WHERE id = ?";
        $stmt = $conn->prepare($barangayNameQuery);
        $stmt->bind_param("i", $barangay_id);
        $stmt->execute();
        $barangayResult = $stmt->get_result();
        $barangay = $barangayResult->fetch_assoc();
        
        if ($barangay) {
            $whereClause = "WHERE cs.barangay = ?";
            $params = [$barangay['barangay_name']];
            $types = "s";
            
            // Add year filter if provided - REMOVED FOR NOW SINCE created_at COLUMN DOESN'T EXIST
            // if ($year) {
            //     $whereClause .= " AND YEAR(cs.created_at) = ?";
            //     $params[] = $year;
            //     $types .= "i";
            // }
        } else {
            // Barangay not found, return empty data
            return getEmptyAnalyticsData();
        }
    } else {
        $whereClause = "WHERE 1=1";
        // Add year filter if provided - REMOVED FOR NOW SINCE created_at COLUMN DOESN'T EXIST
        // if ($year) {
        //     $whereClause .= " AND YEAR(cs.created_at) = ?";
        //     $params[] = $year;
        //     $types = "i";
        // }
    }
    
    // Age distribution from census_submissions - UPDATED WITH DETAILED AGE GROUPS
    $ageQuery = "SELECT 
        CASE 
            WHEN cs.age BETWEEN 0 AND 4 THEN '0-4'
            WHEN cs.age BETWEEN 5 AND 9 THEN '5-9'
            WHEN cs.age BETWEEN 10 AND 14 THEN '10-14'
            WHEN cs.age BETWEEN 15 AND 19 THEN '15-19'
            WHEN cs.age BETWEEN 20 AND 24 THEN '20-24'
            WHEN cs.age BETWEEN 25 AND 29 THEN '25-29'
            WHEN cs.age BETWEEN 30 AND 34 THEN '30-34'
            WHEN cs.age BETWEEN 35 AND 39 THEN '35-39'
            WHEN cs.age BETWEEN 40 AND 44 THEN '40-44'
            WHEN cs.age BETWEEN 45 AND 49 THEN '45-49'
            WHEN cs.age BETWEEN 50 AND 54 THEN '50-54'
            WHEN cs.age BETWEEN 55 AND 59 THEN '55-59'
            WHEN cs.age BETWEEN 60 AND 64 THEN '60-64'
            WHEN cs.age >= 65 THEN '65+'
            ELSE 'Not Specified'
        END AS age_group,
        COUNT(*) AS count
        FROM census_submissions cs
        $whereClause AND cs.age IS NOT NULL
        GROUP BY age_group 
        ORDER BY 
            CASE age_group
                WHEN '0-4' THEN 1
                WHEN '5-9' THEN 2
                WHEN '10-14' THEN 3
                WHEN '15-19' THEN 4
                WHEN '20-24' THEN 5
                WHEN '25-29' THEN 6
                WHEN '30-34' THEN 7
                WHEN '35-39' THEN 8
                WHEN '40-44' THEN 9
                WHEN '45-49' THEN 10
                WHEN '50-54' THEN 11
                WHEN '55-59' THEN 12
                WHEN '60-64' THEN 13
                WHEN '65+' THEN 14
                ELSE 15
            END";
    
    $stmt = $conn->prepare($ageQuery);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $data['age_distribution'] = $result->fetch_all(MYSQLI_ASSOC);
    
    // Employment status from census_submissions (using status_work_business)
    $employmentQuery = "SELECT 
        COALESCE(cs.status_work_business, 'Not Specified') AS employment_status, 
        COUNT(*) AS count 
        FROM census_submissions cs
        $whereClause AND cs.status_work_business IS NOT NULL AND cs.status_work_business != ''
        GROUP BY cs.status_work_business";
    
    $stmt = $conn->prepare($employmentQuery);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $data['employment_status'] = $result->fetch_all(MYSQLI_ASSOC);
    
    // Gender ratio from census_submissions
    $genderQuery = "SELECT 
        COALESCE(cs.gender, 'Not Specified') AS gender, 
        COUNT(*) AS count 
        FROM census_submissions cs
        $whereClause AND cs.gender IS NOT NULL AND cs.gender != ''
        GROUP BY cs.gender";
    
    $stmt = $conn->prepare($genderQuery);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $data['gender_ratio'] = $result->fetch_all(MYSQLI_ASSOC);
    
    // Total members count from census_submissions
    $membersQuery = "SELECT COUNT(*) as total_members FROM census_submissions cs $whereClause";
    
    $stmt = $conn->prepare($membersQuery);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $data['total_members'] = $result->fetch_assoc()['total_members'] ?? 0;
    
    // Total households count from census_submissions
    $householdQuery = "SELECT COUNT(DISTINCT cs.id) as total_households 
                      FROM census_submissions cs 
                      $whereClause";
    
    $stmt = $conn->prepare($householdQuery);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $data['total_households'] = $result->fetch_assoc()['total_households'] ?? 0;
    
    // Census submissions count
    $censusQuery = "SELECT COUNT(*) as total_census FROM census_submissions cs $whereClause";
    $stmt = $conn->prepare($censusQuery);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $data['total_census'] = $result->fetch_assoc()['total_census'] ?? 0;
    
    // Get available years for reporting - TEMPORARILY DISABLED
    // First check if the table has a date column
    $checkDateColumn = $conn->query("SHOW COLUMNS FROM census_submissions LIKE '%date%'");
    $hasDateColumn = $checkDateColumn->num_rows > 0;
    
    $checkTimestampColumn = $conn->query("SHOW COLUMNS FROM census_submissions LIKE '%timestamp%'");
    $hasTimestampColumn = $checkTimestampColumn->num_rows > 0;
    
    $checkCreatedColumn = $conn->query("SHOW COLUMNS FROM census_submissions LIKE '%created%'");
    $hasCreatedColumn = $checkCreatedColumn->num_rows > 0;
    
    $data['available_years'] = [];
    
    // Determine which date column to use
    $dateColumn = null;
    if ($hasCreatedColumn) {
        $result = $conn->query("SHOW COLUMNS FROM census_submissions LIKE '%created%'");
        $row = $result->fetch_assoc();
        $dateColumn = $row['Field'];
    } elseif ($hasTimestampColumn) {
        $result = $conn->query("SHOW COLUMNS FROM census_submissions LIKE '%timestamp%'");
        $row = $result->fetch_assoc();
        $dateColumn = $row['Field'];
    } elseif ($hasDateColumn) {
        $result = $conn->query("SHOW COLUMNS FROM census_submissions LIKE '%date%'");
        $row = $result->fetch_assoc();
        $dateColumn = $row['Field'];
    }
    
    // If we found a date column, get distinct years
    if ($dateColumn) {
        $yearsQuery = "SELECT DISTINCT YEAR($dateColumn) as year FROM census_submissions WHERE $dateColumn IS NOT NULL ORDER BY year DESC";
        $yearsResult = $conn->query($yearsQuery);
        if ($yearsResult) {
            while ($row = $yearsResult->fetch_assoc()) {
                if ($row['year']) {
                    $data['available_years'][] = $row['year'];
                }
            }
        }
    } else {
        // If no date column exists, use current year as default
        $data['available_years'][] = date('Y');
    }
    
    return $data;
}

// Helper function to return empty analytics data structure
function getEmptyAnalyticsData() {
    return [
        'age_distribution' => [],
        'employment_status' => [],
        'gender_ratio' => [],
        'total_members' => 0,
        'total_households' => 0,
        'total_census' => 0,
        'available_years' => [date('Y')] // Default to current year
    ];
}

// NEW FUNCTION: Get Age Pyramid Data with Barangay Filter
function getAgePyramidDataForAnalytics($barangay_id = null) {
    global $conn;
    
    // Build WHERE clause based on barangay filter
    $whereClause = "";
    $params = [];
    $types = "";
    
    if ($barangay_id) {
        // Get barangay name for filtering
        $barangayNameQuery = "SELECT barangay_name FROM barangay_registration WHERE id = ?";
        $stmt = $conn->prepare($barangayNameQuery);
        $stmt->bind_param("i", $barangay_id);
        $stmt->execute();
        $barangayResult = $stmt->get_result();
        $barangay = $barangayResult->fetch_assoc();
        
        if ($barangay) {
            $whereClause = "WHERE cs.barangay = ?";
            $params = [$barangay['barangay_name']];
            $types = "s";
        } else {
            // Barangay not found, return empty data
            return [];
        }
    } else {
        $whereClause = "WHERE 1=1";
    }
    
    // Query to get age pyramid data by gender
    $pyramidQuery = "SELECT 
        CASE 
            WHEN cs.age BETWEEN 0 AND 4 THEN '0-4'
            WHEN cs.age BETWEEN 5 AND 9 THEN '5-9'
            WHEN cs.age BETWEEN 10 AND 14 THEN '10-14'
            WHEN cs.age BETWEEN 15 AND 19 THEN '15-19'
            WHEN cs.age BETWEEN 20 AND 24 THEN '20-24'
            WHEN cs.age BETWEEN 25 AND 29 THEN '25-29'
            WHEN cs.age BETWEEN 30 AND 34 THEN '30-34'
            WHEN cs.age BETWEEN 35 AND 39 THEN '35-39'
            WHEN cs.age BETWEEN 40 AND 44 THEN '40-44'
            WHEN cs.age BETWEEN 45 AND 49 THEN '45-49'
            WHEN cs.age BETWEEN 50 AND 54 THEN '50-54'
            WHEN cs.age BETWEEN 55 AND 59 THEN '55-59'
            WHEN cs.age BETWEEN 60 AND 64 THEN '60-64'
            WHEN cs.age >= 65 THEN '65+'
            ELSE 'Not Specified'
        END AS age_group,
        SUM(CASE WHEN cs.gender = 'Male' THEN 1 ELSE 0 END) AS male_count,
        SUM(CASE WHEN cs.gender = 'Female' THEN 1 ELSE 0 END) AS female_count
        FROM census_submissions cs
        $whereClause AND cs.age IS NOT NULL AND cs.gender IN ('Male', 'Female')
        GROUP BY age_group 
        HAVING age_group != 'Not Specified'
        ORDER BY 
            CASE age_group
                WHEN '0-4' THEN 1
                WHEN '5-9' THEN 2
                WHEN '10-14' THEN 3
                WHEN '15-19' THEN 4
                WHEN '20-24' THEN 5
                WHEN '25-29' THEN 6
                WHEN '30-34' THEN 7
                WHEN '35-39' THEN 8
                WHEN '40-44' THEN 9
                WHEN '45-49' THEN 10
                WHEN '50-54' THEN 11
                WHEN '55-59' THEN 12
                WHEN '60-64' THEN 13
                WHEN '65+' THEN 14
            END";
    
    $stmt = $conn->prepare($pyramidQuery);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    $pyramidData = [];
    $allAgeGroups = [
        '0-4', '5-9', '10-14', '15-19', '20-24', '25-29', '30-34',
        '35-39', '40-44', '45-49', '50-54', '55-59', '60-64', '65+'
    ];
    
    // Create array with all age groups even if some have zero counts
    $fetchedData = $result->fetch_all(MYSQLI_ASSOC);
    $fetchedMap = [];
    foreach ($fetchedData as $row) {
        $fetchedMap[$row['age_group']] = [
            'male_count' => (int)$row['male_count'],
            'female_count' => (int)$row['female_count']
        ];
    }
    
    foreach ($allAgeGroups as $group) {
        $pyramidData[] = [
            'age_group' => $group,
            'male_count' => $fetchedMap[$group]['male_count'] ?? 0,
            'female_count' => $fetchedMap[$group]['female_count'] ?? 0
        ];
    }
    
    return $pyramidData;
}

// Get filters
$selected_barangay = isset($_GET['barangay']) ? intval($_GET['barangay']) : null;
$selected_year = isset($_GET['year']) ? intval($_GET['year']) : null;

// Determine which barangay to show data for
$current_barangay_id = null;

if ($user_role === 'super_admin') {
    // Super admin can select any barangay or view all
    $current_barangay_id = $selected_barangay;
} elseif (in_array($user_role, ['official', 'captain'])) {
    // Barangay officials can only view their own barangay
    $current_barangay_id = $user_barangay_id;
}

// Get all barangays for dropdown (only for super admin)
$barangays = [];
if ($user_role === 'super_admin') {
    $barangayQuery = "SELECT id, barangay_name FROM barangay_registration ORDER BY barangay_name";
    $result = $conn->query($barangayQuery);
    if ($result) {
        $barangays = $result->fetch_all(MYSQLI_ASSOC);
    }
}

// Get current barangay name for display
$current_barangay_name = "All Barangays";
$current_barangay_data = null;

if ($current_barangay_id) {
    // Get specific barangay data
    $barangayQuery = "SELECT * FROM barangay_registration WHERE id = ?";
    $stmt = $conn->prepare($barangayQuery);
    $stmt->bind_param("i", $current_barangay_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $current_barangay_data = $result->fetch_assoc();
    
    if ($current_barangay_data) {
        $current_barangay_name = $current_barangay_data['barangay_name'];
    }
} elseif ($user_role !== 'super_admin' && $user_barangay_id) {
    // For barangay officials, get their barangay name
    $barangayQuery = "SELECT * FROM barangay_registration WHERE id = ?";
    $stmt = $conn->prepare($barangayQuery);
    $stmt->bind_param("i", $user_barangay_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $current_barangay_data = $result->fetch_assoc();
    
    if ($current_barangay_data) {
        $current_barangay_name = $current_barangay_data['barangay_name'];
        $current_barangay_id = $user_barangay_id;
    }
}

// Get analytics data
$analyticsData = getAnalyticsData($current_barangay_id, $selected_year);

// Get age pyramid data using the new unified function
$agePyramidData = getAgePyramidDataForAnalytics($current_barangay_id);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Demographic Analytics - Barangay Profiling System</title>
    <link rel="stylesheet" href="analytics.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/chart.js@3.7.1/dist/chart.min.css">
    <style>
        :root {
            --light-blue: #7da2ce;
            --primary-blue: #1d3b71;
            --primary-color: #3498db;
            --secondary-color: #2980b9;
            --accent-color: #e74c3c;
            --light-color: #ecf0f1;
            --dark-color: #2c3e50;
            --success-color: #2ecc71;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --gray-color: #95a5a6;
            --white: #ffffff;
            --sidebar-width: 250px;
            --gray-100: #f8f9fa;
            --gray-200: #e9ecef;
            --gray-300: #dee2e6;
            --gray-600: #6c757d;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.1);
        }

        body {
            background-color: var(--light-blue);
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .login-btn {
            margin-top: 0px;
            display: inline-block;
            background-color: var(--primary-blue);
            color: white;
            text-decoration: none;
            border-radius: 4px;
            font-weight: bold;
            transition: background-color 0.3s;
            border: none;
            cursor: pointer;
        }

        .logout-btn {
            margin-top: 0px;
            display: inline-block;
            background-color: var(--danger-color);
            color: white;
            text-decoration: none;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: bold;
            transition: background-color 0.3s;
        }
        
        .logout-btn:hover {
            background-color: #c0392b;
        }
        
        .welcome p{
            margin-bottom: 0px;
            margin-top: 5px;
        }

        .analytics-container {
            padding: 20px;
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            background-color: var(--light-blue);
        }
        
        .analytics-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            color: var(--dark-color);
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .mobile-menu-btn {
            display: none;
            background: none;
            border: none;
            cursor: pointer;
            color: var(--dark-color);
        }
        
        .filter-section {
            background-color: var(--white);
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: var(--shadow-sm);
            border-left: 4px solid var(--primary-blue);
        }
        
        .filter-row {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .filter-row select, .filter-row button {
            padding: 10px 15px;
            border-radius: 8px;
            border: 1px solid var(--gray-300);
            font-size: 14px;
        }
        
        .filter-row select {
            background-color: var(--gray-100);
            min-width: 200px;
        }
        
        .filter-row button {
            background-color: var(--primary-blue);
            color: white;
            border: none;
            cursor: pointer;
            font-weight: 500;
        }
        
        .filter-row button:hover {
            background-color: #0056b3;
        }
        
        .generate-report-btn {
            background-color: var(--success-color) !important;
        }
        
        .generate-report-btn:hover {
            background-color: #27ae60 !important;
        }
        
        .current-view {
            background-color: var(--gray-100);
            padding: 12px 16px;
            border-radius: 8px;
            margin-top: 15px;
            font-size: 14px;
            border-left: 4px solid var(--success-color);
        }
        
        .analytics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
            gap: 25px;
        }
        
        .analytics-card {
            background-color: var(--white);
            border-radius: 12px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
        }
        
        .card-header {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--gray-200);
        }

        .card-header h3 {
            color: var(--dark-color);
            font-size: 1.2rem;
            margin: 0;
        }
        
        .chart-container {
            position: relative;
            height: 320px;
            width: 100%;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
        }
        
        .stat-item {
            background-color: var(--gray-100);
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            border: 1px solid var(--gray-200);
        }
        
        .stat-value {
            font-size: 28px;
            font-weight: bold;
            color: var(--primary-blue);
        }
        
        .stat-label {
            font-size: 14px;
            color: var(--gray-600);
        }
        
        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }
        
        .no-data {
            text-align: center;
            padding: 60px 20px;
            color: var(--gray-600);
            font-style: italic;
        }
        
        .data-source {
            font-size: 12px;
            color: var(--gray-600);
            margin-top: 10px;
            font-style: italic;
        }
        
        .user-role-info {
            background-color: #e7f3ff;
            padding: 10px 15px;
            border-radius: 4px;
            margin-top: 10px;
            font-size: 14px;
            border-left: 4px solid var(--primary-blue);
        }
        
        .admin-badge {
            background: var(--primary-blue);
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
        }

        .clear-filter {
            padding: 10px 16px;
            background-color: var(--gray-600);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-size: 14px;
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 9999;
        }

        .sidebar-overlay.active {
            display: block;
        }

        /* Age Pyramid specific styles */
        .pyramid-legend {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-top: 10px;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
        }
        
        .legend-color {
            width: 20px;
            height: 20px;
            border-radius: 4px;
        }
        
        .pyramid-controls {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .pyramid-controls button {
            padding: 6px 12px;
            border: 1px solid var(--gray-300);
            background: var(--gray-100);
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
        }
        
        .pyramid-controls button.active {
            background: var(--primary-blue);
            color: white;
            border-color: var(--primary-blue);
        }
        
        /* Age groups display */
        .age-group-label {
            font-size: 11px;
            font-weight: 500;
            text-align: center;
        }

        /* Narrative Report Styles */
        .narrative-report {
            background-color: var(--gray-100);
            padding: 20px;
            border-radius: 10px;
            margin-top: 15px;
            border-left: 4px solid var(--primary-blue);
            max-height: 400px;
            overflow-y: auto;
            font-size: 14px;
            line-height: 1.5;
        }
        
        .narrative-report h4 {
            color: var(--primary-blue);
            margin-top: 15px;
            margin-bottom: 10px;
            font-size: 16px;
        }
        
        .narrative-report p {
            margin-bottom: 10px;
        }
        
        .narrative-report ul, .narrative-report ol {
            margin-left: 20px;
            margin-bottom: 15px;
        }
        
        .narrative-report li {
            margin-bottom: 5px;
        }

        /* Age Distribution Chart Container */
        .age-chart-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        @media (max-width: 992px) {
            .age-chart-container {
                grid-template-columns: 1fr;
            }
        }

        /* =========================================================
           MOBILE RESPONSIVE STYLES (phones & small screens)
           Applies only at max-width: 768px
        ========================================================= */
        @media (max-width: 768px) {
            /* -----------------------------------------
               Layout Adjustments
            ----------------------------------------- */
            .sidebar {
                position: fixed;
                width: 280px;
                left: -280px;
                top: 0;
                height: 100vh;
                transition: left 0.3s ease-in-out;
                z-index: 9999;
                overflow-y: auto;
            }

            .sidebar.open {
                left: 0;
            }

            .analytics-container {
                margin-left: 0 !important;
                padding: 10px;
                width: 100%;
            }

            /* Hamburger Menu */
            .mobile-menu-btn {
                display: inline-block;
                font-size: 24px;
                cursor: pointer;
                color: var(--dark-color);
                margin-right: 15px;
                padding: 8px;
                border-radius: 6px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            }

            .analytics-header {
                padding: 15px;
                margin-bottom: 15px;
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }

            .analytics-header h1 {
                font-size: 1.3rem;
                margin: 0;
            }

            /* -----------------------------------------
               Filter Section
            ----------------------------------------- */
            .filter-section {
                padding: 15px;
                margin-bottom: 15px;
            }

            .filter-row {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
                width: 100%;
            }

            .filter-row select, .filter-row button, .clear-filter {
                width: 100%;
                margin: 0;
                box-sizing: border-box;
            }

            .filter-row select {
                min-width: unset;
            }

            /* -----------------------------------------
               Analytics Grid
            ----------------------------------------- */
            .analytics-grid {
                grid-template-columns: 1fr !important;
                gap: 15px;
                width: 100%;
            }

            .analytics-card {
                padding: 15px !important;
                margin: 0;
                width: 100%;
                box-sizing: border-box;
            }

            .card-header {
                margin-bottom: 15px;
                padding-bottom: 10px;
            }

            .card-header h3 {
                font-size: 1.1rem !important;
                line-height: 1.3;
            }

            .chart-container {
                height: 280px !important;
                width: 100%;
            }

            /* -----------------------------------------
               Stats Grid
            ----------------------------------------- */
            .stats-grid {
                grid-template-columns: 1fr;
                gap: 12px;
            }

            .stat-item {
                padding: 15px !important;
                text-align: left;
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 15px;
            }

            .stat-value {
                font-size: 1.5rem !important;
                min-width: 60px;
                text-align: right;
            }

            .stat-label {
                font-size: 0.9rem !important;
                flex: 1;
            }

            /* -----------------------------------------
               Age Pyramid on Mobile
            ----------------------------------------- */
            .pyramid-legend {
                flex-direction: column;
                align-items: center;
                gap: 8px;
            }
            
            .legend-item {
                font-size: 13px;
            }
            
            .pyramid-controls {
                flex-wrap: wrap;
            }
            
            .age-group-label {
                font-size: 10px;
            }

            /* -----------------------------------------
               Narrative Report on Mobile
            ----------------------------------------- */
            .narrative-report {
                padding: 15px;
                max-height: 300px;
                font-size: 13px;
            }
            
            .age-chart-container {
                grid-template-columns: 1fr;
            }

            /* -----------------------------------------
               Sidebar Navigation
            ----------------------------------------- */
            .sidebar-nav a {
                font-size: 0.95rem;
                padding: 12px 15px;
                display: flex;
                align-items: center;
                gap: 10px;
            }

            .sidebar-header h2 {
                font-size: 1rem;
                text-align: center;
                padding: 0 10px;
            }

            .welcome {
                font-size: 0.85rem;
                padding: 0 10px;
            }

            /* -----------------------------------------
               User Role Info & Current View
            ----------------------------------------- */
            .user-role-info {
                padding: 12px;
                font-size: 0.9rem;
                margin-top: 10px;
            }

            .current-view {
                padding: 12px;
                font-size: 0.9rem;
                margin-top: 12px;
            }

            .admin-badge {
                padding: 6px 12px;
                font-size: 0.8rem;
            }

            /* -----------------------------------------
               No Data States
            ----------------------------------------- */
            .no-data {
                padding: 40px 15px;
            }

            .no-data i {
                font-size: 2rem !important;
            }

            .no-data p {
                font-size: 0.9rem;
                margin-top: 10px;
            }
        }

        /* =========================================================
           EXTRA SMALL DEVICES (very small phones)
        ========================================================= */
        @media (max-width: 480px) {
            .analytics-container {
                padding: 8px;
            }

            .analytics-header {
                padding: 12px;
            }

            .analytics-header h1 {
                font-size: 1.2rem;
            }

            .mobile-menu-btn {
                font-size: 20px;
                padding: 6px;
            }

            .analytics-card {
                padding: 12px !important;
            }

            .card-header h3 {
                font-size: 1rem !important;
            }

            .chart-container {
                height: 250px !important;
            }

            .stat-value {
                font-size: 1.3rem !important;
                min-width: 50px;
            }

            .stat-label {
                font-size: 0.85rem !important;
            }

            .stat-item {
                padding: 12px !important;
            }

            .filter-section {
                padding: 12px;
            }

            .sidebar {
                width: 260px;
                left: -260px;
            }

            .sidebar.open {
                left: 0;
            }

            .user-role-info,
            .current-view {
                font-size: 0.85rem;
                padding: 10px;
            }
            
            .narrative-report {
                padding: 12px;
                max-height: 250px;
                font-size: 12px;
            }
        }

        /* =========================================================
           SMALL TABLETS (between 769px and 1024px)
        ========================================================= */
        @media (min-width: 769px) and (max-width: 1024px) {
            .analytics-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 20px;
            }

            .analytics-card {
                padding: 20px;
            }

            .chart-container {
                height: 300px;
            }
        }
    </style>
</head>
<body>
<div class="dashboard-container">
    <!-- Sidebar Overlay for Mobile -->
    <div class="sidebar-overlay" onclick="toggleSidebar()"></div>

    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-header">
            <h2>Barangay Event And Program Planning System</h2>
            <?php if ($is_logged_in): ?>
                <div class="welcome">
                    <?php if ($is_captain && $captain_barangay_name): ?>
                        <p>Welcome, <?php echo htmlspecialchars($_SESSION['user']['full_name'] ?? 'User'); ?> of <?php echo htmlspecialchars($captain_barangay_name); ?></p>
                    <?php else: ?>
                        <p>Welcome, <?php echo htmlspecialchars($_SESSION['user']['full_name'] ?? 'User'); ?></p>
                    <?php endif; ?>
                    <a href="logout.php" class="logout-btn">Logout</a>
                </div>
            <?php else: ?>
                <div class="welcome">
                    <a href="login.php" class="login-btn">Login</a>
                </div>
            <?php endif; ?>
        </div>
        <nav class="sidebar-nav">
            <ul>
               <li><a href="dashboard.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? 'active' : ''; ?>"><i class="fas fa-house-user"></i> Dashboard</a></li>
                <li><a href="resident.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'resident.php' ? 'active' : ''; ?>"><i class="fas fa-users"></i> Residents</a></li>
                <li><a href="analytics.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'analytics.php' ? 'active' : ''; ?>"><i class="fas fa-chart-bar"></i> Analytics</a></li>
                <li><a href="predictive.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'predictive.php' ? 'active' : ''; ?>"><i class="fas fa-brain"></i> Predictive Models</a></li>
               
                <!-- Super Admin Only Links -->
                <?php if ($is_super_admin): ?>
                    <li><a href="superadmin.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'superadmin.php' ? 'active' : ''; ?>"><i class="fas fa-inbox"></i> Requests</a></li>
                <?php endif; ?>
                
                <?php if ($is_logged_in): ?>
                    <li><a href="settings.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'settings.php' ? 'active' : ''; ?>"><i class="fas fa-cog"></i> Settings</a></li>
                <?php endif; ?>
            </ul>
        </nav>
    </div>

    <!-- Main Content -->
    <div class="analytics-container">
        <div class="analytics-header">
            <div class="header-left">
                <button class="mobile-menu-btn" onclick="toggleSidebar()">
                    <i class="fas fa-bars"></i>
                </button>
                <h1><i class="fas fa-chart-bar"></i> Demographic Analytics</h1>
            </div>
            <div class="header-right">
                <?php if ($is_super_admin): ?>
                    <span class="admin-badge"><i class="fas fa-crown"></i> Super Admin</span>
                <?php elseif ($is_captain): ?>
                    <span class="admin-badge"><i class="fas fa-user-shield"></i> Barangay Captain</span>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- User Role Information -->
        <?php if (!$is_super_admin): ?>
        <div class="user-role-info">
            <i class="fas fa-info-circle"></i> 
            <strong>Restricted View:</strong> You are viewing data only for your assigned barangay - <?php echo htmlspecialchars($current_barangay_name); ?>
        </div>
        <?php endif; ?>
        
        <!-- Filter Section -->
        <div class="filter-section">
            <div class="filter-row">
                <!-- Main Filter Form -->
                <form method="get" action="analytics.php" style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
                    <?php if ($user_role === 'super_admin'): ?>
                        <label for="barangay">Filter by Barangay:</label>
                        <select name="barangay" id="barangay" onchange="this.form.submit()">
                            <option value="">All Barangays</option>
                            <?php foreach ($barangays as $barangay): ?>
                                <option value="<?php echo $barangay['id']; ?>" <?php echo $selected_barangay == $barangay['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($barangay['barangay_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        
                        <!-- Temporarily disabled year filter until date column is confirmed -->
                        <!--
                        <label for="year">Filter by Year:</label>
                        <select name="year" id="year" onchange="this.form.submit()">
                            <option value="">All Years</option>
                            <?php foreach ($analyticsData['available_years'] as $year): ?>
                                <option value="<?php echo $year; ?>" <?php echo $selected_year == $year ? 'selected' : ''; ?>>
                                    <?php echo $year; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        -->
                    <?php else: ?>
                        <input type="hidden" name="barangay" value="<?php echo $user_barangay_id; ?>">
                    <?php endif; ?>
                    
                    <button type="submit">Apply Filter</button>
                </form>
                
                <!-- Generate Report Button -->
                <form method="get" action="report.php" style="display: inline;">
                    <?php if ($user_role === 'super_admin'): ?>
                        <input type="hidden" name="report_barangay" value="<?php echo $selected_barangay; ?>">
                    <?php else: ?>
                        <input type="hidden" name="report_barangay" value="<?php echo $user_barangay_id; ?>">
                    <?php endif; ?>
                    <?php if ($selected_year): ?>
                        <input type="hidden" name="report_year" value="<?php echo $selected_year; ?>">
                    <?php endif; ?>
                    <button type="submit" class="generate-report-btn">
                        <i class="fas fa-file-alt"></i> Generate Report
                    </button>
                </form>
                
                <?php if (($selected_barangay || $selected_year) && $user_role === 'super_admin'): ?>
                    <a href="analytics.php" class="clear-filter">Clear Filter</a>
                <?php endif; ?>
            </div>
            
            <!-- Current View Info -->
            <div class="current-view">
                <strong>Currently Viewing:</strong> 
                <?php echo htmlspecialchars($current_barangay_name); ?>
                <?php if ($selected_year): ?>
                    (Year: <?php echo $selected_year; ?>)
                <?php endif; ?>
                <?php if (!$is_super_admin): ?>
                    <br><small>Restricted to your barangay only</small>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Analytics Grid -->
        <div class="analytics-grid">
            <!-- Age Distribution Card with Narrative -->
            <div class="analytics-card">
                <div class="card-header">
                    <h3>Age Distribution Analysis - <?php echo htmlspecialchars($current_barangay_name); ?></h3>
                </div>
                <div class="age-chart-container">
                    <div class="chart-container">
                        <?php if (!empty($analyticsData['age_distribution'])): ?>
                            <canvas id="ageDistributionChart"></canvas>
                        <?php else: ?>
                            <div class="no-data">
                                <i class="fas fa-chart-bar fa-3x"></i>
                                <p>No age distribution data available for <?php echo htmlspecialchars($current_barangay_name); ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Narrative Report -->
                    <div class="narrative-report" id="ageNarrative">
                        <?php
                        // Calculate narrative based on age distribution
                        if (!empty($analyticsData['age_distribution'])) {
                            $totalPopulation = $analyticsData['total_members'] ?? 0;
                            $ageGroups = [];
                            
                            // Organize age data
                            foreach ($analyticsData['age_distribution'] as $age) {
                                if ($age['age_group'] !== 'Not Specified') {
                                    $ageGroups[$age['age_group']] = $age['count'];
                                }
                            }
                            
                            // Calculate youth (0-19), working age (20-64), and senior (65+) populations
                            $youthPopulation = 0;
                            $workingAgePopulation = 0;
                            $seniorPopulation = 0;
                            
                            foreach ($ageGroups as $group => $count) {
                                if (in_array($group, ['0-4', '5-9', '10-14', '15-19'])) {
                                    $youthPopulation += $count;
                                } elseif (in_array($group, ['20-24', '25-29', '30-34', '35-39', '40-44', '45-49', '50-54', '55-59', '60-64'])) {
                                    $workingAgePopulation += $count;
                                } elseif ($group === '65+') {
                                    $seniorPopulation = $count;
                                }
                            }
                            
                            $youthPercent = $totalPopulation > 0 ? round(($youthPopulation / $totalPopulation) * 100, 1) : 0;
                            $workingAgePercent = $totalPopulation > 0 ? round(($workingAgePopulation / $totalPopulation) * 100, 1) : 0;
                            $seniorPercent = $totalPopulation > 0 ? round(($seniorPopulation / $totalPopulation) * 100, 1) : 0;
                            
                            // Find dominant age group
                            $dominantAgeGroup = '';
                            $dominantCount = 0;
                            foreach ($ageGroups as $group => $count) {
                                if ($count > $dominantCount) {
                                    $dominantAgeGroup = $group;
                                    $dominantCount = $count;
                                }
                            }
                            $dominantPercent = $totalPopulation > 0 ? round(($dominantCount / $totalPopulation) * 100, 1) : 0;
                            
                            // Generate narrative
                            echo "<h4>Demographic Analysis</h4>";
                            echo "<p>The population shows ";
                            
                            if ($youthPercent > 40) {
                                echo "a <strong>youthful structure</strong> with $youthPercent% under 20 years.";
                            } elseif ($seniorPercent > 15) {
                                echo "an <strong>aging population</strong> with $seniorPercent% over 65 years.";
                            } else {
                                echo "a <strong>balanced age distribution</strong>.";
                            }
                            
                            echo "</p>";
                            
                            echo "<h4>Key Insights</h4>";
                            echo "<ul>";
                            echo "<li><strong>Youth (0-19):</strong> $youthPopulation residents ($youthPercent%)</li>";
                            echo "<li><strong>Working Age (20-64):</strong> $workingAgePopulation residents ($workingAgePercent%)</li>";
                            echo "<li><strong>Seniors (65+):</strong> $seniorPopulation residents ($seniorPercent%)</li>";
                            echo "<li><strong>Dominant Group:</strong> $dominantAgeGroup with $dominantCount residents ($dominantPercent%)</li>";
                            echo "</ul>";
                            
                            echo "<h4>Planning Implications</h4>";
                            
                            if ($youthPercent > 35) {
                                echo "<p>Prioritize education, youth centers, and family services.</p>";
                            } elseif ($seniorPercent > 12) {
                                echo "<p>Focus on healthcare, senior activities, and accessibility.</p>";
                            } elseif ($workingAgePercent > 65) {
                                echo "<p>Develop job programs, skills training, and economic initiatives.</p>";
                            } else {
                                echo "<p>Implement balanced programs serving all age groups.</p>";
                            }
                        } else {
                            echo "<p>No age data available to generate narrative.</p>";
                        }
                        ?>
                    </div>
                </div>
            </div>
            
            <!-- Employment Status Card -->
            <div class="analytics-card">
                <div class="card-header">
                    <h3>Employment Status - <?php echo htmlspecialchars($current_barangay_name); ?></h3>
                </div>
                <div class="chart-container">
                    <?php if (!empty($analyticsData['employment_status'])): ?>
                        <canvas id="employmentChart"></canvas>
                    <?php else: ?>
                        <div class="no-data">
                            <i class="fas fa-briefcase fa-3x"></i>
                            <p>No employment data available for <?php echo htmlspecialchars($current_barangay_name); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Age Pyramid Card -->
            <div class="analytics-card">
                <div class="card-header">
                    <h3>Population Pyramid - <?php echo htmlspecialchars($current_barangay_name); ?></h3>
                </div>
                <div class="chart-container">
                    <?php if (!empty($agePyramidData)): ?>
                        <canvas id="agePyramidChart"></canvas>
                        <div class="pyramid-legend">
                            <div class="legend-item">
                                <div class="legend-color" style="background-color: rgba(54, 162, 235, 0.7);"></div>
                                <span>Male</span>
                            </div>
                            <div class="legend-item">
                                <div class="legend-color" style="background-color: rgba(255, 99, 132, 0.7);"></div>
                                <span>Female</span>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="no-data">
                            <i class="fas fa-chart-bar fa-3x"></i>
                            <p>No population pyramid data available for <?php echo htmlspecialchars($current_barangay_name); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Gender Ratio Card -->
            <div class="analytics-card">
                <div class="card-header">
                    <h3>Gender Ratio - <?php echo htmlspecialchars($current_barangay_name); ?></h3>
                </div>
                <div class="chart-container">
                    <?php if (!empty($analyticsData['gender_ratio'])): ?>
                        <canvas id="genderChart"></canvas>
                    <?php else: ?>
                        <div class="no-data">
                            <i class="fas fa-venus-mars fa-3x"></i>
                            <p>No gender data available for <?php echo htmlspecialchars($current_barangay_name); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Quick Stats Card -->
            <div class="analytics-card">
                <div class="card-header">
                    <h3>Quick Statistics - <?php echo htmlspecialchars($current_barangay_name); ?></h3>
                </div>
                <div class="stats-grid">
                    <?php
                    // Calculate total members from census_submissions
                    $totalMembers = $analyticsData['total_members'] ?? 0;
                    
                    // Calculate gender percentages
                    $maleCount = 0;
                    $femaleCount = 0;
                    $otherCount = 0;
                    foreach (($analyticsData['gender_ratio'] ?? []) as $gender) {
                        if ($gender['gender'] == 'Male') $maleCount = $gender['count'];
                        if ($gender['gender'] == 'Female') $femaleCount = $gender['count'];
                        if ($gender['gender'] == 'Other' || $gender['gender'] == 'Not Specified') $otherCount += $gender['count'];
                    }
                    $malePercent = $totalMembers > 0 ? round(($maleCount / $totalMembers) * 100, 1) : 0;
                    $femalePercent = $totalMembers > 0 ? round(($femaleCount / $totalMembers) * 100, 1) : 0;
                    $otherPercent = $totalMembers > 0 ? round(($otherCount / $totalMembers) * 100, 1) : 0;
                    
                    // Get total households and census
                    $totalHouseholds = $analyticsData['total_households'] ?? 0;
                    $totalCensus = $analyticsData['total_census'] ?? 0;
                    
                    // Calculate average household size
                    $avgHouseholdSize = $totalHouseholds > 0 ? round($totalMembers / $totalHouseholds, 1) : 0;
                    ?>
                    <div class="stat-item">
                        <div class="stat-value"><?php echo number_format($totalMembers); ?></div>
                        <div class="stat-label">Total Residents</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?php echo number_format($totalHouseholds); ?></div>
                        <div class="stat-label">Total Households</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?php echo $malePercent; ?>%</div>
                        <div class="stat-label">Male</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?php echo $femalePercent; ?>%</div>
                        <div class="stat-label">Female</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?php echo $avgHouseholdSize; ?></div>
                        <div class="stat-label">Avg. Household Size</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?php echo number_format($totalCensus); ?></div>
                        <div class="stat-label">Census Records</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?php echo $otherPercent; ?>%</div>
                        <div class="stat-label">Other/Not Specified</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?php echo count($analyticsData['employment_status'] ?? []); ?></div>
                        <div class="stat-label">Employment Categories</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@3.7.1/dist/chart.min.js"></script>
<script>
    // Prepare data for charts
    const ageData = {
        labels: <?php echo json_encode(array_column($analyticsData['age_distribution'] ?? [], 'age_group')); ?>,
        values: <?php echo json_encode(array_column($analyticsData['age_distribution'] ?? [], 'count')); ?>
    };
    
    const employmentData = {
        labels: <?php echo json_encode(array_column($analyticsData['employment_status'] ?? [], 'employment_status')); ?>,
        values: <?php echo json_encode(array_column($analyticsData['employment_status'] ?? [], 'count')); ?>
    };
    
    const genderData = {
        labels: <?php echo json_encode(array_column($analyticsData['gender_ratio'] ?? [], 'gender')); ?>,
        values: <?php echo json_encode(array_column($analyticsData['gender_ratio'] ?? [], 'count')); ?>
    };
    
    // Age Pyramid Data
    const agePyramidData = {
        ageGroups: <?php echo json_encode(array_column($agePyramidData, 'age_group')); ?>,
        maleCounts: <?php echo json_encode(array_column($agePyramidData, 'male_count')); ?>,
        femaleCounts: <?php echo json_encode(array_column($agePyramidData, 'female_count')); ?>
    };
    
    // Colors
    const chartColors = {
        blue: 'rgba(54, 162, 235, 0.7)',
        red: 'rgba(255, 99, 132, 0.7)',
        yellow: 'rgba(255, 206, 86, 0.7)',
        green: 'rgba(75, 192, 192, 0.7)',
        purple: 'rgba(153, 102, 255, 0.7)',
        orange: 'rgba(255, 159, 64, 0.7)',
        gray: 'rgba(201, 203, 207, 0.7)'
    };

    // Initialize charts when DOM is loaded
    document.addEventListener('DOMContentLoaded', function() {
        // Age Distribution Chart (Bar Chart)
        <?php if (!empty($analyticsData['age_distribution'])): ?>
        const ageDistributionCtx = document.getElementById('ageDistributionChart').getContext('2d');
        
        // Define age group colors
        const ageGroupColors = [
            'rgba(255, 99, 132, 0.7)',    // 0-4
            'rgba(54, 162, 235, 0.7)',    // 5-9
            'rgba(255, 206, 86, 0.7)',    // 10-14
            'rgba(75, 192, 192, 0.7)',    // 15-19
            'rgba(153, 102, 255, 0.7)',   // 20-24
            'rgba(255, 159, 64, 0.7)',    // 25-29
            'rgba(201, 203, 207, 0.7)',   // 30-34
            'rgba(255, 99, 132, 0.5)',    // 35-39
            'rgba(54, 162, 235, 0.5)',    // 40-44
            'rgba(255, 206, 86, 0.5)',    // 45-49
            'rgba(75, 192, 192, 0.5)',    // 50-54
            'rgba(153, 102, 255, 0.5)',   // 55-59
            'rgba(255, 159, 64, 0.5)',    // 60-64
            'rgba(201, 203, 207, 0.5)'    // 65+
        ];
        
        new Chart(ageDistributionCtx, {
            type: 'bar',
            data: {
                labels: ageData.labels.filter(label => label !== 'Not Specified'),
                datasets: [{
                    label: 'Residents',
                    data: ageData.values.slice(0, 14), // Only first 14 age groups
                    backgroundColor: ageGroupColors,
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': ' + context.raw.toLocaleString();
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        title: {
                            display: true,
                            text: 'Age Group'
                        },
                        grid: {
                            display: false
                        }
                    },
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Number of Residents'
                        },
                        ticks: {
                            callback: function(value) {
                                return value.toLocaleString();
                            }
                        }
                    }
                }
            }
        });
        <?php endif; ?>
        
        // Age Pyramid Chart (Population Pyramid)
        <?php if (!empty($agePyramidData)): ?>
        const pyramidCtx = document.getElementById('agePyramidChart').getContext('2d');
        
        // Convert male counts to negative for left side of pyramid
        const negativeMaleCounts = agePyramidData.maleCounts.map(count => -Math.abs(count));
        
        new Chart(pyramidCtx, {
            type: 'bar',
            data: {
                labels: agePyramidData.ageGroups,
                datasets: [
                    {
                        label: 'Male',
                        data: negativeMaleCounts,
                        backgroundColor: chartColors.blue,
                        borderWidth: 1,
                        borderColor: 'rgba(54, 162, 235, 1)'
                    },
                    {
                        label: 'Female',
                        data: agePyramidData.femaleCounts,
                        backgroundColor: chartColors.red,
                        borderWidth: 1,
                        borderColor: 'rgba(255, 99, 132, 1)'
                    }
                ]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                label += Math.abs(context.raw).toLocaleString();
                                return label;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        stacked: false,
                        ticks: {
                            callback: function(value) {
                                return Math.abs(value).toLocaleString();
                            }
                        },
                        title: {
                            display: true,
                            text: 'Number of Residents'
                        },
                        grid: {
                            display: true
                        }
                    },
                    y: {
                        stacked: false,
                        title: {
                            display: true,
                            text: 'Age Group'
                        },
                        grid: {
                            display: true
                        }
                    }
                }
            }
        });
        <?php endif; ?>
        
        // Employment Status Chart (Doughnut)
        <?php if (!empty($analyticsData['employment_status'])): ?>
        const employmentCtx = document.getElementById('employmentChart').getContext('2d');
        new Chart(employmentCtx, {
            type: 'doughnut',
            data: {
                labels: employmentData.labels,
                datasets: [{
                    data: employmentData.values,
                    backgroundColor: [
                        chartColors.green,
                        chartColors.red,
                        chartColors.blue,
                        chartColors.orange,
                        chartColors.purple,
                        chartColors.yellow,
                        chartColors.gray
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: window.innerWidth <= 768 ? 'bottom' : 'right'
                    }
                }
            }
        });
        <?php endif; ?>
        
        // Gender Ratio Chart (Pie)
        <?php if (!empty($analyticsData['gender_ratio'])): ?>
        const genderCtx = document.getElementById('genderChart').getContext('2d');
        new Chart(genderCtx, {
            type: 'pie',
            data: {
                labels: genderData.labels,
                datasets: [{
                    data: genderData.values,
                    backgroundColor: [
                        chartColors.blue,
                        chartColors.red,
                        chartColors.purple
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: window.innerWidth <= 768 ? 'bottom' : 'right'
                    }
                }
            }
        });
        <?php endif; ?>
    });

    // Mobile sidebar functionality
    function toggleSidebar() {
        const sidebar = document.querySelector('.sidebar');
        const overlay = document.querySelector('.sidebar-overlay');
        sidebar.classList.toggle('open');
        overlay.classList.toggle('active');
    }

    // Close sidebar when clicking on a link (mobile)
    document.querySelectorAll('.sidebar-nav a').forEach(link => {
        link.addEventListener('click', () => {
            if (window.innerWidth <= 768) {
                toggleSidebar();
            }
        });
    });

    // Close sidebar when pressing escape key
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            const sidebar = document.querySelector('.sidebar');
            const overlay = document.querySelector('.sidebar-overlay');
            if (sidebar.classList.contains('open')) {
                sidebar.classList.remove('open');
                overlay.classList.remove('active');
            }
        }
    });

    // Handle window resize for chart legend positioning
    window.addEventListener('resize', function() {
        // Charts will automatically re-render with responsive options
    });
</script>
</body>
</html>