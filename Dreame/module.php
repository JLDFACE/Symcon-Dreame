<?php

/*
 * Dreame Robot Vacuum (X50 Ultra & Co.) - IP-Symcon Modul
 *
 * Designentscheidungen (kurz):
 * - SymBox-sicher: kein strict_types, keine PHP8-Typen, keine globalen Funktionen ausserhalb der Klasse.
 * - Kein IO-Parent: spricht direkt die Dreame-eigene Cloud (dreamehome) per HTTPS (curl).
 *   Der X50 bietet KEINEN lokalen Direktzugriff (kein miIO/54321), daher zwingend Cloud.
 * - Auth: OAuth "password"-Grant gegen https://<region>.iot.dreame.tech:13267/dreame-auth/oauth/token
 *   Passwort = md5(passwort + Salt). Token (access/refresh/uid/tenant) wird als Attribut gecacht.
 * - Steuerung/Status: MiOT ueber .../device/sendCommand (method get_properties / action).
 * - Karten & Raeume: werden dynamisch aus der Kartendatei (OSS) gelesen und dekodiert
 *   (JSON -> mapstr[] -> base64+zlib -> Trailer-JSON -> seg_inf). Raeume landen in einem
 *   instanzspezifischen Variablenprofil (Auswahl "Etage . Raum").
 * - Raumfilter: die Liste "Raeume" im Konfigurationsformular (Property RoomFilter) entscheidet,
 *   welche Segmente ueberhaupt angeboten werden. Dreame legt beim Kartieren gern Phantomraeume
 *   an (Blick durch Fenster/Glastueren); die lassen sich hier abwaehlen.
 * - Mehrere Raeume je Durchgang: pro relevantem Raum gibt es einen Schalter "Auswahl <Raum>";
 *   die Aktion "Ausgewaehlte Raeume reinigen" schickt alle angehakten Raeume in EINEM
 *   MiOT-Aufruf (CLEANING_PROPERTIES {"selects":[[seg,Durchgaenge,Saugkraft,Wasser,Reihenfolge],...]}).
 * - Polling: ein Timer (UpdateInterval) fuer den Status; kurzer Fast-Poll nach Aktionen.
 * - Stabilitaet: Semaphore-Lock, niemals Fatals; Online/LastError nur bei Aenderung.
 *
 * MiOT-Kennungen (dreame.vacuum.r2532a / 5. Gen):
 *   State 2/1, Error 2/2, Akku 3/1, Charging 3/2, Status 4/1, Reinigungszeit 4/2,
 *   Flaeche 4/3, Saugkraft 4/4, Wasser/Moppfeuchte 4/5, Wassertank 4/6, Task 4/7,
 *   CLEANING_PROPERTIES 4/10, Reinigungsmodus 4/23 (gepackt), CUSTOMIZED_CLEANING 4/26,
 *   AUTO_SWITCH_SETTINGS 4/50 (JSON k/v, u. a. "CleanRoute"), MAP_EXTEND 6/4, MAP_LIST 6/8.
 *   Aktionen: Alles=2/1, Pause=2/2, ZurBasis=3/1, RaumStart=4/1, Stop=4/2, WarnungLoeschen=4/3, Orten=7/1, Kartenwechsel=6/2.
 */

class DREAME extends IPSModule
{
    const SALT      = 'RAylYC%fmSKp7%Tq';
    const UA        = 'Dreame_Smarthome/2.1.9 (iPhone; iOS 18.4.1; Scale/3.00)';
    const BASIC     = 'Basic ZHJlYW1lX2FwcHYxOkFQXmR2QHpAU1FZVnhOODg=';
    const PORT      = 13267;

