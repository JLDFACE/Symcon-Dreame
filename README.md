# Symcon-Dreame

IP-Symcon-Modul zur Steuerung von **Dreame-Saugrobotern** (getestet mit **X50 Ultra Complete**, `dreame.vacuum.r2532a`) über die Dreame-eigene Cloud (**dreamehome**).

> Der X50 bietet **keinen** lokalen Direktzugriff (kein miIO/Port 54321, keine offenen Ports) und lässt sich **nicht** in Mi Home einbinden. Steuerung ist daher nur über die Dreame-Cloud möglich. Das Modul läuft dabei komplett lokal auf deiner Symcon-Instanz — nur der Steuerkanal geht über Dreames Server.

## Funktionen

- **Login** in die dreamehome-Cloud (OAuth password-grant), Token wird gecacht/erneuert.
- **Status-Variablen:** Zustand (Klartext), Akku, Fehler (Klartext), Reinigungszeit, gereinigte Fläche.
- **Aktionen** (Variable *Aktion*): Alles reinigen · Pause · Stopp · Zur Basis · Orten.
- **Raumreinigung** (Variable *Raum reinigen*): Dropdown mit allen Räumen aller Etagen.
- **Karten & Räume werden dynamisch eingelesen** — Etagen und Raumnamen/-IDs kommen direkt aus der Karte (base64+zlib+JSON-Dekodierung in PHP). Bei mehreren Etagen wird vor der Raumreinigung automatisch auf die richtige Karte umgeschaltet.
- **Polling** per Timer (konfigurierbar), Schnell-Poll nach Aktionen.

## Einrichtung

1. Modul hinzufügen (Module Control → per Git-URL `https://github.com/JLDFACE/Symcon-Dreame` oder lokal in den `modules`-Ordner legen).
2. Neue Instanz **„Dreame"** anlegen.
3. E-Mail + Passwort deines dreamehome-Kontos eintragen, Region wählen (DE = `eu`).
4. **„Login testen"** → sollte Gerät + did anzeigen.
5. **„Karten & Räume einlesen"** → füllt das Dropdown *Raum reinigen* und die Liste in der Konfig.

## Hinweise

- Erfordert einen gefüllten **Frischwassertank** für Wisch-/Raumreinigung (sonst Fehler 107).
- Nach Umbau/Neukartierung erneut **„Karten & Räume einlesen"**.
- Kompatibilität: IP-Symcon 7.0.

## Technik

Reverse-engineert aus [Tasshack/dreame-vacuum](https://github.com/Tasshack/dreame-vacuum) (dev-Branch, Dreame-Cloud-Protokoll). Endpunkt `https://<region>.iot.dreame.tech:13267`, MiOT über `.../device/sendCommand`. Kein Fremd-Framework nötig (nur cURL/JSON/MD5).

---
FACE GmbH / JLD
