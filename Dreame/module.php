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
 * - Polling: ein Timer (UpdateInterval) fuer den Status; kurzer Fast-Poll nach Aktionen.
 * - Stabilitaet: Semaphore-Lock, niemals Fatals; Online/LastError nur bei Aenderung.
 *
 * MiOT-Kennungen (dreame.vacuum.r2532a / 5. Gen):
 *   State 2/1, Error 2/2, Akku 3/1, Charging 3/2, Status 4/1, Reinigungszeit 4/2,
 *   Flaeche 4/3, Saugkraft 4/4, Wasser 4/5, Wassertank 4/6, Task 4/7, MAP_EXTEND 6/4, MAP_LIST 6/8.
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

        // ---- Attribute (persistent) ----
        $this->RegisterAttributeString('Auth', '');       // Token-Cache {access,refresh,exp,uid,tenant}
        $this->RegisterAttributeString('Device', '');      // {did,model,host}
        $this->RegisterAttributeString('MapsRooms', '');   // [{map_id,floor,rooms:[{seg,name}]}]
        $this->RegisterAttributeInteger('SelectedMap', -1);

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
        $this->RegisterVariableFloat('CleaningArea', 'Gereinigte Flaeche (m2)', '', 70);

        // ---- Steuerung: Aktion (Dropdown) ----
        $this->RegisterProfileInteger('DREAME.Action', 'Execute', '', '', 0, 0, 0);
        $this->SetAssociations('DREAME.Action', [
            [0, '—', '', -1],
            [1, 'Alles reinigen', '', 0x00A000],
            [2, 'Pause', '', -1],
            [3, 'Stopp', '', -1],
            [4, 'Zur Basis', '', -1],
            [5, 'Orten (Piepen)', '', -1]
        ]);
        $this->RegisterVariableInteger('Aktion', 'Aktion', 'DREAME.Action', 80);
        $this->EnableAction('Aktion');

        // ---- Steuerung: Raum (Dropdown, dynamisch) ----
        $roomProfile = $this->RoomProfileName();
        $this->RegisterProfileInteger($roomProfile, 'Move', '', '', 0, 0, 0);
        $this->SetAssociations($roomProfile, [[0, '— Raum waehlen —', '', -1]]);
        $this->RegisterVariableInteger('Raum', 'Raum reinigen', $roomProfile, 90);
        $this->EnableAction('Raum');

        // ---- Timer ----
        $this->RegisterTimer('Poll', 0, 'DREAME_UpdateStatus($_IPS["TARGET"]);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Token-/Geraete-Cache verwerfen, damit Konfigaenderungen frisch greifen
        $this->WriteAttributeString('Auth', '');
        $this->WriteAttributeString('Device', '');

        // Raumprofil aus gespeicherten Karten wiederherstellen (falls vorhanden)
        $this->RebuildRoomProfile();

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

        // Entdeckte Raeume als Liste in die Konfig einblenden
        $rows = [];
        $maps = json_decode($this->ReadAttributeString('MapsRooms'), true);
        if (is_array($maps)) {
            foreach ($maps as $m) {
                foreach ($m['rooms'] as $r) {
                    $rows[] = [
                        'floor' => $m['floor'],
                        'mapid' => $m['map_id'],
                        'seg'   => $r['seg'],
                        'name'  => $r['name']
                    ];
                }
            }
        }
        // Platzhalter-Element "DiscoveredRooms" mit Werten fuellen
        foreach ($form['elements'] as &$el) {
            if (isset($el['items'])) {
                foreach ($el['items'] as &$it) {
                    if (isset($it['name']) && $it['name'] == 'DiscoveredRooms') {
                        $it['values'] = $rows;
                    }
                }
            }
        }
        return json_encode($form);
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
            }
            // Auswahl wieder auf "—" zuruecksetzen
            $this->SetValueIntegerSafe('Aktion', 0);
            $this->BumpFastPoll();
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
        echo "FEHLER - Login fehlgeschlagen. Bitte E-Mail/Passwort/Region pruefen.";
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
            $this->RebuildRoomProfile();
            $this->SetOnline(true, '');

            $cnt = 0;
            foreach ($maps as $m) $cnt += count($m['rooms']);
            $this->LogMessage('Karten/Raeume eingelesen: ' . count($maps) . ' Etage(n), ' . $cnt . ' Raeume.', KL_NOTIFY);
            echo 'OK - ' . count($maps) . " Etage(n) eingelesen:\n";
            foreach ($maps as $m) {
                echo '  ' . $m['floor'] . ' (map_id ' . $m['map_id'] . '): ';
                $names = [];
                foreach ($m['rooms'] as $r) $names[] = $r['name'] . ' [' . $r['seg'] . ']';
                echo implode(', ', $names) . "\n";
            }
            return true;
        } finally {
            $this->Unlock();
        }
    }

    public function CleanAll()
    {
        return $this->DoAction(2, 1, []);
    }

    // mapId = Etagen-/Karten-ID, segmentId = Raum-ID
    public function CleanRoom($mapId, $segmentId)
    {
        $mapId = intval($mapId);
        $seg   = intval($segmentId);
        if (!$this->TryLock()) return false;
        try {
            if (!$this->Login(false)) { $this->SetOnline(false, 'Login fehlgeschlagen.'); return false; }
            if ($this->EnsureDevice(false) === false) { $this->SetOnline(false, 'Kein Geraet.'); return false; }

            // Bei mehreren Etagen: zuerst auf die Zielkarte umschalten
            if ($this->ReadAttributeInteger('SelectedMap') != $mapId) {
                $sm = json_encode(['sm' => (object)[], 'mapid' => $mapId]);
                $this->Action(6, 2, [['piid' => 4, 'value' => $sm]]);
                $this->WriteAttributeInteger('SelectedMap', $mapId);
                IPS_Sleep(4000);
            }

            // Saugkraft/Wasser vom Geraet uebernehmen (Fallback 1/2)
            $p = $this->GetProperties([[4, 4], [4, 5]]);
            $fan   = isset($p['4.4']) ? intval($p['4.4']) : 1;
            $water = isset($p['4.5']) ? intval($p['4.5']) : 2;

            $selects = json_encode(['selects' => [[$seg, 1, $fan, $water, 1]]]);
            $in = [
                ['piid' => 1, 'value' => 18],       // Status = SEGMENT_CLEANING
                ['piid' => 10, 'value' => $selects]  // CLEANING_PROPERTIES
            ];
            $r = $this->Action(4, 1, $in);
            if ($r === false) { $this->SetOnline(false, 'Raum-Start fehlgeschlagen.'); return false; }
            $this->SetOnline(true, '');
            $this->BumpFastPoll();
            return true;
        } finally {
            $this->Unlock();
        }
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

            $p = $this->GetProperties([[2, 1], [2, 2], [3, 1], [4, 2], [4, 3]]);
            if (count($p) == 0) { $this->SetOnline(false, 'Keine Statusantwort.'); return; }
            $this->SetOnline(true, '');

            if (isset($p['2.1'])) $this->SetValueStringSafe('Zustand', $this->StateText(intval($p['2.1'])));
            if (isset($p['2.2'])) $this->SetValueStringSafe('Fehler', $this->ErrorText(intval($p['2.2'])));
            if (isset($p['3.1'])) $this->SetValueIntegerSafe('Battery', intval($p['3.1']));
            if (isset($p['4.2'])) $this->SetValueIntegerSafe('CleaningTime', intval($p['4.2']));
            if (isset($p['4.3'])) $this->SetValueFloatSafe('CleaningArea', floatval($p['4.3']));
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
            1 => 'Wohnzimmer', 2 => 'Schlafzimmer', 3 => 'Arbeitszimmer', 4 => 'Kueche',
            5 => 'Esszimmer', 6 => 'Badezimmer', 7 => 'Balkon', 8 => 'Flur', 9 => 'Hauswirtschaftsraum',
            10 => 'Ankleide', 11 => 'Besprechungsraum', 12 => 'Buero', 13 => 'Fitnessbereich',
            14 => 'Freizeitbereich', 15 => 'Gaestezimmer'
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

    private function RebuildRoomProfile()
    {
        $profile = $this->RoomProfileName();
        if (!IPS_VariableProfileExists($profile)) return;

        $assocs = [[0, '— Raum waehlen —', '', -1]];
        $maps = json_decode($this->ReadAttributeString('MapsRooms'), true);
        $multi = is_array($maps) && count($maps) > 1;
        if (is_array($maps)) {
            foreach ($maps as $m) {
                foreach ($m['rooms'] as $r) {
                    $code = ($m['map_id'] * 1000) + intval($r['seg']);
                    $label = $multi ? ($m['floor'] . ' . ' . $r['name']) : $r['name'];
                    $assocs[] = [$code, $label, '', -1];
                }
            }
        }
        $this->SetAssociations($profile, $assocs);
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
            5 => 'Rueckkehr zur Basis', 6 => 'Laedt', 7 => 'Wischen', 8 => 'Trocknen', 9 => 'Moppwaesche',
            10 => 'Rueckkehr zur Waesche', 11 => 'Karte wird erstellt', 12 => 'Saugen & Wischen',
            13 => 'Vollstaendig geladen', 14 => 'Aktualisierung', 21 => 'Moppwaesche pausiert',
            22 => 'Selbstentleerung', 23 => 'Fernsteuerung', 24 => 'Intelligentes Laden',
            27 => 'Punktreinigung', 30 => 'Stationsreinigung', 121 => 'Faehrt in die Basis',
            122 => 'Verlaesst die Basis'
        ];
        return isset($s[$code]) ? ($s[$code] . ' (' . $code . ')') : ('Status ' . $code);
    }

    private function ErrorText($code)
    {
        if ($code == 0) return 'kein Fehler';
        $e = [
            101 => 'Staubbehaelter voll', 105 => 'Frischwassertank pruefen', 106 => 'Schmutzwassertank voll',
            107 => 'Frischwassertank leer', 108 => 'Schmutzwassertank voll', 109 => 'Schmutzwasserkanal blockiert'
        ];
        return isset($e[$code]) ? ($e[$code] . ' (' . $code . ')') : ('Fehler ' . $code);
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
