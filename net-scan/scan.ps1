# Finds the devices on one or more IPv4 subnets and prints JSON; called by index.php.
# Input through environment variables:
#   NS_CIDRS  comma-separated subnets, e.g. "10.1.52.0/24,172.16.20.0/24" (each /22 or smaller)
#   NS_PORTS  comma-separated TCP ports to check on every device found
#
# A device counts as found when it answers ping or when this PC has its MAC address (devices that
# block ping still answer ARP on the local subnets). Everything runs in parallel with short timeouts,
# so a /24 takes a few seconds. Nothing is sent beyond ping, ARP, a reverse DNS lookup and a TCP
# connect (closed at once) to each listed port.
$ErrorActionPreference = 'Stop'

function Get-Hosts([string]$cidr) {
    $ip, $bits = $cidr.Split('/')
    $bits = [int]$bits
    if ($bits -lt 22 -or $bits -gt 30) { throw "Subnet $cidr is not between /22 and /30" }
    $bytes = ([Net.IPAddress]::Parse($ip)).GetAddressBytes()
    [Array]::Reverse($bytes)
    $n     = [BitConverter]::ToUInt32($bytes, 0)
    $mask  = [uint32]([math]::Pow(2, 32) - [math]::Pow(2, 32 - $bits))
    $net   = $n -band $mask
    $count = [math]::Pow(2, 32 - $bits)
    for ($i = 1; $i -lt $count - 1; $i++) {
        $b = [BitConverter]::GetBytes([uint32]($net + $i))
        [Array]::Reverse($b)
        ([Net.IPAddress]$b).ToString()
    }
}

try {
    $cidrs = @($env:NS_CIDRS.Split(',') | Where-Object { $_ })
    $ports = @($env:NS_PORTS.Split(',') | Where-Object { $_ } | ForEach-Object { [int]$_ })
    $all   = @($cidrs | ForEach-Object { Get-Hosts $_ })
    $inScope = @{}
    $all | ForEach-Object { $inScope[$_] = $true }

    # --- 1. Ping everything at once (1 s timeout).
    $pings = @{}
    foreach ($ip in $all) { $pings[$ip] = (New-Object Net.NetworkInformation.Ping).SendPingAsync($ip, 1000) }
    try { [Threading.Tasks.Task]::WaitAll([Threading.Tasks.Task[]]@($pings.Values), 6000) | Out-Null } catch {}

    $hosts = @{}
    foreach ($ip in $all) {
        $t = $pings[$ip]
        if ($t.Status -eq 'RanToCompletion' -and $t.Result.Status -eq 'Success') {
            $hosts[$ip] = [ordered]@{ ip = $ip; ping = $true; ms = [int]$t.Result.RoundtripTime; mac = $null; name = $null; ports = @() }
        }
    }

    # --- 2. MAC addresses from this PC's neighbour (ARP) table; adds devices that ignore ping.
    Get-NetNeighbor -AddressFamily IPv4 -ErrorAction SilentlyContinue |
        Where-Object { $inScope.ContainsKey($_.IPAddress) -and $_.LinkLayerAddress -and $_.LinkLayerAddress -notmatch '^(00-){5}00$|^(FF-){5}FF$' -and $_.State -ne 'Unreachable' } |
        ForEach-Object {
            if (-not $hosts.ContainsKey($_.IPAddress)) {
                $hosts[$_.IPAddress] = [ordered]@{ ip = $_.IPAddress; ping = $false; ms = $null; mac = $null; name = $null; ports = @() }
            }
            $hosts[$_.IPAddress].mac = $_.LinkLayerAddress
        }

    # This PC's own addresses.
    $self = @{}
    Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue | ForEach-Object { $self[$_.IPAddress] = $_.InterfaceAlias }
    Get-NetAdapter -ErrorAction SilentlyContinue | ForEach-Object {
        $mac = $_.MacAddress
        Get-NetIPAddress -InterfaceIndex $_.ifIndex -AddressFamily IPv4 -ErrorAction SilentlyContinue | ForEach-Object {
            if ($hosts.ContainsKey($_.IPAddress)) { $hosts[$_.IPAddress].mac = $mac }
        }
    }

    $found = @($hosts.Keys)

    # --- 3. Host names by reverse DNS (3 s for all).
    $dns = @{}
    foreach ($ip in $found) { $dns[$ip] = [Net.Dns]::GetHostEntryAsync($ip) }
    try { [Threading.Tasks.Task]::WaitAll([Threading.Tasks.Task[]]@($dns.Values), 3000) | Out-Null } catch {}
    foreach ($ip in $found) {
        $t = $dns[$ip]
        if ($t.Status -eq 'RanToCompletion' -and $t.Result.HostName -and $t.Result.HostName -ne $ip) { $hosts[$ip].name = $t.Result.HostName }
    }

    # --- 4. Open TCP ports (1.5 s for all).
    $conns = New-Object System.Collections.ArrayList
    foreach ($ip in $found) {
        foreach ($port in $ports) {
            $c = New-Object Net.Sockets.TcpClient
            [void]$conns.Add(@{ ip = $ip; port = $port; client = $c; task = $c.ConnectAsync($ip, $port) })
        }
    }
    if ($conns.Count) {
        try { [Threading.Tasks.Task]::WaitAll([Threading.Tasks.Task[]]@($conns | ForEach-Object { $_.task }), 1500) | Out-Null } catch {}
    }
    foreach ($x in $conns) {
        if ($x.task.Status -eq 'RanToCompletion' -and $x.client.Connected) { $hosts[$x.ip].ports += $x.port }
        $x.client.Dispose()
    }

    $rows = @($hosts.Values | ForEach-Object {
        $_.self = $self.ContainsKey($_.ip)
        [pscustomobject]$_
    })
    ConvertTo-Json -InputObject @{ ok = $true; scanned = $all.Count; hosts = $rows } -Depth 4 -Compress
}
catch {
    ConvertTo-Json -InputObject @{ ok = $false; error = $_.Exception.Message } -Compress
}
