<?php

use App\Gateways\GatewayFailureClassifier;
use Illuminate\Http\Client\ConnectionException;

it('treats dns and connection refused as unambiguous', function (string $message) {
    $e = new ConnectionException($message);

    expect(GatewayFailureClassifier::isAmbiguous($e))->toBeFalse();
})->with([
    'cURL error 6: Could not resolve host: api.stripe.com',
    'cURL error 7: Failed to connect to api.stripe.com port 443: Connection refused',
]);

it('treats timeouts as ambiguous', function (string $message, string $reason) {
    $e = new ConnectionException($message);

    expect(GatewayFailureClassifier::isAmbiguous($e))->toBeTrue()
        ->and(GatewayFailureClassifier::reason($e))->toBe($reason);
})->with([
    ['cURL error 28: Operation timed out after 8000 milliseconds with 0 bytes received', 'timeout'],
    ['cURL error 28: Connection timed out after 3001 milliseconds', 'timeout'],
]);
