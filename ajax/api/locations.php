<?php
require_once __DIR__ . '/_mobile.php';

apiHandlePreflight();
apiRequireMethod(['GET', 'POST']);

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $user = apiFetchAuthenticatedUser($pdo, $_GET);
        $supported = apiLocationFeaturesSupported($pdo);

        if (!$supported) {
            apiRespond(200, [
                'success' => true,
                'supported' => false,
                'user_location' => null,
                'events' => [],
                'nearby_events' => [],
            ]);
        }

        $userLocation = apiFetchUserLocation($pdo, (int) $user['id']);
        $events = apiFetchLocationEvents($pdo, $user, 100);
        $nearbyEvents = $userLocation ? apiFetchNearbyLocationEvents($pdo, $user, $userLocation, 50) : [];

        apiRespond(200, [
            'success' => true,
            'supported' => true,
            'user_location' => $userLocation,
            'events' => $events,
            'nearby_events' => $nearbyEvents,
        ]);
    }

    $data = apiRequestData();
    $user = apiFetchAuthenticatedUser($pdo, $data);
    $action = trim((string) ($data['action'] ?? ''));

    if ($action !== 'save_location') {
        apiRespond(400, ['success' => false, 'error' => 'Unsupported location action']);
    }

    if (!apiLocationFeaturesSupported($pdo)) {
        apiRespond(400, ['success' => false, 'error' => 'Location features are not available on this deployment yet']);
    }

    if (!isset($data['latitude'], $data['longitude']) || $data['latitude'] === '' || $data['longitude'] === '') {
        apiRespond(400, ['success' => false, 'error' => 'Latitude and longitude are required']);
    }

    $latitude = (float) $data['latitude'];
    $longitude = (float) $data['longitude'];
    $locationName = apiNullableString($data['location_name'] ?? null) ?: 'Campus location';
    $locationAddress = apiNullableString($data['location_address'] ?? null);

    apiSaveUserLocation($pdo, (int) $user['id'], $latitude, $longitude, $locationName, $locationAddress);
    logActivity($pdo, (int) $user['id'], 'mobile_location_saved', 'Updated campus location');

    apiRespond(200, [
        'success' => true,
        'message' => 'Location saved successfully',
        'user_location' => apiFetchUserLocation($pdo, (int) $user['id']),
    ]);
} catch (PDOException $e) {
    apiRespond(500, ['success' => false, 'error' => 'Unable to load location tools right now']);
}
?>
