<?php

declare(strict_types=1);

class SmartAlarmManager extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString("MonitoredVariables", "[]");
        $this->RegisterPropertyString("ActionProfiles", "[]");
        $this->RegisterPropertyInteger("EscalationTimeLvl2", 300);
        $this->RegisterPropertyInteger("EscalationTimeLvl3", 900);
        $this->RegisterPropertyInteger("TargetWebFront", 0);
        $this->RegisterPropertyInteger("TargetSMTP", 0);
        $this->RegisterPropertyInteger("TargetVestaboard", 0);
        $this->RegisterPropertyInteger("TargetSonos", 0);
        $this->RegisterPropertyString("EmailAddress", "");
        
        $this->RegisterTimer("EscalationTimer", 0, 'SAM_CheckEscalation($_IPS[\'TARGET\']);');
        $this->RegisterTimer("DelayTimer", 0, 'SAM_HandleDelays($_IPS[\'TARGET\']);');
        
        $this->SetBuffer("ActiveAlarms", "{}");
        $this->SetBuffer("ActiveDelays", "{}");

        // Profiles for Tile UI
        if (!IPS_VariableProfileExists("SAM.SystemStatus")) {
            IPS_CreateVariableProfile("SAM.SystemStatus", 1);
            IPS_SetVariableProfileAssociation("SAM.SystemStatus", 0, "Alles OK", "Ok", 0x00FF00);
            IPS_SetVariableProfileAssociation("SAM.SystemStatus", 1, "Info / Hinweis", "Information", 0xFFFF00);
            IPS_SetVariableProfileAssociation("SAM.SystemStatus", 2, "ALARM!", "Warning", 0xFF0000);
            IPS_SetVariableProfileAssociation("SAM.SystemStatus", 3, "ESKALATION", "Warning", 0xFF0000);
            IPS_SetVariableProfileAssociation("SAM.SystemStatus", 4, "VOLLALARM", "Alert", 0xFF0000);
        }

        // Summary Variables for Tile UI
        $this->RegisterVariableInteger("SystemStatus", "System Status", "SAM.SystemStatus", 1);
        $this->RegisterVariableInteger("ActiveAlarmsCount", "Aktive Alarme", "", 2);
        $this->RegisterVariableString("LastEvent", "Letztes Ereignis", "", 3);
        $this->RegisterVariableBoolean("AcknowledgeAll", "Alle Alarme quittieren", "~Switch", 4);
        $this->EnableAction("AcknowledgeAll");
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Unregister all old messages
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }

        $monitored = json_decode($this->ReadPropertyString("MonitoredVariables"), true);
        if (!is_array($monitored)) $monitored = [];

        $activeIdents = [];

        foreach ($monitored as $item) {
            $vid = $item['VariableID'] ?? 0;
            if ($vid > 0 && IPS_VariableExists($vid)) {
                $this->RegisterMessage($vid, VM_UPDATE);
                
                if (($item['AlarmType'] ?? 0) == 0) {
                    $ident = "Alarm_" . $vid;
                    $activeIdents[] = $ident;
                    $this->MaintainVariable($ident, "Status: " . ($item['Message'] ?? 'Alarm'), 0, "~Alert", 0, true);
                    $this->EnableAction($ident);
                }
            }
        }
        
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $childID) {
            $ident = IPS_GetObject($childID)['ObjectIdent'];
            if (strpos($ident, "Alarm_") === 0) {
                if (!in_array($ident, $activeIdents)) {
                    $this->MaintainVariable($ident, "", 0, "", 0, false);
                }
            }
        }
    }

    private function GetActionProfiles($profileID)
    {
        $matches = [];
        $profiles = json_decode($this->ReadPropertyString("ActionProfiles"), true);
        if (is_array($profiles)) {
            foreach ($profiles as $p) {
                if (($p['ProfileID'] ?? '') === $profileID) {
                    $matches[] = $p;
                }
            }
        }
        return $matches;
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        $monitored = json_decode($this->ReadPropertyString("MonitoredVariables"), true);
        if (!is_array($monitored)) return;

        $currentVal = $Data[0]; 
        
        foreach ($monitored as $item) {
            $vid = $item['VariableID'] ?? 0;
            if ($vid == $SenderID) {
                $triggerVal = $item['TriggerValue'] ?? 'true';
                if ($this->IsTriggered($currentVal, $triggerVal)) {
                    $delay = $item['DelaySeconds'] ?? 0;
                    if ($delay > 0) {
                        $delays = json_decode($this->GetBuffer("ActiveDelays"), true) ?: [];
                        if (!isset($delays[$vid])) {
                            $delays[$vid] = [
                                "triggerTime" => time() + $delay,
                                "item" => $item
                            ];
                            $this->SetBuffer("ActiveDelays", json_encode($delays));
                            $this->SetTimerInterval("DelayTimer", 1000);
                        }
                    } else {
                        $this->HandleTrigger($item);
                    }
                } else {
                    $delays = json_decode($this->GetBuffer("ActiveDelays"), true) ?: [];
                    if (isset($delays[$vid])) {
                        unset($delays[$vid]);
                        $this->SetBuffer("ActiveDelays", json_encode($delays));
                        if (empty($delays)) {
                            $this->SetTimerInterval("DelayTimer", 0);
                        }
                    }
                }
            }
        }
    }

    public function HandleDelays()
    {
        $delays = json_decode($this->GetBuffer("ActiveDelays"), true) ?: [];
        if (empty($delays)) {
            $this->SetTimerInterval("DelayTimer", 0);
            return;
        }

        $now = time();
        $changed = false;

        foreach ($delays as $vid => $delayObj) {
            if ($now >= $delayObj['triggerTime']) {
                $this->HandleTrigger($delayObj['item']);
                unset($delays[$vid]);
                $changed = true;
            }
        }

        if ($changed) {
            $this->SetBuffer("ActiveDelays", json_encode($delays));
            if (empty($delays)) {
                $this->SetTimerInterval("DelayTimer", 0);
            }
        }
    }

    private function HandleTrigger($item)
    {
        $type = $item['AlarmType'] ?? 0;
        $msg = $item['Message'] ?? "Alarm ausgelöst";
        $vid = $item['VariableID'];
        
        $profileIdStr = $item['ProfileID'] ?? '';
        $profileIds = array_map('trim', explode(',', $profileIdStr));
        $profiles = [];
        foreach ($profileIds as $pid) {
            if (empty($pid)) continue;
            $matchedProfiles = $this->GetActionProfiles($pid);
            foreach ($matchedProfiles as $p) {
                $profiles[] = $p;
            }
        }

        if ($type == 1) {
            $this->LogMessage("Info/Event ausgelöst: " . $msg, KL_NOTIFY);
            $this->SendDebug("Trigger", "Info/Event: " . $msg, 0);
            
            foreach ($profiles as $profile) {
                $this->TriggerInfo($profile, $msg);
            }
            
            $this->SetValue("LastEvent", date("d.m.Y H:i:s") . " - " . $msg);
            if ($this->GetValue("SystemStatus") == 0) {
                $this->SetValue("SystemStatus", 1);
                IPS_Sleep(3000); 
                $this->UpdateStatusVariables(); 
            }
        } else {
            $alarms = json_decode($this->GetBuffer("ActiveAlarms"), true) ?: [];
            
            if (!isset($alarms[$vid])) {
                $alarms[$vid] = [
                    "timestamp" => time(),
                    "level" => 1,
                    "item" => $item,
                    "profiles" => $profiles
                ];
                $this->SetBuffer("ActiveAlarms", json_encode($alarms));
                
                $this->LogMessage("ALARM ausgelöst (Stufe 1): " . $msg, KL_WARNING);
                $this->SendDebug("Trigger", "Alarm Stufe 1: " . $msg, 0);
                
                foreach ($profiles as $profile) {
                    $this->TriggerLevel1($profile, $msg);
                }
                
                $ident = "Alarm_" . $vid;
                if (@IPS_GetObjectIDByIdent($ident, $this->InstanceID)) {
                    $this->SetValue($ident, true);
                }
                
                $this->SetValue("LastEvent", date("d.m.Y H:i:s") . " - ALARM: " . $msg);
                $this->SetTimerInterval("EscalationTimer", 10000);
                $this->UpdateStatusVariables();
            }
        }
    }

    public function RequestAction($Ident, $Value)
    {
        if (strpos($Ident, "Alarm_") === 0) {
            if ($Value == false) {
                $this->SetValue($Ident, false);
                $this->LogMessage("Alarm quittiert: " . $Ident, KL_NOTIFY);
                $this->SendDebug("Acknowledge", "Quittiert: " . $Ident, 0);
                
                $vid = substr($Ident, 6);
                $alarms = json_decode($this->GetBuffer("ActiveAlarms"), true) ?: [];
                if (isset($alarms[$vid])) {
                    $profiles = $alarms[$vid]['profiles'] ?? [];
                    if (empty($profiles) && isset($alarms[$vid]['profile'])) {
                        $profiles = [$alarms[$vid]['profile']];
                    }
                    foreach ($profiles as $profile) {
                        $this->TriggerHomematicLEDs($profile, true); 
                        $this->TriggerHomematicSirens($profile, true); 
                    }
                    unset($alarms[$vid]);
                    $this->SetBuffer("ActiveAlarms", json_encode($alarms));
                }
                
                if (empty($alarms)) {
                    $this->SetTimerInterval("EscalationTimer", 0);
                }
                $this->UpdateStatusVariables();
            }
        } elseif ($Ident === "AcknowledgeAll") {
            if ($Value == true) {
                $alarms = json_decode($this->GetBuffer("ActiveAlarms"), true) ?: [];
                foreach ($alarms as $vid => $alarm) {
                    $ident = "Alarm_" . $vid;
                    if (@IPS_GetObjectIDByIdent($ident, $this->InstanceID)) {
                        $this->SetValue($ident, false);
                    }
                }
                
                // Turn off ALL configured devices in ALL profiles to be safe (and to clear tests)
                $profiles = json_decode($this->ReadPropertyString("ActionProfiles"), true);
                if (is_array($profiles)) {
                    foreach ($profiles as $profile) {
                        $this->TriggerHomematicLEDs($profile, true); 
                        $this->TriggerHomematicSirens($profile, true); 
                    }
                }
                
                $this->SetBuffer("ActiveAlarms", "{}");
                $this->SetTimerInterval("EscalationTimer", 0);
                $this->UpdateStatusVariables();
                $this->LogMessage("Alle Alarme quittiert.", KL_NOTIFY);
                $this->SetValue("LastEvent", date("d.m.Y H:i:s") . " - Alle Alarme quittiert");
                
                $this->SetValue("AcknowledgeAll", false);
            }
        } else {
            throw new Exception("Invalid Ident");
        }
    }

    public function CheckEscalation()
    {
        $alarms = json_decode($this->GetBuffer("ActiveAlarms"), true) ?: [];
        if (empty($alarms)) {
            $this->SetTimerInterval("EscalationTimer", 0);
            return;
        }

        $now = time();
        $changed = false;
        $lvl2_time = $this->ReadPropertyInteger("EscalationTimeLvl2");
        $lvl3_time = $this->ReadPropertyInteger("EscalationTimeLvl3");

        foreach ($alarms as $vid => &$alarm) {
            $elapsed = $now - $alarm['timestamp'];
            $msg = $alarm['item']['Message'] ?? "Alarm";
            
            $profiles = $alarm['profiles'] ?? [];
            if (empty($profiles) && isset($alarm['profile'])) {
                $profiles = [$alarm['profile']];
            }

            if ($alarm['level'] == 1 && $elapsed >= $lvl2_time) {
                $alarm['level'] = 2;
                $changed = true;
                $this->LogMessage("Alarm ESKALATION (Stufe 2): " . $msg, KL_WARNING);
                $this->SendDebug("Escalation", "Stufe 2: " . $msg, 0);
                foreach ($profiles as $profile) {
                    $this->TriggerLevel2($profile, $msg);
                }
            }

            if ($alarm['level'] == 2 && $elapsed >= $lvl3_time) {
                $alarm['level'] = 3;
                $changed = true;
                $this->LogMessage("VOLLALARM (Stufe 3): " . $msg, KL_ERROR);
                $this->SendDebug("Escalation", "Stufe 3: " . $msg, 0);
                foreach ($profiles as $profile) {
                    $this->TriggerLevel3($profile, $msg);
                }
            }
        }

        if ($changed) {
            $this->SetBuffer("ActiveAlarms", json_encode($alarms));
            $this->UpdateStatusVariables();
        }
    }

    private function UpdateStatusVariables()
    {
        $alarms = json_decode($this->GetBuffer("ActiveAlarms"), true) ?: [];
        $count = count($alarms);
        $this->SetValue("ActiveAlarmsCount", $count);
        
        if ($count == 0) {
            if ($this->GetValue("SystemStatus") > 1) {
                $this->SetValue("SystemStatus", 0);
            }
        } else {
            $maxLevel = 1;
            foreach ($alarms as $alarm) {
                if ($alarm['level'] > $maxLevel) {
                    $maxLevel = $alarm['level'];
                }
            }
            $this->SetValue("SystemStatus", $maxLevel + 1);
        }
    }

    private function TriggerLevel1($profile, $message)
    {
        $this->LogMessage("--- Starte Aktions-Profil Level 1 ---", KL_NOTIFY);
        
        if ($profile['UseWebFront'] ?? false) {
            $visu = $this->ReadPropertyInteger("TargetWebFront");
            if ($visu > 0 && IPS_InstanceExists($visu)) {
                $this->LogMessage("App/Visu: Sende Push & Notification", KL_NOTIFY);
                if (function_exists('VISU_PostNotification')) {
                    VISU_PostNotification($visu, "Alarm!", $message, "Warning", 0);
                }
            }
        }
        
        if ($profile['UseSonos'] ?? false) {
            $this->LogMessage("Sonos: Spiele TTS", KL_NOTIFY);
            $this->TriggerSonos($message);
        }
        
        $this->TriggerHomematicMP3($profile);
        $this->TriggerHomematicLEDs($profile);
        $this->TriggerHomematicSirens($profile);
        $this->TriggerTargetVariable($profile);
    }

    private function TriggerLevel2($profile, $message)
    {
        $this->LogMessage("--- Starte Aktions-Profil Level 2 (ESKALATION) ---", KL_NOTIFY);
        
        if ($profile['UseVestaboard'] ?? false) {
            $vesta = $this->ReadPropertyInteger("TargetVestaboard");
            if ($vesta > 0 && IPS_InstanceExists($vesta)) {
                if (function_exists('VESTA_SendMessage')) {
                    $this->LogMessage("Vestaboard: Sende Nachricht", KL_NOTIFY);
                    @VESTA_SendMessage($vesta, "ALARM:\n" . $message);
                }
            }
        }

        if ($profile['UseEmail'] ?? false) {
            $smtp = $this->ReadPropertyInteger("TargetSMTP");
            $email = trim($this->ReadPropertyString("EmailAddress"));
            if ($smtp > 0 && IPS_InstanceExists($smtp)) {
                if ($email != "") {
                    $this->LogMessage("E-Mail: Sende Mail an $email", KL_NOTIFY);
                    @SMTP_SendMailEx($smtp, $email, "SmartHome Alarm Stufe 2", "Folgender Alarm wurde ausgelöst und noch nicht quittiert:\n\n" . $message);
                }
            }
        }
        
        if ($profile['UseSonos'] ?? false) {
            $this->LogMessage("Sonos: Spiele TTS", KL_NOTIFY);
            $this->TriggerSonos("Achtung, Alarm: " . $message);
        }
        
        $this->TriggerHomematicMP3($profile);
        $this->TriggerHomematicLEDs($profile);
        $this->TriggerHomematicSirens($profile);
        $this->TriggerTargetVariable($profile);
    }

    private function TriggerLevel3($profile, $message)
    {
        $this->LogMessage("--- Starte Aktions-Profil Level 3 (VOLLALARM) ---", KL_NOTIFY);
        
        if ($profile['UseVestaboard'] ?? false) {
            $vesta = $this->ReadPropertyInteger("TargetVestaboard");
            if ($vesta > 0 && IPS_InstanceExists($vesta)) {
                if (function_exists('VESTA_SendMessage')) {
                    $this->LogMessage("Vestaboard: Sende Nachricht", KL_NOTIFY);
                    @VESTA_SendMessage($vesta, "!!! VOLLALARM !!!\n" . $message);
                }
            }
        }
        
        if ($profile['UseWebFront'] ?? false) {
            $visu = $this->ReadPropertyInteger("TargetWebFront");
            if ($visu > 0 && IPS_InstanceExists($visu)) {
                $this->LogMessage("App/Visu: Sende Push & Notification", KL_NOTIFY);
                if (function_exists('VISU_PostNotification')) {
                    VISU_PostNotification($visu, "VOLLALARM", $message, "Alert", 0);
                }
            }
        }
        
        if ($profile['UseSonos'] ?? false) {
            $this->LogMessage("Sonos: Spiele TTS", KL_NOTIFY);
            $this->TriggerSonos("Vollalarm: " . $message);
        }
        
        $this->TriggerHomematicMP3($profile);
        $this->TriggerHomematicLEDs($profile);
        $this->TriggerHomematicSirens($profile);
        $this->TriggerTargetVariable($profile);
    }

    private function TriggerInfo($profile, $message)
    {
        $this->LogMessage("--- Starte Aktions-Profil Info/Event ---", KL_NOTIFY);
        
        if ($profile['UseWebFront'] ?? false) {
            $visu = $this->ReadPropertyInteger("TargetWebFront");
            if ($visu > 0 && IPS_InstanceExists($visu)) {
                $this->LogMessage("App/Visu: Sende Push & Notification", KL_NOTIFY);
                if (function_exists('VISU_PostNotification')) {
                    VISU_PostNotification($visu, "Info", $message, "Information", 0);
                }
            }
        }
        
        if ($profile['UseVestaboard'] ?? false) {
            $vesta = $this->ReadPropertyInteger("TargetVestaboard");
            if ($vesta > 0 && IPS_InstanceExists($vesta)) {
                if (function_exists('VESTA_SendMessage')) {
                    $this->LogMessage("Vestaboard: Sende Nachricht", KL_NOTIFY);
                    @VESTA_SendMessage($vesta, $message);
                }
            }
        }
        
        if ($profile['UseEmail'] ?? false) {
            $smtp = $this->ReadPropertyInteger("TargetSMTP");
            $email = trim($this->ReadPropertyString("EmailAddress"));
            if ($smtp > 0 && IPS_InstanceExists($smtp)) {
                if ($email != "") {
                    $this->LogMessage("E-Mail: Sende Mail an $email", KL_NOTIFY);
                    @SMTP_SendMailEx($smtp, $email, "SmartHome Info / Event", $message);
                }
            }
        }
        
        if ($profile['UseSonos'] ?? false) {
            $this->LogMessage("Sonos: Spiele TTS", KL_NOTIFY);
            $this->TriggerSonos($message);
        }
        
        $this->TriggerHomematicMP3($profile);
        $this->TriggerHomematicLEDs($profile);
        $this->TriggerHomematicSirens($profile);
        $this->TriggerTargetVariable($profile);
    }
    
    private function TriggerSonos($message)
    {
        $sonos = $this->ReadPropertyInteger("TargetSonos");
        if ($sonos > 0 && IPS_InstanceExists($sonos)) {
            if (function_exists('GSTTS_PlayMessage')) {
                @GSTTS_PlayMessage($sonos, $message);
            } elseif (function_exists('SNS_PlayText')) {
                @SNS_PlayText($sonos, $message);
            }
        }
    }

    private function TriggerHomematicMP3($profile)
    {
        $mp3 = $profile['HmIP_MP3_Inst'] ?? 0;
        if ($mp3 > 0 && IPS_InstanceExists($mp3)) {
            $soundStr = $profile['MP3_Sounds'] ?? "1";
            $vol = $profile['MP3_Volume'] ?? 100;
            $duration = $profile['MP3_Duration'] ?? 0;
            
            // Wenn Dauer angegeben, dann DU=0 (Sekunden) und DV=Dauer, R=0 (Keine Wiederholung)
            // Wenn keine Dauer (0), dann nur 1x abspielen
            $rep = 0;
            $dv = ($duration > 0) ? $duration : 0;
            
            $string = "L=$vol,DU=0,DV=$dv,RTU=0,RTV=0,R=$rep,SL=" . $soundStr;
            $this->LogMessage("Homematic MP3-Gong (Instanz $mp3): Spiele Tracks '$soundStr' (Lautstärke $vol%, Dauer: $duration s)", KL_NOTIFY);
            $this->SendDebug("HmIP-MP3", "Sende $string an Instanz $mp3", 0);
            @HM_WriteValueString($mp3, 'COMBINED_PARAMETER', $string);
        }
    }

    private function TriggerHomematicLEDs($profile, $turnOff = false)
    {
        $instId = $profile['HmIP_LED_Inst'] ?? 0;
        if ($instId > 0 && IPS_InstanceExists($instId)) {
            $color = $profile['LED_Color'] ?? 4; 
            $mode = $profile['LED_Mode'] ?? 1; 
            $bright = $profile['LED_Brightness'] ?? 100;
            $isMP3P = $profile['HmIP_LED_IsMP3P'] ?? false;
            
            if ($isMP3P) {
                if ($turnOff) {
                    $string = 'L=100,DV=10,DU=0,RTV=0,RTU=1,C=0';
                    $this->LogMessage("Homematic MP3P-LED (Instanz $instId): Licht ausgeschaltet", KL_NOTIFY);
                } else {
                    $string = "L=$bright,DV=31,DU=2,RTV=0,RTU=1,C=$color";
                    $this->LogMessage("Homematic MP3P-LED (Instanz $instId): Licht an (Farbe $color, Helligkeit $bright%)", KL_NOTIFY);
                }
            } else {
                if ($turnOff) {
                    $string = 'L=0,DV=31,DU=2,RTV=0,RTU=0,C=0,CB=0,RTTOV=0,RTTOU=3';
                    $this->LogMessage("Homematic LED (Instanz $instId): Licht ausgeschaltet", KL_NOTIFY);
                } else {
                    $string = "L=$bright,DV=31,DU=2,RTV=0,RTU=0,C=$color,CB=$mode,RTTOV=0,RTTOU=3";
                    $this->LogMessage("Homematic LED (Instanz $instId): Licht an (Farbe $color, Modus $mode, Helligkeit $bright%)", KL_NOTIFY);
                }
            }

            $this->SendDebug("HmIP-LED", "Sende $string an LED Instanz $instId", 0);
            @HM_WriteValueString($instId, 'COMBINED_PARAMETER', $string);
        }
    }

    private function TriggerHomematicSirens($profile, $turnOff = false)
    {
        $instId = $profile['HmIP_Siren_Inst'] ?? 0;
        if ($instId > 0 && IPS_InstanceExists($instId)) {
            $ac = $profile['Siren_Acoustic'] ?? 1;
            $opt = $profile['Siren_Optical'] ?? 1;

            if ($turnOff) {
                $string = "O=0,A=0,DV=31,DU=2";
                $this->LogMessage("Homematic Sirene (Instanz $instId): Ausgeschaltet", KL_NOTIFY);
            } else {
                $string = "O=$opt,A=$ac,DV=31,DU=2";
                $this->LogMessage("Homematic Sirene (Instanz $instId): Ausgelöst (Akustik $ac, Optik $opt)", KL_NOTIFY);
            }

            $this->SendDebug("HmIP-Siren", "Sende $string an Sirenen Instanz $instId", 0);
            @HM_WriteValueString($instId, 'COMBINED_PARAMETER', $string);
        }
    }

    private function TriggerTargetVariable($profile)
    {
        $targetId = $profile['TargetVariableID'] ?? 0;
        if ($targetId > 0 && IPS_VariableExists($targetId)) {
            $targetValueStr = $profile['TargetVariableValue'] ?? "";
            $var = IPS_GetVariable($targetId);
            
            switch($var['VariableType']) {
                case 0: // Boolean
                    $targetValueStr = strtolower(trim((string)$targetValueStr));
                    $val = ($targetValueStr === 'true' || $targetValueStr === '1' || $targetValueStr === 'wahr');
                    break;
                case 1: // Integer
                    $val = (int)$targetValueStr;
                    break;
                case 2: // Float
                    $val = (float)str_replace(',', '.', $targetValueStr);
                    break;
                case 3: // String
                default:
                    $val = (string)$targetValueStr;
                    break;
            }
            
            $this->LogMessage("Setze Ziel-Variable $targetId auf Wert: " . var_export($val, true), KL_NOTIFY);
            if (HasAction($targetId)) {
                @RequestAction($targetId, $val);
            } else {
                @SetValue($targetId, $val);
            }
        }
    }

    public function TestProfile(string $profileID, bool $turnOff)
    {
        $profiles = $this->GetActionProfiles($profileID);
        if (empty($profiles)) {
            echo "Fehler: Profil(e) '$profileID' nicht gefunden!";
            return;
        }

        if ($turnOff) {
            $this->SendDebug("Test", "Stoppe Profile: " . $profileID, 0);
            foreach ($profiles as $profile) {
                $this->TriggerHomematicLEDs($profile, true);
                $this->TriggerHomematicSirens($profile, true);
            }
            echo "Profile '$profileID' gestoppt (LEDs & Sirenen Aus).";
        } else {
            $msg = "TEST-ALARM für Profil: " . $profileID;
            $this->SendDebug("Test", "Teste Profile: " . $profileID, 0);
            foreach ($profiles as $profile) {
                $this->TriggerInfo($profile, $msg);
            }
            echo "Profile '$profileID' getestet (Signale gesendet).";
        }
    }

    private function IsTriggered($currentVal, $triggerValStr)
    {
        if (is_bool($currentVal)) {
            $t = strtolower(trim((string)$triggerValStr));
            $target = ($t === 'true' || $t === '1' || $t === 'wahr');
            return $currentVal === $target;
        }
        return (string)$currentVal === (string)$triggerValStr;
    }
}
