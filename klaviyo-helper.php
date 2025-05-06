<?php
require_once(__DIR__ . '/vendor/autoload.php');

use KlaviyoAPI\KlaviyoAPI;

class KlaviyoHelper {
	private $klaviyo;
	private $debugMode;
	private $logFile;
	private $cacheDir;
	private $cacheEnabled;
	private $cacheTTL;
	private $lastRequestTime = 0;
	private $rateLimit = [
		'requests_per_second' => 10, // Default rate limit (adjust as needed)
		'min_interval_ms' => 100     // Minimum interval between requests in milliseconds
	];
	
	/**
	 * Initialize the Klaviyo Helper
	 * 
	 * @param string $apiKey Your Klaviyo API key
	 * @param array $options Configuration options
	 *        - debug: Enable debug mode (default: false)
	 *        - logFile: Path to log file (default: klaviyo.log)
	 *        - numRetries: Number of retries for API calls (default: 3)
	 *        - userAgentSuffix: Suffix for user agent (default: 'KlaviyoHelper')
	 *        - cacheEnabled: Enable caching (default: true)
	 *        - cacheDir: Directory for cache files (default: __DIR__ . '/cache')
	 *        - cacheTTL: Cache time-to-live in seconds (default: 3600 - 1 hour)
	 *        - rateLimit: Requests per second (default: 10)
	 */
	public function __construct($apiKey, $options = []) {
		// Set default options
		$defaults = [
			'debug' => false,
			'logFile' => __DIR__ . '/klaviyo.log',
			'numRetries' => 3,
			'userAgentSuffix' => 'KlaviyoHelper',
			'cacheEnabled' => true,
			'cacheDir' => __DIR__ . '/cache',
			'cacheTTL' => 3600,
			'rateLimit' => 10
		];
		
		// Merge with provided options
		$config = array_merge($defaults, $options);
		
		// Initialize properties
		$this->debugMode = $config['debug'];
		$this->logFile = $config['logFile'];
		$this->cacheEnabled = $config['cacheEnabled'];
		$this->cacheDir = $config['cacheDir'];
		$this->cacheTTL = $config['cacheTTL'];
		$this->rateLimit['requests_per_second'] = $config['rateLimit'];
		$this->rateLimit['min_interval_ms'] = ceil(1000 / $config['rateLimit']);
		
		// Create cache directory if it doesn't exist
		if ($this->cacheEnabled && !file_exists($this->cacheDir)) {
			mkdir($this->cacheDir, 0755, true);
		}
		
		// Initialize Klaviyo API client
		try {
			$this->klaviyo = new KlaviyoAPI(
				$apiKey,
				num_retries: $config['numRetries'],
				user_agent_suffix: $config['userAgentSuffix']
			);
			$this->log("Klaviyo API initialized successfully");
		} catch (Exception $e) {
			$this->log("Failed to initialize Klaviyo API: " . $e->getMessage(), 'ERROR');
			throw new Exception("Failed to initialize Klaviyo API: " . $e->getMessage());
		}
	}
	
	/**
	 * Apply rate limiting before making API requests
	 */
	private function applyRateLimit() {
		$currentTime = microtime(true) * 1000; // Current time in milliseconds
		$timeSinceLastRequest = $currentTime - $this->lastRequestTime;
		
		// If we made a request less than min_interval_ms ago, sleep to respect rate limit
		if ($this->lastRequestTime > 0 && $timeSinceLastRequest < $this->rateLimit['min_interval_ms']) {
			$sleepTime = ($this->rateLimit['min_interval_ms'] - $timeSinceLastRequest) / 1000;
			$this->log("Rate limiting: sleeping for {$sleepTime} seconds");
			usleep($sleepTime * 1000000); // Convert to microseconds
		}
		
		// Update last request time
		$this->lastRequestTime = microtime(true) * 1000;
	}
	
