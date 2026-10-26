#Requires -Version 5.0
<#
.SYNOPSIS
    Posts an HMAC-SHA256-signed WhatsApp Cloud API webhook payload to the local
    dev server so you can exercise the inbound intake pipeline BEFORE wiring up
    ngrok + a real Meta app.

.DESCRIPTION
    Reproduces exactly what Meta sends to POST /api/whatsapp/webhook: a JSON body
    signed with the WHATSAPP_APP_SECRET in the X-Hub-Signature-256 header. The raw
    bytes that are signed are the same bytes that are sent, so the controller's
    hash_equals() check passes. See scripts/README.md for prerequisites.

.PARAMETER Body
    The inbound message text. Defaults to a unique "NEW TICKET ..." message.

.PARAMETER From
    Sender number (bare digits, as WhatsApp sends it). Defaults to the first
    active Employee found in the database (via artisan tinker).

.PARAMETER AppSecret
    Shared secret used to sign the payload. Defaults to WHATSAPP_APP_SECRET read
    from the app .env. MUST match the value the running server uses.

.PARAMETER Wamid
    WhatsApp message id. Defaults to a random wamid.LOCAL<digits> (a fresh id
    each run, since the recorder de-duplicates by message id).

.PARAMETER CloseLatest
    Instead of an arbitrary body, resolve the sender's most recent ticket number
    and send "CLOSE <ticket-number>".

.EXAMPLE
    .\scripts\Send-WhatsAppWebhook.ps1
    # opens a new ticket from the first active employee

.EXAMPLE
    .\scripts\Send-WhatsAppWebhook.ps1 -Body "NEW TICKET Projector in room 3 has no signal"

.EXAMPLE
    .\scripts\Send-WhatsAppWebhook.ps1 -CloseLatest
    # closes the sender's most recent ticket via the CLOSE command
#>
[CmdletBinding()]
param(
    [string]$BaseUrl   = 'http://127.0.0.1:8000',
    [string]$AppPath   = 'C:\xampp\htdocs\it-ticketing\app',
    [string]$Php       = 'C:\xampp\php\php.exe',
    [string]$Body,
    [string]$From,
    [string]$AppSecret,
    [string]$Wamid,
    [switch]$CloseLatest
)

$ErrorActionPreference = 'Stop'
$Endpoint = '/api/whatsapp/webhook'

function Get-AppSecretFromEnv([string]$appPath) {
    $envFile = Join-Path $appPath '.env'
    if (-not (Test-Path $envFile)) { return '' }
    foreach ($line in Get-Content $envFile) {
        if ($line -match '^\s*WHATSAPP_APP_SECRET\s*=\s*(.*)$') {
            $v = $Matches[1].Trim()
            if ($v.Length -ge 2 -and (($v[0] -eq '"' -and $v[-1] -eq '"') -or ($v[0] -eq "'" -and $v[-1] -eq "'"))) {
                $v = $v.Substring(1, $v.Length - 2)
            }
            return $v
        }
    }
    return ''
}

function Invoke-Tinker([string]$code, [string]$appPath, [string]$php) {
    Push-Location $appPath
    try {
        $out = & $php artisan tinker --execute=$code 2>$null
        return (($out | Out-String).Trim())
    }
    finally {
        Pop-Location
    }
}

# --- Resolve the signing secret ------------------------------------------------
if (-not $AppSecret) { $AppSecret = Get-AppSecretFromEnv $AppPath }
if (-not $AppSecret) {
    throw "WHATSAPP_APP_SECRET is empty. Set a non-empty value in $AppPath\.env and run 'php artisan config:clear'. It must match the value the running server uses."
}

# --- Resolve the sender number -------------------------------------------------
# NOTE: PHP string literals here use SINGLE quotes. Windows collapses embedded
# double quotes when PowerShell builds the native-argv for --execute, so double
# quotes in the tinker code would be stripped and break parsing.
if (-not $From) {
    $codeFrom = "echo ltrim(\App\Models\Employee::where('is_active',true)->orderBy('id')->value('whatsapp_number') ?? '', '+');"
    $From = Invoke-Tinker $codeFrom $AppPath $Php
}
if ($From -notmatch '^\d+$') {
    throw "Could not resolve an active sender number (got: '$From'). Seed the DB (php artisan migrate:fresh --seed) or pass -From <digits>."
}

