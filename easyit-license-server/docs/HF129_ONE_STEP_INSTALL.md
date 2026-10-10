# HF129 – Lokale Ein-Schritt-Bootstrap-Aktivierung

Der Lizenzserver stellt zusätzlich `POST /api/v1/bootstrap/local-activate` bereit. Der Endpoint akzeptiert ausschließlich Loopback-Aufrufe (`127.0.0.1` oder `::1`). Der einmalige Bootstrap-Schlüssel wird innerhalb des Serverprozesses erzeugt, direkt an den vorhandenen ActivationService übergeben und bei erfolgreicher Aktivierung sofort verbraucht. Der Schlüssel verlässt den Lizenzserver nicht.

Die bestehende manuelle Bootstrap-Schlüsselerzeugung bleibt für Recovery bestehen und ist im Admin-UI entsprechend gekennzeichnet.
