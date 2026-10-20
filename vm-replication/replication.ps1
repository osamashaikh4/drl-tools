# Reads Hyper-V replication of every VM on one host and prints JSON; called by index.php.
# Host and credentials come in through environment variables so the password never appears
# on a command line:
#   HV_HOST  host name or IP
#   HV_USER  account with Hyper-V rights on the host
#   HV_PASS  its password
#
# This is the data Get-VMReplication shows, read straight from the host's Hyper-V WMI classes
# (root\virtualization\v2) over DCOM. Get-VMReplication itself needs WinRM (PowerShell remoting)
# on the host, or the Hyper-V module on this PC, and neither is available here; DCOM is.
$ErrorActionPreference = 'Stop'

# Number codes from the WMI classes, as the names Get-VMReplication prints.
$STATES = @{
    0 = 'Disabled'; 1 = 'ReadyForInitialReplication'; 2 = 'WaitingForInitialReplication'; 3 = 'Replicating'
    4 = 'SyncedReplicationComplete'; 5 = 'Recovered'; 6 = 'Committed'; 7 = 'Suspended'; 8 = 'Critical'
    9 = 'WaitingForStartResynchronize'; 10 = 'Resynchronizing'; 11 = 'ResynchronizeSuspended'
    12 = 'RecoveryInProgress'; 13 = 'FailbackInProgress'; 14 = 'FailbackComplete'
    15 = 'WaitingForUpdateCompletion'; 16 = 'UpdateError'; 17 = 'WaitingForRepurposeCompletion'
    18 = 'PreparedForSyncReplication'; 19 = 'PreparedForGroupReverseReplication'; 20 = 'FiredrillInProgress'
}
$HEALTH = @{ 0 = 'NotApplicable'; 1 = 'Normal'; 2 = 'Warning'; 3 = 'Critical' }
$MODES  = @{ 0 = 'None'; 1 = 'Primary'; 2 = 'Replica'; 3 = 'TestReplica'; 4 = 'ExtendedReplica' }
$name   = { param($map, $code) if ($null -ne $code -and $map.ContainsKey([int]$code)) { $map[[int]$code] } else { "$code" } }

try {
    $secure = ConvertTo-SecureString $env:HV_PASS -AsPlainText -Force
    $cred   = New-Object System.Management.Automation.PSCredential($env:HV_USER, $secure)
    $cim    = New-CimSession -ComputerName $env:HV_HOST -Credential $cred -SessionOption (New-CimSessionOption -Protocol Dcom) -OperationTimeoutSec 60
    $ns     = 'root\virtualization\v2'

    try {
        $hostName = (Get-CimInstance -CimSession $cim -Namespace $ns -ClassName Msvm_ComputerSystem -Filter "Caption='Hosting Computer System'").ElementName

        # Replication settings per VM; relationship 0 (…\HVR\0) is the one Get-VMReplication shows.
        $settings = @{}
        Get-CimInstance -CimSession $cim -Namespace $ns -ClassName Msvm_ReplicationSettingData |
            Where-Object { $_.InstanceID -like '*\HVR\0' } |
            ForEach-Object { $settings[$_.VirtualSystemIdentifier] = $_ }

        # Like Get-VMReplication: only VMs that have replication set up.
        $rows = @(Get-CimInstance -CimSession $cim -Namespace $ns -ClassName Msvm_ComputerSystem -Filter "Caption='Virtual Machine' AND ReplicationMode<>0" |
            ForEach-Object {
                $s    = $settings[$_.Name]
                $last = $null
                if ($_.LastReplicationTime -and $_.LastReplicationTime.Year -gt 1601) {
                    $last = $_.LastReplicationTime.ToString('yyyy-MM-dd HH:mm:ss')
                }
                [pscustomobject]@{
                    VMName              = $_.ElementName
                    State               = & $name $STATES $_.ReplicationState
                    Health              = & $name $HEALTH $_.ReplicationHealth
                    Mode                = & $name $MODES $_.ReplicationMode
                    PrimaryServer       = if ($s) { $s.PrimaryConnectionPoint } else { $null }
                    ReplicaServer       = if ($s) { $s.RecoveryConnectionPoint } else { $null }
                    FrequencySec        = if ($s) { [int]$s.ReplicationInterval } else { $null }
                    LastReplicationTime = $last
                }
            } | Sort-Object VMName)
    }
    finally {
        Remove-CimSession -CimSession $cim
    }

    ConvertTo-Json -InputObject @{ ok = $true; hostName = $hostName; vms = $rows; checked = (Get-Date).ToString('HH:mm:ss') } -Depth 4 -Compress
}
catch {
    ConvertTo-Json -InputObject @{ ok = $false; error = $_.Exception.Message } -Compress
}