# --- Resolve the message body --------------------------------------------------
if ($CloseLatest) {
    $codeClose = "echo \App\Models\Ticket::where('employee_id', \App\Models\Employee::findByNumber('$From')?->id ?? 0)->latest('id')->value('ticket_number') ?? '';"
    $num = Invoke-Tinker $codeClose $AppPath $Php
    if (-not $num) { throw "No ticket found for sender $From. Send a new-ticket message first, then run -CloseLatest." }
    $Body = "CLOSE $num"
}
elseif (-not $Body) {
    $Body = "NEW TICKET Test issue via local webhook at $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')"
}

if (-not $Wamid) { $Wamid = 'wamid.LOCAL' + (Get-Random -Minimum 100000 -Maximum 999999) }

# --- Build the Meta-shaped payload --------------------------------------------
$ts = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds().ToString()

$payload = [ordered]@{
    object = 'whatsapp_business_account'
    entry  = @(
        [ordered]@{
            id      = '1'
            changes = @(
                [ordered]@{
                    field = 'messages'
                    value = [ordered]@{
                        messaging_product = 'whatsapp'
                        contacts = @(
                            [ordered]@{ wa_id = $From; profile = [ordered]@{ name = 'Local Tester' } }
                        )
                        messages = @(
                            [ordered]@{
                                from      = $From
                                id        = $Wamid
                                type      = 'text'
                                text      = [ordered]@{ body = $Body }
                                timestamp = $ts
                            }
                        )
                    }
                }
            )
        }
    )
}

$json  = $payload | ConvertTo-Json -Depth 12 -Compress
$bytes = [System.Text.Encoding]::UTF8.GetBytes($json)

# --- Sign the EXACT bytes we are about to send ---------------------------------
$hmac      = New-Object System.Security.Cryptography.HMACSHA256
$hmac.Key  = [System.Text.Encoding]::UTF8.GetBytes($AppSecret)
$sigHex    = -join ($hmac.ComputeHash($bytes) | ForEach-Object { $_.ToString('x2') })
$signature = "sha256=$sigHex"

# --- POST ----------------------------------------------------------------------
$uri     = $BaseUrl.TrimEnd('/') + $Endpoint
$headers = @{ 'X-Hub-Signature-256' = $signature }

Write-Host ''
Write-Host "POST $uri" -ForegroundColor Cyan
Write-Host "  from  : $From"
Write-Host "  body  : $Body"
Write-Host "  wamid : $Wamid"

try {
    $resp   = Invoke-WebRequest -Method Post -Uri $uri -Body $bytes -ContentType 'application/json' -Headers $headers -UseBasicParsing
    $status = [int]$resp.StatusCode
    $content = $resp.Content
}
catch {
    $r = $_.Exception.Response
    if ($null -eq $r) { throw }
    $status = [int]$r.StatusCode
    $reader = New-Object System.IO.StreamReader($r.GetResponseStream())
    $content = $reader.ReadToEnd()
}

$color = if ($status -eq 200) { 'Green' } else { 'Yellow' }
Write-Host "  => HTTP $status : $content" -ForegroundColor $color

if ($status -eq 401) {
    Write-Host '  Signature rejected. Ensure WHATSAPP_APP_SECRET matches the running server, then run: php artisan config:clear' -ForegroundColor Red
}
elseif ($status -eq 422) {
    Write-Host '  Payload was not recognised as a WhatsApp business-account event.' -ForegroundColor Red
}
elseif ($status -eq 200) {
    Write-Host '  Event recorded. The intake job runs on the queue - if nothing appeared yet:' -ForegroundColor DarkGray
    Write-Host '    run: php artisan queue:work    (or set QUEUE_CONNECTION=sync + php artisan config:clear for inline processing)' -ForegroundColor DarkGray
}
