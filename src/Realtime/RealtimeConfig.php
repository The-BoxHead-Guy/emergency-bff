<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Realtime;

use DataEmergencia\EmergencyBff\Config\Config;

/**
 * Public Pusher configuration for the MVP.
 *
 * Only the public key and cluster are exposed. The Pusher secret never leaves
 * the server, and private channels are a post-MVP extension.
 */
final class RealtimeConfig
{
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'mode' => 'public',
            'pusher' => [
                'key' => $this->config->pusherKey,
                'cluster' => $this->config->pusherCluster,
                'channel_pattern' => 'whatsapp.{phone}',
            ],
        ];
    }

    public function channelFor(string $phone): string
    {
        return 'whatsapp.'.ltrim($phone, '+');
    }

    /**
     * Reserved for the post-MVP private-channel phase; not routed in the MVP.
     */
    public function authRoute(): string
    {
        return rtrim($this->config->routePrefix, '/').'/pusher/auth';
    }
}
