<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Module;

final class TrustFederationService
{
    private string $root;
    private string $trustDir;
    private string $instancesDir;
    private string $localPath;
    private string $peersPath;
    private string $federationAuditPath;
    private string $syncDir;
    private string $disasterDir;
    private string $registryPath;
    private string $policyPath;
    private string $auditPath;
    private string $catalogPath;

    public function __construct(
        private ReleaseTrustStore $trust,
        private TrustRecoveryService $recovery,
        string $root
    ) {
        $this->root = rtrim($root, '/\\');
        $this->trustDir = $this->root . '/storage/assistant/trust';
        $this->instancesDir = $this->trustDir . '/instances';
        $this->localPath = $this->instancesDir . '/local.json';
        $this->peersPath = $this->instancesDir . '/peers.json';
        $this->federationAuditPath = $this->instancesDir . '/federation-audit.jsonl';
        $this->syncDir = $this->instancesDir . '/sync';
        $this->disasterDir = $this->instancesDir . '/disaster';
        $this->registryPath = $this->trustDir . '/trusted-keys.json';
        $this->policyPath = $this->trustDir . '/policy.json';
        $this->auditPath = $this->trustDir . '/audit.jsonl';
        $this->catalogPath = $this->root . '/storage/assistant/release-catalog/catalog.json';
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        $local = $this->localInstance();
        $peers = $this->loadPeers();
        $trustStatus = $this->trust->status();
        $active = (string)($trustStatus['activeKeyId'] ?? '');
        return [
            'schema' => 'easyit.assistant.trust-federation-status.v1',
            'initialized' => $local !== [],
            'localInstance' => $local,
            'localStateDigest' => $this->publicStateDigest(),
            'peers' => array_values((array)($peers['peers'] ?? [])),
            'peerCount' => count((array)($peers['peers'] ?? [])),
            'trustChain' => $this->trust->verifyChain(),
            'trustAudit' => $this->trust->verifyAuditChain(),
            'federationAudit' => $this->verifyFederationAudit(),
            'activeKeyId' => $active !== '' ? $active : null,
            'activePrivateKeyAvailable' => $active !== '' && is_file($this->trustDir . '/private/' . $active . '.key'),
            'canSignOnThisInstance' => $local === [] ? null : (($local['role'] ?? '') === 'PRIMARY' && ($local['status'] ?? 'ACTIVE') === 'ACTIVE'),
            'syncBundleCount' => $this->countFiles($this->syncDir, '*.trust-sync.zip'),
            'disasterBundleCount' => $this->countFiles($this->disasterDir, '*.trust-disaster.zip'),
        ];
    }

    /** @return array<string,mixed> */
    public function initializeLocal(string $name, string $role, string $confirmation): array
    {
        if ($this->localInstance() !== []) throw new \RuntimeException('Diese Installation besitzt bereits eine Trust-Instanz.');
        $name = trim($name); if ($name === '') throw new \InvalidArgumentException('Instanzname fehlt.');
        $role = $this->normalizeRole($role);
        if (trim($confirmation) !== 'INIT INSTANCE ' . $role . ' ' . $name) {
            throw new \RuntimeException('Initialisierung abgebrochen. Bestätigung muss exakt "INIT INSTANCE ' . $role . ' ' . $name . '" lauten.');
        }
        // PRIMARY initialization validates/creates the signing key before the role file is committed,
        // so a failed key check cannot leave a half-initialized PRIMARY instance behind.
        if ($role === 'PRIMARY') $this->trust->ensureActiveKey();
        $id = 'instance-' . substr(hash('sha256', $name . '|' . microtime(true) . '|' . random_bytes(16)), 0, 20);
        $now = gmdate('c');
        $local = [
            'schema' => 'easyit.assistant.trust-instance.v1',
            'instanceId' => $id,
            'name' => $name,
            'role' => $role,
            'status' => 'ACTIVE',
            'createdAt' => $now,
            'updatedAt' => $now,
            'syncSequence' => 0,
            'lastExportDigest' => null,
            'lastSyncAt' => null,
            'lastSyncSource' => null,
            'recoveredFromInstance' => null,
        ];
        $this->atomicJson($this->localPath, $local, 0640);
        $this->federationAudit('INSTANCE_INIT', $id, ['name' => $name, 'role' => $role]);
        return $local;
    }

