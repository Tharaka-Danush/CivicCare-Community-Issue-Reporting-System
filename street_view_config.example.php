<?php
// Copy to street_view_config.php in the project root and enter YOUR browser API key.
// Enable Maps JavaScript API and billing in your own Google Cloud project.
// Restrict the key to your website origins and Maps JavaScript API.
return [
 'enabled' => true,
 'api_key' => '',
 'auto_zoom' => 19, // Auto: Map -> Satellite at 16 -> Street View at 19, where covered.
 'search_radius_metres' => 50, // Nearby road imagery; the report pin never moves automatically.
 'delay_ms' => 800,
];
