<?php
require_once(__DIR__ . '/vendor/autoload.php');
use KlaviyoAPI\KlaviyoAPI;

// Initialize the Klaviyo client
$klaviyo = new KlaviyoAPI(
	'',  // Replace with your actual API key
	num_retries: 3
);

// Function to format output with syntax highlighting
function prettyPrintJson($data) {
	// Convert to JSON with options for pretty printing
	$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	
	// Apply syntax highlighting with highlight_string
	$json = highlight_string("<?php\n" . var_export(json_decode($json), true) . ";\n?>", true);
	// Remove the php tags and styling
	$json = preg_replace('/<\?php<br \/>|<br \/>;\?>/is', '', $json);
	
	return $json;
}

// Test API connection by retrieving metrics
try {
	$response = $klaviyo->Metrics->getMetrics();
	echo "<div style='background-color: #f8f8f8; padding: 15px; border-radius: 5px;'>";
	echo "<h3 style='color: green;'>Connection successful!</h3>";
	echo prettyPrintJson($response);
	echo "</div>";
} catch (Exception $e) {
	echo "<div style='background-color: #fff0f0; padding: 15px; border-radius: 5px;'>";
	echo "<h3 style='color: red;'>Error</h3>";
	echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
	echo "</div>";
}
