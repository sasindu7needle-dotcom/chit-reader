param([switch]$Restart)
$ErrorActionPreference = 'Stop'
$projectDirectory = Split-Path -Parent $PSScriptRoot
$phpCommand = Get-Command php -ErrorAction SilentlyContinue
$phpExecutable = if ($phpCommand) { $phpCommand.Source } else { 'F:\laragon\laragon\bin\php\php-8.4.4-Win32-vs17-x64\php.exe' }
if (-not (Test-Path -LiteralPath $phpExecutable)) { throw 'PHP was not found. Add your PHP installation to PATH.' }
$pidFile = Join-Path $projectDirectory 'storage\framework\local-worker.pid'
if (Test-Path -LiteralPath $pidFile) {
    $workerProcessId = [int](Get-Content -LiteralPath $pidFile)
    $existingWorker = Get-CimInstance Win32_Process -Filter "ProcessId = $workerProcessId" -ErrorAction SilentlyContinue
    if ($existingWorker -and $existingWorker.CommandLine -like '*queue:listen*' -and $existingWorker.CommandLine.Contains($projectDirectory)) {
        if (-not $Restart) { Write-Output "Worker already running (PID $workerProcessId). Use -Restart after changing .env."; exit 0 }
        & taskkill.exe /PID $workerProcessId /T /F | Out-Null
    }
}
# Restart after changing .env: the listener's child processes inherit its environment.
$artisanPath = Join-Path $projectDirectory 'artisan'
$worker = Start-Process -FilePath $phpExecutable -ArgumentList @(('"' + $artisanPath + '"'), 'queue:listen', '--timeout=3600', '--tries=2', '--sleep=3') -WorkingDirectory $projectDirectory -WindowStyle Hidden -RedirectStandardOutput (Join-Path $projectDirectory 'storage\logs\worker-output.log') -RedirectStandardError (Join-Path $projectDirectory 'storage\logs\worker-error.log') -PassThru
Set-Content -LiteralPath $pidFile -Value $worker.Id
Write-Output "Local processing worker started (PID $($worker.Id))."
