<?php
error_reporting( E_ERROR );
try {
    
    include_once "RemoteBackup.class.php";
    
    $Backup = new CARAX_Remote_Backup;
    $Backup->Password = "123456";
    
    $Backup->SQL( "127.0.0.1", "root", "<your-database-password>" )->Database( "<database1>", "<database2>" );
    
    $Backup->Dir( "/myfiles/important-stuff/", "PathInMyBackup/" );
    $Backup->File( "/myfiles/single.jpg", "PathInMyBackup/" );
    $Backup->File( "/myfiles/single.jpg" );
    
    $Backup->ToFTP( "<your-ftp-user>", "<your-ftp-password>", "<your-ftp-host>", 21, "PathOnMyFTPServer/" );
    $Backup->ToDropbox( "/MyDropboxFolder", "<your-dropbox-accesstoken>" );
    
    $Backup->Process();
    
}catch( Exception $e ){
    echo "\n[ERROR] ".$e->getMessage()."\n\n";
}//end of catch
