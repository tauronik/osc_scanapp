# 📱 Web-basierte QR/Barcode ScannerApp für osConcert

## 🎯 Projektbeschreibung

Eine einfache, browserbasierte Webanwendung zum Scannen von QR-Codes und Barcodes für osConcert. Der gescannte Inhalt wird automatisch an eine Ziel-URL übergeben und das Ergebnis im Browser angezeigt. Ersetzt die proprietäre "pic2Shop PRO" App.

**Technologie-Stack:**
- HTML5 + CSS3 + JavaScript (Vanilla ES6+)
- ZXing Library (@zxing/library) via CDN
- LAMP-Server (Linux, Apache, MySQL, PHP)
- Mobile-first Design
- Web Audio API für Sound-Feedback (keine externen Dateien)

---

## 📋 Voraussetzungen

- Webserver mit HTTPS (Let's Encrypt empfohlen) - **Erforderlich für Camera API**
- Moderne Browser (Chrome, Safari, Firefox, Edge)
- Mobile Devices: iOS 11+ / Android 5.0+
- osConcert Installation mit `includes/application_top.php` und `includes/configure.php`

---

## 🚀 Installation

### 1. Datei erstellen

Erstellen Sie eine neue Datei `scanapp.php` im gewünschten Verzeichnis:

```bash
touch scanapp.php
```

### 2. Code einfügen

Kopieren Sie den vollständigen Code aus `scanapp.php` in die Datei.

### 3. Berechtigungen setzen

Stellen Sie sicher, dass die Datei vom Webserver lesbar ist:

```bash
chmod 644 scanapp.php
```

### 4. Zugriff konfigurieren

Rufen Sie die App über einen modernen Browser auf:
- URL: `https://ihre-domain.com/scanapp.php?location=geraetename`
- Beispiel: `https://concert.example.com/scanapp.php?location=door1`

---

## ⚙️ Konfiguration

### Location-Parameter

Die `location` identifiziert das Scan-Gerät und wird in der Datenbank protokolliert.

- **URL-Parameter:** `?location=geraetename` (hat höchste Priorität)
- **Cookie:** Wird im Browser gespeichert (`scanner_location`)
- **Default:** `dev1` (wenn nichts anderes konfiguriert)

**Bearbeitung:** Klicken Sie auf die Location-Anzeige oben im Header, um den Wert zu ändern. Der neue Wert wird im Cookie gespeichert.

### Debug-Modus

Setzen Sie `$debug = true;` in der PHP-Sektion, um detaillierte Fehlermeldungen zu erhalten.

---

## 🔧 Technische Entscheidungen

### Warum ZXing?
- Open Source (Apache 2.0 License)
- Unterstützt QR, Code 128, Code 39, EAN, UPC und mehr
- Aktive Community und regelmäßige Updates
- Läuft komplett im Browser (kein Server-Processing der Bilder)

### Warum Web Audio API?
- Keine externen MP3-Dateien erforderlich
- Sofortiges Feedback ohne Ladezeiten
- Funktioniert offline nach erstem Laden

### Single-File Ansatz
- Einfache Integration in bestehende osConcert-Installationen
- Keine zusätzlichen Ordner oder Abhängigkeiten
- Leicht zu warten und zu aktualisieren

---

## 🔄 Workflow

1. **Start:** Benutzer öffnet `scanapp.php`
2. **Kamera:** Browser fragt Kamerazugriff an → Benutzer erlaubt
3. **Scannen:** Kamera erfasst Barcode/QR-Code automatisch
4. **Validierung:** Code wird an Server gesendet → Backend prüft Datenbank
5. **Ergebnis:** 
   - ✅ Erfolg (Grün): Ticket gültig
   - ❌ Fehler (Rot): Ticket ungültig/nicht gefunden
   - ⚠️ Warnung (Orange): Bereits gescannt
   - ℹ️ Extra/Like (Blau): Spezialtickets
6. **Bestätigung:** Benutzer klickt "SCAN NEXT" für nächsten Scan
7. **Wiederholung:** Kamera startet sofort neu

---

## 🐛 Troubleshooting

### Kamera wird nicht erkannt
- **HTTPS prüfen:** Camera API funktioniert nur über HTTPS (oder localhost)
- **Browser-Berechtigungen:** Kamera-Zugriff im Browser erlauben
- **Device-Kompatibilität:** Ältere Geräte unterstützen ggf. `getUserMedia` nicht

### ZXing Library lädt nicht
- **Internet-Verbindung:** CDN erfordert Online-Zugriff beim ersten Laden
- **Firewall:** Stellen Sie sicher, dass `unpkg.com` erreichbar ist

### Falsche Ergebnisse
- **Datenbank-Verbindung:** Prüfen Sie `includes/configure.php`
- **Session-Timeout:** Login kann abgelaufen sein
- **Debug-Modus:** Aktivieren für detaillierte Logs

---

## 📝 Versionshistorie

### Version 1.5 - Konfigurierbare Sicherheitsmodi
- Drei Sicherheitsmodi: `none`, `login` und numerische PIN
- **none**: Direktzugriff ohne Authentifizierung (vertrauenswürdige Umgebungen)
- **login**: Box-Office-Login mit E-Mail/Passwort (prüft country_id = 999)
- **PIN**: 5-12 stellige statische PIN in scanapp.php konfiguriert
- Konfigurierbar über `$security` Variable am Anfang der scanapp.php

### Version 1.4 - PIN-Event-Zugriffskontrolle
- 5-12 stellige PIN-Authentifizierung für Zugriffskontrolle
- Vollbild-PIN-Modal beim ersten Zugriff
- Event-Wechsel-Button (🔄) zum Wechseln der Events
- Session-basierte PIN-Validierung (läuft ab wenn Browser geschlossen wird)

### Version 1.0 (Initiale Version)
- Ersetzt pic2Shop PRO durch HTML5 Camera API
- Integriert ZXing Library
- Web Audio API für Sound-Feedback
- Backend-Validierung über osConcert-Datenbank

### Version 1.1 (Usability-Update)
- "SCAN NEXT" Button startet Scan direkt neu (kein Return-to-Start)
- Fließender Übergang zwischen Scans

### Version 1.2 (Device-Konfiguration)
- Location als editierbarer Header
- Cookie-basierte Speicherung pro Gerät
- "SCAN NEXT" immer sichtbar für manuelle Bestätigung

---

## 📄 Lizenz

Dieses Projekt nutzt folgende Open-Source-Komponenten:
- **ZXing-js/library:** Apache 2.0 License
- **osConcert:** Eigene Lizenz (siehe osConcert-Dokumentation)

Der eigene Code folgt der Lizenz von osConcert.

---

## 🔗 Links

- [ZXing GitHub](https://github.com/zxing-js/library)
- [MDN: getUserMedia API](https://developer.mozilla.org/en-US/docs/Web/API/MediaDevices/getUserMedia)
- [Web Audio API](https://developer.mozilla.org/en-US/docs/Web/API/Web_Audio_API)
- [pic2Shop PRO (Legacy)](https://www.visionsmarts.com/apps/pic2shop/pro_version.html)
