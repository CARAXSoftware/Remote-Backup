<?php
/*
 * Copyright 2023 Pascal Brödner <pb@carax-software.de>
 * https://github.com/CARAXSoftware/Remote-Backup
 * 
 * This file serves as an example for using the CARAX Remote Backup. 
 * It's best to create your own file and set up a cronjob as you needed.
 */
error_reporting( E_ERROR );
try {
    
    include_once "RemoteBackup.class.php";
    $Backup = new CARAX_Remote_Backup;
    
    $Backup->TempDir = "/tmp/"; // Temporary directory to create the backup (Note: should have enough disk space!)
    $Backup->Password = "123456"; // Password to open and unzip the ZIP file
    
    $Backup->SQL( "127.0.0.1", "root", "<your-database-password>" )->Database( "<database1>", "<database2>" );
    
    $Backup->Dir( "/myfiles/important-stuff/", "PathInMyBackup/" );
    $Backup->File( "/myfiles/single.jpg", "PathInMyBackup/" );
    $Backup->File( "/myfiles/single.jpg" );
    
    $Backup->ToFTP( "<your-ftp-user>", "<your-ftp-password>", "<your-ftp-host>", 21, "PathOnMyFTPServer/" );
    $Backup->ToDropbox( "/MyDropboxFolder", "<your-dropbox-accesstoken>" );
    
    $Backup->Process(); // Starts the backup process
    
}catch( Exception $e ){
    $Backup->ErrorMessage( $e->getMessage() );
}//end of catch
