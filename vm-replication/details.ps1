# Details of one VM on one Hyper-V host, as JSON; called by index.php when a VM's Details is opened.
# Same connection as replication.ps1 (WMI over DCOM); input through environment variables:
#   HV_HOST, HV_USER, HV_PASS  as in replication.ps1
#   HV_VM                      the VM's Id (GUID)
#
# Hyper-V's event logs cannot be read remotely from here (remote event log access is closed), so
# "what happened lately" comes from the host's recent Hyper-V jobs for the VM instead.
$ErrorActionPreference = 'Stop'

$VM_STATES  = @{ 2 = 'Running'; 3 = 'Off'; 6 = 'Saved'; 9 = 'Paused'; 10 = 'Starting'; 11 = 'Resetting'; 32768 = 'Paused'; 32769 = 'Saved'; 32770 = 'Starting'; 32771 = 'Taking checkpoint'; 32773 = 'Saving'; 32774 = 'Stopping'; 32776 = 'Pausing'; 32777 = 'Resuming' }
$HEARTBEAT  = @{ 2 = 'OK'; 3 = 'Degraded'; 6 = 'Error'; 7 = 'Non-recoverable error'; 12 = 'No contact'; 13 = 'Lost communication'; 15 = 'Paused' }
$JOB_STATES = @{ 2 = 'New'; 3 = 'Starting'; 4 = 'Running'; 5 = 'Suspended'; 6 = 'Shutting down'; 7 = 'Completed'; 8 = 'Terminated'; 9 = 'Killed'; 10 = 'Exception'; 11 = 'Service'; 32768 = 'Queued' }
$AUTH       = @{ 1 = 'Kerberos (HTTP)'; 2 = 'Certificate (HTTPS)' }
$HEALTH     = @{ 0 = 'NotApplicable'; 1 = 'Normal'; 2 = 'Warning'; 3 = 'Critical' }
$name       = { param($map, $code) if ($null -ne $code -and $map.ContainsKey([int]$code)) { $map[[int]$code] } elseif ($null -ne $code) { "$code" } else { $null } }
$when       = { param($d) if ($d -and $d.Year -gt 1601) { $d.ToString('yyyy-MM-dd HH:mm:ss') } else { $null } }