    /** @return array<string,mixed> */
    public function changeRole(string $targetRole, string $reason, string $confirmation): array
    {
        $local = $this->requireLocal();
        $targetRole = $this->normalizeRole($targetRole);
        $id = (string)$local['instanceId'];
        $current = (string)$local['role'];
        if ($current === $targetRole) return $local;
        $reason = trim($reason); if ($reason === '') throw new \InvalidArgumentException('Für den Rollenwechsel ist ein Grund erforderlich.');
        if ($targetRole === 'PRIMARY') {
            if (trim($confirmation) !== 'PROMOTE ' . $id . ' TO PRIMARY') throw new \RuntimeException('Promotion abgebrochen. Bestätigung muss exakt "PROMOTE ' . $id . ' TO PRIMARY" lauten.');
            $chain = $this->trust->verifyChain(); $audit = $this->trust->verifyAuditChain();
            if (empty($chain['ok']) || empty($audit['ok'])) throw new \RuntimeException('Promotion blockiert: Trust-Chain oder Trust-Audit ist ungültig.');
            $status = $this->trust->status(); $active = (string)($status['activeKeyId'] ?? '');
            if ($active === '' || !is_file($this->trustDir . '/private/' . $active . '.key')) throw new \RuntimeException('Promotion blockiert: kein aktiver privater Signing-Key vorhanden. Zuerst Phase 27 Private-Key-Restore verwenden.');
        } else {
            $expected = 'DEMOTE ' . $id . ' TO ' . $targetRole;
            if (trim($confirmation) !== $expected) throw new \RuntimeException('Rollenwechsel abgebrochen. Bestätigung muss exakt "' . $expected . '" lauten.');
        }
        $local['role'] = $targetRole;
        $local['updatedAt'] = gmdate('c');
        $local['roleChangedAt'] = gmdate('c');
        $local['roleChangeReason'] = $reason;
        $this->atomicJson($this->localPath, $local, 0640);
        $this->federationAudit('ROLE_CHANGE', $id, ['from' => $current, 'to' => $targetRole, 'reason' => $reason]);
        return $local;
    }

    /** @return array<string,mixed> */
    public function exportSyncBundle(): array
    {
        $local = $this->requirePrimary();
        $files = $this->publicStateFiles();
        $stateDigest = $this->digestFiles($files);
        $previous = isset($local['lastExportDigest']) && is_string($local['lastExportDigest']) && $local['lastExportDigest'] !== '' ? $local['lastExportDigest'] : null;
        $sequence = (int)($local['syncSequence'] ?? 0) + 1;
        $source = [
            'schema' => 'easyit.assistant.trust-sync-source.v1',
            'instanceId' => (string)$local['instanceId'],
            'name' => (string)$local['name'],
            'role' => 'PRIMARY',
            'sequence' => $sequence,
            'previousStateDigest' => $previous,
            'stateDigest' => $stateDigest,
            'exportedAt' => gmdate('c'),
        ];
        $files['source-instance.json'] = $this->pretty($source);
        $payload = [
            'schema' => 'easyit.assistant.trust-sync-manifest-payload.v1',
            'source' => $source,
            'files' => $this->hashFiles($files),
        ];
        $signed = $this->trust->sign($this->canonical($payload));
        $manifest = ['schema' => 'easyit.assistant.trust-sync-manifest.v1', 'payload' => $payload, 'signature' => $signed];
        $files['trust-sync-manifest.json'] = $this->pretty($manifest);
        $this->ensureDir($this->syncDir);
        $name = 'easyit-trust-sync-' . $this->safe((string)$local['instanceId']) . '-' . str_pad((string)$sequence, 6, '0', STR_PAD_LEFT) . '.trust-sync.zip';
        $path = $this->syncDir . '/' . $name;
        $this->writeZip($path, $files);
        $sha = (string)hash_file('sha256', $path); file_put_contents($path . '.sha256', $sha . '  ' . $name . "\n");
        $local['syncSequence'] = $sequence; $local['lastExportDigest'] = $stateDigest; $local['lastSyncExportAt'] = gmdate('c'); $local['updatedAt'] = gmdate('c');
        $this->atomicJson($this->localPath, $local, 0640);
        $this->federationAudit('SYNC_EXPORT', (string)$local['instanceId'], ['sequence' => $sequence, 'stateDigest' => $stateDigest, 'previousStateDigest' => $previous, 'sha256' => $sha]);
        return ['schema' => 'easyit.assistant.trust-sync-export-result.v1', 'ok' => true, 'verdict' => 'PASS', 'file' => $name, 'path' => $path, 'sha256' => $sha, 'source' => $source];
    }

