<?php
session_start();
require_once "config.php";

// Check login
if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit();
}

$is_logged_in = true;
$user_role = $_SESSION['user']['role'] ?? null;
$user_barangay_id = $_SESSION['user']['barangay_id'] ?? null;
$is_super_admin = ($user_role === 'super_admin');
$is_captain = ($user_role === 'captain');

// Get report parameters
$report_barangay_id = isset($_GET['report_barangay']) ? intval($_GET['report_barangay']) : null;
$report_year = isset($_GET['report_year']) ? intval($_GET['report_year']) : null;

// For non-super admins, restrict to their barangay
if (!$is_super_admin) {
    $report_barangay_id = $user_barangay_id;
}

// Get barangay name for report title
$barangay_scope = "All Barangays";
if ($report_barangay_id) {
    $barangayQuery = "SELECT barangay_name FROM barangay_registration WHERE id = ?";
    $stmt = $conn->prepare($barangayQuery);
    $stmt->bind_param("i", $report_barangay_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $barangay = $result->fetch_assoc();
    if ($barangay) {
        $barangay_scope = "Barangay " . $barangay['barangay_name'];
    }
}

// Add year to scope if specified
if ($report_year) {
    $barangay_scope .= " (Year: " . $report_year . ")";
}

// Function to get data with detailed age groups
function getReportData($barangay_id = null, $year = null) {
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
            
            // Add year filter if provided
            if ($year) {
                $whereClause .= " AND YEAR(cs.created_at) = ?";
                $params[] = $year;
                $types .= "i";
            }
        }
    } else {
        $whereClause = "WHERE 1=1";
        // Add year filter if provided (for all barangays)
        if ($year) {
            $whereClause .= " AND YEAR(cs.created_at) = ?";
            $params[] = $year;
            $types = "i";
        }
    }
    
    // Get total residents
    $residentsQuery = "SELECT COUNT(*) as total_residents FROM census_submissions cs $whereClause";
    $stmt = $conn->prepare($residentsQuery);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $data['total_residents'] = $result->fetch_assoc()['total_residents'] ?? 0;
    
    // Get total households
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
    
    // Get detailed age distribution (0-4 to 65+)
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
    $ageData = $result->fetch_all(MYSQLI_ASSOC);
    
    // Ensure all age groups are present (even if count is 0)
    $allAgeGroups = [
        '0-4', '5-9', '10-14', '15-19', '20-24', '25-29', '30-34',
        '35-39', '40-44', '45-49', '50-54', '55-59', '60-64', '65+'
    ];
    
    $ageGroupsMap = [];
    foreach ($ageData as $ageGroup) {
        $ageGroupsMap[$ageGroup['age_group']] = $ageGroup['count'];
    }
    
    $completeAgeDistribution = [];
    foreach ($allAgeGroups as $group) {
        $completeAgeDistribution[] = [
            'age_group' => $group,
            'count' => $ageGroupsMap[$group] ?? 0
        ];
    }
    
    // Add Not Specified if exists
    if (isset($ageGroupsMap['Not Specified'])) {
        $completeAgeDistribution[] = [
            'age_group' => 'Not Specified',
            'count' => $ageGroupsMap['Not Specified']
        ];
    }
    
    $data['age_distribution'] = $completeAgeDistribution;
    
    // Get gender distribution
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
    $data['gender_distribution'] = $result->fetch_all(MYSQLI_ASSOC);
    
    // Get employment status
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
    
    // Calculate average household size
    $data['avg_household_size'] = $data['total_households'] > 0 ? 
        round($data['total_residents'] / $data['total_households'], 1) : 0;
    
    // Get dominant age group
    $data['dominant_age_group'] = '';
    $data['dominant_age_count'] = 0;
    $data['dominant_age_percent'] = 0;
    foreach ($data['age_distribution'] as $ageGroup) {
        if ($ageGroup['age_group'] !== 'Not Specified' && $ageGroup['count'] > $data['dominant_age_count']) {
            $data['dominant_age_group'] = $ageGroup['age_group'];
            $data['dominant_age_count'] = $ageGroup['count'];
        }
    }
    if ($data['total_residents'] > 0 && $data['dominant_age_count'] > 0) {
        $data['dominant_age_percent'] = round(($data['dominant_age_count'] / $data['total_residents']) * 100, 1);
    }
    
    // Calculate youth population (0-19)
    $data['youth_population'] = 0;
    foreach ($data['age_distribution'] as $ageGroup) {
        if (in_array($ageGroup['age_group'], ['0-4', '5-9', '10-14', '15-19'])) {
            $data['youth_population'] += $ageGroup['count'];
        }
    }
    $data['youth_percent'] = $data['total_residents'] > 0 ? 
        round(($data['youth_population'] / $data['total_residents']) * 100, 1) : 0;
    
    // Calculate senior population (65+)
    $data['senior_population'] = 0;
    foreach ($data['age_distribution'] as $ageGroup) {
        if ($ageGroup['age_group'] === '65+') {
            $data['senior_population'] = $ageGroup['count'];
            break;
        }
    }
    $data['senior_percent'] = $data['total_residents'] > 0 ? 
        round(($data['senior_population'] / $data['total_residents']) * 100, 1) : 0;
    
    // Calculate working age population (20-64)
    $data['working_age_population'] = 0;
    foreach ($data['age_distribution'] as $ageGroup) {
        if (in_array($ageGroup['age_group'], ['20-24', '25-29', '30-34', '35-39', '40-44', '45-49', '50-54', '55-59', '60-64'])) {
            $data['working_age_population'] += $ageGroup['count'];
        }
    }
    $data['working_age_percent'] = $data['total_residents'] > 0 ? 
        round(($data['working_age_population'] / $data['total_residents']) * 100, 1) : 0;
    
    // Get gender counts
    $data['male_count'] = 0;
    $data['female_count'] = 0;
    $data['other_count'] = 0;
    foreach ($data['gender_distribution'] as $gender) {
        if ($gender['gender'] == 'Male') $data['male_count'] = $gender['count'];
        if ($gender['gender'] == 'Female') $data['female_count'] = $gender['count'];
        if ($gender['gender'] == 'Other' || $gender['gender'] == 'Not Specified') $data['other_count'] += $gender['count'];
    }
    
    // Calculate gender percentages
    $data['male_percent'] = $data['total_residents'] > 0 ? 
        round(($data['male_count'] / $data['total_residents']) * 100, 1) : 0;
    $data['female_percent'] = $data['total_residents'] > 0 ? 
        round(($data['female_count'] / $data['total_residents']) * 100, 1) : 0;
    $data['other_percent'] = $data['total_residents'] > 0 ? 
        round(($data['other_count'] / $data['total_residents']) * 100, 1) : 0;
    
    // Get employment counts
    $data['employed_count'] = 0;
    $data['unemployed_count'] = 0;
    $data['student_count'] = 0;
    foreach ($data['employment_status'] as $employment) {
        $status = strtolower($employment['employment_status']);
        if (strpos($status, 'employ') !== false && strpos($status, 'unemploy') === false) {
            $data['employed_count'] += $employment['count'];
        } elseif (strpos($status, 'unemploy') !== false) {
            $data['unemployed_count'] += $employment['count'];
        } elseif (strpos($status, 'student') !== false) {
            $data['student_count'] += $employment['count'];
        }
    }
    
    return $data;
}

