<?php

namespace BoxFTPSConnector\BoxImportScript;

use ExternalModules\ExternalModules;
use Exception;

require_once ExternalModules::getProjectHeaderPath();


class BoxFTPSConnector extends \ExternalModules\AbstractExternalModule
{
    protected $logMessages = [];

   public function addLog($msg, $level = 'info')
   {
    $this->logMessages[] = [
        'time'    => date('Y-m-d H:i:s'),
        'msg'     => $msg,
        'level'   => $level  // 'info', 'detail', 'error', 'summary'
    ];
    }

public function writeRunLog($project_id, $didRun = true)
{
    if (!$project_id) return;
    if (!$didRun) return;

    $this->disableUserBasedSettingPermissions();

    $hasErrors = !empty(array_filter($this->logMessages, fn($m) => $m['level'] === 'error'));

   $filtered = array_filter($this->logMessages, function($m) {
           return in_array($m['level'], ['info', 'error', 'summary']);
    });

    $newLog  = "Run Time: " . date('Y-m-d H:i:s') . "\n";
    $newLog .= $hasErrors ? "** ERRORS OCCURRED **\n" : "** SUCCESS **\n";
    foreach ($filtered as $entry) {
        $newLog .= $entry['time'] . " - " . $entry['msg'] . "\n";
    }

     $separator   = "\n" . str_repeat("-", 60) . "\n";
     $existingLog = $this->getProjectSetting('error_log', $project_id) ?? '';
     $combined    = $newLog . $separator . $existingLog;
 
     // Split on any run boundary (line of 10+ dashes), so this self-corrects
     // even if the stored setting already has extra runs stuck together.
     $runs = preg_split('/\n-{10,}\n/', $combined);
     $runs = array_slice($runs, 0, 2);
 
    $this->setProjectSetting('error_log', implode($separator, $runs), $project_id);

    // Send email notification if errors occurred and email is configured
    if ($hasErrors) {
        $email = $this->getProjectSetting('notification_email', $project_id);
        if (!empty($email)) {
            $subject = "Box FTPS Import - Errors Occurred (Project $project_id)";
            $body    = "The Box FTPS Import module encountered errors during its last run.\n\n";
            $body   .= "Run Time: " . date('Y-m-d H:i:s') . "\n\n";
            $body   .= "Error Details:\n";
            foreach ($this->logMessages as $entry) {
                if ($entry['level'] === 'error') {
                    $body .= $entry['time'] . " - " . $entry['msg'] . "\n";
                }
            }
            $body .= "\nFull log available in the module settings for project $project_id.";

            \REDCap::email($email, 'noreply@' . SERVER_NAME, $subject, nl2br($body));
            $this->addLog("Error notification sent to: $email", 'info');
        }
    }
}
    public function downloadFromFtps($remote, $local, $site, $port, $user, $pass)
    {
        $fp = fopen($local, 'w');

        if (!$fp) {
            $this->addLog("Cannot open local file");
            throw new Exception("File open failed");
        }

        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => "ftps://$site:$port/$remote",
                CURLOPT_USERPWD        => "$user:$pass",
                CURLOPT_FILE           => $fp,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FTP_SSL        => CURLFTPSSL_ALL,
                CURLOPT_FTPSSLAUTH     => CURLFTPAUTH_TLS,
                CURLOPT_SSLVERSION     => CURL_SSLVERSION_TLSv1_2
            ]);

            curl_exec($ch);

            if (curl_errno($ch)) {
                $msg = "FTPS ERROR: " . curl_error($ch);
                $this->addLog($msg);
                throw new Exception($msg);
            }

            $this->addLog("Downloaded file: $remote");


        } finally {
            fclose($fp);
        }
    }
    
    public function listFtpsDirectory($remotePath, $site, $port, $user, $pass)
    {
        $files = [];
        
        try {
            // Ensure path starts with /
            if (empty($remotePath)) {
                $remotePath = '/';
            } elseif (substr($remotePath, 0, 1) !== '/') {
                $remotePath = '/' . $remotePath;
            }
            
            // Ensure path ends with /
            if (substr($remotePath, -1) !== '/') {
                $remotePath .= '/';
            }
            
            // Build the correct URL with proper formatting
            $ftpsUrl = "ftps://" . $site . ":" . $port . $remotePath;
            
            $this->addLog("Listing directory: $ftpsUrl");
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $ftpsUrl,
                CURLOPT_USERPWD        => "$user:$pass",
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FTP_SSL        => CURLFTPSSL_ALL,
                CURLOPT_FTPSSLAUTH     => CURLFTPAUTH_TLS,
                CURLOPT_SSLVERSION     => CURL_SSLVERSION_TLSv1_2,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FTPLISTONLY    => true,
                CURLOPT_VERBOSE        => 1,
                CURLOPT_STDERR         => fopen('php://temp', 'w+')
            ]);
    
            $listing = curl_exec($ch);
    
            if (curl_errno($ch)) {
                $msg = "FTPS LIST ERROR: " . curl_error($ch);
                $this->addLog($msg);
                throw new Exception($msg);
            }
    
    
            // Parse the file listing
            if (empty($listing)) {
                $this->addLog("Directory listing is empty");
                return $files;
            }
    
            $lines = explode("\n", trim($listing));
               foreach ($lines as $line) {
	                 $line = trim($line);
	                 // Skip empty lines and directories (. and ..)
	                 if (!$line || $line === '.' || $line === '..') {
	                     continue;
	                 }
	                 // Skip items without a file extension (likely directories)
	                 if (strpos(basename($line), '.') === false) {
	                     $this->addLog("Skipping directory or extensionless item: $line", 'detail');
	                     continue;
	                 }
	                 $files[] = $line;
	                 $this->addLog("Found file: $line", 'detail');
              }
    
            $this->addLog("Total files found: " . count($files));
    
        } catch (Exception $e) {
            $this->addLog("Error listing directory: " . $e->getMessage());
            throw $e;
        }
    
        return $files;
   }
   
   public function matchesWildcard($filename, $pattern)
   {
       // Convert wildcard pattern to regex
       // * matches any characters
       // ? matches single character
       $regex = '/^' . str_replace(
           ['\\*', '\\?'],
           ['.*', '.'],
           preg_quote($pattern, '/')
       ) . '$/i';
       
       return preg_match($regex, $filename) === 1;
   }
   
   public function parseMapping($file, $mapStr)
   {
       $map = [];
   
       // If mapStr is empty or null, use all fields as-is
       if (!empty($mapStr)) {
           foreach (explode(',', $mapStr) as $p) {
               $parts = array_map('trim', explode(':', $p, 2));
               if (count($parts)==2) {
                   $map[$parts[0]]=$parts[1];
               }
           }
       }
   
       $fh = fopen($file, 'r');
       if ($fh === false) {
           throw new Exception("Cannot open CSV file: $file");
       }
   
       $headers = fgetcsv($fh, 0, ",", "\"", "");
       if ($headers === false) {
           fclose($fh);
           throw new Exception("Cannot read headers from CSV file: $file");
       }
   
       $idx = [];
   
       if (empty($map)) {
           // No mapping provided - use all headers with their exact names
           foreach ($headers as $i=>$col) {
               $idx[$i]=$col;
           }
           $this->addLog("Using all fields from CSV with exact names");
       } else {
           // Use the provided mapping
           foreach ($headers as $i=>$col) {
               if (isset($map[$col])) {
                   $idx[$i]=$map[$col];
               }
           }
       }
   
       $rows = [];
   
       while ($line = fgetcsv($fh, 0, ",", "\"", "")) {
           $r=[];
           foreach ($idx as $i=>$t) {
               $r[$t]=$line[$i]??'';
           }
   
           $rows[]=$r;
       }
   
       fclose($fh);
   
       $this->addLog("Parsed ".count($rows)." rows with ".count($idx)." fields");
   
       return $rows;
    }

