# Symcon-Dreame

IP-Symcon-Modul zur Steuerung von **Dreame-Saugrobotern** (getestet mit **X50 Ultra Complete**, `dreame.vacuum.r2532a`) über die Dreame-eigene Cloud (**dreamehome**).

> Der X50 bietet **keinen** lokalen Direktzugriff (kein miIO/Port 54321, keine offenen Ports) und lässt sich **nicht** in Mi Home einbinden. Steuerung ist daher nur über die Dreame-Cloud möglich. Das Modul läuft dabei komplett lokal auf deiner Symcon-Instanz — nur der Steuerkanal geht über Dreames Server.

## Funktionen

- **Login** in die dreamehome-Cloud (OAuth password-grant), Token wird gecacht/erneuert.
- **Status-Variablen:** Zustand (Klartext), Akku, Fehler (Klartext), Reinigungszeit, gereinigte Fläche.
- **Aktionen** (Variable *Aktion*): Alles reinigen · Pause · Stopp · Zur Basis · Orten · Ausgewählte Räume reinigen · Auswahl leeren.
- **Kartenwahl** (Variable *Karte*): bei mehreren Etagen die aktive Karte wählen — das Gerät schaltet um (Action 6/2), und **alles Raumbezogene richtet sich danach**: *Raum reinigen* zeigt nur die Räume dieser Karte, die Schalter *Auswahl &lt;Raum&gt;* der anderen Karten sind versteckt, abgewählt und nicht schaltbar. Zusätzlich gibt es je Karte ein Boolean *Karte &lt;Etage&gt; aktiv* — damit kann eine Visualisierung ihre Raumtasten ein-/ausblenden (in IPSView: *Variable Sichtbarkeit*).
- **Raumreinigung** (Variable *Raum reinigen*): Dropdown mit den Räumen der aktiven Karte — Auswahl startet sofort.
- **Mehrere Räume in einem Durchgang**: pro Raum ein Schalter *Auswahl &lt;Raum&gt;*. Die gewünschten Räume einschalten, dann in *Aktion* → *Ausgewählte Räume reinigen*. Alle Räume gehen in **einem** Befehl an den Roboter; danach wird die Auswahl automatisch geleert.
- **Vorwahlen vor dem Start** — jeweils mit Option *Geräteeinstellung belassen*:
  - *Reinigungsmodus*: nur kehren · nur wischen · zusammen · erst kehren, dann wischen (Property 4/23, gepackt)
  - *Reinigungsroute*: Standard · Intensiv · Tiefenreinigung · Schnell (Schlüssel `CleanRoute` in Property 4/50)
  - *Saugkraft* (4/4), *Wischfeuchte* (4/5, bei Waschstation = Moppfeuchte), *Durchgänge* (1–3×)
- **Diagnose** *Reinigungsmodus (Gerät)*, *Reinigungsroute (Gerät)* und *Individuelle Raumeinstellungen (Gerät)* zeigen, was der Roboter wirklich gesetzt hat.
- **Raumfilter in der Konfiguration**: die Liste *Räume* bestimmt über die Spalte *Verwenden*, welche Segmente überhaupt angeboten werden. Dreame legt beim Kartieren gern Räume an, die es nicht gibt (Blick durch Fenster/Glastüren) — die lassen sich hier abwählen.
- **Karten & Räume werden dynamisch eingelesen** — Etagen und Raumnamen/-IDs kommen direkt aus der Karte (base64+zlib+JSON-Dekodierung in PHP). Bei mehreren Etagen wird vor der Raumreinigung automatisch auf die richtige Karte umgeschaltet.
- **Polling** per Timer (konfigurierbar), Schnell-Poll nach Aktionen.

## Einrichtung

1. Modul hinzufügen (Module Control → per Git-URL `https://github.com/JLDFACE/Symcon-Dreame` oder lokal in den `modules`-Ordner legen).
2. Neue Instanz **„Dreame"** anlegen.
3. E-Mail + Passwort deines dreamehome-Kontos eintragen, Region wählen (DE = `eu`).
4. **„Login testen"** → sollte Gerät + did anzeigen.
5. **„Karten & Räume einlesen"** → füllt die Liste *Räume*, das Dropdown *Raum reinigen* und die Auswahl-Schalter.
6. In der Liste *Räume* nicht existierende Räume abwählen → **„Änderungen übernehmen"**.

## Skript-Aufrufe

```php
DREAME_CleanRoom($id, 10, 5);            // ein Raum (Karten-ID, Segment-ID)
DREAME_CleanRooms($id, 10, '5,6,7');     // mehrere Räume in einem Durchgang
DREAME_CleanRooms($id, 0, [10005, 10006]); // Karten-ID 0 = ableiten; Profilcodes erlaubt
DREAME_CleanSelectedRooms($id);          // alle angehakten Schalter
DREAME_ClearRoomSelection($id);
```

## Hinweise

- Erfordert einen gefüllten **Frischwassertank** für Wisch-/Raumreinigung (sonst Fehler 107).
- Die Variable *Fehler* zeigt alle ~140 Codes im Klartext. Codes, die der Roboter selbst nur als quittierbare Warnung führt, tragen **hinten** ein `· Hinweis` — z. B. `Wischen beendet – Mopp abnehmen und reinigen (68) · Hinweis`. Das ist keine Störung. (Der Zusatz stand früher vorn; in schmalen Anzeigefeldern verdrängt er dann die eigentliche Meldung.)
- Nach Umbau/Neukartierung erneut **„Karten & Räume einlesen"**. Neue Räume sind zunächst aktiv; Schalter abgewählter oder verschwundener Räume werden entfernt.
- Ein Durchgang deckt nur **eine Etage** ab — eine Auswahl über mehrere Karten wird abgelehnt.
- **Reihenfolge der Räume** bestimmt der Roboter (Reinigungsreihenfolge aus der App). Das Modul schickt im 5. Feld je Raum bewusst konstant `1`: Geräte mit *Individuelle Raumeinstellungen* (Property 4/26, u. a. der X50) brechen den Auftrag ab, wenn dort hochgezählt wird — so hält es auch die Referenz-Implementierung.
- Ist am Gerät **Individuelle Raumeinstellungen** aktiv, gelten die je Raum in der App gespeicherten Werte; Saugkraft, Wischfeuchte und Durchgänge aus dem Modul werden dann ignoriert. Die Diagnosevariable zeigt den Zustand.
- *Intensiv*/*Tiefenreinigung* wirken auf das Wischen. Bei rein kehrenden Modi meldet das Gerät die Route wieder als *Standard* zurück.
- Kompatibilität: IP-Symcon 7.0.

## Technik

Reverse-engineert aus [Tasshack/dreame-vacuum](https://github.com/Tasshack/dreame-vacuum) (dev-Branch, Dreame-Cloud-Protokoll). Endpunkt `https://<region>.iot.dreame.tech:13267`, MiOT über `.../device/sendCommand`. Kein Fremd-Framework nötig (nur cURL/JSON/MD5).

---
FACE GmbH / JLD