// GET DATA
$reportData = getReportData($report_barangay_id, $report_year);

// Convert array results into simple label-value maps
$age_map = [];
foreach ($reportData['age_distribution'] as $row) {
    $age_map[$row['age_group']] = $row['count'];
}
$gender_map = [];
foreach ($reportData['gender_distribution'] as $row) {
    $gender_map[$row['gender']] = $row['count'];
}
$employment_map = [];
foreach ($reportData['employment_status'] as $row) {
    $employment_map[$row['employment_status']] = $row['count'];
}

// Enhanced Narrative generator function with detailed age analysis
function narrative($reportData, $barangay_name, $year = null) {
    
    $total_residents = $reportData['total_residents'];
    $total_households = $reportData['total_households'];
    
    $year_text = $year ? " for the year $year" : "";
    
    // Age group analysis
    $ageGroups = [
        '0-4' => $reportData['age_distribution'][0]['count'] ?? 0,
        '5-9' => $reportData['age_distribution'][1]['count'] ?? 0,
        '10-14' => $reportData['age_distribution'][2]['count'] ?? 0,
        '15-19' => $reportData['age_distribution'][3]['count'] ?? 0,
        '20-24' => $reportData['age_distribution'][4]['count'] ?? 0,
        '25-29' => $reportData['age_distribution'][5]['count'] ?? 0,
        '30-34' => $reportData['age_distribution'][6]['count'] ?? 0,
        '35-39' => $reportData['age_distribution'][7]['count'] ?? 0,
        '40-44' => $reportData['age_distribution'][8]['count'] ?? 0,
        '45-49' => $reportData['age_distribution'][9]['count'] ?? 0,
        '50-54' => $reportData['age_distribution'][10]['count'] ?? 0,
        '55-59' => $reportData['age_distribution'][11]['count'] ?? 0,
        '60-64' => $reportData['age_distribution'][12]['count'] ?? 0,
        '65+' => $reportData['age_distribution'][13]['count'] ?? 0,
    ];
    
    // Find top 3 age groups
    $sortedAgeGroups = $ageGroups;
    arsort($sortedAgeGroups);
    $topAgeGroups = array_slice($sortedAgeGroups, 0, 3, true);
    
    // Calculate dependency ratio
    $youngDependents = $ageGroups['0-4'] + $ageGroups['5-9'] + $ageGroups['10-14'] + $ageGroups['15-19'];
    $oldDependents = $ageGroups['65+'];
    $workingAge = $reportData['working_age_population'];
    $dependencyRatio = $workingAge > 0 ? round(($youngDependents + $oldDependents) / $workingAge * 100, 1) : 0;
    
    // Age structure classification
    $ageStructure = '';
    if ($reportData['youth_percent'] > 40) {
        $ageStructure = 'a youthful population';
    } elseif ($reportData['senior_percent'] > 15) {
        $ageStructure = 'an aging population';
    } elseif ($reportData['working_age_percent'] > 60) {
        $ageStructure = 'a working-age dominant population';
    } else {
        $ageStructure = 'a balanced age structure';
    }
    
    // Calculate combined age groups for easier reference
    $schoolAgeChildren = $ageGroups['5-9'] + $ageGroups['10-14'];
    
    return "
        <h3>EXECUTIVE SUMMARY</h3>
        <p>This comprehensive demographic report provides detailed analysis of $barangay_name$year_text. The data reveals key population characteristics essential for strategic planning, resource allocation, and targeted community development initiatives.</p>
        
        <h3>POPULATION OVERVIEW</h3>
        <p>The barangay has a total population of <b>" . number_format($total_residents) . " residents</b> organized into <b>" . number_format($total_households) . " households</b>. The average household size is <b>" . $reportData['avg_household_size'] . " members</b>, which " . 
        ($reportData['avg_household_size'] > 4 ? "indicates larger-than-average family units" : 
        ($reportData['avg_household_size'] < 3 ? "suggests smaller family sizes" : "represents typical family composition")) . " in the community.</p>
        
        <h3>DETAILED AGE DISTRIBUTION ANALYSIS</h3>
        <p>The population exhibits $ageStructure with the following detailed breakdown:</p>
        
        <p><strong>Youth Population (0-19 years):</strong> Comprising <b>" . number_format($reportData['youth_population']) . " residents (" . $reportData['youth_percent'] . "%)</b>, this segment includes:
        <br>- Early childhood (0-4): " . number_format($ageGroups['0-4']) . " residents
        <br>- School-age children (5-14): " . number_format($schoolAgeChildren) . " residents
        <br>- Adolescents (15-19): " . number_format($ageGroups['15-19']) . " residents</p>
        
        <p><strong>Young Adults (20-34 years):</strong> Totaling <b>" . number_format($ageGroups['20-24'] + $ageGroups['25-29'] + $ageGroups['30-34']) . " residents</b>, representing the emerging workforce and young families.</p>
        
        <p><strong>Prime Working Age (35-49 years):</strong> Numbering <b>" . number_format($ageGroups['35-39'] + $ageGroups['40-44'] + $ageGroups['45-49']) . " residents</b>, this group forms the core of the local economy.</p>
        
        <p><strong>Pre-Retirement (50-64 years):</strong> Consisting of <b>" . number_format($ageGroups['50-54'] + $ageGroups['55-59'] + $ageGroups['60-64']) . " residents</b>, representing experienced workers and community leaders.</p>
        
        <p><strong>Senior Citizens (65+ years):</strong> Comprising <b>" . number_format($reportData['senior_population']) . " residents (" . $reportData['senior_percent'] . "%)</b>, indicating " . 
        ($reportData['senior_percent'] > 10 ? "a significant elderly population requiring healthcare and social services" : "a relatively small elderly demographic") . ".</p>
        
        <p>The dominant age group is <b>" . $reportData['dominant_age_group'] . "</b> with <b>" . number_format($reportData['dominant_age_count']) . " residents (" . $reportData['dominant_age_percent'] . "%)</b>. The dependency ratio (young + old / working age) is <b>$dependencyRatio%</b>, suggesting " . 
        ($dependencyRatio > 60 ? "high dependency on the working population" : 
        ($dependencyRatio < 40 ? "favorable demographic conditions for economic growth" : "moderate demographic pressure")) . ".</p>
        
        <h3>GENDER COMPOSITION</h3>
        <p>The gender distribution shows <b>" . number_format($reportData['male_count']) . " males (" . $reportData['male_percent'] . "%)</b> and <b>" . number_format($reportData['female_count']) . " females (" . $reportData['female_percent'] . "%)</b>. " . 
        (abs($reportData['male_percent'] - $reportData['female_percent']) < 5 ? 
         "The gender ratio is relatively balanced, indicating equitable population distribution." : 
         ($reportData['male_percent'] > $reportData['female_percent'] ? 
          "There is a higher proportion of male residents, which may influence program planning and service delivery towards male-focused activities." : 
          "There is a higher proportion of female residents, suggesting potential for women-focused programs and services.")) . 
        ($reportData['other_count'] > 0 ? " Additionally, <b>" . number_format($reportData['other_count']) . " residents (" . $reportData['other_percent'] . "%)</b> are categorized as other or not specified." : "") . "</p>
        
        <h3>EMPLOYMENT AND ECONOMIC PROFILE</h3>
        <p>The employment data reveals important socioeconomic characteristics. Approximately <b>" . number_format($reportData['employed_count']) . " residents</b> are employed, while <b>" . number_format($reportData['unemployed_count']) . "</b> are currently seeking employment. The student population totals <b>" . number_format($reportData['student_count']) . "</b>, highlighting educational needs and youth development opportunities. " . 
        ($reportData['working_age_population'] > 0 ? 
         "With <b>" . number_format($reportData['working_age_population']) . " residents (" . $reportData['working_age_percent'] . "%)</b> in the working age bracket, there is " . 
         ($reportData['employed_count'] / $reportData['working_age_population'] > 0.7 ? "a strong employment base" : 
          ($reportData['employed_count'] / $reportData['working_age_population'] < 0.5 ? "significant untapped labor potential" : "moderate employment capacity")) . 
         " in the community." : "") . "</p>
        
        <h3>KEY DEMOGRAPHIC INSIGHTS</h3>
        <ol>
            <li><strong>Population Pyramid Shape:</strong> " . 
                ($reportData['youth_percent'] > 40 ? "Expansive (wide base) - High fertility potential" : 
                ($reportData['senior_percent'] > 15 ? "Constrictive (narrowing base) - Aging population trends" : 
                "Stationary (balanced) - Stable demographic structure")) . "</li>
            
            <li><strong>Top 3 Age Groups:</strong> " . 
                implode(", ", array_map(function($group, $count) use ($total_residents) {
                    $percent = $total_residents > 0 ? round(($count / $total_residents) * 100, 1) : 0;
                    return "$group (" . number_format($count) . ", $percent%)";
                }, array_keys($topAgeGroups), array_values($topAgeGroups))) . "</li>
            
            <li><strong>Life Stage Distribution:</strong>
                <br>- Children & Youth (0-19): " . $reportData['youth_percent'] . "%
                <br>- Working Adults (20-64): " . $reportData['working_age_percent'] . "%
                <br>- Seniors (65+): " . $reportData['senior_percent'] . "%</li>
        </ol>
        
        <h3>STRATEGIC RECOMMENDATIONS</h3>
        <p>Based on this detailed demographic analysis, the following targeted recommendations are proposed:</p>
        <ol>
            <li><strong>Age-Specific Programs:</strong> " . 
                ($ageGroups['0-4'] > 0 ? "Develop early childhood care and education programs for " . number_format($ageGroups['0-4']) . " children aged 0-4. " : "") .
                ($schoolAgeChildren > 0 ? "Establish after-school activities for " . number_format($schoolAgeChildren) . " school-age children (5-14). " : "") .
                ($ageGroups['15-19'] > 0 ? "Create youth development and career guidance programs for " . number_format($ageGroups['15-19']) . " adolescents. " : "") .
                ($reportData['senior_population'] > 0 ? "Implement comprehensive senior citizen services including healthcare, social activities, and support for " . number_format($reportData['senior_population']) . " elderly residents." : "") . "</li>
            
            <li><strong>Economic Development:</strong> " . 
                ($reportData['unemployed_count'] > ($total_residents * 0.1) ? 
                "Launch targeted livelihood programs, skills training, and job fairs to address unemployment affecting " . number_format($reportData['unemployed_count']) . " residents." : 
                "Strengthen existing economic initiatives and promote entrepreneurship among " . number_format($reportData['working_age_population']) . " working-age residents.") . "</li>
            
            <li><strong>Infrastructure Planning:</strong> Align public facilities and services with the dominant age groups and population density patterns.</li>
            
            <li><strong>Healthcare Services:</strong> " . 
                ($reportData['senior_percent'] > 10 ? "Prioritize geriatric healthcare and chronic disease management." : 
                ($reportData['youth_percent'] > 35 ? "Focus on maternal and child health services." : 
                "Maintain comprehensive primary healthcare for all age groups.")) . "</li>
            
            <li><strong>Data-Driven Governance:</strong> Establish regular demographic monitoring and update cycles to ensure responsive and evidence-based planning.</li>
        </ol>
        
        <h3>CONCLUSION</h3>
        <p>This detailed demographic profile provides a robust foundation for evidence-based decision-making in $barangay_name. The comprehensive age group analysis enables precise targeting of programs and services. With " . 
        ($reportData['youth_percent'] > 30 ? "a significant youth population representing future potential, " : 
        ($reportData['senior_percent'] > 15 ? "an aging demographic requiring specialized attention, " : 
        "a balanced population structure offering stability, ")) . 
        "strategic planning should focus on maximizing community wellbeing across all life stages. Regular demographic monitoring will ensure continued relevance and effectiveness of barangay development initiatives.</p>
    ";
}