	/**
	 * Track an event in Klaviyo
	 * 
	 * @param string $email Email of the profile
	 * @param string $eventName Name of the event to track
	 * @param array $properties Properties of the event
	 * @param string|null $timestamp ISO 8601 timestamp (default: current time)
	 * @return array|false Response from Klaviyo or false on failure
	 */
	public function trackEvent($email, $eventName, $properties = [], $timestamp = null) {
		$this->log("Tracking event: $eventName for $email");
		
		// Build profile identifier (can be email, phone, or external_id)
		$profile = ['email' => $email];
		
		// Set timestamp
		$time = $timestamp ?: date('c');
		
		// Build event data
		$eventData = [
			'data' => [
				'type' => 'event',
				'attributes' => [
					'profile' => $profile,
					'metric' => [
						'name' => $eventName
					],
					'properties' => $properties,
					'time' => $time
				]
			]
		];
		
		// Apply rate limiting
		$this->applyRateLimit();
		
		// Send to Klaviyo
		try {
			$response = $this->klaviyo->Events->createEvent($eventData);
			$this->log("Event tracked successfully: $eventName");
			return $response;
		} catch (Exception $e) {
			$this->log("Failed to track event: " . $e->getMessage(), 'ERROR');
			return false;
		}
	}
	
	/**
	 * Track multiple events in a batch
	 * 
	 * @param array $events Array of event data
	 * @return array Results for each event (success/failure)
	 */
	public function batchTrackEvents($events) {
		$this->log("Batch tracking " . count($events) . " events");
		$results = [];
		
		// Process each event
		foreach ($events as $index => $event) {
			// Validate event data
			if (empty($event['email']) || empty($event['eventName'])) {
				$this->log("Event #$index missing required data (email or eventName)", 'ERROR');
				$results[$index] = [
					'success' => false,
					'error' => 'Missing required data (email or eventName)'
				];
				continue;
			}
			
			// Properties and timestamp are optional
			$properties = $event['properties'] ?? [];
			$timestamp = $event['timestamp'] ?? null;
			
			// Track the event
			$response = $this->trackEvent($event['email'], $event['eventName'], $properties, $timestamp);
			
			// Store the result
			$results[$index] = [
				'success' => ($response !== false),
				'response' => $response
			];
		}
		
		$this->log("Batch tracking completed: " . count(array_filter($results, function($r) { return $r['success']; })) . " succeeded, " . 
				   count(array_filter($results, function($r) { return !$r['success']; })) . " failed");
		
		return $results;
	}
	
	/**
	 * Create or update a profile in Klaviyo
	 * 
	 * @param array $profileData Profile data with at least one identifier (email, phone_number, external_id)
	 * @return array|false Response from Klaviyo or false on failure
	 */
	public function upsertProfile($profileData) {
		// Ensure we have profile data in the right format
		if (empty($profileData['email']) && empty($profileData['phone_number']) && empty($profileData['external_id'])) {
			$this->log("Profile must have at least one identifier (email, phone_number, or external_id)", 'ERROR');
			return false;
		}
		
		// Identifier for logging
		$identifier = $profileData['email'] ?? $profileData['phone_number'] ?? $profileData['external_id'];
		$this->log("Upserting profile for: $identifier");
		
		// Build request data
		$data = [
			'data' => [
				'type' => 'profile',
				'attributes' => $profileData
			]
		];
		
		// Apply rate limiting
		$this->applyRateLimit();
		
		// Send to Klaviyo
		try {
			$response = $this->klaviyo->Profiles->createProfile($data);
			$this->log("Profile upserted successfully: $identifier");
			return $response;
		} catch (Exception $e) {
			$this->log("Failed to upsert profile: " . $e->getMessage(), 'ERROR');
			return false;
		}
	}
	
	/**
	 * Batch upsert multiple profiles
	 * 
	 * @param array $profiles Array of profile data
	 * @return array Results for each profile (success/failure)
	 */
	public function batchUpsertProfiles($profiles) {
		$this->log("Batch upserting " . count($profiles) . " profiles");
		$results = [];
		
		// Process profiles in batches
		foreach ($profiles as $index => $profileData) {
			// Validate profile data
			if (empty($profileData['email']) && empty($profileData['phone_number']) && empty($profileData['external_id'])) {
				$this->log("Profile #$index missing required identifier", 'ERROR');
				$results[$index] = [
					'success' => false,
					'error' => 'Missing required identifier (email, phone_number, or external_id)'
				];
				continue;
			}
			
			// Upsert the profile
			$response = $this->upsertProfile($profileData);
			
			// Store the result
			$results[$index] = [
				'success' => ($response !== false),
				'response' => $response
			];
		}
		
		$this->log("Batch upsert completed: " . count(array_filter($results, function($r) { return $r['success']; })) . " succeeded, " . 
				  count(array_filter($results, function($r) { return !$r['success']; })) . " failed");
		
		return $results;
	}
	