    public function Create()
    {
        parent::Create();

        // ---- Properties ----
        $this->RegisterPropertyString('Email', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyString('Region', 'eu');
        $this->RegisterPropertyInteger('UpdateInterval', 60);
        $this->RegisterPropertyInteger('FastAfterChange', 20);
        // Raumfilter: [{use,mapid,seg,...}] - leer/unbekannt = Raum wird verwendet
        $this->RegisterPropertyString('RoomFilter', '[]');

        // ---- Attribute (persistent) ----
        $this->RegisterAttributeString('Auth', '');       // Token-Cache {access,refresh,exp,uid,tenant}
        $this->RegisterAttributeString('Device', '');      // {did,model,host}
        $this->RegisterAttributeString('MapsRooms', '');   // [{map_id,floor,rooms:[{seg,name}]}]
        $this->RegisterAttributeInteger('SelectedMap', -1);  // gewaehlte Karte (Anzeige/Ziel)
        $this->RegisterAttributeInteger('DeviceMap', -1);    // Karte, auf die das Geraet zuletzt umgeschaltet wurde
        $this->RegisterAttributeInteger('RoomCleaning', 0); // 1, sobald nach einer Raumauswahl aktiv gereinigt wurde
        $this->RegisterAttributeInteger('CleanModeDefaulted', 0); // 1, sobald der Reinigungsmodus initial vorbelegt wurde
        $this->RegisterAttributeInteger('RunSettingsDefaulted', 0); // 1, sobald Route/Saugkraft/Feuchte/Durchgaenge vorbelegt wurden

        // ---- Buffers ----
        $this->SetBuffer('FastUntil', '0');

        // ---- Diagnose ----
        $this->RegisterVariableBoolean('Online', 'Online', '~Switch', 10);
        IPS_SetIcon($this->GetIDForIdent('Online'), 'Network');
        $this->DisableAction('Online');
        $this->RegisterVariableString('LastError', 'Letzter Fehler', '~TextBox', 20);

        // ---- Status ----
        $this->RegisterVariableString('Zustand', 'Zustand', '~TextBox', 30);
        $this->RegisterVariableInteger('Battery', 'Akku', '~Battery.100', 40);
        $this->RegisterVariableString('Fehler', 'Fehler', '~TextBox', 50);
        $this->RegisterVariableInteger('CleaningTime', 'Reinigungszeit (min)', '', 60);
        $this->RegisterVariableFloat('CleaningArea', 'Gereinigte Fläche (m²)', '', 70);

        // ---- Steuerung: Aktion (Dropdown) ----
        $this->RegisterProfileInteger('DREAME.Action', 'Execute', '', '', 0, 0, 0);
        $this->SetAssociations('DREAME.Action', [
            [0, '—', '', -1],
            [1, 'Alles reinigen', '', 0x00A000],
            [2, 'Pause', '', -1],
            [3, 'Stopp', '', -1],
            [4, 'Zur Basis', '', -1],
            [5, 'Orten (Piepen)', '', -1],
            [6, 'Ausgewählte Räume reinigen', '', 0x00A000],
            [7, 'Auswahl leeren', '', -1]
        ]);
        $this->RegisterVariableInteger('Aktion', 'Aktion', 'DREAME.Action', 80);
        $this->EnableAction('Aktion');

        // ---- Steuerung: Reinigungsmodus (Dropdown) ----
        // Wird vor dem Start ins Modusfeld (untere 2 Bits) der gepackten Property
        // siid 4 / piid 23 geschrieben (Details siehe ApplyCleaningMode). Am r2532a verifiziert.
        $this->RegisterProfileInteger('DREAME.CleanMode', 'Brush', '', '', 0, 0, 0);
        $this->SetAssociations('DREAME.CleanMode', [
            [-1, 'Geräteeinstellung belassen', '', -1],
            [0, 'Nur kehren', '', 0x00A000],
            [1, 'Nur wischen', '', 0x0080FF],
            [2, 'Kehren & wischen (zusammen)', '', 0x00A000],
            [3, 'Erst kehren, dann wischen', '', 0xA0A000]
        ]);
        $this->RegisterVariableInteger('CleanMode', 'Reinigungsmodus', 'DREAME.CleanMode', 85);
        $this->EnableAction('CleanMode');

        // ---- Steuerung: Reinigungsroute / Intensitaet (Dropdown) ----
        // Sitzt in der gepackten JSON-Property AUTO_SWITCH_SETTINGS (siid 4 / piid 50)
        // unter dem Schluessel "CleanRoute". Wirkt vor allem beim Wischen: bei rein
        // kehrenden Modi meldet das Geraet Intensiv/Tief wieder als Standard zurueck.
        $this->RegisterProfileInteger('DREAME.CleanRoute', 'Move', '', '', 0, 0, 0);
        $this->SetAssociations('DREAME.CleanRoute', [
            [-1, 'Geräteeinstellung belassen', '', -1],
            [1, 'Standardreinigung', '', -1],
            [2, 'Intensivreinigung', '', -1],
            [3, 'Tiefenreinigung', '', -1],
            [4, 'Schnellreinigung', '', -1]
        ]);
        $this->RegisterVariableInteger('CleanRoute', 'Reinigungsroute', 'DREAME.CleanRoute', 86);
        $this->EnableAction('CleanRoute');

        // ---- Steuerung: Saugkraft (siid 4 / piid 4) ----
        $this->RegisterProfileInteger('DREAME.Suction', 'Ventilation', '', '', 0, 0, 0);
        $this->SetAssociations('DREAME.Suction', [
            [-1, 'Geräteeinstellung belassen', '', -1],
            [0, 'Leise', '', -1],
            [1, 'Standard', '', -1],
            [2, 'Stark', '', -1],
            [3, 'Turbo', '', -1]
        ]);
        $this->RegisterVariableInteger('Suction', 'Saugkraft', 'DREAME.Suction', 87);
        $this->EnableAction('Suction');

        // ---- Steuerung: Wischfeuchte (siid 4 / piid 5) ----
        // Bei Geraeten mit Waschstation (X50) ist das die Moppfeuchte, nicht die Wassermenge.
        $this->RegisterProfileInteger('DREAME.Water', 'Drops', '', '', 0, 0, 0);
        $this->SetAssociations('DREAME.Water', [
            [-1, 'Geräteeinstellung belassen', '', -1],
            [1, 'Leicht feucht', '', -1],
            [2, 'Feucht', '', -1],
            [3, 'Nass', '', -1]
        ]);
        $this->RegisterVariableInteger('Water', 'Wischfeuchte', 'DREAME.Water', 88);
        $this->EnableAction('Water');

        // ---- Steuerung: Durchgaenge je Raum (2. Feld je selects-Eintrag) ----
        $this->RegisterProfileInteger('DREAME.Repeats', 'Repeat', '', '', 0, 0, 0);
        $this->SetAssociations('DREAME.Repeats', [
            [1, '1× (Standard)', '', -1],
            [2, '2×', '', -1],
            [3, '3×', '', -1]
        ]);
        $this->RegisterVariableInteger('Repeats', 'Durchgänge', 'DREAME.Repeats', 89);
        $this->EnableAction('Repeats');

        // ---- Diagnose: was das Geraet tatsaechlich gesetzt hat ----
        $this->RegisterVariableString('DeviceMode', 'Reinigungsmodus (Gerät)', '~TextBox', 95);
        $this->RegisterVariableString('DeviceRoute', 'Reinigungsroute (Gerät)', '~TextBox', 96);
        // Ist diese Option am Geraet aktiv, benutzt der Roboter die je Raum in der App
        // gespeicherten Werte und ignoriert Saugkraft/Feuchte/Durchgaenge aus diesem Modul.
        $this->RegisterVariableBoolean('CustomRoomSettings', 'Individuelle Raumeinstellungen (Gerät)', '~Switch', 97);
        IPS_SetIcon($this->GetIDForIdent('CustomRoomSettings'), 'Brush');
        $this->DisableAction('CustomRoomSettings');

        // ---- Steuerung: Raum (Dropdown, dynamisch) ----
        // Einzelraum: Auswahl startet sofort. Fuer mehrere Raeume je Durchgang siehe
        // die Schalter "Auswahl <Raum>" (Position ab 100) + Aktion "Ausgewaehlte Raeume reinigen".
        $roomProfile = $this->RoomProfileName();
        $this->RegisterProfileInteger($roomProfile, 'Move', '', '', 0, 0, 0);
        $this->SetAssociations($roomProfile, [[0, '— Raum wählen —', '', -1]]);
        $this->RegisterVariableInteger('Raum', 'Raum reinigen', $roomProfile, 90);
        $this->EnableAction('Raum');

        // ---- Steuerung: Karte (Etage) ----
        // Der Roboter arbeitet immer auf EINER Karte. Bisher war das nur ein Attribut
        // (SelectedMap) und wurde stillschweigend beim Raumstart umgeschaltet. Als
        // Variable kann man die Karte bewusst waehlen, und alles Raumbezogene richtet
        // sich danach: das Dropdown "Raum reinigen" zeigt nur ihre Raeume, die
        // Auswahlschalter der anderen Karten sind versteckt und nicht schaltbar.
        $mapProfile = $this->MapProfileName();
        $this->RegisterProfileInteger($mapProfile, 'Move', '', '', 0, 0, 0);
        $this->SetAssociations($mapProfile, [[0, '— keine Karte —', '', -1]]);
        $this->RegisterVariableInteger('Karte', 'Karte', $mapProfile, 91);
        $this->EnableAction('Karte');

        // ---- Timer ----
        $this->RegisterTimer('Poll', 0, 'DREAME_UpdateStatus($_IPS["TARGET"]);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Token-/Geraete-Cache verwerfen, damit Konfigaenderungen frisch greifen
        $this->WriteAttributeString('Auth', '');
        $this->WriteAttributeString('Device', '');

        // Karten-/Raumprofil + Auswahlschalter aus gespeicherten Karten/Filter wiederherstellen
        $this->RebuildMapProfile();
        $this->SyncRoomSwitches();
        $this->ApplyMapSelection();   // enthaelt RebuildRoomProfile()

        // Alte ASCII-Variablennamen auf Umlaute migrieren (nur solange noch Standardname)
        $areaId = @$this->GetIDForIdent('CleaningArea');
        if ($areaId && IPS_GetName($areaId) == 'Gereinigte Flaeche (m2)') {
            IPS_SetName($areaId, 'Gereinigte Fläche (m²)');
        }

        // Reinigungsmodus einmalig auf "belassen" (-1) vorbelegen -> keine Verhaltensaenderung
        if ($this->ReadAttributeInteger('CleanModeDefaulted') == 0) {
            $this->SetValueIntegerSafe('CleanMode', -1);
            $this->WriteAttributeInteger('CleanModeDefaulted', 1);
        }

        // Route/Saugkraft/Feuchte ebenfalls auf "belassen" vorbelegen. Wichtig, weil eine neue
        // Integer-Variable mit 0 startet und 0 bei der Saugkraft "Leise" bedeuten wuerde.
        if ($this->ReadAttributeInteger('RunSettingsDefaulted') == 0) {
            $this->SetValueIntegerSafe('CleanRoute', -1);
            $this->SetValueIntegerSafe('Suction', -1);
            $this->SetValueIntegerSafe('Water', -1);
            $this->SetValueIntegerSafe('Repeats', 1);
            $this->WriteAttributeInteger('RunSettingsDefaulted', 1);
        }

        $email = trim($this->ReadPropertyString('Email'));
        $pass  = $this->ReadPropertyString('Password');
        if ($email == '' || $pass == '') {
            $this->SetStatus(201);
            $this->SetTimerInterval('Poll', 0);
            return;
        }
        $this->SetStatus(102);
        $this->UpdatePollTimer(false);
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        // Raumliste "RoomFilter" mit den erkannten Raeumen (inkl. aktuellem Filterzustand) fuellen
        $rows = $this->BuildRoomRows();
        foreach ($form['elements'] as &$el) {
            if (isset($el['items'])) {
                foreach ($el['items'] as &$it) {
                    if (isset($it['name']) && $it['name'] == 'RoomFilter') {
                        $it['values'] = $rows;
                    }
                }
            }
        }
        return json_encode($form);
    }

    // Baut die Zeilen fuer die Liste "Räume" im Formular.
    private function BuildRoomRows()
    {
        $rows = [];
        foreach ($this->RoomEntries() as $e) {
            $rows[] = [
                'use'   => $e['use'],
                'floor' => $e['floor'],
                'mapid' => $e['mapid'],
                'seg'   => $e['seg'],
                'name'  => $e['name']
            ];
        }
        return $rows;
    }

    // Interne Raumliste aus den gespeicherten Karten: Code, Anzeigename und Filterzustand.
    // Reihenfolge = Reihenfolge in der Kartendatei (identisch zur Liste im Formular).
    private function RoomEntries()
    {
        $entries = [];
        $maps = json_decode($this->ReadAttributeString('MapsRooms'), true);
        if (!is_array($maps)) return $entries;

        $multi  = count($maps) > 1;
        $filter = $this->ReadRoomFilter();
        $i = 0;
        foreach ($maps as $m) {
            if (!isset($m['rooms']) || !is_array($m['rooms'])) continue;
            $mapId = intval($m['map_id']);
            $floor = strval($m['floor']);
            foreach ($m['rooms'] as $r) {
                $seg  = intval($r['seg']);
                $code = ($mapId * 1000) + $seg;
                $name = strval($r['name']);
                $entries[] = [
                    'code'  => $code,
                    'mapid' => $mapId,
                    'seg'   => $seg,
                    'floor' => $floor,
                    'name'  => $name,
                    'label' => $multi ? ($floor . ' . ' . $name) : $name,
                    'use'   => $this->IsRoomUsed($filter, $code, $i)
                ];
                $i++;
            }
        }
        return $entries;
    }

    // Liest die Property "RoomFilter". Zuordnung primaer ueber mapid/seg; falls die
    // gespeicherten Zeilen diese Spalten nicht enthalten, als Rueckfall ueber den Zeilenindex.
    private function ReadRoomFilter()
    {
        $out  = ['byCode' => [], 'byIndex' => []];
        $rows = json_decode($this->ReadPropertyString('RoomFilter'), true);
        if (!is_array($rows)) return $out;

        $i = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) { $i++; continue; }
            $use = isset($row['use']) ? (bool)$row['use'] : true;
            $out['byIndex'][$i] = $use;
            if (isset($row['mapid']) && isset($row['seg'])) {
                $code = (intval($row['mapid']) * 1000) + intval($row['seg']);
                if ($code > 0) $out['byCode'][$code] = $use;
            }
            $i++;
        }
        return $out;
    }

