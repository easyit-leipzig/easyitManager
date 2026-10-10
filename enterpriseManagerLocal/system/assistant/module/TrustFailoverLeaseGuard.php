<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Module;

/**
 * Phase 29 signing fence.
 *
 * If no failover cluster is configured, this guard is intentionally a no-op so
 * Phase 1-28 installations remain backward compatible. Once cluster.json
 * exists, release signing requires a currently valid quorum-backed leadership
 * lease for the local PRIMARY instance.
 */
final class TrustFailoverLeaseGuard
{
    public static function assertCanSign(string $root): void
    {
        $root = rtrim($root, '/\\');
        $base = $root . '/storage/assistant/trust/instances/failover';
        $clusterPath = $base . '/cluster.json';
        if (!is_file($clusterPath)) return;

        $cluster = self::json($clusterPath);
        if (empty($cluster['enabled'])) return;
        $joint = self::json($base . '/membership/joint.json');
        if (!empty($joint['active'])) {
            throw new \RuntimeException('Release-Signierung ist durch Phase 30 gesperrt: Cluster-Mitgliedschaft befindet sich im Joint-Consensus. Zuerst Reconfiguration finalisieren.');
        }
        $local = self::json($root . '/storage/assistant/trust/instances/local.json');
        $lease = self::json($base . '/leadership-lease.json');
        if ($local === [] || $lease === []) {
            throw new \RuntimeException('Release-Signierung ist durch Phase 29 gesperrt: kein aktives quorumgesichertes PRIMARY-Lease vorhanden.');
        }
        $now = time();
        $expires = self::ts($lease['expiresAt'] ?? null);
        $localId = (string)($local['instanceId'] ?? '');
        if (($local['role'] ?? '') !== 'PRIMARY' || ($local['status'] ?? 'ACTIVE') !== 'ACTIVE') {
            throw new \RuntimeException('Release-Signierung ist durch Phase 29 gesperrt: lokale Instanz ist kein aktives PRIMARY.');
        }
        if (($lease['holderInstanceId'] ?? '') !== $localId) {
            throw new \RuntimeException('Release-Signierung ist durch Phase 29 gesperrt: das aktive Lease gehört einer anderen Instanz.');
        }
        if ($expires === null || $now >= $expires) {
            throw new \RuntimeException('Release-Signierung ist durch Phase 29 gesperrt: PRIMARY-Lease ist abgelaufen. Zuerst quorumgesichert erneuern.');
        }
        if (empty($lease['quorumVerified'])) {
            throw new \RuntimeException('Release-Signierung ist durch Phase 29 gesperrt: Lease besitzt keinen verifizierten Quorum-Nachweis.');
        }
        $clusterDigest = (string)($cluster['membershipDigest'] ?? '');
        if ($clusterDigest === '' || !hash_equals($clusterDigest, (string)($lease['clusterDigest'] ?? ''))) {
            throw new \RuntimeException('Release-Signierung ist durch Phase 29 gesperrt: Lease gehört nicht zur aktuellen Cluster-Mitgliedschaft.');
        }
        self::verifyLease($lease, $cluster);
    }