	/**
	 * Get a profile by identifier with advanced filtering
	 * 
	 * @param array $filters Array of filter conditions
	 *        e.g. [
	 *          ['field' => 'email', 'operator' => 'equals', 'value' => 'test@example.com'],
	 *          ['field' => 'first_name', 'operator' => 'contains', 'value' => 'John']
	 *        ]
	 * @param string $operator Logical operator between filters ('and'|'or')
	 * @return array|null Profile data or null if not found
	 */
	public function getProfilesWithFilters($filters, $operator = 'and') {
		if (empty($filters)) {
			$this->log("No filters provided for profile query", 'ERROR');
			return null;
		}
		
		$filterStrings = [];
		
		// Build filter strings
		foreach ($filters as $filter) {
			if (empty($filter['field']) || empty($filter['operator']) || !isset($filter['value'])) {
				$this->log("Invalid filter: " . json_encode($filter), 'ERROR');
				continue;
			}
			
			// Escape quotes in values
			$value = is_string($filter['value']) ? 
					 '"' . str_replace('"', '\"', $filter['value']) . '"' : 
					 $filter['value'];
			
			$filterStrings[] = "{$filter['operator']}({$filter['field']},$value)";
		}
		
		if (empty($filterStrings)) {
			$this->log("No valid filters to apply", 'ERROR');
			return null;
		}
		
		// Combine filters with the specified operator
		$combinedFilter = implode(" $operator ", $filterStrings);
		$this->log("Querying profiles with filter: $combinedFilter");
		
		// Build params
		$params = [
			'filter' => $combinedFilter
		];
		
		// Check cache first
		$cacheKey = 'profiles_' . md5($combinedFilter);
		$cachedData = $this->getFromCache($cacheKey);
		
		if ($cachedData !== null) {
			$this->log("Retrieved profiles from cache for filter: $combinedFilter");
			return $cachedData;
		}
		
		// Apply rate limiting
		$this->applyRateLimit();
		
		// Query Klaviyo
		try {
			$response = $this->klaviyo->Profiles->getProfiles($params);
			
			// Cache the results
			if (!empty($response['data'])) {
				$this->saveToCache($cacheKey, $response['data']);
				$this->log("Found " . count($response['data']) . " profiles matching filter");
			} else {
				$this->log("No profiles found matching filter");
			}
			
			return $response['data'] ?? [];
		} catch (Exception $e) {
			$this->log("Failed to query profiles: " . $e->getMessage(), 'ERROR');
			return null;
		}
	}
	
	/**
	 * Get a profile by a single identifier (convenience method)
	 * 
	 * @param string $type Type of identifier (email, phone_number, external_id)
	 * @param string $value Value of the identifier
	 * @return array|null Profile data or null if not found
	 */
	public function getProfile($type, $value) {
		$filters = [
			[
				'field' => $type,
				'operator' => 'equals',
				'value' => $value
			]
		];
		
		$profiles = $this->getProfilesWithFilters($filters);
		
		// Return the first profile if found
		return !empty($profiles) ? $profiles[0] : null;
	}
	
	/**
	 * Subscribe a profile to a list
	 * 
	 * @param string $email Email of the profile
	 * @param string $listId ID of the list to subscribe to
	 * @return array|false Response from Klaviyo or false on failure
	 */
	public function subscribeToList($email, $listId) {
		$this->log("Subscribing $email to list: $listId");
		
		// Build request data
		$data = [
			'data' => [
				'type' => 'subscription',
				'attributes' => [
					'custom_source' => 'Website Sign Up',
					'profiles' => [
						['email' => $email]
					]
				],
				'relationships' => [
					'list' => [
						'data' => [
							'type' => 'list',
							'id' => $listId
						]
					]
				]
			]
		];
		
		// Apply rate limiting
		$this->applyRateLimit();
		
		// Subscribe via Klaviyo
		try {
			$response = $this->klaviyo->Lists->createSubscription($data);
			$this->log("Subscription successful for $email to list $listId");
			return $response;
		} catch (Exception $e) {
			$this->log("Failed to subscribe to list: " . $e->getMessage(), 'ERROR');
			return false;
		}
	}
	