public function uploadData($token, $data)
{
    $url = APP_PATH_WEBROOT_FULL . 'api/';

    $this->addLog("Uploading " . count($data) . " records to REDCap", 'detail');
    $this->addLog("Sample record: " . json_encode($data[0] ?? []), 'detail');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => [
            'token'   => $token,
            'content' => 'record',
            'format'  => 'json',
            'data'    => json_encode($data)
        ]
    ]);

    $out   = curl_exec($ch);
    $error = curl_error($ch);

    if (curl_errno($ch)) {
        $msg = "cURL ERROR: " . $error;
        $this->addLog($msg, 'error');
        throw new Exception($msg);
    }


    $this->addLog("API Response: " . substr($out, 0, 500), 'detail');

    $response = json_decode($out, true);
    if (isset($response['error'])) {
        $msg = "REDCap API Error: " . $response['error'];
        $this->addLog($msg, 'error');
        throw new Exception($msg);
    }

    $this->addLog("Successfully uploaded " . count($data) . " records", 'detail');

    return $out;
}

// Update the uploadRepo method to accept overwrite parameter
public function uploadRepo($token, $file, $folder=null, $filename=null, $overwrite=false)
{
    $url = APP_PATH_WEBROOT_FULL . 'api/';

    if (!$filename) {
        $filename = basename($file);
    }

    $this->addLog("Preparing to upload file: $filename to folder: $folder", 'detail');
    $this->addLog("File size: " . filesize($file) . " bytes", 'detail');
    $this->addLog("Overwrite enabled: " . ($overwrite ? 'YES' : 'NO'), 'detail');

    if ($overwrite) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS     => [
                'token'     => $token,
                'content'   => 'fileRepository',
                'action'    => 'list',
                'folder_id' => $folder,
                'format'    => 'json'
            ]
        ]);

        $out = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $this->addLog("List API HTTP code: $httpCode", 'detail');
        $this->addLog("List API raw response: " . substr($out, 0, 500), 'detail');
        $response = json_decode($out, true);
	        if (isset($response['error'])) {
	            throw new Exception("REDCap API Error: " . $response['error']);
        }
        
        
        $this->addLog("List API curl error: $curlError", 'detail');

        $existingFiles = json_decode($out, true);
        $this->addLog("List API decoded: " . json_encode($existingFiles), 'detail');

        if (is_array($existingFiles)) {
            foreach ($existingFiles as $existingFile) {
                if (isset($existingFile['name']) && $existingFile['name'] === $filename) {
                    $doc_id = $existingFile['doc_id'];
                    $this->addLog("Found existing file with doc_id: $doc_id - deleting", 'detail');

                    $ch = curl_init($url);
                    curl_setopt_array($ch, [
                        CURLOPT_POST           => true,
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POSTFIELDS     => [
                            'token'   => $token,
                            'content' => 'fileRepository',
                            'action'  => 'delete',
                            'doc_id'  => $doc_id
                        ]
                    ]);

                    $deleteOut = curl_exec($ch);

                    $this->addLog("Delete response: " . substr($deleteOut, 0, 200), 'detail');
                    break;
                }
            }
        } else {
            $this->addLog("Could not list folder contents or folder is empty", 'detail');
        }
    }

    $tempDir     = sys_get_temp_dir();
    $renamedFile = $tempDir . '/' . $filename;

    if (!copy($file, $renamedFile)) {
        $this->addLog("Failed to copy file for upload: $filename", 'error');
        throw new Exception("Failed to copy file for upload");
    }

    try {
        $post = [
            'token'   => $token,
            'content' => 'fileRepository',
            'action'  => 'import',
            'file'    => new \CURLFile($renamedFile)
        ];

        if ($folder) {
            $post['folder_id'] = $folder;
            $this->addLog("Adding to folder ID: $folder", 'detail');
        }

        $this->addLog("Initiating file repository upload...", 'detail');

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS     => $post
        ]);

        $out   = curl_exec($ch);
        $error = curl_error($ch);

        if (curl_errno($ch)) {
            throw new Exception("Repo upload error: " . $error);
        }


        $this->addLog("File Repo API Response: " . substr($out, 0, 500), 'detail');

        $response = json_decode($out, true);
        if (isset($response['error'])) {
            throw new Exception("REDCap API Error: " . $response['error']);
        }

        $this->addLog("Successfully uploaded file to repo: $filename in folder: $folder", 'detail');

        return $out;

    } finally {
        if (file_exists($renamedFile)) {
            unlink($renamedFile);
        }
     }
  }
  
