# HF111 – Lizenzserver-Vertrauensbeziehung reparieren

HF111 behebt den Fall, dass ein Enterprise-Manager noch einen alten Ed25519-Vertrauensanker gespeichert hat, während der Lizenzserver nach Neuinstallation oder bewusstem Schlüsselwechsel mit einem neuen Server-Schlüssel signiert.

Der Manager vertraut einem geänderten Schlüssel niemals automatisch. Die lokale Administration kann stattdessen den aktuellen Server-Schlüssel über `/api/v1/health` lesen. Die Health-Antwort wird gegen genau den darin veröffentlichten Ed25519-Public-Key verifiziert, sodass der Server den zugehörigen Private Key nachweisen muss. Bei entfernten Servern bleibt HTTPS mit Zertifikatsprüfung zwingend.

Der Administrator sieht die bisher vertraute Key-ID und den bisherigen SHA-256-Fingerprint sowie die neu gelesene Key-ID und deren SHA-256-Fingerprint. Erst nach der exakten Bestätigung `NEU KOPPELN` wird der statische Vertrauensanker ersetzt. Vor dem Ersetzen wird der Server erneut abgefragt; stimmt der Fingerprint nicht mehr mit dem zuvor geprüften Fingerprint überein, wird der Vorgang abgebrochen.

Die lokale Installationsidentität, das Installations-Schlüsselpaar und der vorhandene Aktivierungszustand werden dabei nicht gelöscht. Lediglich der Server-Trust-Cache (`dynamic_server_keys`, `revoked_server_key_ids`, `active_server_key_id`) wird auf den ausdrücklich bestätigten neuen Vertrauensanker zurückgesetzt. Die letzten Neu-Kopplungen werden lokal als Fingerprint-Historie protokolliert.

Auf der Kundenanmeldung wird bei `SERVER_SIGNATURE_UNTRUSTED` oder `SERVER_SIGNATURE_INVALID` zusätzlich ein direkter Einstieg in die lokale Administration angeboten.