	/**
	 * Batch subscribe profiles to a list
	 * 
	 * @param array $emails Array of email addresses
	 * @param string $listId ID of the list to subscribe to
	 * @return bool Success or failure
	 */
	public function batchSubscribeToList($emails, $listId) {
		if (empty($emails)) {
			$this->log("No emails provided for batch subscription", 'ERROR');
			return false;
		}
		
		$this->log("Batch subscribing " . count($emails) . " profiles to list: $listId");
		
		// Build profile objects
		$profiles = array_map(function($email) {
			return ['email' => $email];
		}, $emails);
		
		// Build request data
		$data = [
			'data' => [
				'type' => 'subscription',
				'attributes' => [
					'custom_source' => 'Batch Import',
					'profiles' => $profiles
				],
				'relationships' => [
					'list' => [
						'data' => [
							'type' => 'list',
							'id' => $listId
						]
					]
				]
			]
		];
		
		// Apply rate limiting
		$this->applyRateLimit();
		
		// Subscribe via Klaviyo
		try {
			$response = $this->klaviyo->Lists->createSubscription($data);
			$this->log("Batch subscription successful for " . count($emails) . " profiles to list $listId");
			return true;
		} catch (Exception $e) {
			$this->log("Failed to batch subscribe to list: " . $e->getMessage(), 'ERROR');
			return false;
		}
	}
	
	/**
	 * Get all available metrics
	 * 
	 * @param bool $refreshCache Force refresh of cached data
	 * @return array Array of metrics or empty array on failure
	 */
	public function getMetrics($refreshCache = false) {
		$this->log("Retrieving all metrics");
		
		// Check cache first
		$cacheKey = 'metrics';
		if (!$refreshCache) {
			$cachedData = $this->getFromCache($cacheKey);
			if ($cachedData !== null) {
				$this->log("Retrieved metrics from cache");
				return $cachedData;
			}
		}
		
		// Apply rate limiting
		$this->applyRateLimit();
		
		// Get from API if not in cache or forced refresh
		try {
			$response = $this->klaviyo->Metrics->getMetrics();
			$this->log("Retrieved " . count($response['data']) . " metrics");
			
			// Cache the results
			$this->saveToCache($cacheKey, $response['data']);
			
			return $response['data'];
		} catch (Exception $e) {
			$this->log("Failed to get metrics: " . $e->getMessage(), 'ERROR');
			return [];
		}
	}
	
	/**
	 * Get all available lists
	 * 
	 * @param bool $refreshCache Force refresh of cached data
	 * @return array Array of lists or empty array on failure
	 */
	public function getLists($refreshCache = false) {
		$this->log("Retrieving all lists");
		
		// Check cache first
		$cacheKey = 'lists';
		if (!$refreshCache) {
			$cachedData = $this->getFromCache($cacheKey);
			if ($cachedData !== null) {
				$this->log("Retrieved lists from cache");
				return $cachedData;
			}
		}
		
		// Apply rate limiting
		$this->applyRateLimit();
		
		// Get from API if not in cache or forced refresh
		try {
			$response = $this->klaviyo->Lists->getLists();
			$this->log("Retrieved " . count($response['data']) . " lists");
			
			// Cache the results
			$this->saveToCache($cacheKey, $response['data']);
			
			return $response['data'];
		} catch (Exception $e) {
			$this->log("Failed to get lists: " . $e->getMessage(), 'ERROR');
			return [];
		}
	}
	
	/**
	 * Process a webhook from Klaviyo
	 * 
	 * @param string $rawPayload Raw webhook payload
	 * @param callable $callback Function to call with the processed webhook data
	 * @param bool $verifySignature Whether to verify webhook signature (if supported)
	 * @param string $signatureKey Key for signature verification
	 * @return bool Success or failure
	 */
	public function processWebhook($rawPayload, $callback, $verifySignature = false, $signatureKey = '') {
		$this->log("Processing incoming webhook");
		
		// Decode the payload
		$payload = json_decode($rawPayload, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			$this->log("Failed to decode webhook payload: " . json_last_error_msg(), 'ERROR');
			return false;
		}
		
		// Verify signature if required
		if ($verifySignature && $signatureKey) {
			// Implementation depends on Klaviyo's signature method
			// This is a placeholder for the signature verification logic
			$this->log("Webhook signature verification is not implemented yet", 'WARNING');
		}
		
		// Process the webhook
		try {
			// Extract important data
			$webhookData = [
				'type' => $payload['data']['type'] ?? 'unknown',
				'id' => $payload['data']['id'] ?? null,
				'attributes' => $payload['data']['attributes'] ?? [],
				'raw' => $payload
			];
			
			// Call the provided callback function
			$result = call_user_func($callback, $webhookData);
			$this->log("Webhook processed successfully");
			return true;
		} catch (Exception $e) {
			$this->log("Failed to process webhook: " . $e->getMessage(), 'ERROR');
			return false;
		}
	}
	