public function getRecordIdField($token)
{
    $url = APP_PATH_WEBROOT_FULL . 'api/';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => [
            'token'   => $token,
            'content' => 'exportFieldNames',
            'format'  => 'json'
        ]
    ]);

    $out = curl_exec($ch);

    $fields = json_decode($out, true);
    if (empty($fields)) {
        throw new Exception("Could not retrieve field names from REDCap");
    }

    // First field returned is always the record ID field
    return $fields[0]['export_field_name'];
}

public function verifyRecord($token, $record_id, $id_field)
{
    $url = APP_PATH_WEBROOT_FULL . 'api/';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => [
            'token'   => $token,
            'content' => 'record',
            'format'  => 'json',
            'records' => $record_id,
            'fields'  => $id_field
        ]
    ]);

    $out = curl_exec($ch);

    $records = json_decode($out, true);

    if (empty($records)) {
        return false;
    }

    foreach ($records as $record) {
        if (isset($record[$id_field]) && (string)$record[$id_field] === (string)$record_id) {
            return true;
        }
    }

    return false;
}

public function uploadToRecordField($token, $local, $record_id, $field, $event = null, $filename = null)
{
    $url = APP_PATH_WEBROOT_FULL . 'api/';

    $this->addLog("Uploading file to record: $record_id, field: $field", 'detail');

    // Use original filename if provided, otherwise fall back to basename
    $displayName = $filename ?? basename($local);

    $post = [
        'token'   => $token,
        'content' => 'file',
        'action'  => 'import',
        'record'  => $record_id,
        'field'   => $field,
        'file'    => new \CURLFile($local, '', $displayName)  // <-- third arg sets the filename
    ];


    // Only add event if specified
    if (!empty($event)) {
        $post['event'] = $event;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => $post
    ]);

    $out   = curl_exec($ch);
    $error = curl_error($ch);

    if (curl_errno($ch)) {
        throw new Exception("File upload error: " . $error);
    }


    $this->addLog("Record field API response: " . substr($out, 0, 500), 'detail');

    if (!empty($out)) {
        $response = json_decode($out, true);
        if (isset($response['error'])) {
            throw new Exception("REDCap API Error: " . $response['error']);
        }
        if ($response === null) {
            throw new Exception("Unexpected API response: " . substr($out, 0, 200));
        }
    }

    $this->addLog("Successfully uploaded file to record: $record_id, field: $field", 'detail');
}
   