    // Unbekannte Raeume gelten als "verwenden" -> Verhalten ohne gepflegten Filter bleibt gleich.
    private function IsRoomUsed($filter, $code, $index)
    {
        if (isset($filter['byCode'][$code])) return $filter['byCode'][$code];
        if (count($filter['byCode']) == 0 && isset($filter['byIndex'][$index])) return $filter['byIndex'][$index];
        return true;
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident == 'Aktion') {
            $v = intval($Value);
            switch ($v) {
                case 1: $this->CleanAll(); break;
                case 2: $this->Pause(); break;
                case 3: $this->Stop(); break;
                case 4: $this->Charge(); break;
                case 5: $this->Locate(); break;
                case 6: $this->CleanSelectedRooms(); break;
                case 7: $this->ClearRoomSelection(); break;
            }
            // Auswahl wieder auf "—" zuruecksetzen
            $this->SetValueIntegerSafe('Aktion', 0);
            $this->BumpFastPoll();
            return;
        }
        // Vorwahlen: nur merken, angewendet werden sie beim naechsten Start
        if (in_array($Ident, ['CleanMode', 'CleanRoute', 'Suction', 'Water', 'Repeats'], true)) {
            $this->SetValueIntegerSafe($Ident, intval($Value));
            return;
        }
        if ($Ident == 'Raum') {
            $code = intval($Value);
            if ($code > 0) {
                $mapId = intdiv($code, 1000);
                $seg   = $code % 1000;
                $this->CleanRoom($mapId, $seg);
                $this->SetValueIntegerSafe('Raum', $code);
                $this->BumpFastPoll();
            }
            return;
        }
        if ($Ident == 'Karte') {
            $this->SetSelectedMap(intval($Value), true);
            $this->BumpFastPoll();
            return;
        }
        // Auswahlschalter der Mehrfachauswahl: nur merken, gestartet wird per Aktion
        if (strpos($Ident, 'RoomSel') === 0) {
            $code = intval(substr($Ident, 7));
            if (count($this->MapList()) > 1 && intdiv($code, 1000) != $this->ActiveMap()) {
                // Raum einer anderen Karte: nicht anwaehlbar. Sonst startet der Durchgang
                // mit einem stillen Kartenwechsel - erst die Karte "Karte" umschalten.
                $this->SetValueBooleanSafe($Ident, false);
                $this->SetLastError('Raum gehört zu einer anderen Karte. Erst die Karte umschalten.');
                return;
            }
            $this->SetValueBooleanSafe($Ident, (bool)$Value);
            return;
        }
    }

    // =========================================================================
    // Public API (Form-Buttons / Skripte)
    // =========================================================================

    public function TestLogin()
    {
        if ($this->Login(true)) {
            $dev = $this->EnsureDevice(true);
            if ($dev !== false) {
                $this->SetOnline(true, '');
                echo "OK - Login erfolgreich.\nGeraet: " . $dev['model'] . " (did " . $dev['did'] . ")";
                return true;
            }
            echo "Login OK, aber kein Saugroboter im Konto gefunden.";
            return false;
        }
        echo "FEHLER - Login fehlgeschlagen. Bitte E-Mail/Passwort/Region prüfen.";
        return false;
    }

    // Liest Karten + Raeume aus der Cloud, dekodiert sie und baut das Raum-Dropdown neu auf.
    public function DiscoverMapsAndRooms()
    {
        if (!$this->TryLock()) return false;
        try {
            if (!$this->Login(false)) { $this->SetOnline(false, 'Login fehlgeschlagen.'); return false; }
            $dev = $this->EnsureDevice(false);
            if ($dev === false) { $this->SetOnline(false, 'Kein Geraet gefunden.'); return false; }

            $ml = $this->GetProperties([[6, 8]]);
            if (!isset($ml['6.8'])) { $this->SetOnline(false, 'MAP_LIST nicht lesbar.'); return false; }
            $mlv = json_decode($ml['6.8'], true);
            if (!isset($mlv['object_name'])) { $this->SetOnline(false, 'object_name fehlt.'); return false; }

            $url = $this->GetDownloadUrl($dev, $mlv['object_name']);
            if ($url === false) { $this->SetOnline(false, 'Download-URL fehlgeschlagen.'); return false; }
            $raw = $this->HttpGet($url);
            if ($raw === false || $raw === '') { $this->SetOnline(false, 'Kartendownload fehlgeschlagen.'); return false; }

            $maps = $this->DecodeMaps($raw);
            if ($maps === false || count($maps) == 0) { $this->SetOnline(false, 'Karte konnte nicht dekodiert werden.'); return false; }

            $this->WriteAttributeString('MapsRooms', json_encode($maps));
            $this->RebuildMapProfile();
            $this->SyncRoomSwitches();
            $this->ApplyMapSelection();   // enthaelt RebuildRoomProfile()
            // Liste "Raeume" sofort im offenen Formular aktualisieren
            $this->UpdateFormField('RoomFilter', 'values', json_encode($this->BuildRoomRows()));
            $this->SetOnline(true, '');

            $cnt = 0;
            foreach ($maps as $m) $cnt += count($m['rooms']);
            $this->LogMessage('Karten/Räume eingelesen: ' . count($maps) . ' Etage(n), ' . $cnt . ' Räume.', KL_NOTIFY);
            echo 'OK - ' . count($maps) . " Etage(n) eingelesen:\n";
            foreach ($maps as $m) {
                echo '  ' . $m['floor'] . ' (map_id ' . $m['map_id'] . '): ';
                $names = [];
                foreach ($m['rooms'] as $r) $names[] = $r['name'] . ' [' . $r['seg'] . ']';
                echo implode(', ', $names) . "\n";
            }
            echo "\nNeue Räume sind zunächst aktiv. Nicht existierende Räume (z. B. durch Fenster\n"
                . "erkannte Phantomräume) in der Liste abwählen und \"Änderungen übernehmen\" drücken.";
            return true;
        } finally {
            $this->Unlock();
        }
    }

    public function CleanAll()
    {
        if (!$this->TryLock()) return false;
        try {
            if (!$this->Login(false)) { $this->SetOnline(false, 'Login fehlgeschlagen.'); return false; }
            if ($this->EnsureDevice(false) === false) { $this->SetOnline(false, 'Kein Geraet.'); return false; }
            // Auf der gewaehlten Etage reinigen, auch wenn der Wechsel vorher verschoben wurde
            if (!$this->EnsureDeviceMap($this->ActiveMap())) {
                $this->SetOnline(false, 'Kartenwechsel fehlgeschlagen.');
                return false;
            }
            // Vorwahlen (Modus, Saugkraft, Feuchte, Route) vor dem Start setzen
            $this->ApplyRunSettings();
            $r = $this->Action(2, 1, []);
            if ($r === false) { $this->SetOnline(false, 'Aktion fehlgeschlagen (2/1).'); return false; }
            $this->SetOnline(true, '');
            $this->BumpFastPoll();
            return true;
        } finally {
            $this->Unlock();
        }
    }

    // Einzelner Raum. mapId = Etagen-/Karten-ID, segmentId = Raum-ID
    public function CleanRoom($mapId, $segmentId)
    {
        return $this->CleanRooms($mapId, [$segmentId]);
    }

    // Mehrere Raeume in EINEM Durchgang.
    // $MapID    = Etagen-/Karten-ID. ACHTUNG: 0 ist eine gueltige Karte (die erste Etage
    //             heisst hier map_id 0). Nur ein negativer Wert bedeutet "selbst ableiten".
    // $Segments = Array oder Komma-Liste von Raum-IDs ("5,6,7"). Werte ab 1000 werden als
    //             Profilcode (mapId*1000+seg) verstanden, damit die Codes des Dropdowns passen.
    public function CleanRooms($MapID, $Segments)
    {
        $mapId = intval($MapID);
        $segs  = $this->ParseSegments($Segments);
        if (count($segs) == 0) {
            $this->SetLastError('Keine gültigen Raum-IDs übergeben.');
            echo 'Keine gültigen Raum-IDs übergeben.';
            return false;
        }
        if ($mapId < 0) $mapId = $this->DeriveMapId($Segments, $segs);

        if (!$this->TryLock()) return false;
        try {
            if (!$this->Login(false)) { $this->SetOnline(false, 'Login fehlgeschlagen.'); return false; }
            if ($this->EnsureDevice(false) === false) { $this->SetOnline(false, 'Kein Geraet.'); return false; }

            // Bei mehreren Etagen: zuerst auf die Zielkarte umschalten
            if (!$this->EnsureDeviceMap($mapId)) {
                $this->SetOnline(false, 'Kartenwechsel fehlgeschlagen.');
                return false;
            }

            // Vorwahlen (Modus, Saugkraft, Feuchte, Route) setzen
            $this->ApplyRunSettings();

            // Saugkraft/Feuchte: Vorwahl, sonst der aktuelle Wert vom Geraet (Fallback 1/2)
            $fan   = $this->ChosenSuction();
            $water = $this->ChosenWater();
            if ($fan < 0 || $water <= 0) {
                $p = $this->GetProperties([[4, 4], [4, 5]]);
                if ($fan < 0)     $fan   = isset($p['4.4']) ? intval($p['4.4']) : 1;
                if ($water <= 0)  $water = isset($p['4.5']) ? intval($p['4.5']) : 2;
            }
            $repeats = $this->ChosenRepeats();

            // [Segment, Durchgaenge, Saugkraft, Feuchte, Reihenfolge]
            // Reihenfolge bleibt bewusst bei 1: Geraete mit "Individuelle Raumeinstellungen"
            // (Property 4/26, u. a. der X50) brechen die Fahrt ab, wenn dort hochgezaehlt wird
            // - so macht es auch die Referenz-Implementierung. Die Abfolge bestimmt dann der
            // Roboter selbst (Reinigungsreihenfolge aus der App/Karte).
            $list = [];
            foreach ($segs as $seg) {
                $list[] = [$seg, $repeats, $fan, $water, 1];
            }
            $selects = json_encode(['selects' => $list]);
            $in = [
                ['piid' => 1, 'value' => 18],       // Status = SEGMENT_CLEANING
                ['piid' => 10, 'value' => $selects]  // CLEANING_PROPERTIES
            ];
            $this->SendDebug('CleanRooms', 'map ' . $mapId . ' -> ' . $selects, 0);
            $r = $this->Action(4, 1, $in);
            if ($r === false) { $this->SetOnline(false, 'Raum-Start fehlgeschlagen.'); return false; }
            $this->SetOnline(true, '');
            // Start angefordert -> Auswahl wird zurueckgesetzt, sobald der Durchgang durch ist
            $this->WriteAttributeInteger('RoomCleaning', 1);
            $this->BumpFastPoll();
            return true;
        } finally {
            $this->Unlock();
        }
    }

    // Startet alle angehakten Raeume ("Auswahl <Raum>") in einem Durchgang.
    public function CleanSelectedRooms()
    {
        $codes = $this->GetSelectedRoomCodes();
        if (count($codes) == 0) {
            $this->SetLastError('Kein Raum ausgewählt.');
            echo 'Es ist kein Raum ausgewählt.';
            return false;
        }

        // Ein Durchgang kann nur eine Etage abdecken
        $byMap = [];
        foreach ($codes as $c) {
            $byMap[intdiv($c, 1000)][] = $c % 1000;
        }
        if (count($byMap) > 1) {
            $this->SetLastError('Auswahl umfasst mehrere Etagen.');
            echo "Die Auswahl umfasst mehrere Etagen. Ein Durchgang kann nur Räume einer Etage abdecken.";
            return false;
        }

        $mapId = -1;
        $segs  = [];
        foreach ($byMap as $m => $s) { $mapId = intval($m); $segs = $s; }
        $this->SetLastError('');
        return $this->CleanRooms($mapId, $segs);
    }

    // Setzt alle Auswahlschalter zurueck.
    public function ClearRoomSelection()
    {
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $cid) {
            $o = IPS_GetObject($cid);
            if ($o['ObjectType'] != 2) continue;
            if (strpos($o['ObjectIdent'], 'RoomSel') !== 0) continue;
            if (GetValueBoolean($cid) !== false) SetValueBoolean($cid, false);
        }
        return true;
    }

    public function Pause()  { return $this->DoAction(2, 2, []); }
    public function Stop()   { return $this->DoAction(4, 2, []); }
    public function Charge() { return $this->DoAction(3, 1, []); }
    public function Locate() { return $this->DoAction(7, 1, []); }

    public function UpdateStatus()
    {
        if (!$this->TryLock()) { $this->BumpFastPoll(); return; }
        try {
            $email = trim($this->ReadPropertyString('Email'));
            if ($email == '') { $this->SetOnline(false, 'Konfiguration unvollstaendig.'); return; }
            if (!$this->Login(false)) { $this->SetOnline(false, 'Login fehlgeschlagen.'); return; }
            if ($this->EnsureDevice(false) === false) { $this->SetOnline(false, 'Kein Geraet.'); return; }

            $p = $this->GetProperties([[2, 1], [2, 2], [3, 1], [4, 2], [4, 3], [4, 23], [4, 26], [4, 50]]);
            if (count($p) == 0) { $this->SetOnline(false, 'Keine Statusantwort.'); return; }
            $this->SetOnline(true, '');

            if (isset($p['2.1'])) {
                $state = intval($p['2.1']);
                $this->SetValueStringSafe('Zustand', $this->StateText($state));
                $this->HandleRoomAutoReset($state);
            }
            if (isset($p['2.2'])) $this->SetValueStringSafe('Fehler', $this->ErrorText(intval($p['2.2'])));
            if (isset($p['3.1'])) $this->SetValueIntegerSafe('Battery', intval($p['3.1']));
            if (isset($p['4.2'])) $this->SetValueIntegerSafe('CleaningTime', intval($p['4.2']));
            if (isset($p['4.3'])) $this->SetValueFloatSafe('CleaningArea', floatval($p['4.3']));
            if (isset($p['4.23'])) $this->SetValueStringSafe('DeviceMode', $this->CleanModeText(intval($p['4.23'])));
            if (isset($p['4.26'])) $this->SetValueBooleanSafe('CustomRoomSettings', intval($p['4.26']) == 1);
            if (isset($p['4.50'])) {
                $route = $this->ParseAutoSwitch($p['4.50'], 'CleanRoute');
                $this->SetValueStringSafe('DeviceRoute', $route === null ? 'nicht unterstützt' : $this->CleanRouteText($route));
            }
        } finally {
            $this->Unlock();
            $this->UpdatePollTimer(false);
        }
    }

    // =========================================================================
    // Aktion mit Lock-Wrapper
    // =========================================================================

    private function DoAction($siid, $aiid, $in)
    {
        if (!$this->TryLock()) return false;
        try {
            if (!$this->Login(false)) { $this->SetOnline(false, 'Login fehlgeschlagen.'); return false; }
            if ($this->EnsureDevice(false) === false) { $this->SetOnline(false, 'Kein Geraet.'); return false; }
            $r = $this->Action($siid, $aiid, $in);
            if ($r === false) { $this->SetOnline(false, 'Aktion fehlgeschlagen (' . $siid . '/' . $aiid . ').'); return false; }
            $this->SetOnline(true, '');
            return true;
        } finally {
            $this->Unlock();
        }
    }

    // =========================================================================
    // Cloud: Auth / Geraet
    // =========================================================================

    // Loggt ein (nutzt Cache, ausser $force). Rueckgabe true/false.
    private function Login($force)
    {
        if (!$force) {
            $auth = json_decode($this->ReadAttributeString('Auth'), true);
            if (is_array($auth) && isset($auth['access']) && isset($auth['exp']) && $auth['exp'] > time() + 60) {
                return true;
            }
        }
        $email = trim($this->ReadPropertyString('Email'));
        $pass  = $this->ReadPropertyString('Password');
        $region = $this->Region();
        if ($email == '' || $pass == '') return false;

        $pw = md5($pass . self::SALT);
        $body = 'platform=IOS&scope=all&grant_type=password'
            . '&username=' . rawurlencode($email)
            . '&password=' . $pw
            . '&type=account';
        $headers = [
            'Accept: */*',
            'Content-Type: application/x-www-form-urlencoded',
            'User-Agent: ' . self::UA,
            'Authorization: ' . self::BASIC,
            'Tenant-Id: 000000'
        ];
        list($code, $resp) = $this->HttpPost($this->BaseUrl() . '/dreame-auth/oauth/token', $headers, $body);
        if ($code != 200) { return false; }
        $d = json_decode($resp, true);
        if (!is_array($d) || !isset($d['access_token'])) return false;

        $auth = [
            'access' => $d['access_token'],
            'refresh' => isset($d['refresh_token']) ? $d['refresh_token'] : '',
            'uid' => isset($d['uid']) ? $d['uid'] : '',
            'tenant' => isset($d['tenant_id']) ? $d['tenant_id'] : '000000',
            'exp' => time() + (isset($d['expires_in']) ? intval($d['expires_in']) : 3600)
        ];
        $this->WriteAttributeString('Auth', json_encode($auth));
        return true;
    }

    // Ermittelt/cached did, model, host. Rueckgabe Array oder false.
    private function EnsureDevice($force)
    {
        if (!$force) {
            $dev = json_decode($this->ReadAttributeString('Device'), true);
            if (is_array($dev) && isset($dev['did'])) return $dev;
        }
        list($code, $resp) = $this->ApiPost('dreame-user-iot/iotuserbind/device/listV2', new \stdClass());
        if ($code != 200) return false;
        $d = json_decode($resp, true);
        if (!isset($d['data']['page']['records'])) return false;
        foreach ($d['data']['page']['records'] as $rec) {
            if (isset($rec['model']) && strpos($rec['model'], '.vacuum.') !== false) {
                $dev = [
                    'did' => strval($rec['did']),
                    'model' => $rec['model'],
                    'host' => isset($rec['bindDomain']) ? $rec['bindDomain'] : ''
                ];
                $this->WriteAttributeString('Device', json_encode($dev));
                return $dev;
            }
        }
        return false;
    }

    // =========================================================================
    // Cloud: MiOT-Aufrufe
    // =========================================================================

    private function GetProperties($keys)
    {
        $dev = $this->EnsureDevice(false);
        if ($dev === false) return [];
        $did = $dev['did'];
        $params = [];
        foreach ($keys as $k) {
            $params[] = ['did' => $did, 'siid' => $k[0], 'piid' => $k[1]];
        }
        $body = ['did' => $did, 'id' => 1, 'data' => ['did' => $did, 'id' => 1, 'method' => 'get_properties', 'params' => $params]];
        list($code, $resp) = $this->ApiPost($this->CmdPath($dev), $body);
        $out = [];
        if ($code == 200) {
            $d = json_decode($resp, true);
            if (isset($d['data']['result']) && is_array($d['data']['result'])) {
                foreach ($d['data']['result'] as $r) {
                    if (isset($r['code']) && $r['code'] == 0 && isset($r['value'])) {
                        $out[$r['siid'] . '.' . $r['piid']] = $r['value'];
                    }
                }
            }
        }
        return $out;
    }

    // Setzt eine oder mehrere Properties. $items = [[siid, piid, value], ...]. Rueckgabe true/false.
    private function SetProperties($items)
    {
        $dev = $this->EnsureDevice(false);
        if ($dev === false) return false;
        $did = $dev['did'];
        $params = [];
        foreach ($items as $it) {
            $params[] = ['did' => $did, 'siid' => $it[0], 'piid' => $it[1], 'value' => $it[2]];
        }
        $body = ['did' => $did, 'id' => 1, 'data' => ['did' => $did, 'id' => 1, 'method' => 'set_properties', 'params' => $params]];
        list($code, $resp) = $this->ApiPost($this->CmdPath($dev), $body);
        return $code == 200;
    }

    // Setzt den in der Variable "Reinigungsmodus" gewaehlten Modus am Geraet (siid 4 / piid 23).
    // Bei -1 ("Geraeteeinstellung belassen") wird nichts geaendert.
    // Wichtig: 4/23 ist beim r2532a ein GEPACKTER Wert (Modus in den untersten 2 Bits,
    // darueber Selbstreinigungs-Flaeche/Feuchtigkeit). Daher aktuellen Wert lesen und nur
    // das Modusfeld ersetzen. Bit-Zuordnung (Geraet mit Moppanhebung):
    //   0 = zusammen, 1 = nur wischen, 2 = nur kehren, 3 = erst kehren dann wischen.
    // Wendet alle Vorwahlen vor einem Start an: Reinigungsmodus, Saugkraft, Wischfeuchte
    // und Reinigungsroute. Jede Vorwahl auf "belassen" laesst die Geraeteeinstellung in Ruhe.
    private function ApplyRunSettings()
    {
        $this->ApplyCleaningMode();

        $items = [];
        $fan = $this->ChosenSuction();
        if ($fan >= 0) $items[] = [4, 4, $fan];
        $water = $this->ChosenWater();
        if ($water > 0) $items[] = [4, 5, $water];
        if (count($items) > 0) {
            $this->SetProperties($items);
            IPS_Sleep(300);
        }

        // Route steckt als einzelnes k/v-Paar in der JSON-Property 4/50
        $route = $this->ChosenRoute();
        if ($route > 0) {
            $this->SetProperties([[4, 50, json_encode(['k' => 'CleanRoute', 'v' => $route])]]);
            IPS_Sleep(300);
        }
    }

    // Vorwahlen lesen. Rueckgabe -1 bzw. 0 bedeutet "Geraeteeinstellung belassen".
    private function ChosenSuction()
    {
        $v = GetValueInteger($this->GetIDForIdent('Suction'));
        return ($v >= 0 && $v <= 3) ? $v : -1;
    }

    private function ChosenWater()
    {
        $v = GetValueInteger($this->GetIDForIdent('Water'));
        return ($v >= 1 && $v <= 3) ? $v : 0;
    }

    private function ChosenRoute()
    {
        $v = GetValueInteger($this->GetIDForIdent('CleanRoute'));
        return ($v >= 1 && $v <= 4) ? $v : 0;
    }

    private function ChosenRepeats()
    {
        $v = GetValueInteger($this->GetIDForIdent('Repeats'));
        return ($v >= 1 && $v <= 3) ? $v : 1;
    }

    private function ApplyCleaningMode()
    {
        $mode = GetValueInteger($this->GetIDForIdent('CleanMode'));
        if ($mode < 0) return; // Geraeteeinstellung belassen

        // Logische Dropdown-Auswahl -> Modus-Bits
        $map = [0 => 2, 1 => 1, 2 => 0, 3 => 3]; // 0 Kehren,1 Wischen,2 Zusammen,3 ErstKehrenDannWischen
        if (!isset($map[$mode])) return;
        $bits = $map[$mode];

        $cur = $this->GetProperties([[4, 23]]);
        if (!isset($cur['4.23'])) return;
        $raw = intval($cur['4.23']);
        $new = ($raw & ~3) | $bits;
        if ($new !== $raw) {
            $this->SetProperties([[4, 23, $new]]);
            IPS_Sleep(500);
        }
    }

    // Fuehrt eine MiOT-Action aus. Rueckgabe Antwort-Array oder false.
    private function Action($siid, $aiid, $in)
    {
        $dev = $this->EnsureDevice(false);
        if ($dev === false) return false;
        $did = $dev['did'];
        $params = ['did' => $did, 'siid' => $siid, 'aiid' => $aiid, 'in' => $in];
        $body = ['did' => $did, 'id' => 1, 'data' => ['did' => $did, 'id' => 1, 'method' => 'action', 'params' => $params]];
        list($code, $resp) = $this->ApiPost($this->CmdPath($dev), $body);
        if ($code != 200) return false;
        $d = json_decode($resp, true);
        if (isset($d['data']['result']['code']) && $d['data']['result']['code'] == 0) return $d;
        // Manche Aktionen liefern nur success=true
        if (isset($d['success']) && $d['success'] === true) return $d;
        return false;
    }

    private function GetDownloadUrl($dev, $objectName)
    {
        $auth = json_decode($this->ReadAttributeString('Auth'), true);
        $body = [
            'did' => $dev['did'],
            'model' => $dev['model'],
            'filename' => $objectName,
            'uid' => isset($auth['uid']) ? strval($auth['uid']) : '',
            'region' => $this->Region()
        ];
        list($code, $resp) = $this->ApiPost('dreame-user-iot/iotfile/getDownloadUrl', $body);
        if ($code != 200) return false;
        $d = json_decode($resp, true);
        if (isset($d['data']) && is_string($d['data']) && $d['data'] != '') return $d['data'];
        if (isset($d['data']['url'])) return $d['data']['url'];
        return false;
    }

    // =========================================================================
    // Karten-Dekodierung (JSON -> mapstr[] -> base64+zlib -> Trailer-JSON -> seg_inf)
    // =========================================================================

    private function DecodeMaps($raw)
    {
        $top = json_decode($raw, true);
        if (!isset($top['mapstr']) || !is_array($top['mapstr'])) return false;
        $maps = [];
        foreach ($top['mapstr'] as $m) {
            $floor = isset($m['name']) ? strval($m['name']) : ('Karte ' . (isset($m['id']) ? $m['id'] : '?'));
            $mapId = isset($m['id']) ? intval($m['id']) : 0;
            $mstr = isset($m['map']) ? strval($m['map']) : '';
            if ($mstr == '') continue;
            $mstr = str_replace(['_', '-'], ['/', '+'], $mstr);
            $comma = strpos($mstr, ',');
            if ($comma !== false) $mstr = substr($mstr, 0, $comma);
            $blob = base64_decode($mstr);
            if ($blob === false) continue;
            $bin = @gzuncompress($blob);
            if ($bin === false) continue;
            if (strlen($bin) < 27) continue;

            $w = unpack('v', substr($bin, 19, 2));
            $h = unpack('v', substr($bin, 21, 2));
            $w = $w[1];
            $h = $h[1];
            $imgEnd = 27 + ($w * $h);
            if (strlen($bin) <= $imgEnd) continue;
            $trailer = substr($bin, $imgEnd);
            $dj = json_decode($trailer, true);
            if (!isset($dj['seg_inf']) || !is_array($dj['seg_inf'])) continue;

            $rooms = [];
            $segIds = array_map('intval', array_keys($dj['seg_inf']));
            sort($segIds);
            foreach ($segIds as $sid) {
                $s = $dj['seg_inf'][strval($sid)];
                $type = isset($s['type']) ? intval($s['type']) : 0;
                $index = isset($s['index']) ? intval($s['index']) : 0;
                $rooms[] = ['seg' => $sid, 'name' => $this->RoomName($type, $index, $sid)];
            }
            $maps[] = ['map_id' => $mapId, 'floor' => $floor, 'rooms' => $rooms];
        }
        return $maps;
    }

    private function RoomName($type, $index, $segId)
    {
        $names = [
            1 => 'Wohnzimmer', 2 => 'Schlafzimmer', 3 => 'Arbeitszimmer', 4 => 'Küche',
            5 => 'Esszimmer', 6 => 'Badezimmer', 7 => 'Balkon', 8 => 'Flur', 9 => 'Hauswirtschaftsraum',
            10 => 'Ankleide', 11 => 'Besprechungsraum', 12 => 'Büro', 13 => 'Fitnessbereich',
            14 => 'Freizeitbereich', 15 => 'Gästezimmer'
        ];
        if ($type > 0 && isset($names[$type])) {
            $n = $names[$type];
            if ($index > 0) $n .= ' ' . ($index + 1);
            return $n;
        }
        return 'Raum ' . $segId;
    }

    // =========================================================================
    // Raum-Profil (dynamisch, instanzspezifisch)
    // =========================================================================

    private function RoomProfileName()
    {
        return 'DREAME.Rooms' . $this->InstanceID;
    }

    private function MapProfileName()
    {
        return 'DREAME.Maps' . $this->InstanceID;
    }

    // Karten aus der gespeicherten Kartendatei: [map_id => Etagenname]
    private function MapList()
    {
        $out  = [];
        $maps = json_decode($this->ReadAttributeString('MapsRooms'), true);
        if (!is_array($maps)) return $out;
        foreach ($maps as $m) {
            if (!isset($m['map_id'])) continue;
            $id = intval($m['map_id']);
            $out[$id] = isset($m['floor']) ? strval($m['floor']) : ('Karte ' . $id);
        }
        return $out;
    }

    // Aktive Karte. -1 im Attribut heisst "noch nie gewaehlt" -> erste Karte.
    private function ActiveMap()
    {
        $maps = $this->MapList();
        if (count($maps) == 0) return -1;
        $sel = $this->ReadAttributeInteger('SelectedMap');
        if (isset($maps[$sel])) return $sel;
        $keys = array_keys($maps);
        return $keys[0];
    }

    private function RebuildMapProfile()
    {
        $profile = $this->MapProfileName();
        if (!IPS_VariableProfileExists($profile)) return;
        $assocs = [];
        foreach ($this->MapList() as $id => $floor) $assocs[] = [$id, $floor, '', -1];
        if (count($assocs) == 0) $assocs = [[0, '— keine Karte —', '', -1]];
        $this->SetAssociations($profile, $assocs);
    }

    // Je Karte ein Boolean "Karte <Etage> aktiv". Existiert, damit eine Visu die
    // Sichtbarkeit ihrer Raumtasten daran binden kann (IPSView: ItemVisibility).
    private function SyncMapFlags()
    {
        $active = $this->ActiveMap();
        $keep   = [];
        $pos    = 92;
        foreach ($this->MapList() as $id => $floor) {
            $ident  = 'MapActive' . $id;
            $keep[] = $ident;
            $this->RegisterVariableBoolean($ident, 'Karte ' . $floor . ' aktiv', '~Switch', $pos++);
            $this->DisableAction($ident);
            $this->SetValueBooleanSafe($ident, $id === $active);
        }
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $cid) {
            $o = IPS_GetObject($cid);
            if ($o['ObjectType'] != 2 || strpos($o['ObjectIdent'], 'MapActive') !== 0) continue;
            if (!in_array($o['ObjectIdent'], $keep, true)) $this->UnregisterVariable($o['ObjectIdent']);
        }
    }

    // Karte waehlen. $switchDevice = false, wenn das Geraet schon umgeschaltet ist.
    //
    // Wichtig: Der Kartenwechsel am Geraet (Action 6/2) bricht einen laufenden Auftrag ab.
    // Waehlt man die Etage also, waehrend der Roboter arbeitet, folgt nur die ANZEIGE
    // (Dropdown, Auswahlschalter, Flags) - das Geraet bleibt unangetastet und wird erst
    // beim naechsten Start umgeschaltet (EnsureDeviceMap).
    private function SetSelectedMap($mapId, $switchDevice)
    {
        $maps = $this->MapList();
        if (count($maps) == 0) { $this->SetLastError('Es sind keine Karten eingelesen.'); return false; }
        if (!isset($maps[$mapId])) { $this->SetLastError('Unbekannte Karte ' . $mapId . '.'); return false; }

        if ($switchDevice && $this->ReadAttributeInteger('DeviceMap') != $mapId) {
            if (!$this->Login(false) || $this->EnsureDevice(false) === false) {
                $this->SetOnline(false, 'Kartenwechsel: keine Verbindung.');
                return false;
            }

            $state = $this->CurrentState();
            if (in_array($state, $this->BusyStates(), true)) {
                // Auftrag laeuft -> nur die Anzeige umstellen, nicht das Geraet
                $this->WriteAttributeInteger('SelectedMap', $mapId);
                $this->ApplyMapSelection();
                $this->SetLastError('Karte nur in der Anzeige gewechselt – der Roboter arbeitet gerade.');
                $this->LogMessage('Kartenwechsel auf „' . $maps[$mapId] . '" verschoben, da ein Auftrag läuft ('
                    . $this->StateText($state) . '). Das Gerät wird beim nächsten Start umgeschaltet.', KL_NOTIFY);
                return true;
            }

            if (!$this->SwitchDeviceMap($mapId)) {
                $this->SetOnline(false, 'Kartenwechsel fehlgeschlagen.');
                return false;
            }
        }
        $this->WriteAttributeInteger('SelectedMap', $mapId);
        $this->ApplyMapSelection();
        return true;
    }

    // Schaltet das Geraet auf die Karte um (Action 6/2) und merkt sich das.
    // Nur aufrufen, wenn gerade KEIN Auftrag laeuft - sonst bricht die Fahrt ab.
    private function SwitchDeviceMap($mapId)
    {
        $sm = json_encode(['sm' => (object)[], 'mapid' => $mapId]);
        if ($this->Action(6, 2, [['piid' => 4, 'value' => $sm]]) === false) return false;
        IPS_Sleep(4000);
        $this->WriteAttributeInteger('DeviceMap', $mapId);
        return true;
    }

    // Vor jedem Start: sicherstellen, dass das Geraet auf der gewuenschten Karte steht.
    // Faengt die Faelle ab, in denen der Wechsel wegen eines laufenden Auftrags verschoben wurde.
    private function EnsureDeviceMap($mapId)
    {
        if ($mapId < 0) return true;
        if ($this->ReadAttributeInteger('DeviceMap') == $mapId) return true;
        if (!$this->SwitchDeviceMap($mapId)) return false;
        $this->WriteAttributeInteger('SelectedMap', $mapId);
        $this->ApplyMapSelection();   // Variable "Karte", Flags, Dropdown mitziehen
        return true;
    }

    // Aktueller Zustand (Property 2/1) frisch vom Geraet. -1 = nicht lesbar.
    private function CurrentState()
    {
        $p = $this->GetProperties([[2, 1]]);
        return isset($p['2.1']) ? intval($p['2.1']) : -1;
    }

    // Zustaende, in denen ein Auftrag laeuft oder pausiert ist.
    private function BusyStates()
    {
        return [1, 3, 5, 7, 9, 10, 11, 12, 18, 21, 23, 27, 30, 121, 122];
    }

    // Zieht alles Raumbezogene auf die aktive Karte: Variable, Flags, Dropdown und
    // die Auswahlschalter (fremde Karten werden versteckt und abgewaehlt).
    private function ApplyMapSelection()
    {
        $active = $this->ActiveMap();
        if (@$this->GetIDForIdent('Karte')) $this->SetValueIntegerSafe('Karte', $active);
        $this->SyncMapFlags();
        $this->RebuildRoomProfile();

        $mehrere = count($this->MapList()) > 1;
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $cid) {
            $o = IPS_GetObject($cid);
            if ($o['ObjectType'] != 2 || strpos($o['ObjectIdent'], 'RoomSel') !== 0) continue;
            $code  = intval(substr($o['ObjectIdent'], 7));
            $fremd = $mehrere && intdiv($code, 1000) != $active;
            if ($o['ObjectIsHidden'] != $fremd) IPS_SetHidden($cid, $fremd);
            if ($fremd && GetValueBoolean($cid) !== false) SetValueBoolean($cid, false);
        }
    }

    private function RebuildRoomProfile()
    {
        $profile = $this->RoomProfileName();
        if (!IPS_VariableProfileExists($profile)) return;

        // Nur die Raeume der AKTIVEN Karte. Ein Durchgang kann ohnehin nur eine Etage
        // abdecken, und ein Raum der anderen Karte wuerde das Geraet still umschalten.
        $active  = $this->ActiveMap();
        $mehrere = count($this->MapList()) > 1;

        $assocs = [[0, '— Raum wählen —', '', -1]];
        $codes  = [];
        foreach ($this->RoomEntries() as $e) {
            if (!$e['use']) continue;               // abgewaehlter Raum (z. B. Phantomraum)
            if ($mehrere && $e['mapid'] != $active) continue;
            $assocs[] = [$e['code'], $e['label'], '', -1];
            $codes[] = $e['code'];
        }
        $this->SetAssociations($profile, $assocs);

        // Steht in "Raum reinigen" ein inzwischen abgewaehlter Raum, Auswahl leeren
        $id = @$this->GetIDForIdent('Raum');
        if ($id) {
            $cur = GetValueInteger($id);
            if ($cur != 0 && !in_array($cur, $codes, true)) $this->SetValueIntegerSafe('Raum', 0);
        }
    }

    // Legt fuer jeden relevanten Raum einen Schalter "Auswahl <Raum>" an und entfernt
    // Schalter von Raeumen, die es nicht mehr gibt bzw. die abgewaehlt wurden.
    private function SyncRoomSwitches()
    {
        $keep = [];
        $pos  = 100;
        foreach ($this->RoomEntries() as $e) {
            if (!$e['use']) continue;
            $ident = 'RoomSel' . $e['code'];
            $label = 'Auswahl ' . $e['label'];
            $keep[] = $ident;

            $existed = @$this->GetIDForIdent($ident);
            $this->RegisterVariableBoolean($ident, $label, '~Switch', $pos);
            $this->EnableAction($ident);
            $vid = $this->GetIDForIdent($ident);
            IPS_SetIcon($vid, 'Move');
            // Umbenannte Raeume nachziehen, aber selbst vergebene Namen nicht ueberschreiben
            if ($existed && IPS_GetName($vid) != $label && strpos(IPS_GetName($vid), 'Auswahl ') === 0) {
                IPS_SetName($vid, $label);
            }
            $pos++;
        }

        foreach (IPS_GetChildrenIDs($this->InstanceID) as $cid) {
            $o = IPS_GetObject($cid);
            if ($o['ObjectType'] != 2) continue;
            $ident = $o['ObjectIdent'];
            if (strpos($ident, 'RoomSel') !== 0) continue;
            if (in_array($ident, $keep, true)) continue;
            $this->UnregisterVariable($ident);
        }
    }

    // Angehakte Raeume als Profilcodes, in Anzeige-Reihenfolge (= Abarbeitungsreihenfolge).
    private function GetSelectedRoomCodes()
    {
        $found = [];
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $cid) {
            $o = IPS_GetObject($cid);
            if ($o['ObjectType'] != 2) continue;
            if (strpos($o['ObjectIdent'], 'RoomSel') !== 0) continue;
            if (GetValueBoolean($cid) !== true) continue;
            $code = intval(substr($o['ObjectIdent'], 7));
            if ($code <= 0) continue;
            $found[] = ['pos' => intval($o['ObjectPosition']), 'code' => $code];
        }
        usort($found, function ($a, $b) {
            if ($a['pos'] == $b['pos']) return $a['code'] - $b['code'];
            return $a['pos'] - $b['pos'];
        });
        $out = [];
        foreach ($found as $f) $out[] = $f['code'];
        return $out;
    }

    // Normalisiert die Segment-Uebergabe: Array oder "5,6,7" -> [5,6,7] (ohne Dubletten).
    // Werte ab 1000 gelten als Profilcode (mapId*1000+seg) und werden auf das Segment reduziert.
    private function ParseSegments($segments)
    {
        if (is_array($segments)) {
            $list = $segments;
        } else {
            $list = explode(',', str_replace([';', ' ', "\t", "\n", "\r"], ',', strval($segments)));
        }
        $out = [];
        foreach ($list as $v) {
            $n = intval($v);
            if ($n >= 1000) $n = $n % 1000;
            if ($n <= 0) continue;
            if (!in_array($n, $out, true)) $out[] = $n;
        }
        return $out;
    }

    // Karten-ID ableiten, wenn der Aufrufer keine angegeben hat. Rueckgabe -1 = unbekannt
    // (dann wird nicht umgeschaltet). Karten-ID 0 ist ein gueltiges Ergebnis.
    private function DeriveMapId($segments, $parsed)
    {
        // 1. Profilcode (mapId*1000+seg) ist eindeutig
        if (is_array($segments)) {
            $list = $segments;
        } else {
            $list = explode(',', str_replace([';', ' ', "\t", "\n", "\r"], ',', strval($segments)));
        }
        foreach ($list as $v) {
            $n = intval($v);
            if ($n >= 1000) return intdiv($n, 1000);
        }

        // 2. Segment in den eingelesenen Karten suchen - nur wenn es dort genau einmal vorkommt
        //    (Segment-Nummern wiederholen sich zwischen den Etagen)
        $entries = $this->RoomEntries();
        foreach ($parsed as $seg) {
            $hits = [];
            foreach ($entries as $e) {
                if ($e['seg'] == $seg && !in_array($e['mapid'], $hits, true)) $hits[] = $e['mapid'];
            }
            if (count($hits) == 1) return $hits[0];
        }

        // 3. zuletzt umgeschaltete Karte, sonst die erste eingelesene
        $sel = $this->ReadAttributeInteger('SelectedMap');
        if ($sel >= 0) return $sel;

        $maps = json_decode($this->ReadAttributeString('MapsRooms'), true);
        if (is_array($maps) && count($maps) > 0 && isset($maps[0]['map_id'])) return intval($maps[0]['map_id']);
        return -1;
    }

    // Setzt "Raum reinigen" und die Auswahlschalter nach Abschluss einer Raumreinigung zurueck.
    // Ablauf ueber das Attribut RoomCleaning: 1 = Start abgesetzt (wartet auf "unterwegs"),
    // 2 = Reinigung laeuft. Erst aus Zustand 2 heraus darf die Auswahl geleert werden - so
    // wird eine Vorauswahl nicht durch eine fremde Fahrt (z. B. "Alles reinigen") geloescht
    // und ein Reset direkt nach dem Start (Roboter noch an der Basis) verhindert.
    private function HandleRoomAutoReset($state)
    {
        // Zustaende, in denen der Roboter unterwegs bzw. mitten in der Reinigung ist
        $busy = $this->BusyStates();
        // Zustaende, die "an der Basis / fertig" bedeuten -> Auswahl darf geleert werden
        $done = [2, 6, 8, 13, 14, 22, 24];

        $phase = $this->ReadAttributeInteger('RoomCleaning');

        if (in_array($state, $busy, true)) {
            if ($phase == 1) $this->WriteAttributeInteger('RoomCleaning', 2);
            return;
        }

        if (in_array($state, $done, true) && $phase == 2) {
            // Reinigung war aktiv und ist nun beendet -> Auswahl zuruecksetzen
            $id = @$this->GetIDForIdent('Raum');
            if ($id && GetValueInteger($id) != 0) $this->SetValueIntegerSafe('Raum', 0);
            $this->ClearRoomSelection();
            $this->WriteAttributeInteger('RoomCleaning', 0);
        }
    }

    // =========================================================================
    // HTTP (curl)
    // =========================================================================

    private function ApiPost($path, $bodyArr)
    {
        $auth = json_decode($this->ReadAttributeString('Auth'), true);
        $headers = [
            'Accept: */*',
            'Content-Type: application/json',
            'User-Agent: ' . self::UA,
            'Authorization: ' . self::BASIC,
            'Tenant-Id: ' . (isset($auth['tenant']) && $auth['tenant'] != '' ? $auth['tenant'] : '000000'),
            'Dreame-Auth: ' . (isset($auth['access']) ? $auth['access'] : '')
        ];
        $body = json_encode($bodyArr);
        return $this->HttpPost($this->BaseUrl() . '/' . $path, $headers, $body);
    }

    private function HttpPost($url, $headers, $body)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ]);
        $resp = curl_exec($ch);
        $code = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
        $err = curl_error($ch);
        if ($resp === false) {
            $this->SendDebug('HTTP', 'POST ' . $url . ' -> curl error: ' . $err, 0);
            return [0, ''];
        }
        return [$code, $resp];
    }

    private function HttpGet($url)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ]);
        $resp = curl_exec($ch);
        return $resp === false ? false : $resp;
    }

    private function BaseUrl()
    {
        return 'https://' . $this->Region() . '.iot.dreame.tech:' . self::PORT;
    }

    private function CmdPath($dev)
    {
        $seg = '';
        if (isset($dev['host']) && $dev['host'] != '') {
            $parts = explode('.', $dev['host']);
            $seg = '-' . $parts[0];
        }
        return 'dreame-iot-com' . $seg . '/device/sendCommand';
    }

    private function Region()
    {
        $r = strtolower(trim($this->ReadPropertyString('Region')));
        return $r == '' ? 'eu' : $r;
    }

    // =========================================================================
    // Klartext-Zuordnungen
    // =========================================================================

    private function StateText($code)
    {
        $s = [
            -1 => 'Unbekannt', 1 => 'Saugen', 2 => 'Leerlauf', 3 => 'Pausiert', 4 => 'Fehler',
            5 => 'Rückkehr zur Basis', 6 => 'Lädt', 7 => 'Wischen', 8 => 'Trocknen', 9 => 'Moppwäsche',
            10 => 'Rückkehr zur Wäsche', 11 => 'Karte wird erstellt', 12 => 'Saugen & Wischen',
            13 => 'Vollständig geladen', 14 => 'Aktualisierung', 21 => 'Moppwäsche pausiert',
            22 => 'Selbstentleerung', 23 => 'Fernsteuerung', 24 => 'Intelligentes Laden',
            27 => 'Punktreinigung', 30 => 'Stationsreinigung', 121 => 'Fährt in die Basis',
            122 => 'Verlässt die Basis'
        ];
        return isset($s[$code]) ? ($s[$code] . ' (' . $code . ')') : ('Status ' . $code);
    }

    // Reinigungsmodus laut Geraet (siid 4 / piid 23). Wert ist gepackt; Modus in den untersten 2 Bits.
    private function CleanModeText($raw)
    {
        $bits = $raw & 3;
        $m = [
            0 => 'Kehren & wischen (zusammen)', 1 => 'Nur wischen',
            2 => 'Nur kehren', 3 => 'Erst kehren, dann wischen'
        ];
        $label = isset($m[$bits]) ? $m[$bits] : ('Bits ' . $bits);
        return $label . ' (roh ' . $raw . ')';
    }

    // Reinigungsroute laut Geraet (Schluessel "CleanRoute" in Property 4/50).
    private function CleanRouteText($v)
    {
        $m = [
            0 => 'nicht gesetzt', 1 => 'Standardreinigung', 2 => 'Intensivreinigung',
            3 => 'Tiefenreinigung', 4 => 'Schnellreinigung'
        ];
        return isset($m[$v]) ? ($m[$v] . ' (' . $v . ')') : ('Wert ' . $v);
    }

    // Holt einen Wert aus der JSON-Property AUTO_SWITCH_SETTINGS (4/50). Das Geraet liefert
    // entweder eine Liste von {"k":...,"v":...} oder ein einzelnes Paar. null = nicht enthalten.
    private function ParseAutoSwitch($raw, $key)
    {
        $d = json_decode(strval($raw), true);
        if (!is_array($d)) return null;
        if (isset($d['k'])) {
            return (strval($d['k']) == $key && isset($d['v'])) ? intval($d['v']) : null;
        }
        foreach ($d as $it) {
            if (is_array($it) && isset($it['k']) && strval($it['k']) == $key && isset($it['v'])) {
                return intval($it['v']);
            }
        }
        return null;
    }

    // Vollstaendige Klartexttabelle, uebersetzt aus ERROR_CODE_TO_ERROR_DESCRIPTION der
    // Referenz-Implementierung (Tasshack/dreame-vacuum, dev).
    private function ErrorText($code)
    {
        if ($code == 0) return 'kein Fehler';

        $e = [
            1 => 'Räder in der Luft',
            2 => 'Fallsensor verschmutzt',
            3 => 'Stoßsensor klemmt',
            4 => 'Roboter steht schräg',
            5 => 'Stoßsensor klemmt',
            6 => 'Räder in der Luft',
            7 => 'Fehler optischer Flusssensor',
            8 => 'Staubbehälter fehlt',
            9 => 'Wassertank fehlt',
            10 => 'Wassertank leer',
            11 => 'Filter feucht oder verstopft',
            12 => 'Hauptbürste blockiert',
            13 => 'Seitenbürste blockiert',
            14 => 'Filter feucht oder verstopft',
            15 => 'Linkes Rad blockiert',
            16 => 'Rechtes Rad blockiert',
            17 => 'Roboter kann nicht drehen',
            18 => 'Roboter kommt nicht vorwärts',
            19 => 'Station nicht gefunden',
            20 => 'Akku fast leer',
            21 => 'Ladefehler – Ladekontakte reinigen',
            22 => 'Fehler Akkustand',
            23 => 'Interner Fehler',
            24 => 'Fehler Kamerapositionierung',
            25 => 'Fehler Bewegungssensor',
            26 => 'Optischer Sensor verdeckt',
            27 => 'Infrarotsensor verdeckt',
            28 => 'Ladestation ohne Strom',
            29 => 'Akkutemperatur außerhalb des Bereichs',
            30 => 'Fehler Lüfterdrehzahl',
            31 => 'Linkes Rad blockiert',
            32 => 'Rechtes Rad blockiert',
            33 => 'Fehler Beschleunigungssensor',
            34 => 'Fehler Gyroskop',
            35 => 'Fehler Gyroskop',
            36 => 'Fehler linker Magnetsensor',
            37 => 'Fehler rechter Magnetsensor',
            38 => 'Fehler Durchflusssensor',
            39 => 'Fehler Infrarot',
            40 => 'Fehler Kamera',
            41 => 'Starkes Magnetfeld erkannt',
            42 => 'Fehler Wasserpumpe',
            43 => 'Fehler Echtzeituhr',
            44 => 'Interner Fehler',
            45 => 'Interner Fehler',
            46 => 'Interner Fehler',
            47 => 'Weg blockiert – Rückkehr zur Station',
            48 => 'Fehler Laserdistanzsensor',
            49 => 'Stoßschutz des Laserdistanzsensors klemmt',
            50 => 'Fehler Wasserpumpe',
            51 => 'Filter feucht oder verstopft',
            54 => 'Fehler Kantensensor',
            55 => 'Teppich unter dem Roboter – auf Hartboden starten',
            56 => 'Fehler 3D-Hinderniserkennung',
            57 => 'Fehler Kantensensor',
            58 => 'Fehler Ultraschallsensor',
            59 => 'Sperrzone oder virtuelle Wand erkannt',
            61 => 'Bereich nicht erreichbar – Türen öffnen, Hindernisse entfernen',
            62 => 'Bereich nicht erreichbar – Sperrzone im Weg',
            63 => 'Weg blockiert – Hindernisse entfernen',
            64 => 'Weg blockiert – Sperrzone im Weg',
            65 => 'Roboter steht in einer Sperrzone',
            66 => 'Roboter steht in einer Sperrzone',
            67 => 'Roboter steht in einer Sperrzone',
            68 => 'Wischen beendet – Mopp abnehmen und reinigen',
            69 => 'Moppauflage hat sich gelöst',
            70 => 'Moppauflage hat sich gelöst',
            71 => 'Moppauflage dreht nicht',
            72 => 'Moppauflage dreht nicht',
            74 => 'Moppauflage konnte nicht aufgenommen werden',
            75 => 'Akku fast leer – Roboter schaltet ab',
            76 => 'Schmutzwassertank des Roboters fehlt',
            78 => 'Roboter in einem ausgeblendeten Bereich',
            79 => 'LDS-Modul konnte nicht ausfahren',
            80 => 'Positionierung nicht möglich – in freien Bereich stellen',
            81 => 'Positionierung nicht möglich – in freien Bereich stellen',
            82 => 'Rutschiger Boden',
            84 => 'Unbekannter Fehler',
            85 => 'Moppmontage prüfen',
            86 => 'Schmutzwassertank verschmutzt – reinigen',
            88 => 'Teleskopbeine könnten verheddert sein',
            89 => 'Interner Fehler',
            90 => 'Roboter sitzt fest',
            91 => 'Roboter zwischen Tisch und Stühlen festgefahren',
            92 => 'Roboter in einer Engstelle festgefahren',
            93 => 'Roboter an einer Schwelle festgefahren',
            94 => 'Roboter in einem niedrigen Bereich festgefahren',
            95 => 'Absturzgefährdete Rampe auf dem Weg erkannt',
            96 => 'Hindernis auf dem Weg',
            97 => 'Person oder Tier auf dem Weg',
            98 => 'Roboter rutscht durch – Antriebsräder reinigen',
            99 => 'Roboter rutscht auf Teppich',
            101 => 'Staubbeutel voll oder Luftkanal verstopft',
            102 => 'Deckel der Absaugstation offen oder Staubbeutel fehlt',
            103 => 'Deckel der Absaugstation offen oder Staubbeutel fehlt',
            104 => 'Staubbeutel voll oder Luftkanal verstopft',
            105 => 'Frischwassertank fehlt',
            106 => 'Schmutzwassertank voll oder fehlt',
            107 => 'Frischwassertank fast leer',
            108 => 'Schmutzwassertank voll',
            109 => 'Schmutzwasserkanal blockiert',
            110 => 'Fehler Schmutzwasserpumpe',
            111 => 'Waschbrett nicht richtig eingesetzt',
            112 => 'Wasserstand im Waschbrett auffällig – reinigen',
            114 => 'Reinigung beendet – Waschbrett reinigen',
            116 => 'Frischwassertank nachfüllen',
            117 => 'Basisstation ohne Strom',
            118 => 'Wasserstand im Schmutzwassertank zu hoch',
            119 => 'Wasserstand im Waschbrett zu hoch',
            120 => 'Moppauflage nicht in der Station',
            121 => 'Staubbeutel prüfen',
            122 => 'Unbekannte Warnung',
            123 => 'Selbsttest fehlgeschlagen – kein Wasser im Frischwassertank',
            124 => 'Waschbrett arbeitet nicht – auf Verhedderung prüfen',
            125 => 'Schmutzwasser wird nicht abgepumpt',
            126 => 'Mopp nicht erkannt',
            127 => 'Fehler Mopphalter in der Station',
            128 => 'Fehler Station – Klappe und Reihenfolge der Mopps prüfen',
            129 => 'Waschen des Mopps fehlgeschlagen',
            200 => 'Roboter im Vorhangbereich festgefahren',
            201 => 'Kantenmopp dreht nicht',
            202 => 'Kantenmopp abgefallen',
            203 => 'Fehler Chassis-Hubmechanik',
            207 => 'Interner Fehler',
            209 => 'Fehler Moppabdeckung',
            210 => 'Fehler Walzenmopp',
            212 => 'Roboterarm gestoppt',
            213 => 'Frischwasserbehälter im Roboter fast leer',
            214 => 'Schmutzwasserbehälter im Roboter voll',
            215 => 'Mopp nicht montiert',
            217 => 'Fehler Laserdistanzsensor',
            218 => 'Fehler Walzenmopp',
            222 => 'Fehler Aufplusterwalze',
            223 => 'Fehler Moppabdeckung',
            224 => 'Fehler Moppabdeckung',
            225 => 'Fehler Walzenmopp',
            226 => 'Roboter durch Hindernis blockiert',
            227 => 'Abwasserfilter des Roboters verstopft',
            228 => 'Fehler Antriebsräder',
            229 => 'Interner Fehler',
            230 => 'Interner Fehler',
            1000 => 'Rückkehr zur Ladestation fehlgeschlagen'
        ];

        // KEIN "Hinweis"-Zusatz mehr (Wunsch 06.08.2026: erst vorn weg, dann ganz raus).
        // Nur zur Info, falls es je wieder gebraucht wird: das Geraet fuehrt diese Codes
        // selbst als quittierbare Warnung (WARNING_ERROR_CODE in der Referenz) -
        // 9, 10, 20, 47, 51, 56, 68, 70, 71, 72, 75, 82, 85, 107, 114, 117, 121, 122,
        // 123, 129, 213, 214. Der Meldungstext sagt ohnehin, worum es geht.
        $text = isset($e[$code]) ? $e[$code] : ('Unbekannter Code ' . $code);
        return $text . ' (' . $code . ')';
    }

    // =========================================================================
    // Timer / Fast-Poll
    // =========================================================================

    private function UpdatePollTimer($forceFast)
    {
        $iv = intval($this->ReadPropertyInteger('UpdateInterval'));
        if ($iv < 10) $iv = 60;
        $fastUntil = intval($this->GetBuffer('FastUntil'));
        if ($forceFast || $fastUntil > time()) {
            $this->SetTimerInterval('Poll', 5000);
        } else {
            $this->SetTimerInterval('Poll', $iv * 1000);
        }
    }

    private function BumpFastPoll()
    {
        $sec = intval($this->ReadPropertyInteger('FastAfterChange'));
        if ($sec < 5) $sec = 20;
        $this->SetBuffer('FastUntil', strval(time() + $sec));
        $this->UpdatePollTimer(true);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function SetOnline($online, $err)
    {
        $this->SetValueBooleanSafe('Online', (bool)$online);
        if ($err === null) $err = '';
        $id = $this->GetIDForIdent('LastError');
        if (GetValueString($id) !== strval($err)) {
            $this->SetValueStringSafe('LastError', strval($err));
        }
    }

    // Nur die Fehlertext-Variable schreiben, Online-Status unveraendert lassen
    private function SetLastError($err)
    {
        $this->SetValueStringSafe('LastError', strval($err));
    }

    private function TryLock()
    {
        $key = 'DREAME_' . $this->InstanceID;
        for ($i = 0; $i < 15; $i++) {
            if (IPS_SemaphoreEnter($key, 100)) return true;
            IPS_Sleep(30);
        }
        return false;
    }

    private function Unlock()
    {
        @IPS_SemaphoreLeave('DREAME_' . $this->InstanceID);
    }

    private function RegisterProfileInteger($name, $icon, $prefix, $suffix, $min, $max, $step)
    {
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, 1);
            IPS_SetVariableProfileIcon($name, $icon);
            IPS_SetVariableProfileText($name, $prefix, $suffix);
            if ($max > $min) {
                IPS_SetVariableProfileValues($name, $min, $max, $step);
            }
        }
    }

    private function SetAssociations($profile, $assocs)
    {
        if (!IPS_VariableProfileExists($profile)) return;
        $p = IPS_GetVariableProfile($profile);
        if (isset($p['Associations']) && is_array($p['Associations'])) {
            foreach ($p['Associations'] as $a) {
                IPS_SetVariableProfileAssociation($profile, $a['Value'], '', '', -1);
            }
        }
        for ($i = 0; $i < count($assocs); $i++) {
            IPS_SetVariableProfileAssociation($profile, $assocs[$i][0], $assocs[$i][1], $assocs[$i][2], $assocs[$i][3]);
        }
    }

    private function SetValueBooleanSafe($ident, $value)
    {
        $id = $this->GetIDForIdent($ident);
        if (GetValueBoolean($id) !== (bool)$value) SetValueBoolean($id, (bool)$value);
    }

    private function SetValueIntegerSafe($ident, $value)
    {
        $id = $this->GetIDForIdent($ident);
        $v = intval($value);
        if (GetValueInteger($id) !== $v) SetValueInteger($id, $v);
    }

    private function SetValueFloatSafe($ident, $value)
    {
        $id = $this->GetIDForIdent($ident);
        $v = floatval($value);
        if (GetValueFloat($id) !== $v) SetValueFloat($id, $v);
    }

    private function SetValueStringSafe($ident, $value)
    {
        $id = $this->GetIDForIdent($ident);
        $v = strval($value);
        if (GetValueString($id) !== $v) SetValueString($id, $v);
    }
}