	/**
	 * Register a webhook endpoint with Klaviyo
	 * 
	 * @param string $url Endpoint URL to receive webhooks
	 * @param array $events Array of event types to subscribe to
	 * @return array|false Response from Klaviyo or false on failure
	 */
	public function registerWebhook($url, $events = []) {
		$this->log("Registering webhook endpoint: $url");
		
		// Build webhook data
		$data = [
			'data' => [
				'type' => 'webhook',
				'attributes' => [
					'url' => $url,
					'events' => $events
				]
			]
		];
		
		// Apply rate limiting
		$this->applyRateLimit();
		
		// Register webhook via Klaviyo
		try {
			// Note: This is a placeholder - actual implementation depends on Klaviyo's webhook API
			// You may need to adjust this call based on Klaviyo's webhook registration API
			$response = $this->klaviyo->Webhooks->createWebhook($data);
			$this->log("Webhook registered successfully");
			return $response;
		} catch (Exception $e) {
			$this->log("Failed to register webhook: " . $e->getMessage(), 'ERROR');
			return false;
		}
	}
	
	/**
	 * Get data from cache
	 * 
	 * @param string $key Cache key
	 * @return mixed Cached data or null if not found/expired
	 */
	private function getFromCache($key) {
		if (!$this->cacheEnabled) {
			return null;
		}
		
		$cacheFile = $this->cacheDir . '/' . $key . '.cache';
		
		if (!file_exists($cacheFile)) {
			return null;
		}
		
		$cacheData = file_get_contents($cacheFile);
		$cache = json_decode($cacheData, true);
		
		// Check if cache is expired
		if (!isset($cache['expires']) || $cache['expires'] < time()) {
			// Cache expired, remove the file
			unlink($cacheFile);
			return null;
		}
		
		return $cache['data'];
	}
	
	/**
	 * Save data to cache
	 * 
	 * @param string $key Cache key
	 * @param mixed $data Data to cache
	 * @param int|null $ttl Time-to-live in seconds (null = use default)
	 * @return bool Success or failure
	 */
	private function saveToCache($key, $data, $ttl = null) {
		if (!$this->cacheEnabled) {
			return false;
		}
		
		$cacheFile = $this->cacheDir . '/' . $key . '.cache';
		$ttl = $ttl ?? $this->cacheTTL;
		
		$cache = [
			'expires' => time() + $ttl,
			'data' => $data
		];
		
		return file_put_contents($cacheFile, json_encode($cache)) !== false;
	}
	
	/**
	 * Clear specific cached data
	 * 
	 * @param string $key Cache key
	 * @return bool Success or failure
	 */
	public function clearCache($key) {
		if (!$this->cacheEnabled) {
			return false;
		}
		
		$cacheFile = $this->cacheDir . '/' . $key . '.cache';
		
		if (file_exists($cacheFile)) {
			return unlink($cacheFile);
		}
		
		return true;
	}
	
	/**
	 * Clear all cached data
	 * 
	 * @return bool Success or failure
	 */
	public function clearAllCache() {
		if (!$this->cacheEnabled || !is_dir($this->cacheDir)) {
			return false;
		}
		
		$files = glob($this->cacheDir . '/*.cache');
		
		if ($files === false) {
			return false;
		}
		
		$success = true;
		
		foreach ($files as $file) {
			if (!unlink($file)) {
				$success = false;
			}
		}
		
		return $success;
	}
	
	/**
	 * Log a message to file if debug mode is enabled
	 * 
	 * @param string $message Message to log
	 * @param string $level Log level (INFO, ERROR, WARNING)
	 */
	private function log($message, $level = 'INFO') {
		if ($this->debugMode) {
			$timestamp = date('Y-m-d H:i:s');
			$logMessage = "[$timestamp] [$level] $message" . PHP_EOL;
			
			// Append to log file
			file_put_contents($this->logFile, $logMessage, FILE_APPEND);
			
			// Echo if it's an error
			if ($level === 'ERROR') {
				echo $logMessage;
			}
		}
	}
}