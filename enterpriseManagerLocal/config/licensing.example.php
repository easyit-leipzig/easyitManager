<?php
declare(strict_types=1);
return [
    'enabled' => true,
    'server_url' => 'https://license.example.invalid',
    // Optional. Leer = aus der aktuellen HTTP-Anfrage ermitteln. Bei Reverse-Proxy/CLI fest setzen.
    'surface_origin' => '',
    'connect_timeout' => 5,
    'request_timeout' => 15,
    'verify_tls' => true,
    'storage_dir' => dirname(__DIR__) . '/storage/licensing',
    // HF106+: Bei leerer Liste wird der aktive Public Key über "Server prüfen & Schlüssel laden"
    // aus der Health-Schnittstelle übernommen. Remote nur über HTTPS + Zertifikatsprüfung.
    // Bereits vertraute/rotierte Schlüssel werden anschließend lokal persistiert.
    'trusted_server_keys' => [],
];
