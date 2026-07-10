<?php

use App\Support\TrustedHostResolver;

it('includes the configured app host and any extra trusted hosts', function (): void {
    $hosts = TrustedHostResolver::resolve('https://example.com', 'api.example.com, www.example.com');

    expect($hosts)->toBe(['example.com', 'api.example.com', 'www.example.com']);
});
