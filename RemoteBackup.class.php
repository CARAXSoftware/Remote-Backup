<?php
/*
 * MIT License
 * 
 * Copyright (c) 2023 Pascal Brödner <pb@carax-software.de>
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.

 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */



/************************************************************************
 * MAIN REMOTE BACKUP CLASS
 ************************************************************************/

class CARAX_Remote_Backup{
/**
* This class is handling the backup process of your database or files. 
* Generated backups will automatically encrypted with AES 256 Bit and 
* uploaded to your remote backup system with purge handling.
*
* @author Pascal Brödner <pb@carax-software.de>
* @version 1.0 2018-07-16
*/

    public $TempDir = "/tmp/"; // Temporary work directory
    private $WorkDir = ""; // Is set automatically
    private $SQLInstances = []; // Array with SQL Instances
    private $RemoteTargets = [];
    
    private $Files = [];
    private $Dirs = [];

    public $IsDebug = false;
    public $CompressSourceDirectly = false; // Flag to indicate if source should be compressed directly without moving to a tmp dir
    
    public $Password = ""; // Archive password
    public $DeleteFilesAfterXDays = 14; // in Days (0 = disabled)
    
    // Syslog facility and identifier
    private $SyslogFacility = LOG_USER;
    private $SyslogIdent = 'RemoteBackup';
    
/**
* Destructor to ensure syslog connection is closed
*/
    public function __destruct() {
        // Close syslog connection if it was opened
        closelog();
    }//end of destructor
    
/**
* This method logs a message to syslog.
*
* @param string $message Message to log
* @param int $priority Log priority (LOG_NOTICE, LOG_ERR, etc.)
* @return void
* @access public
* @author Pascal Brödner
*/
    public function LogToSyslog($message, $priority = LOG_NOTICE) {
        // Open syslog connection if not already open
        static $isOpen = false;
        if( !$isOpen ){
            openlog( $this->SyslogIdent, LOG_PID | LOG_CONS, $this->SyslogFacility );
            $isOpen = true;
        }//end of if
        
        // Write to syslog
        syslog( $priority, $message );
    }//end of method
    
    
/**
* This method is starting the backup process.
*
* @return bool
* @access public
* @author Pascal Brödner
*/
    public function Process(){
        // Message
        $start_time = microtime(TRUE);
        $this->Output( "REMOTE-BACKUP STARTED ON ".date("c"), 0, "white", "magenta" );
        $this->LogToSyslog("REMOTE-BACKUP STARTED", LOG_NOTICE);
        $this->Output( "CREATE BACKUP", 0, "black", "white" );
        
        // Create Tmp Dir
        $Filename = $this->IsDebug ? "TestBackup" : "Backup_".date("ymd_Hi");
        $this->WorkDir = $this->TempDir . $Filename . DIRECTORY_SEPARATOR;
        
        // Array to hold directories for direct compression
        $compressDirs = [];
        
        // Create SQL Backups - we always need the temp directory for SQL dumps
        if( count( $this->SQLInstances ) > 0 ){
            if( !is_dir( $this->WorkDir ) ){
                $this->Output( "Create temporary directory: ".$this->WorkDir, 1 );
                mkdir( $this->WorkDir, 0774, true );
                if( !is_dir( $this->WorkDir ) ) throw new Exception("Temporary directory could not be created!");
            }//end of if
            
            // Create SQL Temp Dir
            $SQLDir = $this->WorkDir . "SQLDump" . DIRECTORY_SEPARATOR;
            if( !is_dir( $SQLDir ) ) mkdir( $SQLDir, 0774, true );
            
            // Export Databases
            foreach( $this->SQLInstances AS $SQL ) $SQL->ExportToDir( $SQLDir );
            
            // Add SQL directory to compression list
            if( $this->CompressSourceDirectly ) $compressDirs[] = $SQLDir;
        }//end of if
        
        // Compress directly or move to tmp dir
        if( $this->CompressSourceDirectly ){
            // DIRECT COMPRESSION
            // Process Files - collect original paths for direct compression
            $Max = count( $this->Files );
            if( $Max > 0 ){
                $this->Output( "Preparing ".$Max." file".( $Max > 1 ? "s" : "" )." for direct compression:", 1 );
                $i = 1;
                foreach( $this->Files AS $R ){
                    $this->Output( "#".$i." ".$R["File"]." ... ", 2, "", "", true );
                    if( file_exists( $R["File"] ) AND is_readable( $R["File"] ) ){
                        // Get the directory of the file to add to compress list
                        //$fileDir = dirname( $R["File"] );
                        $fileDir = $R["File"];
                        if( !in_array( $fileDir, $compressDirs ) ){
                            $compressDirs[] = $fileDir;
                        }//end of if
                        $this->Output( "OK", 0, "green" );
                    }else{
                        $this->Output( "FAIL", 0, "red" );
                    }//end of if
                    $i++;
                }//end of foreach
            }//end of if
            
            // Process Directories - collect original paths for direct compression
            $Max = count( $this->Dirs );
            if( $Max > 0 ){
                $this->Output( "Preparing ".$Max." director".( $Max > 1 ? "ies" : "y" )." for direct compression:", 1 );
                $i = 1;
                foreach( $this->Dirs AS $R ){
                    $this->Output( "#".$i." ".$R["Dir"]." ... ", 2, "", "", true );
                    if( is_dir( $R["Dir"] ) AND is_readable( $R["Dir"] ) ){
                        // Add directory to compress list
                        $compressDirs[] = $R["Dir"];
                        $this->Output( "OK", 0, "green" );
                    }else{
                        $this->Output( "FAIL", 0, "red" );
                    }//end of if
                    $i++;
                }//end of foreach
            }//end of if
        
        }else{
            // TRADITIONAL MODE: copy files to temp directory
            if( !is_dir( $this->WorkDir ) ){
                $this->Output( "Create temporary directory: ".$this->WorkDir, 1 );
                mkdir( $this->WorkDir, 0774, true );
                if( !is_dir( $this->WorkDir ) ) throw new Exception("Temporary directory could not be created!");
            }//end of if
            
            // Copy Files
            $Max = count( $this->Files );
            if( $Max > 0 ){
                $this->Output( "Coping ".$Max." file".( $Max > 1 ? "s" : "" ).":", 1 );
                $i = 1;
                foreach( $this->Files AS $R ){
                    // Get/Create Target Dir
                    $FT = preg_replace( "#^/#i", "", preg_replace( "#/$#i", "", $R["TargetDir"] ) );
                    $TmpTargetDir = $this->WorkDir . ( $FT ? $FT . DIRECTORY_SEPARATOR : "" );
                    if( !is_dir( $TmpTargetDir ) ) mkdir( $TmpTargetDir, 0774, true );
                    
                    // Copy File
                    $TargetFile = $TmpTargetDir . basename( $R["File"] );
                    $this->Output( "#".$i." ".$R["File"]." ... ", 2, "", "", true );
                    copy( $R["File"], $TargetFile );
                    if( file_exists( $TargetFile ) ) $this->Output( "OK", 0, "green" ); ELSE $this->Output( "FAIL", 0, "red" );
                    $i++;                
                }//end of foreach
            }//end of if
            
            // Copy Directories
            $Max = count( $this->Dirs );
            if( $Max > 0 ){
                $this->Output( "Coping ".$Max." director".( $Max > 1 ? "ies" : "y" ).":", 1 );
                $i = 1;
                foreach( $this->Dirs AS $R ){
                    // Get/Create Target Dir
                    $FT = preg_replace( "#^/#i", "", preg_replace( "#/$#i", "", $R["TargetDir"] ) );
                    $TmpTargetDir = $this->WorkDir . ( $FT ? $FT . DIRECTORY_SEPARATOR : "" );
                    if( !is_dir( $TmpTargetDir ) ) mkdir( $TmpTargetDir, 0774, true );
                    
                    // Copy File
                    $CopyTargetDir = $TmpTargetDir . basename( $R["Dir"] );
                    $this->Output( "#".$i." ".$R["Dir"]." ... ", 2, "", "", true );
                    exec( "cp -pr ".escapeshellarg( $R["Dir"] )." ".escapeshellarg( $CopyTargetDir ) );
                    if( is_dir( $CopyTargetDir ) ) $this->Output( "OK", 0, "green" ); ELSE $this->Output( "FAIL", 0, "red" );
                    $i++;
                }//end of foreach
            }//end of if
        }//end of if
        
        // ZIP Dir
        $TargetZipFile = $this->TempDir.$Filename.".7z";
        $this->Output( "Create encrypted and secured 7z-file", 1 );
        $this->Output( $TargetZipFile." ... ", 2, "", "", true );
        
        if( $this->CompressSourceDirectly && !empty( $compressDirs ) ){
            // Use direct compression with array of directories
            $File = $this->DirToArchive( $compressDirs, $TargetZipFile );
        }else{
            // Traditional compression of temp directory
            $File = $this->DirToArchive( $this->WorkDir, $TargetZipFile );
        }//end of if
        
        if( file_exists( $TargetZipFile ) ){
            $this->Output( "OK", 0, "green" );
            
            // Log archive size
            $filesize = filesize($TargetZipFile);
            $human_size = $this->formatBytes($filesize);
            $this->LogToSyslog("Archive created: ".$TargetZipFile." (".$human_size.")", LOG_NOTICE);
        }else $this->Output( "FAIL", 0, "red" );
        
        // Submit Remote Targets
        $this->Output( "TRANSFER BACKUP FILE", 0, "black", "white" );
        if( count( $this->RemoteTargets ) > 0 ){
            foreach( $this->RemoteTargets AS $Remote ){
                $TransferOK = false;
                $transfer_start_time = microtime(TRUE);
                
                switch( strtolower( $Remote["Type"] ) ){
                    case "ftp":
                        $this->Output( "Transfer to FTP -> ".$Remote["User"]."@".$Remote["Host"]." ... ", 1, "", "", true );
                        $TransferOK = $this->TransferToFTP( $File, $Remote );
                        $transfer_duration = round(microtime(TRUE) - $transfer_start_time, 2);
                        if( $TransferOK ){
                            $this->LogToSyslog("Transferred to FTP: ".$Remote["User"]."@".$Remote["Host"]." in $transfer_duration seconds", LOG_NOTICE);
                        }else{
                            $this->LogToSyslog("Failed to transfer to FTP: ".$Remote["User"]."@".$Remote["Host"], LOG_ERR);
                        }//end of if
                    break;
                    case "dropbox":
                        $this->Output( "Transfer to Dropbox ... ", 1, "", "", true );
                        $TransferOK = $this->TransferToDropbox( $File, $Remote );
                        if( $TransferOK ){
                            $this->LogToSyslog("Transferred to Dropbox successfully", LOG_NOTICE);
                        }else{
                            $this->LogToSyslog("Failed to transfer to Dropbox", LOG_ERR);
                        }//end of if
                    break;
                    case "dir":
                        $this->Output( "Transfer to directory -> ".$Remote["Path"]." ... ", 1, "", "", true );
                        $TransferOK = $this->TransferToDir( $File, $Remote ); 
                        if( $TransferOK ){
                            $this->LogToSyslog("Transferred to directory: ".$Remote["Path"], LOG_NOTICE);
                        }else{
                            $this->LogToSyslog("Failed to transfer to directory: ".$Remote["Path"], LOG_ERR);
                        }//end of if
                    break;
                }//end of switch
                if( $TransferOK ) $this->Output( "OK", 0, "green" ); ELSE $this->Output( "FAIL", 0, "red" );
            }//end of foreach
        }//end of if
        
        // Delete Tmp Dir
        exec("rm -f -r ".escapeshellarg( $this->WorkDir ) );
        unlink( $File );
                
        // return
        $end_time = microtime(TRUE);
        $duration = round($end_time - $start_time, 3);
        $this->Output( "COMPLETED IN ".$duration." SECONDS", 0, "black", "green" );
        $this->LogToSyslog("REMOTE-BACKUP COMPLETED IN ".$duration." SECONDS", LOG_NOTICE);
        return true;
    }//end of method
    
    
/**
* This method uploads a file to dropbox.
*
* @param string $File
* @param array $Options
* @return array $DropboxResponse
* @access public
* @author Pascal Brödner
*/
    private function TransferToDropbox( $File, $Options ){
        // Create Innstance
        $Dropbox = new CARAX_Dropbox( $this, $Options["Token"] );
        
        // Track deletions
        $filesDeleted = 0;
        
        // Remove older files
        if( $this->DeleteFilesAfterXDays > 0 ){
            $FileList = $Dropbox->Listing( $Options["Path"] );
            if( count( $FileList ) > 0 ){
                foreach( $FileList AS $RFile ){
                    if( strtotime( $RFile["server_modified"] ) <= strtotime( "-".intval( $this->DeleteFilesAfterXDays )." days" ) ){
                        $Dropbox->Delete( $RFile["path_display"] );
                        $filesDeleted++;
                    }//end of if
                }//end of foreach
            }//end of if
            
            if($filesDeleted > 0) {
                $this->LogToSyslog("Deleted $filesDeleted old files from Dropbox", LOG_NOTICE);
            }
        }//end of if
        
        // Upload new File
        $Response = $Dropbox->Upload( $File, $Options["Path"] );
        return $Response["id"] ? true : false;
    }//end of method
    
    
/**
* This method uploads a file to ftp server.
*
* @param string $File
* @param array $Options
* @return boolean $Result
* @access public
* @author Pascal Brödner
*/
    private function TransferToFTP( $File, $Options ){
        // Connect
        $Con = ftp_connect( $Options["Host"], $Options["Port"] ); // Connect to FTP
        if( !$Con ) throw new Exception("Could not connect to FTP ".$Options["Host"].":".$Options["Port"] );
        $Auth = ftp_login( $Con, $Options["User"], $Options["Pass"] ); // Auth on FTP
        ftp_pasv( $Con, true ); // Enable passive mode
        
        // Change to remote directory
        if( $Options["Path"] ) ftp_chdir( $Con, $Options["Path"] ); 
        
        // Track deletions
        $filesDeleted = 0;
        
        // Remove older files
        if( $this->DeleteFilesAfterXDays > 0 ){
            $FileList = ftp_nlist( $Con, DIRECTORY_SEPARATOR . preg_replace( "#^/#i", "", $Options["Path"] ) );
            if( count( $FileList ) > 0 ){
                foreach( $FileList AS $RFile ){
                    $ModTime = ftp_mdtm( $Con, $RFile );
                    if( $ModTime <= strtotime( "-".intval( $this->DeleteFilesAfterXDays )." days" ) ) {
                        ftp_delete( $Con, $RFile );
                        $filesDeleted++;
                    }
                }//end of foreach
            }//end of if
            
            if( $filesDeleted > 0 ){
                $this->LogToSyslog("Deleted ".$filesDeleted." old files from FTP", LOG_NOTICE);
            }//end of if
        }//end of if
        
        // Upload new file
        $Result = ftp_put( $Con, basename( $File ), $File, FTP_BINARY ); // Upload File
        ftp_close( $Con ); // Close Connection
        
        // return
        return $Result;
    }//end of method
    
    
/**
* This method transfer a file to directory.
*
* @param string $File
* @param array $Options
* @return boolean $Result
* @access public
* @author Pascal Brödner
*/
    private function TransferToDir( $File, $Options ){
        // Change to remote directory
        $Path = realpath( $Options["Path"] ) . DIRECTORY_SEPARATOR;
        if( !is_dir( $Path ) AND !is_writable( $Path ) ) throw new Exception( "Directory not found or not writable" );
        
        // Track deletions
        $filesDeleted = 0;
        
        // Remove older files
        if( $this->DeleteFilesAfterXDays > 0 ){
            $FileList = glob( $Path."*" );
            if( count( $FileList ) > 0 ){
                foreach( $FileList AS $RFile ){
                    $ModTime = filemtime( $RFile );
                    if( $ModTime <= strtotime( "-".intval( $this->DeleteFilesAfterXDays )." days" ) ) {
                        unlink( $RFile );
                        $filesDeleted++;
                    }
                }//end of foreach
            }//end of if
            
            if($filesDeleted > 0) {
                $this->LogToSyslog("Deleted $filesDeleted old files from directory $Path", LOG_NOTICE);
            }
        }//end of if
        
        // Copy new file
        $TargetFile = $Path . basename( $File );
        $Result = copy( $File, $TargetFile );
        
        // return
        return file_exists( $TargetFile );
    }//end of method
    
    
/**
* This method adding locale directory as target.
*
* @param string $Path
* @return object $this
* @access public
* @author Pascal Brödner
*/
    public function ToDir( $Path ){
        $this->RemoteTargets[] = [
            "Type"=> "Dir",
            "Path"=> $Path
        ];
        return $this;
    }//end of method
    
    
/**
* This method adding dropbox as remote target.
*
* @param string $Path
* @param string $AccessToken
* @return object $this
* @access public
* @author Pascal Brödner
*/
    public function ToDropbox( $Path, $AccessToken ){
        $this->RemoteTargets[] = [
            "Type"=> "Dropbox",
            "Token"=> $AccessToken,
            "Path"=> $Path
        ];
        return $this;
    }//end of method
    
    
/**
* This method adding ftp as remote target.
*
* @param string $User
* @param string $Pass
* @param string $Host
* @param int $Port
* @param string $Path
* @return object $this
* @access public
* @author Pascal Brödner
*/
    public function ToFTP( $User, $Pass, $Host, $Port = 21, $Path = "/" ){
        $this->RemoteTargets[] = [
            "Type"=> "FTP",
            "User"=> $User,
            "Pass"=> $Pass,
            "Host"=> $Host,
            "Port"=> $Port,
            "Path"=> $Path
        ];
        return $this;
    }//end of method
    
    
/**
* This method adding a directory to backup.
*
* @param string $Dir
* @param string $TargetDir
* @return object
* @access public
* @author Pascal Brödner
*/
    public function Dir( $Dir, $TargetDir = "" ){
        $ID = hash("CRC32", $Dir );
        if( is_dir( $Dir ) AND is_readable( $Dir ) AND !$this->Dirs[ $ID ] ){
            $this->Dirs[ $ID ] = [ "Dir"=> $Dir, "TargetDir"=> $TargetDir ];
        }//end of if
        return $this;
    }//end of method
    
    
/**
* This method adding a single file to backup.
*
* @param string $File
* @param string $TargetDir
* @return object $this
* @access public
* @author Pascal Brödner
*/
    public function File( $File, $TargetDir = "" ){
        $ID = hash("CRC32", $File );
        if( file_exists( $File ) AND is_readable( $File ) AND !$this->Files[ $ID ] ){
            $this->Files[ $ID ] = [ "File"=> $File, "TargetDir"=> $TargetDir ];
        }//end of if
        return $this;
    }//end of method
    
    
/**
* This method is creating a SQL backup plan.
*
* @param string $Host
* @param string $User
* @param string $Pass
* @return object $Instance
* @access public
* @author Pascal Brödner
*/
    public function SQL( $Host, $User, $Pass ){
        $Instance = new CARAX_BackupSQL( $this, $Host, $User, $Pass );
        $this->SQLInstances[] = $Instance;
        return $Instance;
    }//end of method
    
    
/**
* This method returns the cpu count of the host machine.
*
* @return int $Number
* @access private
* @author Pascal Brödner
*/
    private function GetCPUCount(): Int {
        // Automatic CPU detection with fallbacks
        $cpuCount = 4; // Safe default fallback
        
        // Try to detect CPU count using various methods
        if( function_exists('shell_exec') ){
            // Try using nproc command (Linux/Unix)
            $detected = intval( trim( shell_exec('nproc 2>/dev/null') ) );
            if( $detected > 0 ){
                $cpuCount = $detected;
            }else{
                // Alternative: parse /proc/cpuinfo on Linux
                $cpuinfo = @shell_exec('cat /proc/cpuinfo | grep -c processor 2>/dev/null');
                if( $cpuinfo ){
                    $detected = intval( trim( $cpuinfo ) );
                    if( $detected > 0 ) $cpuCount = $detected;
                }//end of if
            }//end of if
        }//end of if
        
        // Return
        return (int)$cpuCount;
    }//end of method
    
    
/**
* This method is creating an encrypted arctive file supported by 7z.
* Can accept a single directory or an array of directories.
*
* @param string|array $Dir Directory or array of directories to archive
* @param string $File Output archive file path
* @return string $File Path to created archive file
* @access private
* @author Pascal Brödner
*/
    private function DirToArchive( $Dir, $File ): String {
        // Calculate optimal affinity mask and thread count
        $affinity = '';
        $cpuCount = $this->GetCPUCount(); // Automatic CPU detection with fallbacks
        $threadCount = $cpuCount - 1;
        
        if( $cpuCount <= 2 ){
            // For 1-2 core systems, just use all cores (don't set affinity)
            $affinity = '';
            $threadCount = $cpuCount; // Use all available threads
        }else if( $cpuCount <= 4 ){
            // For 3-4 core systems: binary 1110 -> hex 'E' (excludes CPU 0)
            $affinity = 'E';
            $threadCount = $cpuCount - 1;
        }else{
            // For 5+ core systems: Create mask with all bits set except bit 0
            // This ensures CPU 0 is never used regardless of core count
            $mask = (1 << $cpuCount) - 2; // Set all bits up to cpuCount, then clear bit 0
            $affinity = dechex($mask);
        }//end of if
        
        // Check if ionice is available to reduce I/O impact
        $ionice = '';
        if( !$this->IsDebug AND function_exists('shell_exec') ){
            $ioniceCheck = shell_exec('which ionice 2>/dev/null');
            if( !empty( $ioniceCheck ) ){
                // Use class 2 (best-effort) with priority 6 (lower number = higher priority)
                // This allows other processes like MySQL and Nginx to have priority
                $ionice = 'ionice -c2 -n6 ';
            }//end of if
        }//end of if

        // Check if nice is available to control CPU priority
        $nice = '';
        if( !$this->IsDebug AND function_exists('shell_exec') ){
            $niceCheck = shell_exec('which nice 2>/dev/null');
            if( !empty( $niceCheck ) ){
                // Set a nice value of 10 (higher = lower priority, range is -20 to 19)
                // This gives good balance between backup speed and system responsiveness
                $nice = 'nice -n 10 ';
            }//end of if
        }//end of if
        
        // Build 7zip command with both I/O and CPU priority controls
        $cmd = $nice . $ionice . "7z a -t7z -slp -m0=lzma2 -mx=9 -mfb=64 ";
        $cmd.= "-md=32m -ms=on -mhe=on -ssw "; // -mfb=128 -md=128m
        
        // Add absolute path flag when using direct compression (when Dir is an array)
        if( is_array( $Dir ) ){
            $cmd.= "-spf ";  // Use absolute paths for files
        }//end of if
        
        // Only add affinity if we need it
        if( !$this->IsDebug AND !empty( $affinity ) ){
            $cmd.= "-stm".$affinity." ";
        }//end of if
        
        // Set thread count if we have multiple cores
        if( !$this->IsDebug AND $threadCount > 1 ){
            $cmd.= "-mmt=".$threadCount." ";
        }//end of if
        
        if( $this->Password ) $cmd.= "-p".escapeshellarg( $this->Password )." ";
        $cmd.= escapeshellarg( $File )." ";
        
        // Handle directory parameter
        if( is_array( $Dir ) ){
            // Process array of directories
            foreach( $Dir as $directory ){
                if( !$directory OR !is_readable($directory) ) throw new Exception("Directory ".$directory." is not readable");
                $cmd.= escapeshellarg( is_dir( $directory ) ? $directory."*" : $directory )." ";
            }//end of foreach
        }else{
            // Process single directory
            if( !$Dir OR !is_readable( $Dir ) ) throw new Exception("Directory ".$directory." is not readable");
            $cmd .= escapeshellarg( $Dir."*" );
        }//end of if
        
        // Execute command
        exec( $cmd );
        
        // return
        return file_exists( $File ) ? $File : "";
    }//end of method
    
    
/**
* This method prints a message to the screen.
*
* @param string $Msg
* @param int $Level [optional]
* @param string $Foreground [optional]
* @param string $Background [optional]
* @return CARAX_Remote_Backup $this
* @access public
* @author Pascal Brödner
*/
    private $LatestOutputLevel = 0;
    public function Output( $Msg, $Level = 0, $Foreground = "", $Background = "", $NoBreak = false ){
        if( $Level === null ) $Level = $this->LatestOutputLevel;
        
        $Foregrounds = [ "default"=> "39m", "black"=> "30m", "red"=> "91m", "green"=> "92m", "orange"=> "93m", "blue"=> "94m", "magenta"=> "95m", "cyan"=> "96m", "white"=> "97m" ];
        $FgFormat = $Foregrounds[ $Foreground ];
        
        $Backgrounds = [ "default"=> "49m", "black"=> "40m", "red"=> "101m", "green"=> "102m", "orange"=> "103m", "blue"=> "104m", "magenta"=> "105m", "cyan"=> "106m", "white"=> "107m" ];
        $BgFormat = $Backgrounds[ $Background ];
        
        if( $FgFormat OR $BgFormat ){
            $Msg = ( $FgFormat ? "\e[".$FgFormat : "" ).( $BgFormat ? "\e[".$BgFormat : "" ).$Msg."\e[0m";
        }//end of if
        
        echo str_repeat( "\t", $Level ).$Msg;
        if( !$NoBreak ) echo PHP_EOL;
        $this->LatestOutputLevel = $Level;
        return $this;
    }//end of method
    
    
/**
* This method prints an error message.
*
* @param string $Msg
* @return CARAX_Remote_Backup $this
* @access public
* @author Pascal Brödner
*/
    public function ErrorMessage( $Msg ){
        $this->Output( $Msg, null, "white", "red" );
        return $this;
    }//end of method
    
    
/**
* Format bytes to human readable size
*
* @param int $bytes Number of bytes
* @param int $precision Precision of rounding
* @return string Human readable size
* @access public
*/
    public function formatBytes( $bytes, $precision = 2 ){
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, $precision) . ' ' . $units[$pow];
    }//end of method

}//end of class


