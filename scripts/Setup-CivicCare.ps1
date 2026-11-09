[CmdletBinding()]
param(
 [string]$ProjectPath = (Split-Path -Parent $PSScriptRoot),
 [string]$PhpPath = "C:\xampp\php\php.exe",
 [switch]$EnableZip,
 [switch]$InstallTask,
 [switch]$RunWhenSignedOut,
 [switch]$WakeToRun
)
$ErrorActionPreference = "Stop"
if (($RunWhenSignedOut -or $WakeToRun) -and -not $InstallTask) { throw "Use these scheduler options with -InstallTask." }
$ProjectPath = (Resolve-Path -LiteralPath $ProjectPath).Path
if (-not (Test-Path -LiteralPath (Join-Path $ProjectPath "scripts\run_overdue.php"))) { throw "Project runner was not found. Select the existing CivicCare project." }
if (-not (Test-Path -LiteralPath $PhpPath -PathType Leaf)) { throw "PHP executable was not found. Supply -PhpPath with your installed php.exe." }
$PhpPath = (Resolve-Path -LiteralPath $PhpPath).Path
Write-Host "Project: $ProjectPath"
Write-Host "PHP: $PhpPath"
& $PhpPath --version | Select-Object -First 1
if ($LASTEXITCODE -ne 0) { throw "PHP did not run successfully." }
$moduleText = (& $PhpPath -m 2>&1 | Out-String)
if ($LASTEXITCODE -ne 0) { throw "PHP module check failed." }
foreach ($module in @("pdo_mysql","gd","zip")) {
 $present = $moduleText -match "(?im)^$([regex]::Escape($module))\s*$"
 Write-Host ("{0}: {1}" -f $module, $(if($present){"loaded"}else{"missing"}))
}
if ($EnableZip -and $moduleText -notmatch "(?im)^zip\s*$") {
 $iniPath = (& $PhpPath -r 'echo php_ini_loaded_file();' | Out-String).Trim()
 if (-not $iniPath -or -not (Test-Path -LiteralPath $iniPath -PathType Leaf)) { throw "The selected PHP did not load a php.ini. Configure PHP first." }
 $dll = Join-Path (Split-Path -Parent $PhpPath) "ext\php_zip.dll"
 if (-not (Test-Path -LiteralPath $dll -PathType Leaf)) { throw "php_zip.dll was not found. Install the ZIP extension matching this PHP build first." }
 $backup = $iniPath + ".civiccare-" + (Get-Date -Format "yyyyMMdd-HHmmss") + ".bak"
 Copy-Item -LiteralPath $iniPath -Destination $backup -ErrorAction Stop
 $text = [IO.File]::ReadAllText($iniPath)
 if ($text -match '(?im)^\s*;+\s*extension\s*=\s*"?(?:php_)?zip(?:\.dll)?"?\s*(?:;.*)?$') {
  $zipLine = [regex]::new('(?im)^\s*;+\s*extension\s*=\s*"?(?:php_)?zip(?:\.dll)?"?\s*(?:;.*)?$')
  $text = $zipLine.Replace($text,"extension=zip",1)
 } elseif ($text -notmatch '(?im)^\s*extension\s*=\s*"?(?:php_)?zip(?:\.dll)?"?\s*(?:;.*)?$') {
  $text += [Environment]::NewLine + "extension=zip" + [Environment]::NewLine
 }
 try {
  [IO.File]::WriteAllText($iniPath,$text,([Text.UTF8Encoding]::new($false)))
  $check = (& $PhpPath -m 2>&1 | Out-String)
  if ($LASTEXITCODE -ne 0 -or $check -notmatch "(?im)^zip\s*$") { throw "ZIP did not load after the configuration change." }
 } catch {
  Copy-Item -LiteralPath $backup -Destination $iniPath -Force
  throw
 }
 Write-Host "ZIP enabled in $iniPath. Backup: $backup"
 Write-Host "Restart Apache, then check CivicCare System tools. Apache may use a different php.ini from CLI."
}
if ($InstallTask) {
 if ($moduleText -notmatch "(?im)^pdo_mysql\s*$") { throw "PDO MySQL is required by the scheduled runner." }
 $runner = Join-Path $ProjectPath "scripts\run_overdue.php"
 $action = New-ScheduledTaskAction -Execute $PhpPath -Argument ('"' + $runner + '"') -WorkingDirectory $ProjectPath
 $triggers = @(New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(2) -RepetitionInterval (New-TimeSpan -Hours 1))
 $identity = [Security.Principal.WindowsIdentity]::GetCurrent().Name
 $settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -MultipleInstances IgnoreNew -WakeToRun:$WakeToRun
 $hash = [Security.Cryptography.SHA256]::Create()
 try {
  $pathTag = ([BitConverter]::ToString($hash.ComputeHash([Text.Encoding]::UTF8.GetBytes($ProjectPath)))).Replace("-","").Substring(0,10)
 } finally {
  $hash.Dispose()
 }
 $taskName = "CivicCare-Followup-" + $pathTag
 if ($RunWhenSignedOut) {
  $credential = Get-Credential -UserName $identity -Message "Enter the Windows account password for CivicCare background scheduling."
  if (-not $credential -or $credential.UserName -ine $identity) { throw "Use the current Windows account." }
  $triggers += New-ScheduledTaskTrigger -AtStartup
  Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $triggers -Settings $settings -User $identity -Password ($credential.GetNetworkCredential().Password) -RunLevel Limited -Description "Hourly CivicCare workflow follow-up." -Force | Out-Null
  Write-Host "Background mode enabled. Start MySQL automatically as a Windows service for runs after restart."
 } else {
  $principal = New-ScheduledTaskPrincipal -UserId $identity -LogonType Interactive -RunLevel Limited
  Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $triggers -Principal $principal -Settings $settings -Description "Hourly CivicCare workflow follow-up." -Force | Out-Null
  Write-Host "Interactive mode runs while this Windows account is signed in."
 }
 Write-Host "Installed task: $taskName"
 Write-Host "Check Task Scheduler results and the CivicCare heartbeat."
}
Write-Host "Check completed. No reports, passwords or town mappings were changed."
