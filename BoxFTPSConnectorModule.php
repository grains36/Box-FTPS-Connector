<?php
namespace BoxFTPSConnector\BoxFTPSConnectorModule;
use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;
use REDCap;
use Records;
use Logging;
use Project;

class BoxFTPSConnectorModule extends AbstractExternalModule
{
    protected $logMessages = [];

    public function redcap_every_page_top($project_id)
    {
        if (!$project_id) return;
        if (PAGE !== 'ExternalModules/manager/project.php') return;

        $log = $this->getProjectSetting('error_log', $project_id);
        if (empty($log)) return;

        // Extract just the first two lines (Run Time and status)
        $lines = explode("\n", trim($log));
        $runTime = $lines[0] ?? '';
        $status  = $lines[1] ?? '';

        $color = (strpos($status, 'ERRORS') !== false)
            ? '#f8d7da'   // red tint
            : '#d4edda';  // green tint
        $border = (strpos($status, 'ERRORS') !== false)
            ? '#f5c6cb'
            : '#c3e6cb';
        $text = (strpos($status, 'ERRORS') !== false)
            ? '#721c24'
            : '#155724';

        echo '<div style="margin: 10px 20px; padding: 10px 15px; background-color: ' . $color . '; 
                    border: 1px solid ' . $border . '; border-radius: 4px; color: ' . $text . ';
                    font-family: Arial, sans-serif; font-size: 13px;">';
        echo '<strong>Box FTPS Import</strong> &mdash; ';
        echo htmlspecialchars($runTime) . ' &mdash; ';
        echo '<strong>' . htmlspecialchars($status) . '</strong>';
        echo '</div>';
    }

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
 
     $filtered = array_filter($this->logMessages, function($m) use ($hasErrors) {
         if ($hasErrors) return true;
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
 
     $parts = explode($separator, $combined);
     if (count($parts) > 2) {
         $parts = array_slice($parts, 0, 2);
     }
 
     $this->setProjectSetting('error_log', implode($separator, $parts), $project_id);
 
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
function importmethod($cronAttributes) {
    try {
        $this->addLog("CRON JOB STARTED");
        
        $today = new \DateTime();
        $current_hour = $today->format('G');
        $current_day = $today->format('N'); // 1-7 Monday to Sunday

        $this->addLog("Current hour: $current_hour, Current day: $current_day");

        $framework = \ExternalModules\ExternalModules::getFrameworkInstance($this->PREFIX);
        $projects = $framework->getProjectsWithModuleEnabled();

        $this->addLog("Found " . count($projects) . " projects with module enabled");

        if (count($projects) > 0) {
            foreach ($projects as $project_id) {
                try {
                    $Proj = new Project($project_id);
                    
                    // Check if the cron feature is enabled for this project
                    $enable_cron = $this->getProjectSetting('enable_cron', $project_id); 
                    
                    if ($enable_cron) {
                        // Get project-specific settings
                        $time_of_day = $this->getProjectSetting('time_of_day', $project_id);
                        $time_of_day2 = $this->getProjectSetting('time_of_day2', $project_id);
                        $run_on_monday = $this->getProjectSetting('run_on_monday', $project_id);
                        $run_on_tuesday = $this->getProjectSetting('run_on_tuesday', $project_id);
                        $run_on_wednesday = $this->getProjectSetting('run_on_wednesday', $project_id);
                        $run_on_thursday = $this->getProjectSetting('run_on_thursday', $project_id);
                        $run_on_friday = $this->getProjectSetting('run_on_friday', $project_id);
                        $run_on_saturday = $this->getProjectSetting('run_on_saturday', $project_id);
                        $run_on_sunday = $this->getProjectSetting('run_on_sunday', $project_id);
        
                        $this->addLog("Project ID: $project_id - Cron enabled");
                        $this->addLog("Schedule - Time 1: $time_of_day, Time 2: $time_of_day2");
                        $this->addLog("Days - Mon: $run_on_monday, Tue: $run_on_tuesday, Wed: $run_on_wednesday, Thu: $run_on_thursday, Fri: $run_on_friday, Sat: $run_on_saturday, Sun: $run_on_sunday");
        
                        // Check if the current time matches the project settings for either run time
                        $time_matches = ($current_hour == $time_of_day || $current_hour == $time_of_day2);
                        $day_matches = (
                            ($current_day == 1 && $run_on_monday) ||
                            ($current_day == 2 && $run_on_tuesday) ||
                            ($current_day == 3 && $run_on_wednesday) ||
                            ($current_day == 4 && $run_on_thursday) ||
                            ($current_day == 5 && $run_on_friday) ||
                            ($current_day == 6 && $run_on_saturday) ||
                            ($current_day == 7 && $run_on_sunday)
                        );

                        if ($time_matches && $day_matches) {
                            $this->addLog("Schedule match found - executing import for project $project_id");
                            
                            $module_cron_url = \ExternalModules\ExternalModules::getUrl($this->PREFIX, 'boximp_now.php', $project_id, true, true);
                            $this->addLog("Calling URL: $module_cron_url");

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $module_cron_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_VERBOSE, 0);
                            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                            curl_setopt($ch, CURLOPT_AUTOREFERER, true);
                            curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
                            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
                            curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
                            curl_setopt($ch, CURLOPT_TIMEOUT, 300);
                            
                            $output = curl_exec($ch);
                            $curl_error = curl_error($ch);

                            if (!empty($curl_error)) {
                                $this->addLog("ERROR: cURL error - $curl_error");
                            } else {
                                $this->addLog("FTPS import triggered successfully for project $project_id");
                            }

                            $this->writeRunLog($project_id, true);  // ← only write on actual run

                        } else {
                            // Skipped - don't log at all
                        }
                    } else {
                        // Cron disabled - don't log at all
                    }
                    
                    // Remove the writeRunLog($project_id) that was here
                    $this->logMessages = [];
                    
                } catch (Exception $ee) {
                    $this->addLog("ERROR in project $project_id: " . $ee->getMessage());
                    $this->writeRunLog($project_id, true);  // ← errors should always be logged
                    $this->logMessages = [];
                }
            }
        } else {
            $this->addLog("No projects found with module enabled");
        }
        
    } catch (Exception $e) {
        $this->addLog("FATAL ERROR: " . $e->getMessage());
    }
  }
}