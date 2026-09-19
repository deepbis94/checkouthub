<?php

namespace App\Gateways;

use Illuminate\Http\Client\ConnectionException;

final class GatewayFailureClassifier
{
    /**
     * Ambiguous: the provider may have accepted the charge (timeouts, mid-request drops).
     * Unambiguous: the request never reached the provider (DNS, connection refused).
     *
     * Classification is substring matching on Guzzle/cURL messages ("curl error 7",
     * "connection refused"). Those strings can vary across curl/Guzzle versions; anything
     * unrecognized is treated as ambiguous so we reconcile instead of risking a double charge.
     */
    public static function isAmbiguous(ConnectionException $e): bool
    {
        return ! self::neverReachedProvider($e);
    }

    public static function reason(ConnectionException $e): string
    {
        $message = strtolower($e->getMessage());

        if (self::matches($message, [
            'could not resolve',
            "couldn't resolve",
            'name or service not known',
            'nodename nor servname',
            'curl error 6',
        ])) {
            return 'dns_failure';
        }

        if (self::matches($message, [
            'connection refused',
            'failed to connect',
            "couldn't connect",
            'could not connect',
            'curl error 7',
        ])) {
            return 'connection_refused';
        }

        return 'timeout';
    }

    private static function neverReachedProvider(ConnectionException $e): bool
    {
        $message = strtolower($e->getMessage());

        return self::matches($message, [
            'could not resolve',
            "couldn't resolve",
            'name or service not known',
            'nodename nor servname',
            'curl error 6',
            'connection refused',
            'failed to connect',
            "couldn't connect",
            'could not connect',
            'curl error 7',
        ]);
    }

    /**
     * @param  list<string>  $needles
     */
    private static function matches(string $message, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }
}