    /** @param array<string,mixed> $lease @param array<string,mixed> $cluster */
    private static function verifyLease(array $lease, array $cluster): void
    {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            throw new \RuntimeException('Release-Signierung ist durch Phase 29 gesperrt: ext-sodium fehlt für die Lease-Verifikation.');
        }
        $holder = (string)($lease['holderInstanceId'] ?? '');
        $members = (array)($cluster['members'] ?? []);
        $member = $members[$holder] ?? null;
        if (!is_array($member)) throw new \RuntimeException('Phase-29-Lease ungültig: Holder ist kein Cluster-Mitglied.');
        $payload = (array)($lease['payload'] ?? []);
        if (($payload['holderInstanceId'] ?? '') !== $holder) throw new \RuntimeException('Phase-29-Lease ungültig: Holder-Payload stimmt nicht.');
        if (($payload['clusterDigest'] ?? '') !== ($cluster['membershipDigest'] ?? '')) throw new \RuntimeException('Phase-29-Lease ungültig: Cluster-Digest stimmt nicht.');
        if ((int)($payload['epoch'] ?? -1) !== (int)($lease['epoch'] ?? -2) || (int)($payload['sequence'] ?? -1) !== (int)($lease['sequence'] ?? -2) || (string)($payload['expiresAt'] ?? '') !== (string)($lease['expiresAt'] ?? '')) throw new \RuntimeException('Phase-29-Lease ungültig: äußere Lease-Metadaten stimmen nicht mit dem signierten Payload überein.');
        $sig = (array)($lease['signature'] ?? []);
        $pub = base64_decode((string)($member['publicKey'] ?? ''), true);
        $det = base64_decode((string)($sig['signature'] ?? ''), true);
        if ($pub === false || $det === false || strlen($pub) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || strlen($det) !== SODIUM_CRYPTO_SIGN_BYTES || !sodium_crypto_sign_verify_detached($det, self::canonical($payload), $pub)) {
            throw new \RuntimeException('Phase-29-Lease ungültig: Holder-Signatur ist nicht gültig.');
        }
        $quorum = (int)($cluster['quorum'] ?? 0);
        $proofs = (array)($lease['proofs'] ?? []);
        $seen = [];
        foreach ($proofs as $proof) {
            if (!is_array($proof)) continue;
            $pp = (array)($proof['payload'] ?? []);
            $voter = (string)($pp['voterInstanceId'] ?? '');
            if ($voter === '' || isset($seen[$voter])) continue;
            $vm = $members[$voter] ?? null;
            if (!is_array($vm) || empty($vm['voting'])) continue;
            $ps = (array)($proof['signature'] ?? []);
            if (($pp['candidateInstanceId'] ?? $pp['holderInstanceId'] ?? '') !== $holder) continue;
            if ((string)($pp['clusterDigest'] ?? '') !== (string)($cluster['membershipDigest'] ?? '')) continue;
            if ((int)($pp['epoch'] ?? -1) !== (int)($lease['epoch'] ?? -2)) continue;
            $proofSchema = (string)($pp['schema'] ?? '');
            $source = (string)($payload['source'] ?? '');
            if ($source === 'QUORUM_LEASE' && ($proofSchema !== 'easyit.assistant.trust-lease-ack.v1' || (int)($pp['sequence'] ?? -1) !== (int)($lease['sequence'] ?? -2) || !hash_equals((string)($pp['proposalDigest'] ?? ''), (string)($payload['proposalDigest'] ?? '')))) continue;
            if ($source === 'ELECTION_QUORUM' && ($proofSchema !== 'easyit.assistant.trust-election-vote.v1' || !hash_equals((string)($pp['requestDigest'] ?? ''), (string)($payload['proposalDigest'] ?? '')))) continue;
            $vp = base64_decode((string)($vm['publicKey'] ?? ''), true);
            $vs = base64_decode((string)($ps['signature'] ?? ''), true);
            if ($vp === false || $vs === false || strlen($vp) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || strlen($vs) !== SODIUM_CRYPTO_SIGN_BYTES) continue;
            if (!sodium_crypto_sign_verify_detached($vs, self::canonical($pp), $vp)) continue;
            $seen[$voter] = true;
        }
        if (count($seen) < $quorum) throw new \RuntimeException('Phase-29-Lease ungültig: Quorum-Nachweis reicht nicht aus.');
    }

    /** @return array<string,mixed> */
    private static function json(string $path): array
    {
        if (!is_file($path)) return [];
        $d = json_decode((string)@file_get_contents($path), true);
        return is_array($d) ? $d : [];
    }
    private static function ts(mixed $v): ?int { if (!is_string($v) || trim($v)==='') return null; $t=strtotime($v); return $t===false?null:$t; }
    /** @param mixed $v */ private static function normalize($v){ if(!is_array($v))return $v; if(array_is_list($v))return array_map(fn($x)=>self::normalize($x),$v); ksort($v,SORT_STRING); foreach($v as $k=>$x)$v[$k]=self::normalize($x); return $v; }
    /** @param array<string,mixed> $d */ private static function canonical(array $d): string { return json_encode(self::normalize($d), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); }
}