/************************************************************************
 * BACKUP SQL
 ************************************************************************/

class CARAX_BackupSQL{
/**
* This class is handling the SQL backup process of your database. 
*
* @author Pascal Brödner <pb@carax-software.de>
* @version 1.0 2018-07-16
*/

    private $BackupInstance = null;    
    private $Host = "";
    private $User = "";
    private $Pass = "";
    private $Databases = [];

    public function __construct( $BackupInstance, $Host, $User, $Pass ){
        if( !$Host ) throw new Exception("Database Host is empty");
        if( !$User ) throw new Exception("Database User is empty");
        if( !$Pass ) throw new Exception("Database Pass is empty");
        $this->BackupInstance = $BackupInstance;
        $this->Host = $Host;
        $this->User = $User;
        $this->Pass = $Pass;
    }//end of method
    
    
/**
* This method adding a database to backup. 
* Add multiple databases comma separated as arguments.
*
* @param string
* @return object
* @access public
* @author Pascal Brödner
*/
    public function Database(){
        $Args = func_get_args();
        if( count( $Args ) > 0 ){
            foreach( $Args AS $Database ){
                if( $Database AND !in_array( $Database, $this->Databases ) ){
                    $this->Databases[] = $Database;
                }//end of if
            }//end of foreach
        }//end of if
        return $this;
    }//end of method
   
   
/**
* This method dumps all selected databases into a directory.
*
* @param string $Dir
* @return object $this
* @access public
* @author Pascal Brödner
*/
    public function ExportToDir( $Dir ){
        // Settings
        $Max = count( $this->Databases );
        if( $Max<=0 ) return $this;
        if( !$Dir OR !is_writeable( $Dir ) ) throw new Exception("SQL export target dir is not writable");
        
        // Export each database
        $this->BackupInstance->Output( "Export ".$Max." database".( $Max > 1 ? "s" : "" ).":", 1 );
        $i = 0; // Initialize counter
        foreach( $this->Databases AS $Database ){
            // Settings
            $i++; $File = $Dir . $Database.".sql";
            $this->BackupInstance->Output( "#".$i." ".$Database." ... ", 2, "", "", true );
            
            // Dump Database
            $this->DumpDatabase( $Database, $File );
            
            // Validate
            if( file_exists( $File ) ) {
                $this->BackupInstance->Output( "OK", 0, "green" );
                // Log database export size
                $filesize = filesize($File);
                $human_size = $this->BackupInstance->formatBytes($filesize);
                $this->BackupInstance->LogToSyslog("SQL Database $Database exported ($human_size)", LOG_NOTICE);
            } ELSE $this->BackupInstance->Output( "FAIL", 0, "red" );
        }//end of foreach
        
        return $this;
    }//end of method
    

/**
* This method is dumping a database.
*
* @param string $Database
* @param string $File
* @return string $File
* @access public
* @author Pascal Brödner
*/
    public function DumpDatabase( $Database, $File = "" ){
        // Settings
        if( !$Database ) throw new Exception("Database Host is empty");
        if( !$File ) $File = $Database.".sql";
        
        // Dump
        $cmd = "export MYSQL_PWD=".escapeshellcmd( $this->Pass )."; "; // Define MySQL Password to prevent Console Warning!
        $cmd.= "mysqldump -h ".escapeshellcmd( $this->Host )." -u ".escapeshellcmd( $this->User )." ";
        $cmd.= "-c --add-drop-table --add-locks --quick --lock-tables ";
        $cmd.= escapeshellcmd( $Database )." > ".escapeshellcmd( $File );
        
        //$cmd = "mysqldump -h ".escapeshellcmd( $this->Host )." -u ".escapeshellcmd( $this->User )." -p".escapeshellcmd( $this->Pass )." ";
        //$cmd.= "-c --add-drop-table --add-locks --quick --lock-tables ";
        //$cmd.= escapeshellcmd( $Database )." > ".escapeshellcmd( $File );
        
        // Exec
        exec( $cmd );
        return file_exists( $File );
    }//end of func

}//end of method


