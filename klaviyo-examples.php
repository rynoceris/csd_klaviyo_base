<?php
require_once('KlaviyoHelper.php');

// Initialize with API key and options
$klaviyo = new KlaviyoHelper('pk_8db019cd000955ae7292ad684ceae904d8', [
	'debug' => true,
	'logFile' => __DIR__ . '/klaviyo_debug.log',
	'cacheEnabled' => true,
	'cacheTTL' => 1800, // 30 minutes
	'rateLimit' => 15   // 15 requests per second
]);

// Example 1: Track individual event
$klaviyo->trackEvent(
	'customer@example.com', 
	'Viewed Product', 
	[
		'ProductName' => 'Blue T-Shirt',
		'ProductID' => '123456',
		'Price' => 29.99,
		'Category' => 'Apparel'
	]
);

// Example 2: Batch track multiple events
$events = [
	[
		'email' => 'customer1@example.com',
		'eventName' => 'Added to Cart',
		'properties' => [
			'ProductName' => 'Red Shoes',
			'Price' => 49.99
		]
	],
	[
		'email' => 'customer2@example.com',
		'eventName' => 'Started Checkout',
		'properties' => [
			'CartTotal' => 135.45,
			'ItemCount' => 3
		]
	]
];
$results = $klaviyo->batchTrackEvents($events);

// Example 3: Advanced profile filtering
$filters = [
	[
		'field' => 'location.city',
		'operator' => 'equals',
		'value' => 'New York'
	],
	[
		'field' => 'properties.last_purchase_date',
		'operator' => 'greater-than',
		'value' => '2025-01-01'
	]
];
$newYorkRecentBuyers = $klaviyo->getProfilesWithFilters($filters);

// Example 4: Batch update profiles
$profiles = [
	[
		'email' => 'customer1@example.com',
		'first_name' => 'John',
		'last_name' => 'Doe',
		'properties' => [
			'Loyalty_Points' => 150
		]
	],
	[
		'email' => 'customer2@example.com',
		'first_name' => 'Jane',
		'last_name' => 'Smith',
		'properties' => [
			'Loyalty_Points' => 230
		]
	]
];
$klaviyo->batchUpsertProfiles($profiles);

// Example 5: Get lists with caching
$lists = $klaviyo->getLists();
// To force refresh the cache:
// $lists = $klaviyo->getLists(true);

// Example 6: Batch subscribe to a list
$emailsToSubscribe = [
	'customer1@example.com',
	'customer2@example.com',
	'customer3@example.com'
];
$klaviyo->batchSubscribeToList($emailsToSubscribe, 'YOUR_LIST_ID');

// Example 7: Process a webhook
// This would typically be in a separate webhook endpoint file
$webhookHandler = function($data) {
	// Process different types of webhooks
	switch ($data['type']) {
		case 'email_delivered':
			// Handle email delivery event
			$emailId = $data['attributes']['email_id'] ?? null;
			$profileId = $data['attributes']['profile_id'] ?? null;
			// Store in database, trigger follow-up action, etc.
			break;
			
		case 'unsubscribed':
			// Handle unsubscribe event
			$profileId = $data['attributes']['profile_id'] ?? null;
			// Update local database, etc.
			break;
			
		default:
			// Handle other events
			break;
	}
	
	return true; // Success
};

// Example 9: Clear specific cache
$klaviyo->clearCache('lists');

// Example 10: Clear all cache
$klaviyo->clearAllCache();

// Example 11: Using advanced filtering with OR operator
$filters = [
	[
		'field' => 'email',
		'operator' => 'contains',
		'value' => 'gmail.com'
	],
	[
		'field' => 'email',
		'operator' => 'contains',
		'value' => 'yahoo.com'
	]
];
$gmailOrYahooUsers = $klaviyo->getProfilesWithFilters($filters, 'or');

// Example 12: Monitor API rate limits
function trackMultipleUserActions($userEmails, $action) {
	global $klaviyo;
	
	foreach ($userEmails as $email) {
		// The helper automatically handles rate limiting between requests
		$klaviyo->trackEvent($email, $action, ['timestamp' => date('c')]);
	}
}

// Call with many users - rate limiting will be applied automatically
$users = ['user1@example.com', 'user2@example.com', /* many more users */];
trackMultipleUserActions($users, 'Login');iyo = new KlaviyoHelper('pk_8db019cd000955ae7292ad684ceae904d8', [
	'debug' => true,
	'logFile' => __DIR__ . '/klaviyo_debug.log',
	'cacheEnabled' => true,
	'cacheTTL' => 1800, // 30 minutes
	'rateLimit' => 15   // 15 requests per second
]);

// When receiving a webhook POST request:
$rawPayload = file_get_contents('php://input');
$klaviyo->processWebhook($rawPayload, $webhookHandler);

// Example 8: Register a webhook endpoint
$webhookUrl = 'https://example.com/webhooks/klaviyo';
$events = ['email_delivered', 'email_opened', 'unsubscribed'];
$klav