public function archiveOnBox($remote, $archivePath, $site, $port, $user, $pass)
  {
      $filename = basename($remote);
      
      // Ensure archive path ends with /
      $archivePath = rtrim($archivePath, '/') . '/';
      $archiveFile = $archivePath . $filename;
      
      $this->addLog("Archiving $filename to $archivePath", 'detail');
      
      // Step 1: Download to temp
      $local = sys_get_temp_dir() . "/tmp_" . uniqid('ftps_archive_', true) . "_" . $filename;
      
      $this->downloadFromFtps($remote, $local, $site, $port, $user, $pass);
      
      if (!file_exists($local) || filesize($local) == 0) {
          throw new Exception("Downloaded file is empty or missing: $filename");
      }
      
      try {
          // Step 2: Upload to archive path on Box
          $fp = fopen($local, 'r');
          if (!$fp) {
              throw new Exception("Cannot open local file for archive upload: $filename");
          }
  
          try {
              $ch = curl_init();
              curl_setopt_array($ch, [
                  CURLOPT_URL            => "ftps://$site:$port/$archiveFile",
                  CURLOPT_USERPWD        => "$user:$pass",
                  CURLOPT_UPLOAD         => true,
                  CURLOPT_INFILE         => $fp,
                  CURLOPT_INFILESIZE     => filesize($local),
                  CURLOPT_SSL_VERIFYPEER => true,
                  CURLOPT_SSL_VERIFYHOST => 2,
                  CURLOPT_FTP_SSL        => CURLFTPSSL_ALL,
                  CURLOPT_FTPSSLAUTH     => CURLFTPAUTH_TLS,
                  CURLOPT_SSLVERSION     => CURL_SSLVERSION_TLSv1_2
              ]);
  
              curl_exec($ch);
  
              if (curl_errno($ch)) {
                  $msg = "FTPS archive upload error: " . curl_error($ch);
                  throw new Exception($msg);
              }
  
              $this->addLog("Archived file uploaded to: $archiveFile", 'detail');
  
          } finally {
              fclose($fp);
          }
  
          // Step 3: Delete original only if archive upload succeeded
          $this->deleteFromBox($remote, $site, $port, $user, $pass);
          $this->addLog("Archived $filename to $archivePath", 'summary');
  
      } finally {
          if (file_exists($local)) unlink($local);
      }
  }
  
  public function deleteFromBox($remote, $site, $port, $user, $pass)
  {
      $this->addLog("Deleting from Box: $remote", 'detail');
  
      $ch = curl_init();
      curl_setopt_array($ch, [
          CURLOPT_URL            => "ftps://$site:$port/",
          CURLOPT_USERPWD        => "$user:$pass",
          CURLOPT_SSL_VERIFYPEER => true,
          CURLOPT_SSL_VERIFYHOST => 2,
          CURLOPT_FTP_SSL        => CURLFTPSSL_ALL,
          CURLOPT_FTPSSLAUTH     => CURLFTPAUTH_TLS,
          CURLOPT_SSLVERSION     => CURL_SSLVERSION_TLSv1_2,
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_QUOTE          => ["DELE $remote"]
      ]);
  
      curl_exec($ch);
  
      if (curl_errno($ch)) {
          $msg = "FTPS delete error: " . curl_error($ch);
          throw new Exception($msg);
      }
  
      $this->addLog("Deleted from Box: $remote", 'detail');
}
  
  
  
    /**
     * Runs a full import job for the given project.
     * Called by boximp_now.php (no-auth, triggered by cron) and
     * boximp_manual.php (authenticated, triggered by a REDCap user).
     */
    public function runImportJob($pid)
    {
        if (!$pid) {
            exit("Missing PID");
        }

        $successMessages = [];
        $fileCount = 0;
        $recordCount = 0;

    try {

        $this->addLog("STARTING IMPORT");

        $settings = ExternalModules::getProjectSettingsAsArray(
            $this->PREFIX,
            $pid
        );

        // Extract scalar settings
        $site     = $settings['site']['value']      ?? null;
        $port     = $settings['port']['value']      ?? null;
        $username = $settings['username']['value']  ?? null;
        $password = $settings['password']['value']  ?? null;
        $api_token = $settings['api_token']['value'] ?? null;

        $this->addLog("Site: $site, Port: $port, User: $username");

        $data_files = $settings['data_files']['value']       ?? null;
        $field_map  = $settings['field_map']['value']        ?? '';
  

       $enable_data_import = !empty($settings['enable_data_import']['value']);
       $enable_repo_upload = !empty($settings['enable_repo_upload']['value']);
       $enable_upload_field = !empty($settings['enable_upload_field']['value']);
       $enable_archive     = !empty($settings['enable_archive']['value']);
       $enable_delete      = !empty($settings['enable_delete']['value']);
   
   
       $this->addLog("Enabled jobs - Data: " . ($enable_data_import ? 'YES' : 'NO') . 
                       ", Repo: " . ($enable_repo_upload ? 'YES' : 'NO') .
                       ", UploadField: " . ($enable_upload_field ? 'YES' : 'NO') .
                       ", Archive: " . ($enable_archive ? 'YES' : 'NO') .
                       ", Delete: " . ($enable_delete ? 'YES' : 'NO'), 'detail');
                   
      $this->addLog("DEBUG settings: " . json_encode([
          'enable_data_import'  => $settings['enable_data_import']['value']  ?? 'NOT SET',
          'enable_repo_upload'  => $settings['enable_repo_upload']['value']  ?? 'NOT SET',
          'enable_upload_field' => $settings['enable_upload_field']['value'] ?? 'NOT SET',
          'enable_archive'      => $settings['enable_archive']['value']      ?? 'NOT SET',
          'enable_delete'       => $settings['enable_delete']['value']       ?? 'NOT SET'
    ]), 'detail');

    $this->addLog("DEBUG flags: data=$enable_data_import, repo=$enable_repo_upload, field=$enable_upload_field, archive=$enable_archive, delete=$enable_delete", 'detail');
   
      if (!$enable_data_import && !$enable_repo_upload && !$enable_upload_field && !$enable_archive && !$enable_delete) {
          $this->addLog("WARNING: No job types enabled - nothing to do", 'error');
          throw new Exception("No job types enabled");
    }

     // -------------------------------------------------------
         // DATA IMPORT
         // -------------------------------------------------------
     
        if ($enable_data_import) {
 
             $data_files_list = $settings['data_files']['value']  ?? [];
             $field_maps      = $settings['field_map']['value']   ?? [];
 
             if (!is_array($data_files_list)) $data_files_list = [$data_files_list];
             if (!is_array($field_maps))      $field_maps      = [$field_maps];
 
             $this->addLog("Found " . count($data_files_list) . " data import job(s)", 'detail');
 
             foreach ($data_files_list as $job_index => $data_files) {
                 if (empty($data_files)) {
                     $this->addLog("Skipping data job $job_index - no files specified", 'error');
                     continue;
                 }
 
                 $field_map = $field_maps[$job_index] ?? '';
 
                 $this->addLog("Data job $job_index: files='$data_files'", 'detail');
 
                 $hasWildcard = (strpos($data_files, '*') !== false || strpos($data_files, '?') !== false);
 
                 if ($hasWildcard) {
                     $pathParts = explode('/', $data_files);
                     $pattern   = array_pop($pathParts);
                     $directory = trim(implode('/', $pathParts), '/') ?: '/';
 
                     $this->addLog("Wildcard detected - Directory: $directory, Pattern: $pattern", 'detail');
 
                     try {
                         $allFiles = $this->listFtpsDirectory($directory, $site, $port, $username, $password);
 
                         $matchingFiles = [];
                         foreach ($allFiles as $fname) {
                             if ($this->matchesWildcard($fname, $pattern)) {
                                 $matchingFiles[] = trim($directory, '/') . '/' . $fname;
                             }
                         }
 
                         if (empty($matchingFiles)) {
                             $this->addLog("WARNING: No data files matched pattern '$pattern'", 'error');
                         }
 
                     } catch (Exception $e) {
                         $this->addLog("ERROR processing data wildcard: " . $e->getMessage(), 'error');
                         $matchingFiles = [];
                     }
                 } else {
                     $matchingFiles = [$data_files];
                 }
 
                 foreach ($matchingFiles as $remoteFile) {
                     $fname = basename($remoteFile);
                     $this->addLog("Downloading data file: $fname", 'detail');
 
                     try {
                         $local = sys_get_temp_dir() . "/tmp_" . uniqid('ftps_', true) . "_" . $fname;
 
                         $this->downloadFromFtps($remoteFile, $local, $site, $port, $username, $password);
 
                         if (!file_exists($local) || filesize($local) == 0) {
                             $this->addLog("ERROR: File was not downloaded or is empty: $fname", 'error');
                             continue;
                         }
 
                         $this->addLog("File downloaded successfully. Size: " . filesize($local) . " bytes", 'detail');
 
                         $data = $this->parseMapping($local, $field_map);
 
                         $this->uploadData($api_token, $data);
 
                         $recordCount += count($data);
                         $successMessages[] = "Successfully imported " . count($data) . " records from $fname";
                         $this->addLog("Imported " . count($data) . " records from $fname", 'summary');
 
                     } catch (Exception $e) {
                         $this->addLog("ERROR with data file $fname: " . $e->getMessage(), 'error');
                     } finally {
                         if (!empty($local) && file_exists($local)) unlink($local);
                     }
                 }
             }
        }

            // -------------------------------------------------------
        // REPO IMPORT
        // -------------------------------------------------------
    
        if ($enable_repo_upload) {
            // Fields are parallel top-level arrays
            $repo_files_list = $settings['repo_files']['value']    ?? [];
            $repo_folder_ids = $settings['repo_folder_id']['value'] ?? [];
            $overwrite_flags = $settings['overwrite_file']['value'] ?? [];
            // Normalize to arrays in case only one job exists
            if (!is_array($repo_files_list)) $repo_files_list = [$repo_files_list];
            if (!is_array($repo_folder_ids)) $repo_folder_ids = [$repo_folder_ids];
            if (!is_array($overwrite_flags)) $overwrite_flags  = [$overwrite_flags];
            $this->addLog("Found " . count($repo_files_list) . " repo upload job(s)", 'detail');
            foreach ($repo_files_list as $job_index => $repo_files) {
                if (empty($repo_files)) {
                    $this->addLog("Skipping repo job $job_index - no files specified", 'error');
                    continue;
                }
                $repo_folder_id = $repo_folder_ids[$job_index] ?? null;
                $overwrite      = in_array($overwrite_flags[$job_index] ?? null, ['true', true, '1', 1], true);
                if (!is_numeric($repo_folder_id) || intval($repo_folder_id) <= 0) {
    	                   $this->addLog("ERROR: Invalid folder ID '$repo_folder_id' for repo job $job_index", 'error');
    	                   continue;
                }
                $this->addLog("Repo job $job_index: files='$repo_files', folder='$repo_folder_id', overwrite=" . ($overwrite ? 'YES' : 'NO'), 'detail');
                $hasWildcard = (strpos($repo_files, '*') !== false || strpos($repo_files, '?') !== false);
                if ($hasWildcard) {
                    $pathParts = explode('/', $repo_files);
                    $pattern   = array_pop($pathParts);
                    $directory = trim(implode('/', $pathParts), '/') ?: '/';
                    $this->addLog("Wildcard detected - Directory: $directory, Pattern: $pattern", 'detail');
                    try {
                        $allFiles = $this->listFtpsDirectory($directory, $site, $port, $username, $password);
                        $matchingFiles = [];
                        foreach ($allFiles as $fname) {
                            if ($this->matchesWildcard($fname, $pattern)) {
                                $matchingFiles[] = trim($directory, '/') . '/' . $fname;
                            }
                        }
                        if (empty($matchingFiles)) {
                            $this->addLog("WARNING: No repo files matched pattern '$pattern'", 'error');
                        }
                    } catch (Exception $e) {
                        $this->addLog("ERROR processing repo wildcard: " . $e->getMessage(), 'error');
                        $matchingFiles = [];
                    }
                } else {
                    $matchingFiles = [$repo_files];
                }
                foreach ($matchingFiles as $remoteFile) {
                    $fname = basename($remoteFile);
                    $this->addLog("Downloading repo file: $fname", 'detail');
                    try {
                        $tmpDir = sys_get_temp_dir() . "/ftps_" . uniqid('', true);
    		    mkdir($tmpDir, 0700, true);
                        $local = $tmpDir . "/" . $fname;
                    
                        $this->downloadFromFtps($remoteFile, $local, $site, $port, $username, $password);
                        $this->uploadRepo($api_token, $local, $repo_folder_id, $fname, $overwrite);
                        $fileCount++;
                        $successMessages[] = "Successfully uploaded $fname to folder $repo_folder_id";
                        $this->addLog("Uploaded $fname to folder $repo_folder_id", 'summary');
                    } catch (Exception $e) {
                        $this->addLog("ERROR with repo file $fname: " . $e->getMessage(), 'error');
                    } finally {
    		    if (!empty($local) && file_exists($local)) unlink($local);
    		    if (!empty($tmpDir) && is_dir($tmpDir)) rmdir($tmpDir);
                    }
                }
            }
        }
    
        // -------------------------------------------------------
            // UPLOAD FIELD JOBS
            // -------------------------------------------------------
            if ($enable_upload_field) {
	
    	    $this->addLog("DEBUG: Entered upload field job block", 'detail');
	
    	    $upload_field_sources    = $settings['upload_field_source']['value']    ?? [];
    	    $upload_fields           = $settings['upload_field']['value']           ?? [];
    	    $upload_field_events     = $settings['upload_field_event']['value']     ?? [];
    	    $upload_field_delimiters = $settings['upload_field_delimiter']['value'] ?? [];
    	    $upload_field_error_dirs = $settings['upload_field_error_dir']['value'] ?? [];
	
    	    if (!is_array($upload_field_sources))    $upload_field_sources    = [$upload_field_sources];
    	    if (!is_array($upload_fields))           $upload_fields           = [$upload_fields];
    	    if (!is_array($upload_field_events))     $upload_field_events     = [$upload_field_events];
    	    if (!is_array($upload_field_delimiters)) $upload_field_delimiters = [$upload_field_delimiters];
    	    if (!is_array($upload_field_error_dirs)) $upload_field_error_dirs = [$upload_field_error_dirs];
	
    	    $this->addLog("Found " . count($upload_field_sources) . " upload field job(s)", 'detail');
	
    	    // Detect record ID field once for all jobs
    	    try {
    	        $id_field = $this->getRecordIdField($api_token);
    	        $this->addLog("Record ID field: $id_field", 'detail');
    	    } catch (Exception $e) {
    	        $this->addLog("ERROR detecting record ID field: " . $e->getMessage(), 'error');
    	        $id_field = null;
    	    }
	
    	    if (empty($id_field)) {
    	        $this->addLog("ERROR: Cannot proceed with upload field jobs - record ID field not detected", 'error');
    	    } else {
	
    	        foreach ($upload_field_sources as $job_index => $upload_field_source) {
    	            if (empty($upload_field_source)) {
    	                $this->addLog("Skipping upload field job $job_index - no source specified", 'detail');
    	                continue;
    	            }
	
    	            $field     = $upload_fields[$job_index]           ?? null;
    	            $event     = $upload_field_events[$job_index]     ?? null;
    	            $delimiter = $upload_field_delimiters[$job_index] ?? null;
    	            if ($delimiter === '') $delimiter = null;
    	            $error_dir = $upload_field_error_dirs[$job_index] ?? null;
	
    	            $this->addLog("DEBUG job $job_index: field=" . var_export($field, true) . ", error_dir=" . var_export($error_dir, true), 'detail');
    	            $this->addLog("DEBUG delimiter for job $job_index: " . var_export($delimiter, true), 'detail');
	
    	            if (empty($field)) {
    	                $this->addLog("Skipping upload field job $job_index - no field specified", 'info');
    	                continue;
    	            }
	
    	            if (empty($error_dir)) {
    	                $this->addLog("Skipping upload field job $job_index - no error directory specified", 'info');
    	                continue;
    	            }
	
    	            $this->addLog("Upload field job $job_index: source='$upload_field_source', field='$field', event='$event', delimiter=" . var_export($delimiter, true), 'detail');
	
    	            // List all files in source directory
    	            try {
    	                $directory = trim($upload_field_source, '/');
    	                $allFiles  = $this->listFtpsDirectory($directory, $site, $port, $username, $password);
	
    	                if (empty($allFiles)) {
    	                    $this->addLog("No files found in directory: $upload_field_source", 'detail');
    	                    continue;
    	                }
	
    	            } catch (Exception $e) {
    	                $this->addLog("ERROR listing directory $upload_field_source: " . $e->getMessage(), 'error');
    	                continue;
    	            }
	
    	            foreach ($allFiles as $fname) {
	
    	                $nameWithoutExt = pathinfo($fname, PATHINFO_FILENAME);
    	                $remoteFile = trim($directory, '/') . '/' . $fname;
    	                $this->addLog("DEBUG: fname=$fname, nameWithoutExt=$nameWithoutExt, delimiter=" . var_export($delimiter, true), 'detail');
	
    	                if ($delimiter === null) {
    	                    // No delimiter configured - use entire filename stem as record ID
    	                    $record_id = $nameWithoutExt;
    	                } elseif (strpos($nameWithoutExt, $delimiter) !== false) {
    	                    // Delimiter found - parse record ID from before it
    	                    $record_id = strstr($nameWithoutExt, $delimiter, true);
    	                } else {
    	                    // Delimiter configured but not found in filename - error
    	                    $this->addLog("ERROR: Delimiter '$delimiter' not found in filename '$fname' - moving to error directory", 'error');
    	                    try {
    	                        $this->archiveOnBox($remoteFile, $error_dir, $site, $port, $username, $password);
    	                    } catch (Exception $e) {
    	                        $this->addLog("ERROR moving $fname to error directory: " . $e->getMessage(), 'error');
    	                    }
    	                    continue;
    	                }
	
    	                $this->addLog("DEBUG: record_id=" . var_export($record_id, true), 'info');
    	                $this->addLog("Processing file: $fname, parsed record ID: $record_id", 'detail');
	
    	                $local = null;
	
    	                try {
    	                    // Verify record exists
    	                    $recordExists = $this->verifyRecord($api_token, $record_id, $id_field);
	
    	                    if (!$recordExists) {
    	                        $this->addLog("ERROR: Record $record_id not found for file $fname - moving to error directory", 'error');
    	                        $this->archiveOnBox($remoteFile, $error_dir, $site, $port, $username, $password);
    	                        $this->addLog("Moved $fname to error directory: $error_dir", 'error');
    	                        continue;
    	                    }
	
    	                    // Download file to temp
    	                    $local = sys_get_temp_dir() . "/tmp_" . uniqid('ftps_field_', true) . "_" . $fname;
    	                    $this->downloadFromFtps($remoteFile, $local, $site, $port, $username, $password);
	
    	                    if (!file_exists($local) || filesize($local) == 0) {
    	                        $this->addLog("ERROR: File was not downloaded or is empty: $fname", 'error');
    	                        continue;
    	                    }
	
    	                    // Upload to REDCap record field
    	                    $this->uploadToRecordField($api_token, $local, $record_id, $field, $event, $fname);
	
    	                    $successMessages[] = "Uploaded $fname to record $record_id field $field";
    	                    $this->addLog("Uploaded $fname to record $record_id field $field", 'summary');
	
    	                } catch (Exception $e) {
    	                    $this->addLog("ERROR processing $fname: " . $e->getMessage(), 'error');
    	                } finally {
    	                    if (!empty($local) && file_exists($local)) unlink($local);
    	                }
    	            }
    	        }
    	    }
              }
    
    // -------------------------------------------------------
        // ARCHIVE JOBS
        // -------------------------------------------------------
       if ($enable_archive) {
               $archive_sources      = $settings['archive_source']['value']      ?? [];
               $archive_destinations = $settings['archive_destination']['value'] ?? [];
   
               if (!is_array($archive_sources))      $archive_sources      = [$archive_sources];
               if (!is_array($archive_destinations)) $archive_destinations = [$archive_destinations];
   
               $this->addLog("Found " . count($archive_sources) . " archive job(s)", 'detail');
   
               foreach ($archive_sources as $job_index => $archive_source) {
                   if (empty($archive_source)) {
                       $this->addLog("Skipping archive job $job_index - no source specified", 'detail');
                       continue;
                   }
   
                   $archive_destination = $archive_destinations[$job_index] ?? null;
   
                   if (empty($archive_destination)) {
                       $this->addLog("Skipping archive job $job_index - no destination specified", 'detail');
                       continue;
                   }
   
                   $this->addLog("Archive job $job_index: source='$archive_source', destination='$archive_destination'", 'detail');
   
                   $hasWildcard = (strpos($archive_source, '*') !== false || strpos($archive_source, '?') !== false);
   
                   if ($hasWildcard) {
                       $pathParts = explode('/', $archive_source);
                       $pattern   = array_pop($pathParts);
                       $directory = trim(implode('/', $pathParts), '/') ?: '/';
   
                       $this->addLog("Wildcard detected - Directory: $directory, Pattern: $pattern", 'detail');
   
                       try {
                           $allFiles = $this->listFtpsDirectory($directory, $site, $port, $username, $password);
   
                           $matchingFiles = [];
                           foreach ($allFiles as $fname) {
                               if ($this->matchesWildcard($fname, $pattern)) {
                                   $matchingFiles[] = trim($directory, '/') . '/' . $fname;
                               }
                           }
   
                           if (empty($matchingFiles)) {
                               $this->addLog("WARNING: No archive files matched pattern '$pattern'", 'error');
                           }
   
                       } catch (Exception $e) {
                           $this->addLog("ERROR processing archive wildcard: " . $e->getMessage(), 'error');
                           $matchingFiles = [];
                       }
                   } else {
                       $matchingFiles = [$archive_source];
                   }
   
                   foreach ($matchingFiles as $remoteFile) {
                       $fname = basename($remoteFile);
                       try {
                           $this->archiveOnBox(
                               $remoteFile,
                               $archive_destination,
                               $site,
                               $port,
                               $username,
                               $password
                           );
                           $successMessages[] = "Archived $fname to $archive_destination";
                       } catch (Exception $e) {
                           $this->addLog("ERROR archiving $fname: " . $e->getMessage(), 'error');
                       }
                   }
               }
        }
    
        // -------------------------------------------------------
        // DELETE JOBS
        // -------------------------------------------------------
        if ($enable_delete) {

            $delete_files_list = $settings['delete_files']['value'] ?? [];

            if (!is_array($delete_files_list)) $delete_files_list = [$delete_files_list];

            $this->addLog("Found " . count($delete_files_list) . " delete job(s)", 'detail');

            foreach ($delete_files_list as $job_index => $delete_files) {
                if (empty($delete_files)) {
                    $this->addLog("Skipping delete job $job_index - no files specified", 'delete');
                    continue;
                }

                $this->addLog("Delete job $job_index: files='$delete_files'", 'detail');

                $hasWildcard = (strpos($delete_files, '*') !== false || strpos($delete_files, '?') !== false);

                if ($hasWildcard) {
                    $pathParts = explode('/', $delete_files);
                    $pattern   = array_pop($pathParts);
                    $directory = trim(implode('/', $pathParts), '/') ?: '/';

                    $this->addLog("Wildcard detected - Directory: $directory, Pattern: $pattern", 'detail');

                    try {
                        $allFiles = $this->listFtpsDirectory($directory, $site, $port, $username, $password);

                        $matchingFiles = [];
                        foreach ($allFiles as $fname) {
                            if ($this->matchesWildcard($fname, $pattern)) {
                                $matchingFiles[] = trim($directory, '/') . '/' . $fname;
                            }
                        }

                        if (empty($matchingFiles)) {
                            $this->addLog("WARNING: No delete files matched pattern '$pattern'", 'error');
                        }

                    } catch (Exception $e) {
                        $this->addLog("ERROR processing delete wildcard: " . $e->getMessage(), 'error');
                        $matchingFiles = [];
                    }
                } else {
                    $matchingFiles = [$delete_files];
                }

                foreach ($matchingFiles as $remoteFile) {
                    $fname = basename($remoteFile);
                    try {
                        $this->deleteFromBox($remoteFile, $site, $port, $username, $password);
                        $successMessages[] = "Deleted $fname from Box";
                        $this->addLog("Deleted $fname from Box", 'summary');
                    } catch (Exception $e) {
                        $this->addLog("ERROR deleting $fname: " . $e->getMessage(), 'error');
                    }
                }
            }
        }
    

        $this->addLog("IMPORT COMPLETE", 'summary');

    } catch (\Throwable $e) {

        $this->addLog("FATAL ERROR: " . $e->getMessage());

    } finally {

        $this->writeRunLog($pid);

        $hasSuccesses = !empty($successMessages);

        if ($hasSuccesses || !empty($this->logMessages)) {
            echo '<div style="margin: 20px;">';

            if ($hasSuccesses) {
                echo '<div style="padding: 15px; background-color: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; color: #155724; margin-bottom: 15px;">';
                echo '<strong>Box FTPS Import - Success!</strong><br><br>';

                if ($fileCount > 0) {
                    echo $fileCount . ' file(s) were imported to the file repository<br>';
                }
                if ($recordCount > 0) {
                    echo $recordCount . ' record(s) were successfully imported<br>';
                }
                foreach ($successMessages as $msg) {
                    echo htmlspecialchars($msg) . '<br>';
                }

                echo '</div>';
            }

            if (!empty($this->logMessages)) {
                echo '<div style="padding: 15px; background-color: #fff3cd; border: 1px solid #ffc107; border-radius: 4px; color: #856404;">';
                echo '<strong>Import Log Details:</strong><br><br>';
                echo '<div style="font-family: monospace; font-size: 12px; max-height: 400px; overflow-y: auto; background-color: #f8f9fa; padding: 10px; border-radius: 3px;">';

    foreach ($this->logMessages as $logEntry) {
        $line = htmlspecialchars($logEntry['time'] . ' - ' . $logEntry['msg']);

        if ($logEntry['level'] === 'error') {
            echo '<span style="color: #dc3545;">' . $line . '</span><br>';
        } elseif (strpos($logEntry['msg'], 'WARNING') !== false) {
            echo '<span style="color: #ff9800;">' . $line . '</span><br>';
        } elseif ($logEntry['level'] === 'summary' || strpos($logEntry['msg'], 'Downloaded') !== false || strpos($logEntry['msg'], 'Uploaded') !== false) {
            echo '<span style="color: #28a745;">' . $line . '</span><br>';
        } else {
            echo $line . '<br>';
        }
    }  // closes foreach
                echo '</div>';
                echo '</div>';
            }                   // closes if (!empty($this->logMessages))

            echo '</div>';
        }                       // closes if ($hasSuccesses || ...)
    }                           // closes finally
    }

} // <-- CLASS CLOSING BRACE