/************************************************************************
 * DROPBOX CLASS
 ************************************************************************/

class CARAX_Dropbox{
/**
* This class is handling the dropbox api v2.
* 
* @author Pascal Brödner <pb@carax-software.de>
* @version 1.0 2018-07-16
*/

    private $BackupInstance = null;
    private $AccessToken = "";
    
    public function __construct( $BackupInstance, $AccessToken ){
        $this->BackupInstance = $BackupInstance;
        $this->AccessToken = $AccessToken;
    }//end of construct
    
    
/**
* This method uploads a file into the dropbox.
*
* @param string $File
* @param string $RemotePath
* @return array $Response
* @access public
* @author Pascal Brödner
*/
    public function Upload( $File, $RemotePath ){
        $Response = $this->REST_Request( 
            "https://content.dropboxapi.com/2/files/upload", 
            file_get_contents( $File ), 
            [
                "Authorization: Bearer ".$this->AccessToken,
                "Content-Type: application/octet-stream",
                "Dropbox-API-Arg: ".json_encode(array(
                    "path"=> preg_replace( "#/$#i", "", $RemotePath )."/".basename( $File ),
                    "mode"=> "add",
                    "autorename"=> true,
                    "mute"=> false
                ))
            ]
        );
        return $Response;
    }//end of method
    
    
/**
* This method delete a remote file from dropbox.
*
* @param string $RemoteFile
* @return array $Response
* @access public
* @author Pascal Brödner
*/
    public function Delete( $RemoteFile ){
        $Response = $this->REST_Request( 
            "https://api.dropboxapi.com/2/files/delete_v2", 
            json_encode([ "path"=> $RemoteFile ]), 
            [
                "Authorization: Bearer ".$this->AccessToken,
                "Content-Type: application/json"
            ]
        );
        return $Response["metadata"];
    }//end of method
    
    
/**
* This method returns the path files/directories.
*
* @param string $Path
* @return array $Response
* @access public
* @author Pascal Brödner
*/
    public function Listing( $Path = "" ){
        $Response = $this->REST_Request( 
            "https://api.dropboxapi.com/2/files/list_folder", 
            json_encode([
                "path"=> $Path,
                "recursive"=> false,
                "include_media_info"=> false,
                "include_deleted"=> false,
                "include_has_explicit_shared_members"=> false,
                "include_mounted_folders"=> true
            ]), 
            [
                "Authorization: Bearer ".$this->AccessToken,
                "Content-Type: application/json"
            ]
        );
        return $Response["entries"];
    }//end of method
    
    
/**
* This method is sending a cURL request.
*
* @param string $URL
* @param array $Data
* @param string $Headers
* @param boolean $AuthRequired
* @return array
* @access public
* @author Pascal Brödner
*/
    public function REST_Request( $URL, $Data = [], $Headers = array(), $AuthRequired = false ){
        if( !is_array( $Headers ) ) $Headers = [];
        $Content = ( is_array( $Data ) ) ? http_build_query( $Data, '', '&' ) : $Data;

        $Headers[] = "Accept: application/json";
        $Headers[] = "Accept-Language: en_US";
        if( $Content ) $Headers[] ="Content-length: ".strlen( $Content );
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $URL);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $Headers);
        if( $Content ) curl_setopt($ch, CURLOPT_POSTFIELDS, $Content );

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_VERBOSE, false);
        curl_setopt($ch, CURLOPT_POST, ( $Content ) ? true : false );
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, true);
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);

        $Response = curl_exec( $ch );
        $CURL_Info = curl_getinfo( $ch );
        curl_close( $ch );

        // return
        return ( $CURL_Info["content_type"] == "application/json" ) ? json_decode( $Response, true ) : $Response;
    }//end of method

}//end of class