// Generate narrative
$narrative = narrative($reportData, $barangay_scope, $report_year);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Barangay Demographic Report - Printable</title>
    <style>
        body {
            font-family: 'Times New Roman', Times, serif;
            margin: 40px;
            line-height: 1.6;
            color: #333;
        }
        
        .print-header {
            text-align: center;
            border-bottom: 2px solid #1d3b71;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
        
        .print-header h1 {
            color: #1d3b71;
            margin: 0;
            font-size: 24px;
        }
        
        .print-header h2 {
            color: #666;
            margin: 10px 0 0 0;
            font-size: 18px;
        }
        
        .print-header .meta {
            font-size: 14px;
            color: #777;
            margin-top: 10px;
        }
        
        .section {
            margin: 30px 0;
            page-break-inside: avoid;
        }
        
        .section h3 {
            color: #1d3b71;
            border-bottom: 1px solid #ddd;
            padding-bottom: 8px;
            margin-bottom: 15px;
            font-size: 18px;
        }
        
        .print-btn-container {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .print-btn {
            padding: 12px 25px;
            background: #2ecc71;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: background 0.3s;
        }
        
        .print-btn:hover {
            background: #27ae60;
        }
        
        .back-btn {
            padding: 10px 20px;
            background: #3498db;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 14px;
            text-decoration: none;
            display: inline-block;
            margin-right: 10px;
        }
        
        .back-btn:hover {
            background: #2980b9;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            page-break-inside: avoid;
        }
        
        table th {
            background: #1d3b71;
            color: white;
            padding: 12px;
            text-align: left;
            font-weight: bold;
        }
        
        table td {
            padding: 10px 12px;
            border-bottom: 1px solid #ddd;
        }
        
        table tr:nth-child(even) {
            background-color: #f8f9fa;
        }
        
        .stats-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin: 25px 0;
        }
        
        .stat-box {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            border-left: 4px solid #1d3b71;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: bold;
            color: #1d3b71;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 14px;
            color: #666;
        }
        
        .footer {
            margin-top: 50px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
            font-size: 12px;
            color: #777;
            text-align: center;
        }
        
        .watermark {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            font-size: 120px;
            color: rgba(0,0,0,0.05);
            z-index: -1;
            font-weight: bold;
            white-space: nowrap;
        }
        
        @media print {
            .print-btn-container, .back-btn {
                display: none;
            }
            
            body {
                margin: 20px;
            }
            
            .print-header {
                border-bottom: 2px solid #000;
            }
            
            .watermark {
                display: block;
            }
            
            table th {
                background: #000 !important;
                -webkit-print-color-adjust: exact;
                color: white !important;
            }
        }
        
        @media screen and (max-width: 768px) {
            body {
                margin: 20px;
            }
            
            .stats-summary {
                grid-template-columns: 1fr;
            }
            
            table {
                font-size: 14px;
            }
        }
        
        .no-data {
            text-align: center;
            padding: 40px;
            color: #777;
            font-style: italic;
            border: 1px dashed #ddd;
            border-radius: 8px;
            margin: 20px 0;
        }
        
        .age-summary {
            background: #e8f4f8;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
            border-left: 4px solid #1d3b71;
        }
        
        .age-summary h4 {
            margin-top: 0;
            color: #1d3b71;
        }
    </style>
</head>

<body>
    <!-- Watermark for printed version -->
    <div class="watermark">BARANGAY REPORT</div>

    <!-- Print Button Container -->
    <div class="print-btn-container">
        <a href="analytics.php" class="back-btn">← Back to Analytics</a>
        <button class="print-btn" onclick="window.print()">
            <i class="fas fa-print"></i> Print This Report
        </button>
    </div>

    <!-- Report Header -->
    <div class="print-header">
        <h1>BARANGAY DEMOGRAPHIC & SOCIOECONOMIC REPORT</h1>
        <h2><?= htmlspecialchars($barangay_scope) ?></h2>
        <div class="meta">
            Generated on <?= date("F j, Y") ?> at <?= date("g:i A") ?><br>
            Generated by: <?= htmlspecialchars($_SESSION['user']['full_name'] ?? 'System User') ?> (<?= htmlspecialchars($user_role) ?>)<br>
            Report ID: BRG-<?= strtoupper(substr(md5(time() . $barangay_scope), 0, 8)) ?>
        </div>
    </div>

    <?php if ($reportData['total_residents'] > 0): ?>
        <!-- Quick Statistics Summary -->
        <div class="stats-summary">
            <div class="stat-box">
                <div class="stat-value"><?= number_format($reportData['total_residents']) ?></div>
                <div class="stat-label">Total Residents</div>
            </div>
            <div class="stat-box">
                <div class="stat-value"><?= number_format($reportData['total_households']) ?></div>
                <div class="stat-label">Total Households</div>
            </div>
            <div class="stat-box">
                <div class="stat-value"><?= $reportData['avg_household_size'] ?></div>
                <div class="stat-label">Average Household Size</div>
            </div>
            <div class="stat-box">
                <div class="stat-value"><?= $reportData['male_percent'] ?>%</div>
                <div class="stat-label">Male Population</div>
            </div>
        </div>

        <!-- Age Summary -->
        <div class="age-summary">
            <h4>Age Distribution Summary</h4>
            <p><strong>Youth (0-19):</strong> <?= number_format($reportData['youth_population']) ?> residents (<?= $reportData['youth_percent'] ?>%)</p>
            <p><strong>Working Age (20-64):</strong> <?= number_format($reportData['working_age_population']) ?> residents (<?= $reportData['working_age_percent'] ?>%)</p>
            <p><strong>Seniors (65+):</strong> <?= number_format($reportData['senior_population']) ?> residents (<?= $reportData['senior_percent'] ?>%)</p>
            <p><strong>Dominant Age Group:</strong> <?= $reportData['dominant_age_group'] ?> with <?= number_format($reportData['dominant_age_count']) ?> residents (<?= $reportData['dominant_age_percent'] ?>%)</p>
        </div>

        <!-- Narrative Report -->
        <div class="section">
            <?= $narrative ?>
        </div>

        <!-- Detailed Data Tables -->
        <div class="section">
            <h3>DETAILED STATISTICAL DATA</h3>

            <h4>Detailed Age Distribution (0-4 to 65+)</h4>
            <table>
                <tr>
                    <th>Age Group</th>
                    <th>Count</th>
                    <th>Percentage</th>
                    <th>Life Stage</th>
                </tr>
                <?php 
                $age_total = 0;
                $lifeStages = [
                    '0-4' => 'Early Childhood',
                    '5-9' => 'Childhood',
                    '10-14' => 'Early Adolescence',
                    '15-19' => 'Late Adolescence',
                    '20-24' => 'Young Adult',
                    '25-29' => 'Young Adult',
                    '30-34' => 'Young Adult',
                    '35-39' => 'Middle Adult',
                    '40-44' => 'Middle Adult',
                    '45-49' => 'Middle Adult',
                    '50-54' => 'Pre-Retirement',
                    '55-59' => 'Pre-Retirement',
                    '60-64' => 'Pre-Retirement',
                    '65+' => 'Senior Citizen'
                ];
                
                foreach ($reportData['age_distribution'] as $ageGroup):
                    if ($ageGroup['age_group'] === 'Not Specified') continue;
                    
                    $age_total += $ageGroup['count'];
                    $percent = $reportData['total_residents'] > 0 ? round(($ageGroup['count'] / $reportData['total_residents']) * 100, 1) : 0;
                    $lifeStage = $lifeStages[$ageGroup['age_group']] ?? 'Not Specified';
                ?>
                    <tr>
                        <td><?= htmlspecialchars($ageGroup['age_group']) ?></td>
                        <td><?= number_format($ageGroup['count']) ?></td>
                        <td><?= $percent ?>%</td>
                        <td><?= $lifeStage ?></td>
                    </tr>
                <?php endforeach; ?>
                
                <?php 
                // Check for Not Specified in age data
                $notSpecifiedCount = 0;
                foreach ($reportData['age_distribution'] as $ageGroup) {
                    if ($ageGroup['age_group'] === 'Not Specified') {
                        $notSpecifiedCount = $ageGroup['count'];
                        break;
                    }
                }
                
                if ($notSpecifiedCount > 0): 
                    $percent = $reportData['total_residents'] > 0 ? round(($notSpecifiedCount / $reportData['total_residents']) * 100, 1) : 0;
                ?>
                    <tr>
                        <td><em>Age Not Specified</em></td>
                        <td><?= number_format($notSpecifiedCount) ?></td>
                        <td><?= $percent ?>%</td>
                        <td>Unknown</td>
                    </tr>
                <?php endif; ?>
                
                <tr style="font-weight: bold; background-color: #e8f4f8;">
                    <td>TOTAL</td>
                    <td><?= number_format($reportData['total_residents']) ?></td>
                    <td>100%</td>
                    <td>-</td>
                </tr>
            </table>

            <!-- Age Summary Statistics -->
            <div class="stats-summary" style="margin-top: 20px;">
                <div class="stat-box">
                    <div class="stat-value"><?= $reportData['youth_percent'] ?>%</div>
                    <div class="stat-label">Youth (0-19)</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value"><?= $reportData['working_age_percent'] ?>%</div>
                    <div class="stat-label">Working Age (20-64)</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value"><?= $reportData['senior_percent'] ?>%</div>
                    <div class="stat-label">Seniors (65+)</div>
                </div>
            </div>

            <h4>Gender Distribution</h4>
            <table>
                <tr>
                    <th>Gender</th>
                    <th>Count</th>
                    <th>Percentage</th>
                </tr>
                <?php 
                $gender_total = 0;
                foreach ($gender_map as $gender => $count):
                    $gender_total += $count;
                    $percent = $reportData['total_residents'] > 0 ? round(($count / $reportData['total_residents']) * 100, 1) : 0;
                ?>
                    <tr>
                        <td><?= htmlspecialchars($gender) ?></td>
                        <td><?= number_format($count) ?></td>
                        <td><?= $percent ?>%</td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($gender_total < $reportData['total_residents']): ?>
                    <tr>
                        <td><em>Gender Not Specified</em></td>
                        <td><?= number_format($reportData['total_residents'] - $gender_total) ?></td>
                        <td><?= $reportData['total_residents'] > 0 ? round((($reportData['total_residents'] - $gender_total) / $reportData['total_residents']) * 100, 1) : 0 ?>%</td>
                    </tr>
                <?php endif; ?>
                <tr style="font-weight: bold; background-color: #e8f4f8;">
                    <td>TOTAL</td>
                    <td><?= number_format($reportData['total_residents']) ?></td>
                    <td>100%</td>
                </tr>
            </table>

            <h4>Employment Status</h4>
            <table>
                <tr>
                    <th>Employment Status</th>
                    <th>Count</th>
                    <th>Percentage</th>
                </tr>
                <?php 
                $employment_total = 0;
                foreach ($employment_map as $status => $count):
                    $employment_total += $count;
                    $percent = $reportData['total_residents'] > 0 ? round(($count / $reportData['total_residents']) * 100, 1) : 0;
                ?>
                    <tr>
                        <td><?= htmlspecialchars($status) ?></td>
                        <td><?= number_format($count) ?></td>
                        <td><?= $percent ?>%</td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($employment_total < $reportData['total_residents']): ?>
                    <tr>
                        <td><em>Employment Not Specified</em></td>
                        <td><?= number_format($reportData['total_residents'] - $employment_total) ?></td>
                        <td><?= $reportData['total_residents'] > 0 ? round((($reportData['total_residents'] - $employment_total) / $reportData['total_residents']) * 100, 1) : 0 ?>%</td>
                    </tr>
                <?php endif; ?>
                <tr style="font-weight: bold; background-color: #e8f4f8;">
                    <td>TOTAL</td>
                    <td><?= number_format($reportData['total_residents']) ?></td>
                    <td>100%</td>
                </tr>
            </table>
        </div>
    <?php else: ?>
        <div class="no-data">
            <h3>No Data Available</h3>
            <p>No demographic data is available for the selected parameters.</p>
            <p>Please check if census data has been recorded for <?= htmlspecialchars($barangay_scope) ?><?= $report_year ? " in year $report_year" : "" ?>.</p>
        </div>
    <?php endif; ?>

    <!-- Footer -->
    <div class="footer">
        <p>This report was automatically generated by the Barangay Event And Program Planning System</p>
        <p>Confidential - For official use only</p>
        <p>Page generated on: <?= date("Y-m-d H:i:s") ?></p>
    </div>

    <!-- Add Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    
    <script>
        // Auto-print option (optional)
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('autoprint') === '1') {
            window.print();
        }
        
        // Add page breaks for printing
        document.addEventListener('DOMContentLoaded', function() {
            const tables = document.querySelectorAll('table');
            tables.forEach((table, index) => {
                if (index > 0) {
                    table.style.pageBreakBefore = 'always';
                }
            });
        });
    </script>
</body>
</html>