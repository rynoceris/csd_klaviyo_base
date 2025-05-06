<?php
/**
 * Klaviyo Webhook Handler
 * 
 * This script receives and processes webhooks from Klaviyo.
 * Place this file at the URL you registered with Klaviyo for webhook delivery.
 */

require_once(__DIR__ . '/vendor/autoload.php');
require_once(__DIR__ . '/KlaviyoHelper.php');

// Initialize the helper
$klaviyo = new KlaviyoHelper('your-api-key', [
	'debug' => true,
	'logFile' => __DIR__ . '/webhooks.log'
]);

// Function to handle different webhook types
function handleWebhook($data) {
	// Extract common data
	$type = $data['type'];
	$timestamp = $data['attributes']['timestamp'] ?? date('c');
	
	// Log to database (example)
	logToDatabase($type, $timestamp, json_encode($data));
	
	// Handle different event types
	switch ($type) {
		case 'received_email':
			handleEmailReceived($data);
			break;
			
		case 'opened_email':
			handleEmailOpened($data);
			break;
			
		case 'clicked_email':
			handleEmailClicked($data);
			break;
			
		case 'unsubscribed':
			handleUnsubscribe($data);
			break;
			
		case 'bounced_email':
			handleEmailBounce($data);
			break;
			
		default:
			// Generic handling for other events
			// You could add more specific handlers as needed
			break;
	}
	
	return true;
}

/**
 * Handler for email received events
 */
function handleEmailReceived($data) {
	$profileId = $data['attributes']['profile_id'] ?? null;
	$campaignId = $data['attributes']['campaign_id'] ?? null;
	
	// Update local tracking database
	// updateEmailStatus($profileId, $campaignId, 'received');
	
	// Additional logic if needed
}

/**
 * Handler for email opened events
 */
function handleEmailOpened($data) {
	$profileId = $data['attributes']['profile_id'] ?? null;
	$campaignId = $data['attributes']['campaign_id'] ?? null;
	
	// Update open statistics
	// updateEmailStatus($profileId, $campaignId, 'opened');
	
	// Could trigger additional workflows
	// triggerFollowupSequence($profileId, 'email_opened');
}

/**
 * Handler for email clicked events
 */
function handleEmailClicked($data) {
	$profileId = $data['attributes']['profile_id'] ?? null;
	$campaignId = $data['attributes']['campaign_id'] ?? null;
	$linkUrl = $data['attributes']['link_url'] ?? '';
	
	// Update click statistics
	// updateEmailStatus($profileId, $campaignId, 'clicked', ['link' => $linkUrl]);
	
	// Based on link clicked, might trigger different actions
	// if (strpos($linkUrl, 'product') !== false) {
	//    flagProductInterest($profileId, extractProductIdFromUrl($linkUrl));
	// }
}

/**
 * Handler for unsubscribe events
 */
function handleUnsubscribe($data) {
	$profileId = $data['attributes']['profile_id'] ?? null;
	$email = $data['attributes']['email'] ?? extractEmailFromProfile($profileId);
	
	// Update local database
	// markUnsubscribed($email);
	
	// Maybe send to CRM or other systems
	// syncUnsubscribeToCRM($email);
	
	// Log for analysis
	// logUnsubscribeForAnalysis($email, $data['attributes']['reason'] ?? 'unknown');
}

/**
 * Handler for email bounce events
 */
function handleEmailBounce($data) {
	$profileId = $data['attributes']['profile_id'] ?? null;
	$email = $data['attributes']['email'] ?? extractEmailFromProfile($profileId);
	$bounceType = $data['attributes']['bounce_type'] ?? 'unknown';
	
	// Mark email as problematic in local database
	// markEmailBounced($email, $bounceType);
	
	// For hard bounces, might want to take additional action
	// if ($bounceType === 'hard') {
	//    flagForCleanup($email);
	// }
}

/**
 * Example function to log webhook data to a database
 */
function logToDatabase($eventType, $timestamp, $data) {
	// This is a placeholder - implement based on your database
	// E.g., using PDO:
	/*
	$db = new PDO('mysql:host=localhost;dbname=your_db', 'username', 'password');
	$stmt = $db->prepare("INSERT INTO klaviyo_webhooks (event_type, timestamp, data) VALUES (?, ?, ?)");
	$stmt->execute([$eventType, $timestamp, $data]);
	*/
}

/**
 * Utility function to extract email from profile ID
 */
function extractEmailFromProfile($profileId) {
	global $klaviyo;
	
	// Example implementation - would need to be adapted based on your needs
	if (!$profileId) {
		return null;
	}
	
	// You might want to cache this lookup to avoid API calls
	// This is a placeholder for the actual implementation
	/*
	$profile = $klaviyo->getProfileById($profileId);
	return $profile['attributes']['email'] ?? null;
	*/
	
	return null; // Placeholder
}

// Get the raw POST data
$rawData = file_get_contents('php://input');

// Process the webhook
$klaviyo->processWebhook($rawData, 'handleWebhook');

// Send a 200 OK response
http_response_code(200);
echo json_encode(['status' => 'success']);