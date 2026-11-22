# Tests am echten Gerät

| Was | Wie prüfen | Wann | Status |
|---|---|---|---|
| Nachrichten kommen an | Instanz anlegen, Hostname eintragen → „Letzte Nachricht …“, Debug zeigt `state/<host>`, `Faikout/<host>` | Build 1 | offen |
| Geräte-ID erkannt | Formular unter „Status jetzt anfordern“: „Geräte-ID …“ | Build 1 | offen |
| Werte plausibel | Solltemperatur ≠ Raumtemperatur, Betriebsart/Lüfter als Text, Energie in kWh | Build 1 | offen |
| Status ohne Live-Status | Live-Status im Faikout aus → Werte trotzdem spätestens nach 1 min aktuell | Build 1 | offen |
| Faikout offline | Faikout vom Strom → „Faikout verbunden“ = Nein; nach 5 min Status 201 | Build 1 | offen |
| Kompressor-Einheit | Wert von `status_comp` mit Weboberfläche vergleichen (Hz oder %?) | Build 1 | offen |
| BLE-Batterie | `status_ble_bat` = Prozent? (Kris hatte mV) | Build 1, nur mit BLE-Sensor | offen |
| Darstellung in der App | Variablen in der Symcon-App (iOS/Android) ansehen, nicht nur im Browser | Build 1 | offen |
| Darstellung ersetzt altes Profil | Nach Übernahme einer Kris-Instanz: Variable zeigt Darstellung, nicht mehr FAIKIN_-Profil | bei Store-Übergabe | offen |
| Befehls-Topics | MQTT-Mitschnitt beim Schalten (Weboberfläche und Kris' Modul) | vor Build 2 | offen |
