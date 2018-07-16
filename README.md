# CARAX Remote Backup
This small program offers you the possibility to back up entire directories, individual files or databases to an encrypted archive and upload them to an external data storage. We currently support FTP (File Transfer Protocol) and Dropbox.

*Tested in PHP5.x and up.*

### Backup your ...

* Entire directories
* Single files
* SQL databases

... as password secured and encrypted (AES-256 bit) 7z-archive with automatic upload and purge functionality to ...

* ... FTP Servers (File Transfer Protocol) or ...
* ... your [Dropbox](https://www.dropbox.com) account.

### How to use

**Simple usage:**
```php
// Configuration
require "RemoteBackup.class.php";
$Backup = new CARAX_Remote_Backup;
$Backup->Password = "123456"; // Set your archive password

// Add entire directory to your backup ("PathInMyBackup" is optional)
$Backup->Dir( "/myfiles/important-stuff/", "PathInMyBackup/" );

// Add some single files to your backup ("PathInMyBackup" is optional)
$Backup->File( "/myfiles/single.jpg", "PathInMyBackup/" );
$Backup->File( "/myfiles/another-file.jpg" );

// Set up the upload targets (Note: you can use multiple targets at the same time)
$Backup->ToFTP( "<your-ftp-user>", "<your-ftp-password>", "<your-ftp-host>", 21, "PathOnMyFTPServer/" );
$Backup->ToDropbox( "/MyDropboxFolder", "<your-dropbox-accesstoken>" );

// Start Backup
$Backup->Process();
```

**Add database to your archive:**
```php
// Configuration
require "RemoteBackup.class.php";
$Backup = new CARAX_Remote_Backup;
$Backup->Password = "123456"; // Set your archive password

// Add databases to backup
$SQLBackup = $Backup->SQL( "<your-database-host>", "<your-database-user>", "<your-database-password>" )
$SQLBackup->Database( "<database1>", "<database2>" );
$SQLBackup->Database( "<database3>" );

// Set up the upload targets
$Backup->ToFTP( "<your-ftp-user>", "<your-ftp-password>", "<your-ftp-host>", 21, "PathOnMyFTPServer/" );

// Start Backup
$Backup->Process();
```

**Add databases from different SQL servers:**
```php
// Configuration
require "RemoteBackup.class.php";
$Backup = new CARAX_Remote_Backup;
$Backup->Password = "123456"; // Set your archive password

// Add databases to backup
$SQLSource1 = $Backup->SQL( "<db-source1-host>", "<db-source1-user>", "<db-source1-password>" )
$SQLSource1->Database( "<database1>" );

$SQLSource2 = $Backup->SQL( "<db-source2-host>", "<db-source2-user>", "<db-source2-password>" )
$SQLSource2->Database( "<database2>", "<database3>" );

// Set up the upload targets
$Backup->ToDropbox( "/MyDropboxFolder", "<your-dropbox-accesstoken>" );

// Start Backup
$Backup->Process();
```

**Purge files older than X days:**

Enable the purge functionality to remove files older than specified days and keep your external storage clean.
```php
$Backup->DeleteFilesAfterXDays = 7; // Default = 14 | 0 = Disabled
```

## Dependencies

The programm uses the following server-side packages:
* 7z
* mysqldump (only for database backups)
* php-curl (only for dropbox api)

To use the dropbox upload, you need to create a [dropbox app](https://www.dropbox.com/developers/apps) with your dropbox account. Afterward create a "OAuth2 Access Token" which you set up in the source code.