try {
    if ($env:HV_VM -notmatch '^[0-9A-Fa-f-]{36}$') { throw 'Invalid VM id' }

    $secure = ConvertTo-SecureString $env:HV_PASS -AsPlainText -Force
    $cred   = New-Object System.Management.Automation.PSCredential($env:HV_USER, $secure)
    $cim    = New-CimSession -ComputerName $env:HV_HOST -Credential $cred -SessionOption (New-CimSessionOption -Protocol Dcom) -OperationTimeoutSec 60
    $ns     = 'root\virtualization\v2'

    try {
        $vm = Get-CimInstance -CimSession $cim -Namespace $ns -ClassName Msvm_ComputerSystem -Filter "Name='$($env:HV_VM)'"
        if (!$vm) { throw 'VM not found on this host' }

        # --- The VM: power state, uptime, CPU, memory, guest OS (what Hyper-V Manager's summary shows).
        $vsms = Get-CimInstance -CimSession $cim -Namespace $ns -ClassName Msvm_VirtualSystemManagementService
        $vssd = Get-CimAssociatedInstance -CimSession $cim -InputObject $vm -ResultClassName Msvm_VirtualSystemSettingData |
            Where-Object { $_.VirtualSystemType -eq 'Microsoft:Hyper-V:System:Realized' }
        $sum = (Invoke-CimMethod -CimSession $cim -InputObject $vsms -MethodName GetSummaryInformation -Arguments @{
                SettingData          = [CimInstance[]]@($vssd)
                RequestedInformation = [uint32[]](4, 100, 101, 103, 104, 105, 106, 109, 111, 112)
            }).SummaryInformation | Select-Object -First 1
        $vmInfo = [ordered]@{
            State           = & $name $VM_STATES $sum.EnabledState
            Status          = (@($sum.StatusDescriptions) -join ', ')
            UptimeSec       = if ($sum.UpTime) { [math]::Floor([double]$sum.UpTime / 1000) } else { 0 }
            Processors      = $sum.NumberOfProcessors
            ProcessorLoad   = $sum.ProcessorLoad
            MemoryMB        = $sum.MemoryUsage
            MemoryAvailable = $sum.MemoryAvailable
            Heartbeat       = & $name $HEARTBEAT $sum.Heartbeat
            GuestOS         = $sum.GuestOperatingSystem
        }

        # --- Replication settings (relationship 0, the one Get-VMReplication shows).
        $s = Get-CimInstance -CimSession $cim -Namespace $ns -ClassName Msvm_ReplicationSettingData |
            Where-Object { $_.VirtualSystemIdentifier -eq $env:HV_VM -and $_.InstanceID -like '*\HVR\0' } | Select-Object -First 1
        $settings = if ($s) {
            [ordered]@{
                PrimaryServer       = $s.PrimaryConnectionPoint
                ReplicaServer       = $s.RecoveryConnectionPoint
                Port                = $s.RecoveryServerPortNumber
                Authentication      = & $name $AUTH $s.AuthenticationType
                FrequencySec        = $s.ReplicationInterval
                RecoveryPoints      = $s.RecoveryHistory
                VssSnapshotHours    = $s.ApplicationConsistentSnapshotInterval
                Compression         = [bool]$s.CompressionEnabled
            }
        } else { $null }

        # --- Replication statistics (Measure-VMReplication / "View Replication Health").
        # They come back as CIM-XML text, one block per relationship; the first is relationship 0.
        $stats = $null
        $svc = Get-CimInstance -CimSession $cim -Namespace $ns -ClassName Msvm_ReplicationService
        $raw = @((Invoke-CimMethod -CimSession $cim -InputObject $svc -MethodName GetReplicationStatistics -Arguments @{ ComputerSystem = $vm }).ReplicationStatistics)
        if ($raw.Count -and $raw[0]) {
            $p = @{}
            ([xml]$raw[0]).INSTANCE.PROPERTY | ForEach-Object { $p[$_.NAME] = $_.VALUE }
            $date = { param($v) if ($v -and $v -notlike '1601*') { [Management.ManagementDateTimeConverter]::ToDateTime($v).ToString('yyyy-MM-dd HH:mm:ss') } else { $null } }
            $stats = [ordered]@{
                From             = & $date $p['StartStatisticTime']
                To               = & $date $p['EndStatisticTime']
                Health           = & $name $HEALTH $p['ReplicationHealth']
                Successful       = [int]$p['ReplicationSuccessCount']
                Missed           = [int]$p['ReplicationMissCount']
                Failed           = [int]$p['ReplicationFailureCount']
                NetworkFailures  = [int]$p['NetworkFailureCount']
                VssFailures      = [int]$p['ApplicationConsistentSnapshotFailureCount']
                AvgLatencySec    = [int]$p['ReplicationLatency']
                MaxLatencySec    = [int]$p['MaxReplicationLatency']
                TotalBytes       = [double]$p['ReplicationSize']
                MaxBytes         = [double]$p['MaxReplicationSize']
                PendingBytes     = [double]$p['PendingReplicationSize']
                LastTestFailover = & $date $p['LastTestFailoverTime']
            }
        }

        # --- Recent Hyper-V jobs on this VM (newest first). The host only keeps recent ones.
        $jobs = @(Get-CimAssociatedInstance -CimSession $cim -InputObject $vm -ResultClassName Msvm_ConcreteJob |
            Sort-Object StartTime -Descending | ForEach-Object {
                [pscustomobject]@{
                    Name       = $_.ElementName
                    State      = & $name $JOB_STATES $_.JobState
                    Percent    = $_.PercentComplete
                    Started    = & $when $_.StartTime
                    ElapsedSec = if ($_.ElapsedTime) { [math]::Round($_.ElapsedTime.TotalSeconds, 1) } else { $null }
                    Error      = if ($_.ErrorCode) { "$($_.ErrorCode): $($_.ErrorDescription)" } else { $null }
                }
            })
    }
    finally {
        Remove-CimSession -CimSession $cim
    }

    ConvertTo-Json -InputObject @{ ok = $true; vm = $vmInfo; settings = $settings; stats = $stats; jobs = $jobs } -Depth 4 -Compress
}
catch {
    ConvertTo-Json -InputObject @{ ok = $false; error = $_.Exception.Message } -Compress
}
