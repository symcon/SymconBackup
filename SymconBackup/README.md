# SymconBackup
Erstellt ein Backup über SFTP.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Software-Installation](#3-software-installation)
4. [Einrichten der Instanzen in IP-Symcon](#4-einrichten-der-instanzen-in-ip-symcon)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [PHP-Befehlsreferenz](#5-php-befehlsreferenz)

### 1. Funktionsumfang

* Erstell ein Backup über SFTP auf einem Server
* Updatet ein Backup über SFTP auf einem Server

### 2. Voraussetzungen

- IP-Symcon ab Version 6.3

### 3. Software-Installation

* Über den Module Store das 'SymconBackup'-Modul installieren.
* Alternativ über das Module Control folgende URL hinzufügen

### 4. Einrichten der Instanzen in IP-Symcon

 Unter 'Instanz hinzufügen' kann das 'SymconBackup'-Modul mithilfe des Schnellfilters gefunden werden.  
	- Weitere Informationen zum Hinzufügen von Instanzen in der [Dokumentation der Instanzen](https://www.symcon.de/service/dokumentation/konzepte/instanzen/#Instanz_hinzufügen)

__Konfigurationsseite__:

Name                      | Beschreibung
------------------------- | ------------------
Verbindungstyp            | Auswahl der Verbindungsart
Host                      | IP Adresse des Servers
Port                      | Port auf dem der Client sich auf den Server verbindet. Bei FTPS und FTP ist dies normalerweise 21. Bei SFTP ist dies der Port 22
Benutzername              | Benutzernamen für die SFTP Verbindung 
Passwort                  | Passwort für die SFTP Verbindung 
Modus                     | Modus was für ein Backup erstellt werden soll 
Zielordner                | Ordner auf dem Server, in dem das Backup erstellt werden soll
Timer aktivieren          | Aktiviert ein tägliches Update
Tägliche Zeit zum updaten | Zeit, wann das Update täglich startet  
Expertenoptionen          | Filter um bestimmte Ordner nicht zu übertragen
Backup erstellen          | Button, welcher sofort ein Update startet. 
Verbindung testen         | Testet, ob eine Verbindung hergestellt werden kann

__Modus__: 
Vollständiges Backup: Erstellt eine vollständige Kopie in einem seperaten Ordner 
Inkrementelles Backup: Updatet ein bestehendes Backup. 

### 5. Statusvariablen und Profile

Die Statusvariablen/Kategorien werden automatisch angelegt. Das Löschen einzelner kann zu Fehlfunktionen führen.

#### Statusvariablen

Name             | Typ            | Beschreibung
---------------- | -------------- | ------------
Zuletzt abgeschlossenes Backup | Integer | Zeitpunkt, zudem das letzte Backup abgeschlossen wurde
Übertragene Megabytes          | Float   | Megabytes, welche beim letzten Update übertragen wurde 

### 6. PHP-Befehlsreferenz

`boolean SB_CreateBackup(integer $InstanzID);`
Erstellt oder updatet ein Backup nach den Einstellungen der Instanz

Beispiel:
`SB_CreateBackup(12345);`