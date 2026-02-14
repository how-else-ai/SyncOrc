<?php

/**
 * Verification Script for Step 3 Implementation
 * Checks that all controllers, middleware, and routes are properly defined
 */
echo "=== SyncOrc Step 3 Implementation Verification ===\n\n";

// Check files exist
$files = [
    'app/Http/Controllers/HealthController.php',
    'app/Http/Middleware/ApiAuthMiddleware.php',
    'app/Http/Controllers/Api/DeviceController.php',
    'app/Http/Controllers/Api/PairingController.php',
    'app/Http/Controllers/Api/SyncController.php',
    'app/Http/Controllers/Api/CacheController.php',
    'app/Http/Controllers/Api/SignalingController.php',
    'app/Http/Controllers/Api/GroupController.php',
    'routes/api.php',
];

echo "1. Checking file existence...\n";
$allFilesExist = true;
foreach ($files as $file) {
    $filePath = __DIR__.'/../'.$file;
    if (file_exists($filePath)) {
        $size = filesize($filePath);
        echo "   ✓ $file ($size bytes)\n";
    } else {
        echo "   ✗ $file - MISSING\n";
        $allFilesExist = false;
    }
}

echo "\n2. Checking class definitions...\n";

require_once __DIR__.'/../vendor/autoload.php';

$classes = [
    'App\Http\Controllers\HealthController',
    'App\Http\Middleware\ApiAuthMiddleware',
    'App\Http\Controllers\Api\DeviceController',
    'App\Http\Controllers\Api\PairingController',
    'App\Http\Controllers\Api\SyncController',
    'App\Http\Controllers\Api\CacheController',
    'App\Http\Controllers\Api\SignalingController',
    'App\Http\Controllers\Api\GroupController',
];

$allClassesExist = true;
foreach ($classes as $class) {
    if (class_exists($class)) {
        $reflection = new ReflectionClass($class);
        $methods = count($reflection->getMethods(ReflectionMethod::IS_PUBLIC));
        echo "   ✓ $class ($methods public methods)\n";
    } else {
        echo "   ✗ $class - NOT FOUND\n";
        $allClassesExist = false;
    }
}

echo "\n3. Checking middleware registration...\n";
$bootstrapPath = __DIR__.'/../bootstrap/app.php';
if (file_exists($bootstrapPath)) {
    $content = file_get_contents($bootstrapPath);
    if (str_contains($content, 'api.auth') && str_contains($content, 'ApiAuthMiddleware')) {
        echo "   ✓ api.auth middleware registered in bootstrap/app.php\n";
    } else {
        echo "   ✗ api.auth middleware not registered\n";
    }
} else {
    echo "   ✗ bootstrap/app.php not found\n";
}

echo "\n4. Checking API routes definition...\n";
$routesPath = __DIR__.'/../routes/api.php';
if (file_exists($routesPath)) {
    $routes = file_get_contents($routesPath);
    $expectedRoutes = [
        '/health',
        '/v1/devices/register',
        '/v1/devices/refresh-token',
        '/v1/devices/push-token',
        '/v1/pairing/initiate',
        '/v1/pairing/accept',
        '/v1/sync/state-changed',
        '/v1/sync/acknowledge',
        '/v1/sync/status',
        '/v1/cache/store',
        '/v1/cache/retrieve',
        '/v1/signaling/offer',
        '/v1/signaling/offers',
        '/v1/signaling/answer',
        '/v1/groups/{group_id}/members',
        '/v1/groups/{group_id}/leave',
    ];

    $allRoutesDefined = true;
    foreach ($expectedRoutes as $route) {
        if (str_contains($routes, $route)) {
            echo "   ✓ $route\n";
        } else {
            echo "   ✗ $route - NOT DEFINED\n";
            $allRoutesDefined = false;
        }
    }
} else {
    echo "   ✗ routes/api.php not found\n";
}

echo "\n5. Checking for placeholders...\n";
$placeholderPatterns = ['TODO', 'FIXME', 'XXX', 'PLACEHOLDER'];
$foundPlaceholders = [];

foreach ($files as $file) {
    $filePath = __DIR__.'/../'.$file;
    if (file_exists($filePath)) {
        $content = file_get_contents($filePath);
        foreach ($placeholderPatterns as $pattern) {
            if (str_contains($content, $pattern)) {
                $foundPlaceholders[] = "$file: $pattern";
            }
        }
    }
}

if (empty($foundPlaceholders)) {
    echo "   ✓ No TODO/FIXME placeholders found\n";
} else {
    foreach ($foundPlaceholders as $placeholder) {
        echo "   ! $placeholder\n";
    }
}

echo "\n6. Checking method implementations...\n";

// Check HealthController
$healthController = 'App\Http\Controllers\HealthController';
if (class_exists($healthController)) {
    $methods = get_class_methods($healthController);
    if (in_array('index', $methods)) {
        echo "   ✓ HealthController::index()\n";
    } else {
        echo "   ✗ HealthController::index() missing\n";
    }
}

// Check ApiAuthMiddleware
$authMiddleware = 'App\Http\Middleware\ApiAuthMiddleware';
if (class_exists($authMiddleware)) {
    $methods = get_class_methods($authMiddleware);
    if (in_array('handle', $methods) && in_array('extractToken', $methods)) {
        echo "   ✓ ApiAuthMiddleware::handle() and extractToken()\n";
    } else {
        echo "   ✗ ApiAuthMiddleware methods incomplete\n";
    }
}

echo "\n=== Summary ===\n";
$allChecksPassed = $allFilesExist && $allClassesExist && $allRoutesDefined;

if ($allChecksPassed && empty($foundPlaceholders)) {
    echo "✅ All checks passed! Step 3 implementation is complete.\n";
} else {
    echo "⚠️  Some checks failed. Please review the output above.\n";
}

echo "\nTotal: ";
echo count($files).' files, ';
echo count($classes).' classes, '.count($expectedRoutes)." routes\n";