    /** @return array<string,mixed> */
    public function inspectSyncBundle(string $path): array
    {
        $bundle = $this->readSyncBundle($path);
        $manifest = $bundle['manifest']; $payload = (array)$manifest['payload']; $source = (array)$payload['source']; $sig = (array)$manifest['signature'];
        $errors = []; $warnings = [];
        if (($source['role'] ?? '') !== 'PRIMARY') $errors[] = 'Nur eine PRIMARY-Instanz darf autoritative Trust-Synchronisationspakete liefern.';
        $verification = $this->verifyIncomingSyncSignature($payload, $sig, $bundle['files']);
        if (empty($verification['ok'])) $errors[] = 'Sync-Signatur ist lokal nicht vertrauenswürdig. Importiere zunächst den öffentlichen Trust-Anker über Phase 27. ' . implode(' ', (array)($verification['errors'] ?? []));
        $local = $this->localInstance(); $localDigest = $this->publicStateDigest(); $sourceDigest = (string)($source['stateDigest'] ?? ''); $previous = (string)($source['previousStateDigest'] ?? '');
        $syncState = 'DIVERGED';
        if ($localDigest !== '' && hash_equals($localDigest, $sourceDigest)) $syncState = 'IN_SYNC';
        elseif ($previous !== '' && $localDigest !== '' && hash_equals($localDigest, $previous)) $syncState = 'FAST_FORWARD';
        elseif ($local === [] && $this->trustKeyCount() === 0) $syncState = 'BOOTSTRAP_REQUIRED';
        $sourceId = (string)($source['instanceId'] ?? '');
        if ($local !== [] && ($local['role'] ?? '') === 'PRIMARY' && (string)($local['instanceId'] ?? '') !== $sourceId) {
            $syncState = 'PRIMARY_CONFLICT'; $errors[] = 'PRIMARY-Konflikt: Diese Instanz ist selbst PRIMARY. Zuerst kontrolliert auf SECONDARY/VERIFY_ONLY herabstufen.';
        }
        if ($syncState === 'DIVERGED') $warnings[] = 'Der lokale Public-Trust-Stand ist weder identisch noch der direkte Vorgänger des Quellstands.';
        if ($syncState === 'BOOTSTRAP_REQUIRED') $warnings[] = 'Leere Instanz: zuerst Phase 27 Public-Trust-Anker importieren oder ein Phase-28-Disaster-Recovery durchführen.';
        return [
            'schema' => 'easyit.assistant.trust-sync-inspection.v1',
            'ok' => $errors === [],
            'verdict' => $errors === [] ? ($warnings === [] ? 'PASS' : 'PASS_WITH_WARNINGS') : 'FAIL',
            'syncState' => $syncState,
            'source' => $source,
            'sourceKeyId' => $sig['keyId'] ?? null,
            'sourceStateDigest' => $sourceDigest,
            'localStateDigest' => $localDigest,
            'signatureVerification' => $verification,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /** @return array<string,mixed> */
    public function applySyncBundle(string $path, string $confirmation): array
    {
        $inspection = $this->inspectSyncBundle($path);
        if (empty($inspection['ok'])) throw new \RuntimeException('Trust-Synchronisation kann nicht angewendet werden: ' . implode(' ', (array)$inspection['errors']));
        $state = (string)$inspection['syncState']; $source = (array)$inspection['source']; $sourceId = (string)$source['instanceId'];
        if ($state === 'IN_SYNC') return ['schema' => 'easyit.assistant.trust-sync-apply-result.v1', 'ok' => true, 'verdict' => 'PASS', 'syncState' => 'IN_SYNC', 'changed' => false, 'source' => $source];
        if ($state === 'BOOTSTRAP_REQUIRED') throw new \RuntimeException('Bootstrap über Sync ist absichtlich nicht erlaubt. Zuerst Phase 27 Public-Trust-Import oder Disaster-Recovery verwenden.');
        if ($state === 'PRIMARY_CONFLICT') throw new \RuntimeException('PRIMARY-Konflikt muss vor der Synchronisation aufgelöst werden.');
        $expected = ($state === 'DIVERGED' ? 'FORCE TRUST SYNC ' : 'APPLY TRUST SYNC ') . $sourceId;
        if (trim($confirmation) !== $expected) throw new \RuntimeException('Synchronisation abgebrochen. Bestätigung muss exakt "' . $expected . '" lauten.');
        $backup = $this->recovery->exportPublicBundle();
        $bundle = $this->readSyncBundle($path); $files = $bundle['files'];
        $this->atomicRaw($this->registryPath, $files['trusted-keys.json'], 0640);
        $this->atomicRaw($this->policyPath, $files['policy.json'], 0640);
        $this->atomicRaw($this->auditPath, $files['audit.jsonl'], 0640);
        $this->atomicRaw($this->catalogPath, $files['release-catalog.json'], 0640);
        $post = $this->publicStateDigest(); $expectedDigest = (string)$source['stateDigest'];
        if (!hash_equals($expectedDigest, $post)) throw new \RuntimeException('Synchronisation wurde geschrieben, aber der resultierende Public-Trust-Digest stimmt nicht mit der PRIMARY-Quelle überein.');
        $this->registerPeer($source, 'IN_SYNC', $post, (string)($inspection['sourceKeyId'] ?? ''));
        $local = $this->localInstance();
        if ($local !== []) { $local['lastSyncAt'] = gmdate('c'); $local['lastSyncSource'] = $sourceId; $local['lastImportedDigest'] = $post; $local['updatedAt'] = gmdate('c'); $this->atomicJson($this->localPath, $local, 0640); }
        $this->federationAudit('SYNC_APPLY', $sourceId, ['syncStateBefore' => $state, 'stateDigest' => $post, 'backupFile' => $backup['file'] ?? null]);
        return ['schema' => 'easyit.assistant.trust-sync-apply-result.v1', 'ok' => true, 'verdict' => $state === 'DIVERGED' ? 'PASS_WITH_WARNINGS' : 'PASS', 'syncState' => 'IN_SYNC', 'changed' => true, 'source' => $source, 'stateDigest' => $post, 'preSyncBackup' => $this->withoutPath($backup)];
    }

    /** @return array<string,mixed> */
    public function createDisasterBundle(string $passphrase, string $confirmation): array
    {
        $local = $this->requirePrimary(); $id = (string)$local['instanceId'];
        if (trim($confirmation) !== 'CREATE TRUST DISASTER ' . $id) throw new \RuntimeException('Disaster-Backup abgebrochen. Bestätigung muss exakt "CREATE TRUST DISASTER ' . $id . '" lauten.');
        $private = $this->recovery->createPrivateBackup($passphrase, 'BACKUP PRIVATE KEYS');
        $files = $this->publicStateFiles();
        $files['local-instance.json'] = $this->pretty($local);
        $files['peers.json'] = $this->pretty($this->loadPeers());
        $files['federation-audit.jsonl'] = is_file($this->federationAuditPath) ? (string)file_get_contents($this->federationAuditPath) : '';
        $files['private-backup.json'] = (string)file_get_contents((string)$private['path']);
        $payload = [
            'schema' => 'easyit.assistant.trust-disaster-manifest-payload.v1',
            'sourceInstanceId' => $id,
            'sourceInstanceName' => (string)$local['name'],
            'sourceRole' => 'PRIMARY',
            'createdAt' => gmdate('c'),
            'publicStateDigest' => $this->digestFiles($this->publicStateFiles()),
            'files' => $this->hashFiles($files),
        ];
        $manifest = ['schema' => 'easyit.assistant.trust-disaster-manifest.v1', 'payload' => $payload, 'signature' => $this->trust->sign($this->canonical($payload))];
        $files['trust-disaster-manifest.json'] = $this->pretty($manifest);
        $this->ensureDir($this->disasterDir); $name = 'easyit-trust-disaster-' . $this->safe($id) . '-' . gmdate('Ymd-His') . '.trust-disaster.zip'; $path = $this->disasterDir . '/' . $name;
        $this->writeZip($path, $files); $sha = (string)hash_file('sha256', $path); file_put_contents($path . '.sha256', $sha . '  ' . $name . "\n");
        $this->federationAudit('DISASTER_EXPORT', $id, ['sha256' => $sha, 'privateBackupSha256' => $private['sha256'] ?? null]);
        return ['schema' => 'easyit.assistant.trust-disaster-export-result.v1', 'ok' => true, 'verdict' => 'PASS', 'file' => $name, 'path' => $path, 'sha256' => $sha, 'sourceInstanceId' => $id, 'privateMaterialEncrypted' => true];
    }

    /** @return array<string,mixed> */
    public function restoreDisasterBundle(string $path, string $passphrase, string $newName, string $confirmation): array
    {
        if ($this->localInstance() !== [] || $this->trustKeyCount() > 0) throw new \RuntimeException('Disaster-Restore ist nur auf einer noch nicht initialisierten/leeren Trust-Instanz erlaubt.');
        $files = $this->readZipStrict($path, ['trusted-keys.json','policy.json','audit.jsonl','release-catalog.json','local-instance.json','peers.json','federation-audit.jsonl','private-backup.json','trust-disaster-manifest.json']);
        $manifest = $this->decodeJson($files['trust-disaster-manifest.json'], 'Disaster-Manifest');
        if (($manifest['schema'] ?? '') !== 'easyit.assistant.trust-disaster-manifest.v1') throw new \RuntimeException('Unbekanntes Disaster-Manifest-Schema.');
        $payload = (array)($manifest['payload'] ?? []); $sourceId = (string)($payload['sourceInstanceId'] ?? '');
        if (trim($confirmation) !== 'RESTORE TRUST DISASTER ' . $sourceId) throw new \RuntimeException('Disaster-Restore abgebrochen. Bestätigung muss exakt "RESTORE TRUST DISASTER ' . $sourceId . '" lauten.');
        $this->verifyFileHashes((array)($payload['files'] ?? []), $files);
        $registry = $this->decodeJson($files['trusted-keys.json'], 'Trust-Store');
        $sig = (array)($manifest['signature'] ?? []); $keyId = (string)($sig['keyId'] ?? ''); $key = $registry['keys'][$keyId] ?? null;
        if (!is_array($key) || ($key['status'] ?? '') === 'REVOKED' || ($key['trustLevel'] ?? '') !== 'RELEASE') throw new \RuntimeException('Disaster-Paket wurde nicht mit einem gültigen RELEASE-Schlüssel signiert.');
        $pub = base64_decode((string)($key['publicKey'] ?? ''), true); $signature = base64_decode((string)($sig['signature'] ?? ''), true);
        if ($pub === false || $signature === false || !function_exists('sodium_crypto_sign_verify_detached') || !sodium_crypto_sign_verify_detached($signature, $this->canonical($payload), $pub)) throw new \RuntimeException('Disaster-Paket-Signatur ist kryptographisch ungültig.');
        $expectedDigest = (string)($payload['publicStateDigest'] ?? ''); $actualDigest = $this->digestFiles(['trusted-keys.json'=>$files['trusted-keys.json'],'policy.json'=>$files['policy.json'],'audit.jsonl'=>$files['audit.jsonl'],'release-catalog.json'=>$files['release-catalog.json']]);
        if ($expectedDigest === '' || !hash_equals($expectedDigest, $actualDigest)) throw new \RuntimeException('Public-Trust-Digest des Disaster-Pakets stimmt nicht.');
        $this->atomicRaw($this->registryPath, $files['trusted-keys.json'], 0640); $this->atomicRaw($this->policyPath, $files['policy.json'], 0640); $this->atomicRaw($this->auditPath, $files['audit.jsonl'], 0640); $this->atomicRaw($this->catalogPath, $files['release-catalog.json'], 0640);
        $tmp = tempnam(sys_get_temp_dir(), 'easyit-trust-private-'); if ($tmp === false) throw new \RuntimeException('Temporäre Restore-Datei kann nicht erzeugt werden.');
        try { file_put_contents($tmp, $files['private-backup.json']); $privateRestore = $this->recovery->restorePrivateBackup($tmp, $passphrase, 'RESTORE PRIVATE KEYS', 'Phase 28 Disaster-Recovery'); } finally { @unlink($tmp); }
        $newName = trim($newName); if ($newName === '') $newName = 'Recovered ' . (string)($payload['sourceInstanceName'] ?? $sourceId);
        $newId = 'instance-' . substr(hash('sha256', $newName . '|' . microtime(true) . '|' . random_bytes(16)), 0, 20); $now = gmdate('c');
        $local = ['schema'=>'easyit.assistant.trust-instance.v1','instanceId'=>$newId,'name'=>$newName,'role'=>'SECONDARY','status'=>'ACTIVE','createdAt'=>$now,'updatedAt'=>$now,'syncSequence'=>0,'lastExportDigest'=>null,'lastSyncAt'=>$now,'lastSyncSource'=>$sourceId,'recoveredFromInstance'=>$sourceId,'recoveryMode'=>'DISASTER_RESTORE','promotionRequired'=>true];
        $this->atomicJson($this->localPath, $local, 0640); $this->atomicRaw($this->federationAuditPath, $files['federation-audit.jsonl'], 0640);
        $this->registerPeer($this->decodeJson($files['local-instance.json'], 'Quellinstanz'), 'RECOVERY_SOURCE', $actualDigest, $keyId);
        $chain = $this->trust->verifyChain(); $audit = $this->trust->verifyAuditChain(); if (empty($chain['ok']) || empty($audit['ok'])) throw new \RuntimeException('Trust-System wurde wiederhergestellt, aber Trust-Chain/Audit ist nicht gültig.');
        $this->federationAudit('DISASTER_RESTORE', $sourceId, ['newInstanceId'=>$newId,'newRole'=>'SECONDARY','publicStateDigest'=>$actualDigest]);
        return ['schema'=>'easyit.assistant.trust-disaster-restore-result.v1','ok'=>true,'verdict'=>'PASS_WITH_WARNINGS','sourceInstanceId'=>$sourceId,'newInstance'=>$local,'privateRestore'=>$privateRestore,'publicStateDigest'=>$actualDigest,'chainVerification'=>$chain,'auditVerification'=>$audit,'warning'=>'Die wiederhergestellte Instanz startet absichtlich als SECONDARY. Eine Promotion auf PRIMARY muss separat bestätigt werden, damit kein unbeabsichtigtes Dual-Primary entsteht.'];
    }

    /** @return array<string,mixed> */
    public function verifyFederationAudit(): array
    {
        if (!is_file($this->federationAuditPath)) return ['schema'=>'easyit.assistant.trust-federation-audit-verification.v1','ok'=>true,'verdict'=>'PASS','events'=>0,'lastHash'=>null,'errors'=>[]];
        $lines = file($this->federationAuditPath, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) ?: []; $prev = ''; $errors=[]; $count=0;
        foreach ($lines as $line) { $e=json_decode($line,true); if(!is_array($e)){ $errors[]='Federation-Audit enthält ungültiges JSON.'; continue; } $hash=(string)($e['eventHash']??''); $p=(string)($e['previousHash']??''); $tmp=$e; unset($tmp['eventHash']); $calc=hash('sha256',$this->canonical($tmp)); if(!hash_equals($p,$prev))$errors[]='Federation-Audit previousHash stimmt bei '.(string)($e['eventId']??'?').' nicht.'; if($hash===''||!hash_equals($hash,$calc))$errors[]='Federation-Audit eventHash stimmt bei '.(string)($e['eventId']??'?').' nicht.'; $prev=$hash; $count++; }
        return ['schema'=>'easyit.assistant.trust-federation-audit-verification.v1','ok'=>$errors===[],'verdict'=>$errors===[]?'PASS':'FAIL','events'=>$count,'lastHash'=>$prev?:null,'errors'=>$errors,'checkedAt'=>gmdate('c')];
    }

    /** @return list<array<string,mixed>> */
    public function peers(): array { return array_values((array)($this->loadPeers()['peers'] ?? [])); }

    /** @return array<string,mixed> */
    private function readSyncBundle(string $path): array
    {
        $files = $this->readZipStrict($path, ['trusted-keys.json','policy.json','audit.jsonl','release-catalog.json','source-instance.json','trust-sync-manifest.json']);
        $manifest = $this->decodeJson($files['trust-sync-manifest.json'], 'Sync-Manifest'); if (($manifest['schema']??'')!=='easyit.assistant.trust-sync-manifest.v1') throw new \RuntimeException('Unbekanntes Sync-Manifest-Schema.');
        $payload=(array)($manifest['payload']??[]); $this->verifyFileHashes((array)($payload['files']??[]),$files); $source=$this->decodeJson($files['source-instance.json'],'Sync-Quellinstanz'); if($source !== (array)($payload['source']??[])) throw new \RuntimeException('Quellinstanz im Manifest stimmt nicht mit source-instance.json überein.');
        $public=['trusted-keys.json'=>$files['trusted-keys.json'],'policy.json'=>$files['policy.json'],'audit.jsonl'=>$files['audit.jsonl'],'release-catalog.json'=>$files['release-catalog.json']]; $digest=$this->digestFiles($public); if(!hash_equals((string)($source['stateDigest']??''),$digest))throw new \RuntimeException('Sync-State-Digest stimmt nicht mit den enthaltenen Public-Trust-Dateien überein.');
        return ['files'=>$files,'manifest'=>$manifest];
    }

    /** @return array<string,mixed> */
    private function verifyIncomingSyncSignature(array $payload, array $sig, array $files): array
    {
        $keyId=(string)($sig['keyId']??'');$signature=(string)($sig['signature']??'');
        $direct=$this->trust->verifyForPurpose($this->canonical($payload),$signature,$keyId,'historical');
        if(!empty($direct['ok'])){ $direct['verificationMode']='LOCAL_KEY'; return $direct; }
        $incoming=$this->decodeJson((string)($files['trusted-keys.json']??''),'Eingehender Trust-Store');
        $keys=(array)($incoming['keys']??[]);$signing=$keys[$keyId]??null;$errors=[];
        if(!is_array($signing))return ['schema'=>'easyit.assistant.trust-sync-signature-verification.v1','ok'=>false,'verdict'=>'FAIL','errors'=>['Signaturschlüssel fehlt auch im eingehenden Trust-Store.'],'keyId'=>$keyId];
        if(($signing['status']??'TRUSTED')==='REVOKED')$errors[]='Eingehender Signaturschlüssel ist widerrufen.';
        if(!in_array((string)($signing['trustLevel']??'RELEASE'),['RELEASE','VERIFY_ONLY'],true))$errors[]='Eingehender Signaturschlüssel besitzt keine ausreichende historische Vertrauensstufe.';
        $from=$this->ts($signing['validFrom']??$signing['createdAt']??null);$until=$this->ts($signing['validUntil']??null);$now=time();if($from!==null&&$now<$from)$errors[]='Eingehender Signaturschlüssel ist noch nicht gültig.';if($until!==null&&$now>$until)$errors[]='Eingehender Signaturschlüssel ist abgelaufen.';
        $seen=[];$current=$keyId;$anchored=false;
        while($current!==''){
            if(isset($seen[$current])){$errors[]='Zyklus in eingehender Trust-Chain.';break;}$seen[$current]=true;
            $k=$keys[$current]??null;if(!is_array($k)){$errors[]='Trust-Chain-Key fehlt: '.$current;break;}
            $local=$this->trust->key($current);if($local!==[]&&hash_equals((string)($local['publicKey']??''),(string)($k['publicKey']??''))){$ev=$this->trust->evaluateKey($current,'historical');if(!empty($ev['ok'])){$anchored=true;break;}$errors[]='Lokaler Trust-Anker ist nicht mehr historisch vertrauenswürdig: '.$current;break;}
            $prev=(string)($k['previousKeyId']??'');if($prev==='')break;
            $rotation=null;foreach((array)($incoming['rotations']??[]) as $r)if(is_array($r)&&($r['newKeyId']??'')===$current&&($r['previousKeyId']??'')===$prev){$rotation=$r;break;}
            $old=$keys[$prev]??null;if(!is_array($rotation)||!is_array($old)){$errors[]='Rotation/Alt-Key fehlt für '.$current;break;}
            $rp=$this->canonical(['schema'=>'easyit.assistant.release-key-transition.v1','previousKeyId'=>$prev,'newKeyId'=>$current,'newPublicKey'=>(string)($k['publicKey']??''),'rotatedAt'=>(string)($rotation['rotatedAt']??'')]);
            if(!hash_equals((string)($rotation['payloadSha256']??''),hash('sha256',$rp))){$errors[]='Rotation-Payload-SHA ungültig für '.$current;break;}
            $op=base64_decode((string)($old['publicKey']??''),true);$np=base64_decode((string)($k['publicKey']??''),true);$os=base64_decode((string)($rotation['previousSignature']??''),true);$ns=base64_decode((string)($rotation['newSignature']??''),true);
            if($op===false||$np===false||$os===false||$ns===false||!sodium_crypto_sign_verify_detached($os,$rp,$op)||!sodium_crypto_sign_verify_detached($ns,$rp,$np)){$errors[]='Rotation-Signatur ungültig für '.$current;break;}
            $current=$prev;
        }
        if(!$anchored)$errors[]='Eingehende Trust-Chain besitzt keinen bereits lokal vertrauenswürdigen Anker.';
        $pub=base64_decode((string)($signing['publicKey']??''),true);$det=base64_decode($signature,true);if($pub===false||$det===false||strlen($pub)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES||strlen($det)!==SODIUM_CRYPTO_SIGN_BYTES||!sodium_crypto_sign_verify_detached($det,$this->canonical($payload),$pub))$errors[]='Ed25519-Sync-Signatur ist kryptographisch ungültig.';
        return ['schema'=>'easyit.assistant.trust-sync-signature-verification.v1','ok'=>$errors===[],'verdict'=>$errors===[]?'PASS':'FAIL','errors'=>$errors,'keyId'=>$keyId,'verificationMode'=>'INCOMING_CHAIN_ANCHORED_LOCALLY','anchorKeyId'=>$anchored?$current:null];
    }

    private function ts(mixed $v):?int{if(!is_string($v)||trim($v)==='')return null;$t=strtotime($v);return $t===false?null:$t;}

    /** @return array<string,string> */
    private function publicStateFiles(): array
    {
        return [
            'trusted-keys.json' => $this->pretty($this->readJsonFile($this->registryPath, ['schema'=>'easyit.assistant.release-trust-store.v2','activeKeyId'=>null,'keys'=>[],'rotations'=>[]])),
            'policy.json' => $this->pretty($this->readJsonFile($this->policyPath, ['schema'=>'easyit.assistant.release-trust-policy.v1','installMinimumTrustLevel'=>'RELEASE','historicalMinimumTrustLevel'=>'VERIFY_ONLY','blockSuspended'=>true,'blockRevoked'=>true,'blockExpired'=>true])),
            'audit.jsonl' => is_file($this->auditPath) ? (string)file_get_contents($this->auditPath) : '',
            'release-catalog.json' => $this->pretty($this->readJsonFile($this->catalogPath, ['schema'=>'easyit.assistant.release-catalog.v1','entries'=>[]])),
        ];
    }

    private function publicStateDigest(): string { return $this->digestFiles($this->publicStateFiles()); }
    private function digestFiles(array $files): string { return hash('sha256', $this->canonical($this->hashFiles($files))); }
    /** @return array<string,string> */ private function hashFiles(array $files): array { $h=[]; foreach($files as $n=>$c)$h[(string)$n]=hash('sha256',(string)$c); ksort($h,SORT_STRING); return $h; }
    private function trustKeyCount(): int { return count((array)($this->readJsonFile($this->registryPath, ['keys'=>[]])['keys'] ?? [])); }

    /** @return array<string,mixed> */ private function localInstance(): array { return $this->readJsonFile($this->localPath, []); }
    /** @return array<string,mixed> */ private function requireLocal(): array { $l=$this->localInstance(); if($l===[])throw new \RuntimeException('Trust-Instanz ist noch nicht initialisiert.'); return $l; }
    /** @return array<string,mixed> */ private function requirePrimary(): array { $l=$this->requireLocal(); if(($l['role']??'')!=='PRIMARY'||($l['status']??'ACTIVE')!=='ACTIVE')throw new \RuntimeException('Diese Operation ist nur auf einer aktiven PRIMARY-Instanz zulässig.'); return $l; }
    private function normalizeRole(string $r): string { $r=strtoupper(trim($r)); if(!in_array($r,['PRIMARY','SECONDARY','VERIFY_ONLY'],true))throw new \InvalidArgumentException('Ungültige Instanzrolle.'); return $r; }

    /** @return array<string,mixed> */ private function loadPeers(): array { return $this->readJsonFile($this->peersPath, ['schema'=>'easyit.assistant.trust-peer-registry.v1','peers'=>[]]); }
    private function registerPeer(array $source,string $state,string $digest,string $keyId): void { $id=(string)($source['instanceId']??''); if($id==='')return; $p=$this->loadPeers(); $p['peers'][$id]=['schema'=>'easyit.assistant.trust-peer.v1','instanceId'=>$id,'name'=>$source['name']??$id,'role'=>$source['role']??null,'lastSeenAt'=>gmdate('c'),'lastStateDigest'=>$digest,'lastSequence'=>$source['sequence']??null,'syncState'=>$state,'sourceKeyId'=>$keyId]; $p['updatedAt']=gmdate('c'); $this->atomicJson($this->peersPath,$p,0640); }

    private function federationAudit(string $action,string $peerId,array $details=[]): void { $this->ensureDir($this->instancesDir); $prev=''; if(is_file($this->federationAuditPath)){ $lines=file($this->federationAuditPath,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[]; $last=end($lines); if(is_string($last)){ $x=json_decode($last,true); if(is_array($x))$prev=(string)($x['eventHash']??''); } } $e=['schema'=>'easyit.assistant.trust-federation-audit-event.v1','eventId'=>'federation-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)),'action'=>$action,'peerId'=>$peerId,'occurredAt'=>gmdate('c'),'previousHash'=>$prev,'details'=>$details]; $e['eventHash']=hash('sha256',$this->canonical($e)); file_put_contents($this->federationAuditPath,json_encode($e,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX); @chmod($this->federationAuditPath,0640); }

    /** @return array<string,mixed> */ private function readJsonFile(string $path,array $default): array { if(!is_file($path))return $default; $d=json_decode((string)file_get_contents($path),true); return is_array($d)?$d:$default; }
    private function atomicJson(string $path,array $data,int $mode): void { $this->atomicRaw($path,$this->pretty($data),$mode); }
    private function atomicRaw(string $path,string $content,int $mode): void { $this->ensureDir(dirname($path)); $tmp=$path.'.tmp.'.bin2hex(random_bytes(4)); if(file_put_contents($tmp,$content,LOCK_EX)===false||!rename($tmp,$path)){@unlink($tmp);throw new \RuntimeException('Datei kann nicht atomar gespeichert werden: '.$path);}@chmod($path,$mode); }
    private function ensureDir(string $dir): void { if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new \RuntimeException('Verzeichnis kann nicht erstellt werden: '.$dir); }
    private function pretty(array $d): string { return json_encode($d,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n"; }
    /** @param mixed $v */ private function normalize($v){ if(!is_array($v))return $v; if(array_is_list($v))return array_map(fn($x)=>$this->normalize($x),$v); ksort($v,SORT_STRING); foreach($v as $k=>$x)$v[$k]=$this->normalize($x); return $v; }
    private function canonical(array $d): string { return json_encode($this->normalize($d),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); }
    private function safe(string $s): string { return trim((string)preg_replace('/[^A-Za-z0-9._-]+/','-',trim($s)),'-')?:'instance'; }
    private function withoutPath(array $a): array { unset($a['path']); return $a; }
    private function countFiles(string $dir,string $pattern): int { return is_dir($dir)?count(glob($dir.'/'.$pattern)?:[]):0; }
    /** @return array<string,mixed> */ private function decodeJson(string $raw,string $what): array { $d=json_decode($raw,true); if(!is_array($d))throw new \RuntimeException($what.' ist kein gültiges JSON.'); return $d; }
    private function verifyFileHashes(array $hashes,array $files): void { foreach($hashes as $name=>$sha){ if(!array_key_exists((string)$name,$files)||!hash_equals((string)$sha,hash('sha256',(string)$files[$name])))throw new \RuntimeException('Bundle-Dateiprüfsumme stimmt nicht: '.(string)$name); } }

    /** @return array<string,string> */
    private function readZipStrict(string $path,array $allowed): array
    {
        if(!is_file($path))throw new \RuntimeException('ZIP wurde nicht gefunden.'); if(filesize($path)>50*1024*1024)throw new \RuntimeException('ZIP ist zu groß.');
        try{$z=new \PharData($path);}catch(\Throwable $e){throw new \RuntimeException('ZIP kann nicht geöffnet werden: '.$e->getMessage(),0,$e);} $out=[];$map=array_fill_keys($allowed,true);$prefix='phar://'.str_replace('\\','/',$path).'/';$it=new \RecursiveIteratorIterator($z);
        foreach($it as $f){$n=str_replace('\\','/',$f->getPathname());if(str_starts_with($n,$prefix))$n=substr($n,strlen($prefix));if($n===''||str_ends_with($n,'/'))continue;if(str_starts_with($n,'/')||preg_match('#(^|/)\.\.(/|$)#',$n)||str_contains($n,'\\')||!isset($map[$n])){unset($z);throw new \RuntimeException('Unerwarteter oder unsicherer ZIP-Eintrag: '.$n);} $out[$n]=(string)$z[$n]->getContent();}
        unset($z); foreach($allowed as $n)if(!array_key_exists($n,$out))throw new \RuntimeException('Erforderlicher ZIP-Eintrag fehlt: '.$n); return $out;
    }
    private function writeZip(string $path,array $files): void { if(is_file($path))@unlink($path); try{$z=new \PharData($path,0,null,\Phar::ZIP);foreach($files as $n=>$c)$z->addFromString((string)$n,(string)$c);unset($z);}catch(\Throwable $e){@unlink($path);throw new \RuntimeException('ZIP kann nicht erstellt werden: '.$e->getMessage(),0,$e);}@chmod($path,0640); }
}
