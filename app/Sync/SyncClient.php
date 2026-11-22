<?php

declare(strict_types=1);

namespace App\Sync;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * SyncClient is an example device-side client for the SyncOrc Synchronization
 * Orchestration Server.
 *
 * Design principle: the server orchestrates, never payloads. Devices announce
 * change metadata (content hashes, not content) and fetch actual payloads
 * peer-to-peer (payload_location) so the orchestration server shares minimal
 * information.
 */
class SyncClient
{
    public function __construct(
        private string $baseUrl,
        private string $syncToken,
        private ?Client $http = null,
    ) {
        $this->http = $http ?? new Client(['base_uri' => rtrim($baseUrl, '/') . '/']);
    }

    public function register(?string $name = null, array $capabilities = []): array
    {
        $response = $this->http->post('api/devices', [
            'json' => array_filter([
                'name' => $name,
                'capabilities' => $capabilities,
            ]),
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Announce local changes to the orchestration server.
     *
     * Each change carries only metadata: entity type/id, change type, a
     * SHA-256 content hash, a version, and an optional payload_location where
     * peers can fetch the encrypted payload.
     */
    public function announce(array $changes): array
    {
        $response = $this->http->post('api/sync/announce', [
            'headers' => ['Authorization' => 'Bearer ' . $this->syncToken],
            'json' => ['changes' => $changes],
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Poll for changes announced by other devices since the last sync cursor.
     */
    public function poll(int $limit = 50): array
    {
        $response = $this->http->get('api/sync/changes', [
            'headers' => ['Authorization' => 'Bearer ' . $this->syncToken],
            'query' => ['limit' => $limit],
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    public function acknowledge(string $lastChangeId): array
    {
        $response = $this->http->post('api/sync/ack', [
            'headers' => ['Authorization' => 'Bearer ' . $this->syncToken],
            'json' => ['last_change_id' => $lastChangeId],
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Fetch an encrypted payload from a peer's payload_location.
     * The orchestration server is not involved in the payload transfer.
     */
    public function fetchPayload(string $url): string
    {
        $response = $this->http->get($url);
        return $response->getBody()->getContents();
    }

    /**
     * Helper: build a change entry for a local edit.
     */
    public static function makeChange(
        string $entityType,
        string $entityId,
        string $changeType,
        string $contentHash,
        int $version,
        ?string $payloadLocation = null,
        ?array $vectorClock = null
    ): array {
        $change = [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'change_type' => $changeType,
            'content_hash' => $contentHash,
            'version' => $version,
        ];
        if ($vectorClock !== null) {
            $change['vector_clock'] = $vectorClock;
        }
        if ($payloadLocation !== null) {
            $change['payload_location'] = $payloadLocation;
        }
        return $change;
    }

    /**
     * Full sync round-trip for a device: announce local changes, poll remote
     * changes, acknowledge up to the last polled change.
     */
    public function sync(array $localChanges, int $limit = 50): array
    {
        $announced = $this->announce($localChanges);
        $polled = $this->poll($limit);
        $changes = $polled['changes'] ?? [];

        if ($changes) {
            $last = end($changes);
            $this->acknowledge($last['id']);
        }

        return [
            'announced' => $announced,
            'received' => $changes,
        ];
    }
}
