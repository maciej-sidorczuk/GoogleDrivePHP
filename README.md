# GoogleDrivePHP
This program which works from linux cli and windows cmd allows you to make backups to google drive. You can use it for example in raspberryPi in cron to make regular backups. Except full backups program allows you to make backups only for files which changed or not exist in backup.

Instructions:
1. Clone repository to your server
2. If you don't have composer install it first.
3. In main folder use command: composer install
4. Enable API in google API console: https://console.developers.google.com/
5. Download from google API console client_secret.json and put this file to main folder of project
6. Optionally you can install sendmail to allow application sending emails about upload process status. Email parameter is not obligatory and it can be omitted.

Now you are ready to use application.

To make full backup from specific folder use command:
php backupgd.php [path to folder which you want to backup] [ID of folder in google drive where you want to put backups] [your email]

To make backup only files which were changed first add to CRON below command:
php gettimemod.php [path to folder which you want to backup] [path to file where time modifications will be stored] [path to file where hash codes will be sotred]

this command will: 
1) read time modification attributes from all files and store them in file
2) based on time modification attributes (above file) hash codes of each file will be calculated and store in file
this is necessary step to use command php backupgddiff.php

If you have hash codes file you can use backupgddiff.php
It will upload only files which were changed. 

Command:
php backupgddiff.php [path to folder which you want to backup] [ID of folder in google drive where you want to put backups] [path to file which contains files hash codes] [email]
