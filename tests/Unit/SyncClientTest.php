<?php

namespace Tests\Unit;

use App\Sync\SyncClient;
use PHPUnit\Framework\TestCase;

class SyncClientTest extends TestCase
{
    /** @test */
    public function test_make_change_returns_correctly_shaped_array(): void
    {
        $change = SyncClient::makeChange(
            entityType: 'note',
            entityId: 'note-1',
            changeType: 'create',
            contentHash: 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            version: 1,
        );

        $this->assertSame('note', $change['entity_type']);
        $this->assertSame('note-1', $change['entity_id']);
        $this->assertSame('create', $change['change_type']);
        $this->assertSame('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855', $change['content_hash']);
        $this->assertSame(1, $change['version']);
        $this->assertArrayNotHasKey('vector_clock', $change);
        $this->assertArrayNotHasKey('payload_location', $change);
    }

    /** @test */
    public function test_make_change_includes_optional_fields_when_provided(): void
    {
        $change = SyncClient::makeChange(
            entityType: 'contact',
            entityId: 'contact-5',
            changeType: 'delete',
            contentHash: 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            version: 2,
            payloadLocation: 'http://peer.local:9090/sync/contact-5',
            vectorClock: ['phone' => 2],
        );

        $this->assertSame('http://peer.local:9090/sync/contact-5', $change['payload_location']);
        $this->assertSame(['phone' => 2], $change['vector_clock']);
    }

    /** @test */
    public function test_make_change_accepts_all_change_types(): void
    {
        foreach (['create', 'update', 'delete'] as $changeType) {
            $change = SyncClient::makeChange(
                entityType: 'note',
                entityId: 'note-x',
                changeType: $changeType,
                contentHash: 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
                version: 1,
            );

            $this->assertSame($changeType, $change['change_type']);
        }
    }
}
