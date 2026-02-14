#!/usr/bin/env php
<?php

/**
 * Spec Coverage Test Script
 * Tests that all services implement the required functionality from the spec
 */

$specRequirements = [
    'DeviceService' => [
        'required' => [
            'registerDevice' => 'Register device with token generation',
            'refreshToken' => 'Refresh API token',
            'updatePushToken' => 'Update push token',
            'getDevice' => 'Get device by ID',
            'getDeviceByToken' => 'Get device by API token',
            'markAsActive' => 'Mark device as active',
            'isOnline' => 'Check if device is online',
            'setOnline' => 'Set device online in Redis',
            'setOffline' => 'Set device offline in Redis',
            'validateToken' => 'Validate device credentials',
            'getPairedDevices' => 'Get all paired devices',
        ],
    ],
    'PairingService' => [
        'required' => [
            'generatePairingCode' => 'Generate alphanumeric code',
            'initiatePairing' => 'Create pairing request',
            'acceptPairing' => 'Accept and create group',
            'decodeQrData' => 'Decode QR payload',
            'cleanupExpiredRequests' => 'Remove expired requests',
            'getActiveRequests' => 'Get device active requests',
        ],
    ],
    'VectorClockService' => [
        'required' => [
            'initializeClock' => 'Create new vector clock',
            'incrementClock' => 'Increment device counter',
            'mergeClocks' => 'Merge clocks',
            'happenedBefore' => 'Check causality',
            'isConcurrent' => 'Check concurrent states',
            'areEqual' => 'Compare clocks',
            'detectLoop' => 'Detect sync loops',
            'getLatestClocksForGroup' => 'Get group clocks',
            'compareClocks' => 'Determine relationship',
            'clockDistance' => 'Calculate difference',
        ],
    ],
    'NotificationService' => [
        'required' => [
            'notifyDevice' => 'Send notification to device',
            'notifySyncRequired' => 'Notify state change',
            'notifyDeviceJoined' => 'Notify new member',
            'notifyDeviceLeft' => 'Notify member leaving',
            'notifySignalingOffer' => 'Send WebRTC offer',
            'notifySignalingAnswer' => 'Send WebRTC answer',
            'notifySyncAcknowledged' => 'Notify acknowledgment',
        ],
        'supported_events' => [
            'sync_required',
            'device_joined',
            'device_left',
            'signaling_offer',
            'signaling_answer',
            'sync_acknowledged',
            'pairing_accepted',
        ],
    ],
    'CacheService' => [
        'required' => [
            'storePayload' => 'Store encrypted payload',
            'retrievePayloads' => 'Get device payloads',
            'retrievePayload' => 'Get specific payload',
            'deletePayload' => 'Remove payload',
            'clearDeviceCache' => 'Remove all device payloads',
            'clearGroupCache' => 'Remove all group payloads',
            'cleanupExpired' => 'Remove expired entries',
            'getDeviceCacheSize' => 'Get device total size',
            'getGroupCacheSize' => 'Get group total size',
            'isDeviceQuotaExceeded' => 'Check device quota',
            'isGroupQuotaExceeded' => 'Check group quota',
            'getDeviceCacheStats' => 'Get device statistics',
        ],
        'constants' => [
            'MAX_PAYLOAD_SIZE' => 10485760, // 10MB
            'DEFAULT_TTL' => 86400, // 24 hours
            'MAX_TTL' => 604800, // 7 days
        ],
    ],
    'SyncCoordinatorService' => [
        'required' => [
            'notifyStateChange' => 'Handle state change',
            'acknowledgeSync' => 'Process acknowledgment',
            'getSyncStatus' => 'Get sync status',
            'getDeviceGroups' => 'List device groups',
            'getLatestSyncState' => 'Get last sync state',
            'getUnacknowledgedSyncs' => 'Get pending syncs',
        ],
        'topologies' => ['pair', 'chain', 'group'],
    ],
];

$servicesDir = __DIR__ . '/app/Services';
$results = [];
$totalRequired = 0;
$totalFound = 0;

echo "========================================\n";
echo "SyncOrc Spec Coverage Test\n";
echo "========================================\n\n";

foreach ($specRequirements as $serviceName => $specs) {
    echo "Testing $serviceName...\n";
    echo str_repeat('-', 50) . "\n";

    $filePath = $servicesDir . '/' . $serviceName . '.php';

    if (!file_exists($filePath)) {
        echo "  ✗ File not found: $filePath\n\n";
        continue;
    }

    $content = file_get_contents($filePath);
    $results[$serviceName] = ['found' => [], 'missing' => [], 'file' => true];

    // Test required methods
    if (isset($specs['required'])) {
        foreach ($specs['required'] as $method => $description) {
            $totalRequired++;
            $pattern = '/\bpublic\s+function\s+' . preg_quote($method, '/') . '\s*\(/';
            if (preg_match($pattern, $content)) {
                $results[$serviceName]['found'][] = $method;
                $totalFound++;
                echo "  ✓ $method - $description\n";
            } else {
                $results[$serviceName]['missing'][] = $method;
                echo "  ✗ $method - $description\n";
            }
        }
    }

    // Test supported events for NotificationService
    if (isset($specs['supported_events'])) {
        echo "\n  Checking supported events:\n";
        foreach ($specs['supported_events'] as $event) {
            $pattern = "/['\"]" . preg_quote($event, '/') . "['\"]/";
            if (preg_match($pattern, $content)) {
                echo "    ✓ $event\n";
            } else {
                echo "    ✗ $event\n";
            }
        }
    }

    // Test constants for CacheService
    if (isset($specs['constants'])) {
        echo "\n  Checking constants:\n";
        foreach ($specs['constants'] as $const => $value) {
            $pattern = '/const\s+' . preg_quote($const, '/') . '\s*=\s*' . preg_quote($value, '/') . '/';
            if (preg_match($pattern, $content)) {
                echo "    ✓ $const = $value\n";
            } else {
                echo "    ✗ $const = $value\n";
            }
        }
    }

    // Test topologies for SyncCoordinatorService
    if (isset($specs['topologies'])) {
        echo "\n  Checking topology support:\n";
        foreach ($specs['topologies'] as $topology) {
            $pattern = "/['\"]" . preg_quote($topology, '/') . "['\"]/";
            if (preg_match($pattern, $content)) {
                echo "    ✓ $topology\n";
            } else {
                echo "    ✗ $topology\n";
            }
        }
    }

    echo "\n";
}

// Summary
echo "========================================\n";
echo "SUMMARY\n";
echo "========================================\n";
echo "Total Required Methods: $totalRequired\n";
echo "Total Found: $totalFound\n";
echo "Coverage: " . round(($totalFound / $totalRequired) * 100, 2) . "%\n\n";

$missingMethods = [];
foreach ($results as $service => $data) {
    if (!empty($data['missing'])) {
        $missingMethods[$service] = $data['missing'];
    }
}

if (empty($missingMethods)) {
    echo "✓ ALL REQUIRED METHODS FOUND\n";
    echo "✓ SPEC COVERAGE: 100%\n";
    exit(0);
} else {
    echo "✗ MISSING METHODS:\n";
    foreach ($missingMethods as $service => $methods) {
        echo "  $service:\n";
        foreach ($methods as $method) {
            echo "    - $method\n";
        }
    }
    exit(1);
}
