<?php

namespace App\Protocols;

use App\Protocols\Singbox\Singbox;

/** Clash routing semantics with native outbounds for the NekoBox routing v1 client. */
class NekoBoxRouting extends ClashMeta
{
    public $flag = 'nekobox-routing';

    public function handle()
    {
        return parent::handle()->header('X-NekoBox-Routing', '1');
    }

    protected function nativeOutbounds($user, array $servers): ?array
    {
        // This capability opts into the 1.13+ core, including certificate pinning.
        return (new Singbox($user, $servers, [
            'supports_certificate_public_key_sha256' => true,
        ]))->buildProxies();
    }
}
