<?php
require_once 'config.php';
require_once 'functions.php';

header('Content-Type: application/json');

// Check if household_id is provided
if (!isset($_GET['household_id']) || !is_numeric($_GET['household_id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid household ID']);
    exit();
}

$household_id = intval($_GET['household_id']);

try {
    // Get household head (census submission) data with ALL fields
    $query = "SELECT * FROM census_submissions WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $household_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Household not found']);
        exit();
    }
    
    $household_head = $result->fetch_assoc();
    
    // Get household members with ALL fields
    $members_query = "SELECT * FROM household_members WHERE household_id = ? ORDER BY id";
    $members_stmt = $conn->prepare($members_query);
    $members_stmt->bind_param("i", $household_id);
    $members_stmt->execute();
    $members_result = $members_stmt->get_result();
    $household_members = $members_result->fetch_all(MYSQLI_ASSOC);
    
    // Get additional census information (facilities, etc.)
    $census_info = [
        'water_supply' => $household_head['water_supply'] ?? null,
        'toilet_facility' => $household_head['toilet_facility'] ?? null,
        'garbage_disposal' => $household_head['garbage_disposal'] ?? null,
        'electricity' => $household_head['electricity'] ?? null,
        'internet_connection' => $household_head['internet_connection'] ?? null,
        'source_income' => $household_head['source_income'] ?? null,
        'submitted_at' => $household_head['submitted_at'] ?? null,
        'submitted_by' => $household_head['submitted_by'] ?? null
    ];
    
    // Prepare response data
    $response = [
        'success' => true,
        'data' => [
            'household_head' => $household_head,
            'household_members' => $household_members,
            'census_info' => $census_info
        ]
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    error_log("Error fetching household details: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>