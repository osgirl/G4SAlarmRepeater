# G4SAlarmRepeater

PHP-project to retrieve alarm-status from G4S SmartAlarm P5 or P7 and relay to openHAB.

Requirements:
php-cli
php-curl

Installation:
1) Clone project

2) Install composer into the directory
(e.g. run this command:
curl -sS https://getcomposer.org/installer | php
)

3) Run composer install
(e.g.
php composer.phar install

4) Comment line 299 in this file:
vendor/symfony/dom-crawler/Crawler.php



Requires 6 arguments to run:

loginID //This is the number for the panel (in danish: Anlægsnummer)
passCode //Passcode used to access webinterface (https://homelink.g4s.dk/ELAS/WUApp/MainPage.aspx)
openHabUser //Username for your openHAB installation
openHabPass //Your public ip-address to the openHAB webinterface
openHabPort //Port-number used to access your openHAB webinterface

Example of run-command:
php -f G4SStatusAccess.php 123456789 1234 myuser mypass 2.220.1.1 8080
